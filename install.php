#!/usr/bin/env php
<?php

/**
 * Masters Radio Top 100 Rankings - Installation Script
 * 
 * Sets up database schema and validates configuration
 */

require __DIR__ . '/vendor/autoload.php';

use MastersRadio\Top100\Config;
use MastersRadio\Top100\Logger;
use MastersRadio\Top100\Database\Connection;
use MastersRadio\Top100\Database\Migrator;
use MastersRadio\Top100\WordPress\ConfigReader;

echo "\n";
echo "==================================================\n";
echo "  Masters Radio Top 100 Rankings - Installation  \n";
echo "==================================================\n\n";

$baseDir = __DIR__;

try {
    // Step 1: Check PHP version
    echo "[1/8] Checking PHP version...\n";
    $phpVersion = PHP_VERSION;
    $requiredVersion = '8.0.0';
    
    if (version_compare($phpVersion, $requiredVersion, '<')) {
        throw new Exception("PHP {$requiredVersion} or higher required (found: {$phpVersion})");
    }
    
    echo "  ✓ PHP {$phpVersion}\n\n";
    
    // Step 2: Check required extensions
    echo "[2/8] Checking PHP extensions...\n";
    $requiredExtensions = ['curl', 'json', 'mbstring', 'pdo', 'pdo_mysql'];
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        } else {
            echo "  ✓ {$ext}\n";
        }
    }
    
    if (!empty($missingExtensions)) {
        throw new Exception("Missing required PHP extensions: " . implode(', ', $missingExtensions));
    }
    echo "\n";
    
    // Step 3: Check if .env exists
    echo "[3/8] Checking configuration...\n";
    $envFile = $baseDir . '/.env';
    $envExample = $baseDir . '/config/.env.example';
    
    if (!file_exists($envFile)) {
        echo "  ! .env file not found\n";
        echo "  Copying .env.example to .env...\n";
        
        if (file_exists($envExample)) {
            copy($envExample, $envFile);
            chmod($envFile, 0640);
            echo "  ✓ Created .env file\n";
            echo "  ! IMPORTANT: Edit .env and configure required values before continuing\n\n";
            
            echo "Required configuration:\n";
            echo "  - WP_ROOT (WordPress root directory)\n";
            echo "  - SPOTIFY_CLIENT_ID\n";
            echo "  - SPOTIFY_CLIENT_SECRET\n";
            echo "  - GMAIL_OAUTH_CLIENT_ID\n";
            echo "  - GMAIL_OAUTH_CLIENT_SECRET\n";
            echo "  - GMAIL_OAUTH_REFRESH_TOKEN\n";
            echo "  - REPORT_TO_EMAIL\n\n";
            
            exit(1);
        } else {
            throw new Exception(".env.example not found at: {$envExample}");
        }
    }
    
    echo "  ✓ .env file found\n\n";
    
    // Step 4: Load and validate configuration
    echo "[4/8] Validating configuration...\n";
    $config = new Config($baseDir);
    $errors = $config->validate();
    
    if (!empty($errors)) {
        echo "  Configuration errors:\n";
        foreach ($errors as $error) {
            echo "    ✗ {$error}\n";
        }
        echo "\n  Please fix these errors in .env and run install again.\n\n";
        exit(2);
    }
    
    echo "  ✓ Configuration valid\n\n";
    
    // Step 5: Verify WordPress installation
    echo "[5/8] Checking WordPress installation...\n";
    $wpRoot = $config->get('WP_ROOT');
    
    if (!is_dir($wpRoot)) {
        throw new Exception("WordPress root directory not found: {$wpRoot}");
    }
    
    $wpConfigFile = $wpRoot . '/wp-config.php';
    if (!file_exists($wpConfigFile)) {
        throw new Exception("wp-config.php not found: {$wpConfigFile}");
    }
    
    echo "  ✓ WordPress root: {$wpRoot}\n";
    
    $wpConfig = new ConfigReader($wpRoot);
    $uploadsPath = $wpConfig->getUploadsPath();
    
    if (!is_dir($uploadsPath)) {
        throw new Exception("WordPress uploads directory not found: {$uploadsPath}");
    }
    
    echo "  ✓ Uploads directory: {$uploadsPath}\n\n";
    
    // Step 6: Connect to database
    echo "[6/8] Connecting to database...\n";
    $dbHost = $config->get('DB_HOST') ?: $wpConfig->getDbHost();
    $dbName = $config->get('DB_NAME', 'top100');
    $dbUser = $config->get('DB_USER') ?: $wpConfig->getDbUser();
    $dbPass = $config->get('DB_PASS') ?: $wpConfig->getDbPassword();
    
    echo "  Database: {$dbName}@{$dbHost}\n";
    echo "  User: {$dbUser}\n";
    
    $db = Connection::getInstance($dbHost, $dbName, $dbUser, $dbPass);
    echo "  ✓ Database connection successful\n\n";
    
    // Step 7: Run migrations
    echo "[7/8] Running database migrations...\n";
    $logFile = $baseDir . '/logs/install.log';
    $logger = new Logger($logFile, 'INFO', true);
    
    $migrator = new Migrator($db, $logger, $baseDir . '/config/schema/migrations');
    $appliedMigrations = $migrator->migrate();
    
    if (empty($appliedMigrations)) {
        echo "  ✓ Database schema up to date\n";
    } else {
        echo "  ✓ Applied migrations:\n";
        foreach ($appliedMigrations as $migration) {
            echo "    - {$migration}\n";
        }
    }
    
    $currentVersion = $migrator->getCurrentVersion();
    echo "  Schema version: {$currentVersion}\n\n";
    
    // Step 8: Set permissions
    echo "[8/8] Setting permissions...\n";
    
    $directories = ['logs', 'reports'];
    foreach ($directories as $dir) {
        $path = $baseDir . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0750, true);
        }
        chmod($path, 0750);
        echo "  ✓ {$dir}/\n";
    }
    
    chmod($envFile, 0640);
    echo "  ✓ .env\n\n";
    
    // Installation complete
    echo "==================================================\n";
    echo "  ✓ Installation Complete!                        \n";
    echo "==================================================\n\n";
    
    echo "Next steps:\n\n";
    
    echo "1. Configure Gmail OAuth (if not already done):\n";
    echo "   php scripts/gmail_oauth_setup.php\n\n";
    
    echo "2. Test Spotify connectivity:\n";
    echo "   php scripts/test_spotify.php\n\n";
    
    echo "3. Run a test with limited files:\n";
    echo "   php Top100Rankings.php --run-now --dry-run --limit=10 --verbose\n\n";
    
    echo "4. Schedule cron job:\n";
    echo "   Add to crontab:\n";
    echo "   0 4 * * 1 /usr/bin/php {$baseDir}/Top100Rankings.php --cron >> {$baseDir}/logs/cron.log 2>&1\n\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\n✗ Installation failed: " . $e->getMessage() . "\n\n";
    exit(1);
}
