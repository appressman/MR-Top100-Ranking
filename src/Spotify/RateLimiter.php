<?php

namespace MastersRadio\Top100\Spotify;

/**
 * Rate limiter with exponential backoff and jitter
 */
class RateLimiter
{
    private int $requestsPerSecond;
    private float $lastRequestTime = 0;
    private int $maxRetries;
    private int $baseDelayMs;

    public function __construct(int $requestsPerSecond = 10, int $maxRetries = 5, int $baseDelayMs = 500)
    {
        $this->requestsPerSecond = $requestsPerSecond;
        $this->maxRetries = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
    }

    /**
     * Throttle requests to respect rate limit
     */
    public function throttle(): void
    {
        $minInterval = 1.0 / $this->requestsPerSecond;
        $elapsed = microtime(true) - $this->lastRequestTime;

        if ($elapsed < $minInterval) {
            $sleepTime = ($minInterval - $elapsed) * 1000000; // Convert to microseconds
            usleep((int) $sleepTime);
        }

        $this->lastRequestTime = microtime(true);
    }

    /**
     * Calculate delay for retry attempt with exponential backoff and jitter
     * 
     * @param int $attempt Attempt number (1-based)
     * @return int Delay in milliseconds
     */
    public function getRetryDelay(int $attempt): int
    {
        if ($attempt > $this->maxRetries) {
            throw new \Exception("Maximum retry attempts exceeded");
        }

        // Calculate exponential backoff: baseDelay * 2^(attempt-1)
        $delay = $this->baseDelayMs * pow(2, $attempt - 1);

        // Add jitter (±25%)
        $jitter = $delay * 0.25;
        $randomJitter = mt_rand((int)(-$jitter), (int)$jitter);

        return (int)($delay + $randomJitter);
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
}
