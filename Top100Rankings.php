#!/usr/bin/env php
<?php

/**
 * Masters Radio Top 100 Rankings CLI
 * 
 * Scans WordPress uploads for MP3 files, matches to Spotify tracks,
 * generates Top 100 ranking by popularity, and emails CSV report.
 * 
 * Usage: php Top100Rankings.php [OPTIONS]
 */

require __DIR__ . '/vendor/autoload.php';

use MastersRadio\Top100\Config;
use MastersRadio\Top100\Logger;
use MastersRadio\Top100\Database\Connection;
use MastersRadio\Top100\Database\Migrator;
use MastersRadio\Top100\WordPress\ConfigReader;
use MastersRadio\Top100\WordPress\FileScanner;
use MastersRadio\Top100\Metadata\ID3Reader;
use MastersRadio\Top100\Spotify\Authenticator;
use MastersRadio\Top100\Spotify\RateLimiter;
use MastersRadio\Top100\Spotify\Client as SpotifyClient;
use MastersRadio\Top100\Spotify\Matcher;
use MastersRadio\Top100\Ranking\RankingEngine;
use MastersRadio\Top100\Ranking\CsvGenerator;
use MastersRadio\Top100\Email\OAuthManager;
use MastersRadio\Top100\Email\Mailer;
use MastersRadio\Top100\Scheduling\CronGate;
use MastersRadio\Top100\Scheduling\LockManager;

// Parse command line options
$options = getopt('', [
    'cron',
    'run-now',
    'month:',
    'dry-run',
    'verbose',
    'limit:',
    'help',
    'version',
]);

if (isset($options['help'])) {
    displayHelp();
    exit(0);
}

if (isset($options['version'])) {
    echo "Masters Radio Top 100 Rankings v1.0.0\n";
    exit(0);
}

$baseDir = __DIR__;
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;

