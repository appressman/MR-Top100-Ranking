<?php

namespace MastersRadio\Top100\Database;

use MastersRadio\Top100\Logger;

/**
 * Database schema migrator
 */
class Migrator
{
    private Connection $db;
    private Logger $logger;
    private string $migrationsPath;

    public function __construct(Connection $db, Logger $logger, string $migrationsPath)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->migrationsPath = $migrationsPath;
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): array
    {
        $applied = [];
        $migrations = $this->getPendingMigrations();

        if (empty($migrations)) {
            $this->logger->info("No pending migrations to apply");
            return $applied;
        }

        foreach ($migrations as $migration) {
            $this->logger->info("Applying migration: {$migration['file']}");
            
            try {
                $this->applyMigration($migration['file']);
                $this->recordMigration($migration['version'], $migration['description']);
                $applied[] = $migration['version'];
                
                $this->logger->info("Successfully applied migration: {$migration['version']}");
            } catch (\Exception $e) {
                $this->logger->error("Failed to apply migration {$migration['version']}: " . $e->getMessage());
                throw $e;
            }
        }

        return $applied;
    }

    /**
     * Get all migration files that haven't been applied yet
     */
    private function getPendingMigrations(): array
    {
        $appliedVersions = $this->getAppliedVersions();
        $allMigrations = $this->getAllMigrations();

        return array_filter($allMigrations, function($migration) use ($appliedVersions) {
            return !in_array($migration['version'], $appliedVersions);
        });
    }

    /**
     * Get list of applied migration versions
     */
    private function getAppliedVersions(): array
    {
        try {
            $result = $this->db->fetchAll("SELECT version FROM migrations ORDER BY version");
            return array_column($result, 'version');
        } catch (\Exception $e) {
            // migrations table doesn't exist yet
            return [];
        }
    }

    /**
     * Get all available migration files
     */
    private function getAllMigrations(): array
    {
        $migrations = [];
        $files = glob($this->migrationsPath . '/*.sql');

        foreach ($files as $file) {
            $filename = basename($file);
            
            // Extract version from filename (e.g., "001_initial_schema.sql" -> "001")
            if (preg_match('/^(\d+)_(.+)\.sql$/', $filename, $matches)) {
                $migrations[] = [
                    'version' => $matches[1],
                    'description' => str_replace('_', ' ', $matches[2]),
                    'file' => $file,
                ];
            }
        }

        // Sort by version
        usort($migrations, fn($a, $b) => strcmp($a['version'], $b['version']));

        return $migrations;
    }

    /**
     * Apply a single migration file
     */
    private function applyMigration(string $file): void
    {
        $sql = file_get_contents($file);
        
        if ($sql === false) {
            throw new \Exception("Failed to read migration file: {$file}");
        }

        // Execute the SQL (may contain multiple statements)
        $this->db->getPdo()->exec($sql);
    }

    /**
     * Record a migration as applied
     */
    private function recordMigration(string $version, string $description): void
    {
        $this->db->execute(
            "INSERT INTO migrations (version, description) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE version=version",
            [$version, $description]
        );
    }

    /**
     * Get current schema version (latest applied migration)
     */
    public function getCurrentVersion(): ?string
    {
        try {
            $result = $this->db->fetchOne("SELECT version FROM migrations ORDER BY version DESC LIMIT 1");
            return $result['version'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
