<?php

namespace MastersRadio\Top100\Email;

use GuzzleHttp\Client as HttpClient;

/**
 * Manages Gmail OAuth2 token refresh
 */
class OAuthManager
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private HttpClient $httpClient;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->httpClient = new HttpClient([
            'base_uri' => 'https://oauth2.googleapis.com',
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

        // Refresh token
        $this->refreshAccessToken();

        return $this->accessToken;
    }

    private function refreshAccessToken(): void
    {
        $response = $this->httpClient->post('/token', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['access_token'])) {
            throw new \Exception("Failed to refresh Gmail OAuth token");
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