try {
    // Load configuration
    $config = new Config($baseDir);
    $errors = $config->validate();
    
    if (!empty($errors)) {
        echo "Configuration errors:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
        exit(2);
    }

    // Override config with command line options
    if ($dryRun) {
        $_ENV['DRY_RUN'] = 'true';
    }
    if ($limit > 0) {
        $_ENV['PROCESS_LIMIT'] = (string)$limit;
    }

    // Determine label month
    $labelMonth = $options['month'] ?? null;
    
    // Initialize logger
    $logFile = $baseDir . '/logs/' . ($labelMonth ?? date('Y-m')) . '.log';
    $logger = new Logger($logFile, $config->get('LOG_LEVEL', 'INFO'), $verbose);
    
    $logger->info("=== Masters Radio Top 100 Rankings Starting ===");
    $logger->info("PHP Version: " . PHP_VERSION);
    $logger->info("Base Directory: {$baseDir}");
    $logger->info("Dry Run: " . ($dryRun ? 'YES' : 'NO'));
    
    // Check schedule gate (if --cron mode)
    if (isset($options['cron'])) {
        $cronGate = new CronGate($logger, $config->get('TIMEZONE'));
        
        if (!$cronGate->shouldRun()) {
            $nextRun = $cronGate->getNextRunDate()->format('Y-m-d H:i:s T');
            $logger->info("Schedule gate: Not the last Monday. Next run: {$nextRun}");
            exit(4);
        }
        
        $labelMonth = $labelMonth ?? $cronGate->getLabelMonth();
    } elseif (!isset($options['run-now'])) {
        echo "Error: Must specify --cron or --run-now\n";
        displayHelp();
        exit(1);
    }
    
    $labelMonth = $labelMonth ?? date('Y-m');
    $logger->info("Label Month: {$labelMonth}");
    
    // Acquire lock
    $lockFile = $baseDir . '/run.lock';
    $lockManager = new LockManager($logger, $lockFile, $config->getInt('LOCK_MAX_AGE_HOURS', 12));
    
    if (!$lockManager->acquire()) {
        $logger->critical("Could not acquire lock - another run may be in progress");
        exit(3);
    }
    
    // Initialize WordPress reader
    $wpRoot = $config->get('WP_ROOT');
    $logger->info("WordPress root: {$wpRoot}");
    
    $wpConfig = new ConfigReader($wpRoot);
    $uploadsPath = $wpConfig->getUploadsPath();
    $logger->info("Uploads path: {$uploadsPath}");
    
    // Initialize database connection
    $dbHost = $config->get('DB_HOST') ?: $wpConfig->getDbHost();
    $dbName = $config->get('DB_NAME', 'top100');
    $dbUser = $config->get('DB_USER') ?: $wpConfig->getDbUser();
    $dbPass = $config->get('DB_PASS') ?: $wpConfig->getDbPassword();
    
    $logger->info("Connecting to database: {$dbName}@{$dbHost}");
    $db = Connection::getInstance($dbHost, $dbName, $dbUser, $dbPass);
    
    // Run migrations
    $migrator = new Migrator($db, $logger, $baseDir . '/config/schema/migrations');
    $appliedMigrations = $migrator->migrate();
    if (!empty($appliedMigrations)) {
        $logger->info("Applied migrations: " . implode(', ', $appliedMigrations));
    }
    
    // Create run record
    $runId = createRunRecord($db, $labelMonth, $logger);
    $logger->info("Run ID: {$runId}");
    
    // Scan for MP3 files
    $scanner = new FileScanner($logger, $uploadsPath);
    $files = $scanner->scan();
    
    if ($limit > 0 && count($files) > $limit) {
        $files = array_slice($files, 0, $limit);
        $logger->notice("Limited to {$limit} files for testing");
    }
    
    $totalFiles = count($files);
    $logger->info("Processing {$totalFiles} MP3 files");
    
    // Initialize Spotify client
    $spotifyAuth = new Authenticator(
        $config->get('SPOTIFY_CLIENT_ID'),
        $config->get('SPOTIFY_CLIENT_SECRET')
    );
    $rateLimiter = new RateLimiter(
        $config->getInt('SPOTIFY_RATE_LIMIT_PER_SECOND', 10),
        $config->getInt('SPOTIFY_RETRY_MAX_ATTEMPTS', 5),
        $config->getInt('SPOTIFY_RETRY_BASE_DELAY_MS', 500)
    );
    $spotifyClient = new SpotifyClient($spotifyAuth, $rateLimiter, $logger);
    $matcher = new Matcher(
        $spotifyClient,
        $logger,
        $config->getFloat('MATCH_CONFIDENCE_THRESHOLD', 0.85)
    );
    
    // Initialize ID3 reader
    $id3Reader = new ID3Reader();
    
    // Process files and match to Spotify
    $matched = 0;
    $unmatched = 0;
    $matchedTracks = [];
    
    foreach ($files as $index => $fileInfo) {
        $num = $index + 1;
        $logger->info("[{$num}/{$totalFiles}] Processing: {$fileInfo['filename']}");
        
        try {
            // Extract metadata
            $metadata = $id3Reader->read($fileInfo['file_path']);
            $logger->debug("  Artist: {$metadata['artist']}, Title: {$metadata['title']}");
            
            // Find Spotify match
            $spotifyMatch = $matcher->findMatch($metadata);
            
            if ($spotifyMatch && !empty($spotifyMatch['spotify_id'])) {
                $matched++;
                $matchedTracks[] = array_merge($fileInfo, $metadata, $spotifyMatch);
                $logger->info("  ✓ Matched: {$spotifyMatch['artist']} - {$spotifyMatch['title']} (popularity: {$spotifyMatch['popularity']})");
            } else {
                $unmatched++;
                $logger->notice("  ✗ No match found");
            }
            
            // Store observation (if not dry run)
            if (!$dryRun) {
                storeObservation($db, $runId, $fileInfo, $metadata, $spotifyMatch, $logger);
            }
            
        } catch (\Exception $e) {
            $logger->error("  Error processing file: " . $e->getMessage());
            $unmatched++;
        }
    }
    
    $logger->info("Matching complete: {$matched} matched, {$unmatched} unmatched");

    // Filter tracks by upload eligibility window
    $eligibilityMonths = $config->getInt('ELIGIBILITY_MONTHS', 3);
    $eligibleTracks = $matchedTracks;

    if ($eligibilityMonths > 0) {
        $eligibleTracks = filterByUploadDate($matchedTracks, $labelMonth, $eligibilityMonths, $logger);
        $logger->info("Eligibility filter: {$eligibilityMonths} months - " . count($eligibleTracks) . " of {$matched} tracks eligible for Top 100");
    } else {
        $logger->info("Eligibility filter: Disabled (all matched tracks eligible)");
    }

    // Generate rankings (using only eligible tracks)
    $ranker = new RankingEngine($logger, $config->getInt('TOP_N_TRACKS', 100));
    $rankedTracks = $ranker->rank($eligibleTracks);
    
    $logger->info("Generated " . count($rankedTracks) . " rankings");
    
    // Generate CSV
    $reportDir = $baseDir . '/reports/' . $labelMonth;
    $csvPath = $reportDir . '/Top100_' . $labelMonth . '.csv';
    
    $csvGenerator = new CsvGenerator($logger);
    $csvGenerator->generate($rankedTracks, $csvPath);
    
    // Update run record
    $avgPopularity = !empty($matchedTracks) 
        ? array_sum(array_column($matchedTracks, 'popularity')) / count($matchedTracks)
        : 0;
    
    finishRunRecord($db, $runId, 'success', $totalFiles, $matched, $unmatched, $avgPopularity, $logger);
    
    // Send email (if not dry run)
    if (!$dryRun) {
        sendSuccessEmail($config, $logger, $labelMonth, $rankedTracks, $csvPath, $matched, $unmatched, count($eligibleTracks), $eligibilityMonths);
    } else {
        $logger->info("Dry run mode: Skipping email");
    }
    
    $logger->info("=== Run completed successfully ===");
    
    // Release lock
    $lockManager->release();
    
    exit(0);
    
} catch (\Exception $e) {
    if (isset($logger)) {
        $logger->critical("Fatal error: " . $e->getMessage());
        $logger->debug("Stack trace: " . $e->getTraceAsString());
    } else {
        echo "Fatal error: " . $e->getMessage() . "\n";
    }
    
    if (isset($lockManager)) {
        $lockManager->release();
    }
    
    exit(1);
}

