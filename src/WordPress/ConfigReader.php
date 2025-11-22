<?php

namespace MastersRadio\Top100\WordPress;

/**
 * Reads WordPress configuration from wp-config.php
 */
class ConfigReader
{
    private string $wpRoot;
    private array $config = [];

    public function __construct(string $wpRoot)
    {
        $this->wpRoot = rtrim($wpRoot, '/');
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $configFile = $this->wpRoot . '/wp-config.php';

        if (!file_exists($configFile)) {
            throw new \Exception("WordPress config file not found: {$configFile}");
        }

        $contents = file_get_contents($configFile);

        // Extract database configuration
        $this->extractDefine($contents, 'DB_NAME');
        $this->extractDefine($contents, 'DB_USER');
        $this->extractDefine($contents, 'DB_PASSWORD');
        $this->extractDefine($contents, 'DB_HOST');
        $this->extractDefine($contents, 'DB_CHARSET');
        $this->extractDefine($contents, 'WP_CONTENT_DIR');
        $this->extractDefine($contents, 'UPLOADS');
    }

    private function extractDefine(string $contents, string $constant): void
    {
        // Match define('CONSTANT', 'value') or define("CONSTANT", "value")
        $pattern = "/define\s*\(\s*['\"]" . preg_quote($constant, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\\s*\)/";
        
        if (preg_match($pattern, $contents, $matches)) {
            $this->config[$constant] = $matches[1];
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->config[$key] ?? $default;
    }

    public function getDbName(): string
    {
        return $this->config['DB_NAME'] ?? 'wordpress';
    }

    public function getDbUser(): string
    {
        return $this->config['DB_USER'] ?? 'wordpress';
    }

    public function getDbPassword(): string
    {
        return $this->config['DB_PASSWORD'] ?? '';
    }

    public function getDbHost(): string
    {
        return $this->config['DB_HOST'] ?? 'localhost';
    }

    public function getDbCharset(): string
    {
        return $this->config['DB_CHARSET'] ?? 'utf8mb4';
    }

    /**
     * Get the uploads directory path
     */
    public function getUploadsPath(): string
    {
        // Check for UPLOADS constant override
        if (isset($this->config['UPLOADS'])) {
            return $this->wpRoot . '/' . trim($this->config['UPLOADS'], '/');
        }

        // Check for WP_CONTENT_DIR override
        if (isset($this->config['WP_CONTENT_DIR'])) {
            return $this->config['WP_CONTENT_DIR'] . '/uploads';
        }

        // Default WordPress uploads path
        return $this->wpRoot . '/wp-content/uploads';
    }

    /**
     * Get all extracted configuration
     */
    public function all(): array
    {
        return $this->config;
    }
}
