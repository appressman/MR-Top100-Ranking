<?php

namespace MastersRadio\Top100\Ranking;

use MastersRadio\Top100\Logger;

/**
 * Generates Excel-compatible CSV files with Top 100 rankings
 */
class CsvGenerator
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Generate CSV file from ranked tracks
     * 
     * @param array $rankedTracks Tracks with rank, artist, title, etc.
     * @param string $outputPath Full path to output CSV file
     * @return string Path to generated file
     */
    public function generate(array $rankedTracks, string $outputPath): string
    {
        $this->logger->info("Generating CSV: {$outputPath}");

        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0750, true);
        }

        // Open file for writing
        $handle = fopen($outputPath, 'w');
        
        if ($handle === false) {
            throw new \Exception("Failed to open output file: {$outputPath}");
        }

        // Write UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Write header row
        $this->writeCsvRow($handle, [
            'rank',
            'artist',
            'title',
            'isrc',
            'spotify_id',
            'popularity',
            'release_date',
            'wp_media_id',
            'source_url',
        ]);

        // Write data rows
        foreach ($rankedTracks as $track) {
            $this->writeCsvRow($handle, [
                $track['rank'] ?? '',
                $track['artist'] ?? '',
                $track['title'] ?? '',
                $track['isrc'] ?? '',
                $track['spotify_id'] ?? '',
                $track['popularity'] ?? '',
                $track['release_date'] ?? '',
                $track['wp_media_id'] ?? '',
                $track['source_url'] ?? '',
            ]);
        }

        fclose($handle);

        // Set file permissions
        chmod($outputPath, 0644);

        $this->logger->info("CSV generated successfully: " . count($rankedTracks) . " tracks");

        return $outputPath;
    }

    /**
     * Write a single CSV row with RFC 4180 compliance
     * Uses CRLF line endings for Windows/Excel compatibility
     */
    private function writeCsvRow($handle, array $fields): void
    {
        $escapedFields = array_map([$this, 'escapeField'], $fields);
        $line = implode(',', $escapedFields);
        
        // Write with CRLF line ending
        fwrite($handle, $line . "\r\n");
    }

    /**
     * Escape CSV field according to RFC 4180
     * Quotes fields containing comma, double-quote, or newline
     * Doubles internal double-quotes
     */
    private function escapeField(string $field): string
    {
        // Check if field needs quoting
        if (strpos($field, ',') !== false || 
            strpos($field, '"') !== false || 
            strpos($field, "\n") !== false ||
            strpos($field, "\r") !== false) {
            
            // Escape internal quotes by doubling them
            $field = str_replace('"', '""', $field);
            
            // Wrap in quotes
            return '"' . $field . '"';
        }

        return $field;
    }
}