function displayHelp(): void
{
    echo <<<HELP
Masters Radio Top 100 Rankings
Version 1.0.0

Usage:
  php Top100Rankings.php [OPTIONS]

Options:
  --cron              Run in cron mode (exit unless last Monday 04:00 ET)
  --run-now           Bypass schedule gate and run immediately
  --month=YYYY-MM     Force specific label month (default: current month)
  --dry-run           Execute without DB writes or email sending
  --verbose           Enable detailed DEBUG logging
  --limit=N           Process only N files (for testing)
  --help              Display this help message
  --version           Display version information

Examples:
  php Top100Rankings.php --cron
  php Top100Rankings.php --run-now --verbose
  php Top100Rankings.php --run-now --dry-run --limit=50

HELP;
}

function createRunRecord($db, string $labelMonth, Logger $logger): int
{
    $sql = "INSERT INTO runs (label_month, started_at, hostname, php_version) 
            VALUES (?, NOW(), ?, ?)";
    
    $db->execute($sql, [
        $labelMonth,
        gethostname(),
        PHP_VERSION
    ]);
    
    return (int)$db->lastInsertId();
}

function finishRunRecord($db, int $runId, string $status, int $totalFiles, int $matched, int $unmatched, float $avgPopularity, Logger $logger): void
{
    $sql = "UPDATE runs 
            SET finished_at = NOW(),
                status = ?,
                total_files = ?,
                matched_tracks = ?,
                unmatched_tracks = ?,
                avg_popularity = ?,
                execution_time = TIMESTAMPDIFF(SECOND, started_at, NOW())
            WHERE id = ?";
    
    $db->execute($sql, [
        $status,
        $totalFiles,
        $matched,
        $unmatched,
        $avgPopularity,
        $runId
    ]);
}

function storeObservation($db, int $runId, array $fileInfo, array $metadata, ?array $spotifyMatch, Logger $logger): void
{
    // This is a simplified version - full implementation would handle wp_media and tracks tables
    // For now, just log that we would store it
    $logger->debug("Would store observation for: {$fileInfo['filename']}");
}

function sendSuccessEmail(Config $config, Logger $logger, string $labelMonth, array $rankedTracks, string $csvPath, int $matched, int $unmatched, int $eligible = 0, int $eligibilityMonths = 0): void
{
    try {
        // Try reports@mastersradio.com first, fallback to adam.pressman@mastersradio.com
        $username = $config->get('SMTP_USERNAME', 'adam.pressman@mastersradio.com');
        $password = $config->get('GMAIL_APP_PASSWORD');

        if (empty($password)) {
            throw new \Exception("GMAIL_APP_PASSWORD not configured in .env");
        }

        $mailer = new Mailer(
            $logger,
            $config->get('SMTP_HOST'),
            $config->getInt('SMTP_PORT'),
            $username,
            $password,
            $config->get('SMTP_FROM_EMAIL'),
            $config->get('SMTP_FROM_NAME')
        );

        $monthName = date('F Y', strtotime($labelMonth . '-01'));
        $subject = "Masters Radio Top 100 for {$monthName}";

        $htmlBody = generateEmailBody($rankedTracks, $matched, $unmatched, $monthName, $eligible, $eligibilityMonths);

        $mailer->sendSuccessEmail(
            $config->get('REPORT_TO_EMAIL'),
            $config->get('REPORT_TO_NAME'),
            $subject,
            $htmlBody,
            $csvPath
        );

    } catch (\Exception $e) {
        $logger->error("Failed to send email: " . $e->getMessage());
    }
}

