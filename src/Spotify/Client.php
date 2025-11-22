<?php

namespace MastersRadio\Top100\Spotify;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use MastersRadio\Top100\Logger;

/**
 * Spotify API client with rate limiting and retry logic
 */
class Client
{
    private Authenticator $auth;
    private RateLimiter $rateLimiter;
    private HttpClient $httpClient;
    private Logger $logger;

    public function __construct(Authenticator $auth, RateLimiter $rateLimiter, Logger $logger)
    {
        $this->auth = $auth;
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
        $this->httpClient = new HttpClient([
            'base_uri' => 'https://api.spotify.com',
            'timeout' => 30,
        ]);
    }

    /**
     * Search for tracks by ISRC
     */
    public function searchByISRC(string $isrc): array
    {
        $query = "isrc:{$isrc}";
        $this->logger->debug("Spotify search by ISRC: {$query}");
        
        return $this->search($query, 'track', 1);
    }

    /**
     * Search for tracks by artist and title
     */
    public function searchByArtistTitle(string $artist, string $title, int $limit = 10): array
    {
        $query = "track:" . urlencode($title) . " artist:" . urlencode($artist);
        $this->logger->debug("Spotify search by artist/title: {$query}");
        
        return $this->search($query, 'track', $limit);
    }

    /**
     * Get track details by Spotify ID
     */
    public function getTrack(string $trackId): array
    {
        $this->logger->debug("Fetching Spotify track: {$trackId}");
        
        return $this->request('GET', "/v1/tracks/{$trackId}");
    }

    /**
     * Perform search query
     */
    private function search(string $query, string $type, int $limit): array
    {
        $params = [
            'q' => $query,
            'type' => $type,
            'limit' => $limit,
        ];

        $response = $this->request('GET', '/v1/search', $params);

        return $response['tracks']['items'] ?? [];
    }

    /**
     * Make authenticated API request with retry logic
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->rateLimiter->getMaxRetries()) {
            $attempt++;

            try {
                // Apply rate limiting
                $this->rateLimiter->throttle();

                // Get valid access token
                $accessToken = $this->auth->getAccessToken();

                // Make request
                $options = [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                    ],
                ];

                if (!empty($params)) {
                    if ($method === 'GET') {
                        $options['query'] = $params;
                    } else {
                        $options['json'] = $params;
                    }
                }

                $response = $this->httpClient->request($method, $endpoint, $options);
                $data = json_decode($response->getBody()->getContents(), true);

                return $data;

            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

                // Check if we should retry
                if (!$this->shouldRetry($statusCode)) {
                    throw $e;
                }

                if ($attempt < $this->rateLimiter->getMaxRetries()) {
                    $delay = $this->rateLimiter->getRetryDelay($attempt);
                    $this->logger->warning("Spotify API error (attempt {$attempt}), retrying in {$delay}ms: " . $e->getMessage());
                    usleep($delay * 1000); // Convert ms to microseconds
                } else {
                    $this->logger->error("Spotify API max retries exceeded: " . $e->getMessage());
                }
            }
        }

        throw $lastException ?? new \Exception("Failed to make Spotify API request");
    }

    /**
     * Determine if request should be retried based on status code
     */
    private function shouldRetry(int $statusCode): bool
    {
        // Retry on rate limit, server errors, and network issues
        $retryableCodes = [429, 500, 502, 503, 504, 0];
        
        return in_array($statusCode, $retryableCodes, true);
    }
}
