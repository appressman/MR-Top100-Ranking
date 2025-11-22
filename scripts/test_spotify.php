#!/usr/bin/env php
<?php

/**
 * Test Spotify API connectivity and authentication
 */

require __DIR__ . '/../vendor/autoload.php';

use MastersRadio\Top100\Config;
use MastersRadio\Top100\Logger;
use MastersRadio\Top100\Spotify\Authenticator;
use MastersRadio\Top100\Spotify\RateLimiter;
use MastersRadio\Top100\Spotify\Client as SpotifyClient;

echo "\n";
echo "Testing Spotify API Connectivity\n";
echo "=================================\n\n";

try {
    $baseDir = dirname(__DIR__);
    
    // Load configuration
    $config = new Config($baseDir);
    $logFile = $baseDir . '/logs/test.log';
    $logger = new Logger($logFile, 'DEBUG', true);
    
    echo "1. Checking configuration...\n";
    $clientId = $config->get('SPOTIFY_CLIENT_ID');
    $clientSecret = $config->get('SPOTIFY_CLIENT_SECRET');
    
    if (empty($clientId) || empty($clientSecret)) {
        throw new Exception("Spotify credentials not configured in .env");
    }
    
    echo "   ✓ Credentials found\n\n";
    
    // Test authentication
    echo "2. Testing authentication...\n";
    $auth = new Authenticator($clientId, $clientSecret);
    $accessToken = $auth->getAccessToken();
    
    echo "   ✓ Access token obtained: " . substr($accessToken, 0, 20) . "...\n\n";
    
    // Test API client
    echo "3. Testing API search...\n";
    $rateLimiter = new RateLimiter(10, 5, 500);
    $client = new SpotifyClient($auth, $rateLimiter, $logger);
    
    // Search for a well-known track
    $results = $client->searchByArtistTitle("The Beatles", "Hey Jude", 5);
    
    if (empty($results)) {
        throw new Exception("No search results returned");
    }
    
    echo "   ✓ Search successful, found " . count($results) . " results\n\n";
    
    // Display first result
    echo "4. First result details:\n";
    $track = $results[0];
    echo "   Artist: " . $track['artists'][0]['name'] . "\n";
    echo "   Title: " . $track['name'] . "\n";
    echo "   Album: " . ($track['album']['name'] ?? 'N/A') . "\n";
    echo "   Popularity: " . $track['popularity'] . "\n";
    echo "   Spotify ID: " . $track['id'] . "\n\n";
    
    // Test ISRC search
    echo "5. Testing ISRC search...\n";
    $isrcResults = $client->searchByISRC("GBUM71029604"); // Queen - Bohemian Rhapsody
    
    if (!empty($isrcResults)) {
        $track = $isrcResults[0];
        echo "   ✓ ISRC search successful\n";
        echo "   Found: " . $track['artists'][0]['name'] . " - " . $track['name'] . "\n\n";
    } else {
        echo "   ! No results for test ISRC (this is not critical)\n\n";
    }
    
    echo "=================================\n";
    echo "✓ All Spotify tests passed!\n\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n\n";
    exit(1);
}
