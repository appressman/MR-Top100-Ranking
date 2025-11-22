<?php

namespace MastersRadio\Top100\Spotify;

use GuzzleHttp\Client as HttpClient;

/**
 * Handles Spotify API authentication using Client Credentials flow
 */
class Authenticator
{
    private string $clientId;
    private string $clientSecret;
    private HttpClient $httpClient;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->httpClient = new HttpClient([
            'base_uri' => 'https://accounts.spotify.com',
            'timeout' => 30,
        ]);
    }

    /**
     * Get valid access token (refresh if needed)
     */
    public function getAccessToken(): string
    {
        // Return cached token if still valid (with 5-minute buffer)
        if ($this->accessToken && time() < ($this->tokenExpiresAt - 300)) {
            return $this->accessToken;
        }

        // Request new token
        $this->requestToken();

        return $this->accessToken;
    }

    private function requestToken(): void
    {
        $response = $this->httpClient->post('/api/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['access_token'])) {
            throw new \Exception("Failed to obtain Spotify access token");
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600);
    }

    /**
     * Check if we have a valid token
     */
    public function hasValidToken(): bool
    {
        return $this->accessToken !== null && time() < $this->tokenExpiresAt;
    }
}
