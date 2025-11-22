<?php

namespace MastersRadio\Top100\Spotify;

use MastersRadio\Top100\Metadata\Normalizer;
use MastersRadio\Top100\Logger;

/**
 * Matches MP3 files to Spotify tracks with confidence scoring
 */
class Matcher
{
    private Client $client;
    private Logger $logger;
    private float $confidenceThreshold;

    public function __construct(Client $client, Logger $logger, float $confidenceThreshold = 0.85)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->confidenceThreshold = $confidenceThreshold;
    }

    /**
     * Find best Spotify match for a track
     * 
     * @param array $metadata Metadata from ID3Reader
     * @return array|null Match result or null if no match
     */
    public function findMatch(array $metadata): ?array
    {
        // Try ISRC match first if available
        if (!empty($metadata['isrc'])) {
            $isrcMatch = $this->matchByISRC($metadata['isrc']);
            
            if ($isrcMatch !== null) {
                return $isrcMatch;
            }
        }

        // Fallback to artist + title search
        return $this->matchByArtistTitle(
            $metadata['artist'],
            $metadata['title'],
            $metadata['duration'] ?? null
        );
    }

    /**
     * Match by ISRC (exact match, no scoring needed)
     */
    private function matchByISRC(string $isrc): ?array
    {
        $results = $this->client->searchByISRC($isrc);

        if (empty($results)) {
            $this->logger->debug("No ISRC match found for: {$isrc}");
            return null;
        }

        if (count($results) > 1) {
            $this->logger->warning("Multiple ISRC matches found for: {$isrc}");
        }

        $track = $results[0];

        $this->logger->info("ISRC exact match: {$track['artists'][0]['name']} - {$track['name']}");

        return [
            'spotify_id' => $track['id'],
            'artist' => $track['artists'][0]['name'],
            'title' => $track['name'],
            'album_name' => $track['album']['name'] ?? null,
            'spotify_url' => $track['external_urls']['spotify'] ?? null,
            'preview_url' => $track['preview_url'] ?? null,
            'popularity' => $track['popularity'],
            'release_date' => $track['album']['release_date'] ?? null,
            'duration_ms' => $track['duration_ms'],
            'match_confidence' => 1.0,
            'matched_via' => 'isrc',
            'status' => 'ok',
            'candidates_found' => count($results),
        ];
    }

    /**
     * Match by artist and title with confidence scoring
     */
    private function matchByArtistTitle(string $artist, string $title, ?float $duration = null): ?array
    {
        $results = $this->client->searchByArtistTitle($artist, $title, 10);

        if (empty($results)) {
            $this->logger->notice("No match found for: {$artist} - {$title}");
            
            return [
                'spotify_id' => null,
                'match_confidence' => 0.0,
                'matched_via' => 'artist_title',
                'status' => 'no_match',
                'candidates_found' => 0,
            ];
        }

        // Score all candidates
        $scoredResults = [];
        foreach ($results as $track) {
            $score = Normalizer::matchScore(
                $artist,
                $track['artists'][0]['name'],
                $title,
                $track['name'],
                $duration,
                ($track['duration_ms'] ?? 0) / 1000 // Convert ms to seconds
            );

            $scoredResults[] = [
                'track' => $track,
                'score' => $score,
            ];

            $this->logger->debug("Candidate: {$track['artists'][0]['name']} - {$track['name']} (score: " . round($score, 3) . ")");
        }

        // Sort by score (descending)
        usort($scoredResults, fn($a, $b) => $b['score'] <=> $a['score']);

        $bestMatch = $scoredResults[0];
        $confidence = $bestMatch['score'];

        // Check if confidence meets threshold
        if ($confidence < $this->confidenceThreshold) {
            $this->logger->notice("Low confidence match ({$confidence}) for: {$artist} - {$title}");
            
            return [
                'spotify_id' => null,
                'match_confidence' => $confidence,
                'matched_via' => 'artist_title',
                'status' => 'no_match',
                'candidates_found' => count($results),
                'best_candidate' => $bestMatch['track']['artists'][0]['name'] . ' - ' . $bestMatch['track']['name'],
            ];
        }

        $track = $bestMatch['track'];

        $this->logger->info("Auto-picked ({$confidence}): {$track['artists'][0]['name']} - {$track['name']}");

        return [
            'spotify_id' => $track['id'],
            'artist' => $track['artists'][0]['name'],
            'title' => $track['name'],
            'album_name' => $track['album']['name'] ?? null,
            'spotify_url' => $track['external_urls']['spotify'] ?? null,
            'preview_url' => $track['preview_url'] ?? null,
            'popularity' => $track['popularity'],
            'release_date' => $track['album']['release_date'] ?? null,
            'duration_ms' => $track['duration_ms'],
            'match_confidence' => $confidence,
            'matched_via' => 'artist_title',
            'status' => 'auto_picked',
            'candidates_found' => count($results),
        ];
    }
}