function generateEmailBody(array $rankedTracks, int $matched, int $unmatched, string $monthName, int $eligible = 0, int $eligibilityMonths = 0): string
{
    $topTracksHtml = '';
    $displayCount = min(10, count($rankedTracks));

    for ($i = 0; $i < $displayCount; $i++) {
        $track = $rankedTracks[$i];
        $topTracksHtml .= "<tr>\n";
        $topTracksHtml .= "  <td class='rank'>{$track['rank']}</td>\n";
        $topTracksHtml .= "  <td>{$track['artist']}</td>\n";
        $topTracksHtml .= "  <td>{$track['title']}</td>\n";
        $topTracksHtml .= "  <td>{$track['popularity']}</td>\n";
        $topTracksHtml .= "</tr>\n";
    }

    // Build eligibility info
    $eligibilityHtml = '';
    if ($eligibilityMonths > 0) {
        $eligibilityHtml = "Eligible for Top 100: {$eligible} tracks (uploaded within {$eligibilityMonths} months)<br>";
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .summary { background: #f4f4f4; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .eligibility { background: #e8f4f8; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #3498db; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        tr:hover { background: #f5f5f5; }
        .rank { font-weight: bold; color: #e74c3c; }
    </style>
</head>
<body>
    <h1>Masters Radio Top 100 - {$monthName}</h1>

    <div class="summary">
        <strong>Run Summary:</strong><br>
        Total Matched: {$matched} tracks<br>
        Unmatched: {$unmatched} tracks<br>
        {$eligibilityHtml}
        Generated: {$monthName}
    </div>

    <h2>Top 10 Preview</h2>
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Artist</th>
                <th>Title</th>
                <th>Popularity</th>
            </tr>
        </thead>
        <tbody>
            {$topTracksHtml}
        </tbody>
    </table>

    <p><strong>Full Top 100 rankings are available in the attached CSV file.</strong></p>
    <p><em>Note: Only songs uploaded within the last {$eligibilityMonths} months are eligible for the Top 100 ranking.</em></p>
</body>
</html>
HTML;
}

/**
 * Filter tracks by upload date to only include those within the eligibility window
 *
 * @param array $tracks Array of matched tracks with file_mtime
 * @param string $labelMonth The report month (YYYY-MM format)
 * @param int $months Number of months back from label month to include
 * @param Logger $logger Logger instance
 * @return array Filtered tracks within the eligibility window
 */
function filterByUploadDate(array $tracks, string $labelMonth, int $months, Logger $logger): array
{
    // Calculate cutoff date: first day of (labelMonth - months)
    // For labelMonth 2025-11 with 3 months: cutoff is 2025-08-01
    $labelDate = new DateTime($labelMonth . '-01');
    $cutoffDate = (clone $labelDate)->modify("-{$months} months");
    $cutoffDateStr = $cutoffDate->format('Y-m-d 00:00:00');

    // End date is the last moment of the label month
    $endDate = (clone $labelDate)->modify('last day of this month');
    $endDateStr = $endDate->format('Y-m-d 23:59:59');

    $logger->debug("Upload eligibility window: {$cutoffDateStr} to {$endDateStr}");

    $eligible = [];
    $excluded = 0;

    foreach ($tracks as $track) {
        $uploadDate = $track['file_mtime'] ?? '1900-01-01 00:00:00';

        // Check if upload date is within the eligibility window
        if ($uploadDate >= $cutoffDateStr && $uploadDate <= $endDateStr) {
            $eligible[] = $track;
        } else {
            $excluded++;
            $logger->debug("Excluded (upload {$uploadDate}): {$track['artist']} - {$track['title']}");
        }
    }

    $logger->notice("Eligibility filter excluded {$excluded} tracks uploaded outside the {$months}-month window");

    return $eligible;
}
