<?php

namespace MastersRadio\Top100\WordPress;

use MastersRadio\Top100\Logger;

/**
 * Recursively scans WordPress uploads directory for MP3 files
 */
class FileScanner
{
    private Logger $logger;
    private string $uploadsPath;
    private array $excludeDirs = ['backup', 'cache', 'tmp'];

    public function __construct(Logger $logger, string $uploadsPath)
    {
        $this->logger = $logger;
        $this->uploadsPath = rtrim($uploadsPath, '/');

        if (!is_dir($this->uploadsPath)) {
            throw new \Exception("Uploads directory does not exist: {$this->uploadsPath}");
        }
    }

    /**
     * Scan for all MP3 files
     * 
     * @return array Array of file information [path, url, size, mtime, filename]
     */
    public function scan(): array
    {
        $this->logger->info("Scanning uploads directory: {$this->uploadsPath}");
        
        $files = [];
        $this->scanDirectory($this->uploadsPath, $files);

        $this->logger->notice("Found " . count($files) . " MP3 files");

        return $files;
    }

    private function scanDirectory(string $dir, array &$files): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Skip directories and non-MP3 files
            if ($file->isDir()) {
                continue;
            }

            // Check if file is an MP3 (case-insensitive)
            if (strtolower($file->getExtension()) !== 'mp3') {
                continue;
            }

            // Check if in excluded directory
            $path = $file->getPathname();
            if ($this->isExcluded($path)) {
                $this->logger->debug("Skipping excluded file: {$path}");
                continue;
            }

            // Validate file
            if ($file->getSize() === 0) {
                $this->logger->warning("Skipping zero-byte file: {$path}");
                continue;
            }

            if (!$file->isReadable()) {
                $this->logger->warning("Skipping unreadable file: {$path}");
                continue;
            }

            // Calculate checksum (first 64KB + last 64KB for speed)
            $checksum = $this->calculateChecksum($path);

            // Generate URL (relative to uploads directory)
            $relativePath = str_replace($this->uploadsPath . '/', '', $path);
            $url = '/wp-content/uploads/' . $relativePath;

            $files[] = [
                'file_path' => $path,
                'source_url' => $url,
                'filename' => $file->getFilename(),
                'file_size' => $file->getSize(),
                'file_mtime' => date('Y-m-d H:i:s', $file->getMTime()),
                'checksum' => $checksum,
            ];

            $this->logger->debug("Found MP3: {$path}");
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludeDirs as $excludeDir) {
            if (strpos($path, '/' . $excludeDir . '/') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate fast checksum using first and last 64KB of file
     */
    private function calculateChecksum(string $path): string
    {
        $chunkSize = 64 * 1024; // 64KB
        $fileSize = filesize($path);

        if ($fileSize <= $chunkSize * 2) {
            // File is small enough to hash entirely
            return hash_file('sha256', $path);
        }

        // Read first 64KB
        $handle = fopen($path, 'rb');
        $firstChunk = fread($handle, $chunkSize);

        // Seek to last 64KB
        fseek($handle, -$chunkSize, SEEK_END);
        $lastChunk = fread($handle, $chunkSize);

        fclose($handle);

        // Hash combined chunks
        return hash('sha256', $firstChunk . $lastChunk);
    }
}
