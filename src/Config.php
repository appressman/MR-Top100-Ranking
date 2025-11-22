<?php

namespace MastersRadio\Top100;

use Dotenv\Dotenv;

/**
 * Configuration loader and validator
 * Loads .env file and provides validated access to configuration values
 */
class Config
{
    private array $config = [];
    private array $required = [
        'WP_ROOT',
        'SPOTIFY_CLIENT_ID',
        'SPOTIFY_CLIENT_SECRET',
        'REPORT_TO_EMAIL',
    ];

    public function __construct(string $baseDir)
    {
        // Load .env file
        $dotenv = Dotenv::createImmutable($baseDir);
        $dotenv->load();

        // Store all environment variables
        $this->config = $_ENV;

        // Set defaults
        $this->setDefaults();
    }

    private function setDefaults(): void
    {
        $defaults = [
            'APP_ENV' => 'production',
            'TIMEZONE' => 'America/New_York',
            'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATE' => 'utf8mb4_unicode_ci',
            'DB_NAME' => 'top100',
            'SPOTIFY_RATE_LIMIT_PER_SECOND' => '10',
            'SPOTIFY_RETRY_MAX_ATTEMPTS' => '5',
            'SPOTIFY_RETRY_BASE_DELAY_MS' => '500',
            'SMTP_HOST' => 'smtp.gmail.com',
            'SMTP_PORT' => '587',
            'SMTP_ENCRYPTION' => 'tls',
            'MATCH_CONFIDENCE_THRESHOLD' => '0.85',
            'MATCH_DURATION_TOLERANCE_STRICT' => '5',
            'MATCH_DURATION_TOLERANCE_LOOSE' => '10',
            'TOP_N_TRACKS' => '100',
            'RETENTION_MONTHS' => '24',
            'LOCK_MAX_AGE_HOURS' => '12',
            'SCHEDULE_ENABLED' => 'true',
            'SCHEDULE_DAY_OF_WEEK' => '1',
            'SCHEDULE_HOUR' => '4',
            'SCHEDULE_MINUTE' => '0',
            'LOG_LEVEL' => 'INFO',
            'LOG_FORMAT' => 'text',
            'LOG_ERRORS_SEPARATE' => 'false',
            'DEBUG_MODE' => 'false',
            'DRY_RUN' => 'false',
            'PROCESS_LIMIT' => '0',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key]) || $this->config[$key] === '') {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * Validate configuration and return errors
     */
    public function validate(): array
    {
        $errors = [];

        // Check required fields
        foreach ($this->required as $key) {
            if (empty($this->config[$key])) {
                $errors[] = "Missing required configuration: {$key}";
            }
        }

        // Validate WP_ROOT exists
        if (!empty($this->config['WP_ROOT']) && !is_dir($this->config['WP_ROOT'])) {
            $errors[] = "WP_ROOT directory does not exist: {$this->config['WP_ROOT']}";
        }

        // Validate email format
        if (!empty($this->config['REPORT_TO_EMAIL']) && !filter_var($this->config['REPORT_TO_EMAIL'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address: REPORT_TO_EMAIL";
        }

        // Validate numeric values
        $numeric = ['RETENTION_MONTHS', 'LOCK_MAX_AGE_HOURS', 'TOP_N_TRACKS', 
                    'SPOTIFY_RATE_LIMIT_PER_SECOND', 'SPOTIFY_RETRY_MAX_ATTEMPTS'];
        foreach ($numeric as $key) {
            if (!empty($this->config[$key]) && !is_numeric($this->config[$key])) {
                $errors[] = "{$key} must be a number";
            }
        }

        // Validate confidence threshold
        $threshold = $this->get('MATCH_CONFIDENCE_THRESHOLD', 0.85);
        if ($threshold < 0 || $threshold > 1) {
            $errors[] = "MATCH_CONFIDENCE_THRESHOLD must be between 0 and 1";
        }

        return $errors;
    }

    /**
     * Get configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get configuration value as boolean
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get configuration value as integer
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Get configuration value as float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    /**
     * Check if in debug mode
     */
    public function isDebug(): bool
    {
        return $this->getBool('DEBUG_MODE');
    }

    /**
     * Check if in dry run mode
     */
    public function isDryRun(): bool
    {
        return $this->getBool('DRY_RUN');
    }

    /**
     * Get all configuration values
     */
    public function all(): array
    {
        return $this->config;
    }
}
