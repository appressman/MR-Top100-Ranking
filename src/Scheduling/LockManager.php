<?php

namespace MastersRadio\Top100\Scheduling;

use MastersRadio\Top100\Logger;

/**
 * Manages run locking to prevent concurrent executions
 */
class LockManager
{
    private Logger $logger;
    private string $lockFile;
    private int $maxAgeHours;

    public function __construct(Logger $logger, string $lockFile, int $maxAgeHours = 12)
    {
        $this->logger = $logger;
        $this->lockFile = $lockFile;
        $this->maxAgeHours = $maxAgeHours;
    }

    /**
     * Attempt to acquire lock
     * Returns true if lock acquired, false if already locked
     */
    public function acquire(): bool
    {
        // Check if lock file exists
        if (!file_exists($this->lockFile)) {
            return $this->createLock();
        }

        // Read existing lock
        $lockContents = file_get_contents($this->lockFile);
        $lock = json_decode($lockContents, true);

        if (!$lock || !isset($lock['pid'], $lock['started_at'])) {
            // Invalid lock file, recreate
            $this->logger->warning("Invalid lock file found, recreating");
            unlink($this->lockFile);
            return $this->createLock();
        }

        $lockAge = time() - strtotime($lock['started_at']);

        // Check if process is still running
        if ($this->isProcessRunning($lock['pid'])) {
            // Process exists
            if ($lockAge < ($this->maxAgeHours * 3600)) {
                // Fresh lock, respect it
                $this->logger->warning("Run already in progress (PID {$lock['pid']}, started: {$lock['started_at']})");
                return false;
            } else {
                // Stale lock but process still running (should be killed manually)
                $this->logger->error("Stale lock detected but process still running (PID {$lock['pid']})");
                return false;
            }
        } else {
            // Process doesn't exist
            if ($lockAge >= ($this->maxAgeHours * 3600)) {
                // Stale lock, break it
                $ageHours = round($lockAge / 3600, 1);
                $this->logger->warning("Breaking stale lock (age: {$ageHours} hours, PID {$lock['pid']})");
                unlink($this->lockFile);
                return $this->createLock();
            } else {
                // Recently dead process, wait for cleanup
                $this->logger->warning("Previous run terminated abnormally (PID {$lock['pid']})");
                unlink($this->lockFile);
                return $this->createLock();
            }
        }
    }

    /**
     * Release lock
     */
    public function release(): bool
    {
        if (file_exists($this->lockFile)) {
            $result = unlink($this->lockFile);
            if ($result) {
                $this->logger->info("Lock released");
            } else {
                $this->logger->error("Failed to release lock");
            }
            return $result;
        }
        return true;
    }

    /**
     * Create new lock file
     */
    private function createLock(): bool
    {
        $lock = [
            'pid' => getmypid(),
            'started_at' => date('c'),
            'hostname' => gethostname(),
            'command' => implode(' ', $_SERVER['argv'] ?? []),
        ];

        $result = file_put_contents(
            $this->lockFile,
            json_encode($lock, JSON_PRETTY_PRINT)
        );

        if ($result !== false) {
            $this->logger->info("Lock acquired (PID: " . getmypid() . ")");
            return true;
        } else {
            $this->logger->error("Failed to create lock file: {$this->lockFile}");
            return false;
        }
    }

    /**
     * Check if a process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        // Use posix_kill with signal 0 to check if process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc filesystem (Linux)
        return file_exists("/proc/{$pid}");
    }

    /**
     * Get current lock info if exists
     */
    public function getLockInfo(): ?array
    {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        $lockContents = file_get_contents($this->lockFile);
        return json_decode($lockContents, true);
    }
}
