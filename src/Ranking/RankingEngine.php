<?php

namespace MastersRadio\Top100\Ranking;

use MastersRadio\Top100\Logger;

/**
 * Ranks tracks by popularity with multi-level tie-breaking
 */
class RankingEngine
{
    private Logger $logger;
    private int $topN;

    public function __construct(Logger $logger, int $topN = 100)
    {
        $this->logger = $logger;
        $this->topN = $topN;
    }

    /**
     * Rank tracks and return top N
     * 
     * @param array $tracks Array of tracks with popularity, release_date, file_mtime, artist, title
     * @return array Top N ranked tracks
     */
    public function rank(array $tracks): array
    {
        $this->logger->info("Ranking " . count($tracks) . " tracks");

        // Sort tracks using multi-level criteria
        usort($tracks, [$this, 'compareTracksl']);

        // Take top N
        $topTracks = array_slice($tracks, 0, $this->topN);

        // Assign rank numbers
        $ranked = [];
        $currentRank = 1;
        
        foreach ($topTracks as $index => $track) {
            $track['rank'] = $currentRank;
            $ranked[] = $track;
            
            // Check if next track has same popularity for proper rank numbering
            if (isset($topTracks[$index + 1])) {
                $nextTrack = $topTracks[$index + 1];
                
                // If next track has different popularity, increment rank
                if ($track['popularity'] !== $nextTrack['popularity']) {
                    $currentRank = $index + 2; // Next position
                }
            }
        }

        $this->logger->info("Generated Top {$this->topN} rankings");

        return $ranked;
    }

    /**
     * Compare two tracks for sorting (multi-level tie-breaking)
     * Returns negative if $a ranks higher, positive if $b ranks higher
     */
    private function compareTracks(array $a, array $b): int
    {
        // Primary: Popularity (descending - higher is better)
        if ($a['popularity'] !== $b['popularity']) {
            return $b['popularity'] <=> $a['popularity'];
        }

        // Tie-breaker 1: Release date (descending - newer is better)
        $dateA = $a['release_date'] ?? '1900-01-01';
        $dateB = $b['release_date'] ?? '1900-01-01';
        
        if ($dateA !== $dateB) {
            return $dateB <=> $dateA;
        }

        // Tie-breaker 2: File modification time (descending - newer is better)
        $mtimeA = $a['file_mtime'] ?? '1900-01-01 00:00:00';
        $mtimeB = $b['file_mtime'] ?? '1900-01-01 00:00:00';
        
        if ($mtimeA !== $mtimeB) {
            return $mtimeB <=> $mtimeA;
        }

        // Tie-breaker 3: Artist name (ascending - A-Z)
        $artistCompare = strcasecmp($a['artist'] ?? '', $b['artist'] ?? '');
        
        if ($artistCompare !== 0) {
            return $artistCompare;
        }

        // Tie-breaker 4: Title (ascending - A-Z)
        return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
    }
}
