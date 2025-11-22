<?php

namespace MastersRadio\Top100;

/**
 * Simple file-based logger with support for multiple log levels
 */
class Logger
{
    private const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'NOTICE' => 2,
        'WARNING' => 3,
        'ERROR' => 4,
        'CRITICAL' => 5,
    ];

    private string $logFile;
    private string $minLevel;
    private bool $verbose;

    public function __construct(string $logFile, string $minLevel = 'INFO', bool $verbose = false)
    {
        $this->logFile = $logFile;
        $this->minLevel = strtoupper($minLevel);
        $this->verbose = $verbose;

        // Ensure log directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // Check if this level should be logged
        if (!$this->shouldLog($level)) {
            return;
        }

        // Format log entry
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

        // Write to file
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Also output to console if verbose
        if ($this->verbose) {
            echo $logEntry;
        }
    }

    private function shouldLog(string $level): bool
    {
        $levelValue = self::LEVELS[$level] ?? 0;
        $minLevelValue = self::LEVELS[$this->minLevel] ?? 0;
        return $levelValue >= $minLevelValue;
    }

    /**
     * Get the last N lines from the log file
     */
    public function tail(int $lines = 200): string
    {
        if (!file_exists($this->logFile)) {
            return '';
        }

        $file = new \SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->current();
            if ($line !== false) {
                $result[] = $line;
            }
            $file->next();
        }

        return implode('', $result);
    }
}
