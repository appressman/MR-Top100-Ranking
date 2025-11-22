<?php

namespace MastersRadio\Top100\Metadata;

/**
 * Aggressive string normalization for Spotify matching
 */
class Normalizer
{
    /**
     * Normalize artist name for matching
     */
    public static function normalizeArtist(string $artist): string
    {
        $normalized = $artist;

        // Remove featuring credits
        $patterns = [
            '/\s+feat\.?\s+.*/i',
            '/\s+ft\.?\s+.*/i',
            '/\s+featuring\s+.*/i',
            '/\s+with\s+.*/i',
            '/\s+&\s+.*/i',
            '/\s+and\s+.*/i',
            '/\s+vs\.?\s+.*/i',
        ];

        foreach ($patterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
        }

        // Remove parenthetical and bracketed content
        $normalized = preg_replace('/\s*[\(\[].*?[\)\]]/', '', $normalized);

        // Remove common prefixes
        $normalized = preg_replace('/^(the|a|an)\s+/i', '', $normalized);

        // Remove punctuation except hyphens
        $normalized = preg_replace('/[^\w\s\-]/u', '', $normalized);

        // Collapse multiple spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim and lowercase
        $normalized = trim(mb_strtolower($normalized));

        return $normalized;
    }

    /**
     * Normalize title for matching
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = $title;

        // Remove edition markers
        $patterns = [
            '/\s*-?\s*(remastered|remaster)\s*\d*/i',
            '/\s*[\(\[](remastered|remaster).*?[\)\]]/i',
            '/\s*[\(\[](live|acoustic|radio edit|single version|album version).*?[\)\]]/i',
            '/\s*[\(\[](deluxe|bonus track|demo).*?[\)\]]/i',
            '/\s*-\s*(live|acoustic|radio edit|single version)/i',
        ];

        foreach ($patterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
        }

        // Remove featuring credits (same as artist)
        $featuringPatterns = [
            '/\s+feat\.?\s+.*/i',
            '/\s+ft\.?\s+.*/i',
            '/\s+featuring\s+.*/i',
        ];

        foreach ($featuringPatterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
        }

        // Remove parenthetical and bracketed content
        $normalized = preg_replace('/\s*[\(\[].*?[\)\]]/', '', $normalized);

        // Remove punctuation except hyphens
        $normalized = preg_replace('/[^\w\s\-]/u', '', $normalized);

        // Collapse multiple spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim and lowercase
        $normalized = trim(mb_strtolower($normalized));

        return $normalized;
    }

    /**
     * Calculate Levenshtein distance ratio between two strings
     * Returns value between 0.0 (no match) and 1.0 (perfect match)
     */
    public static function similarity(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }

        if ($str1 === '' || $str2 === '') {
            return 0.0;
        }

        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        $distance = levenshtein(
            mb_strtolower($str1),
            mb_strtolower($str2)
        );

        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Calculate duration match score
     * 
     * @param float $duration1 Duration in seconds
     * @param float $duration2 Duration in seconds
     * @param int $strictTolerance Seconds tolerance for perfect match (default 5)
     * @param int $looseTolerance Seconds tolerance for partial match (default 10)
     * @return float Score between 0.0 and 1.0
     */
    public static function durationMatch(
        float $duration1,
        float $duration2,
        int $strictTolerance = 5,
        int $looseTolerance = 10
    ): float {
        $diff = abs($duration1 - $duration2);

        if ($diff <= $strictTolerance) {
            return 1.0; // Perfect match
        }

        if ($diff <= $looseTolerance) {
            return 0.5; // Partial match
        }

        return 0.0; // No match
    }

    /**
     * Calculate overall match confidence score
     * 
     * @param string $artist1 First artist name
     * @param string $artist2 Second artist name
     * @param string $title1 First title
     * @param string $title2 Second title
     * @param float|null $duration1 First duration (seconds)
     * @param float|null $duration2 Second duration (seconds)
     * @return float Confidence score between 0.0 and 1.0
     */
    public static function matchScore(
        string $artist1,
        string $artist2,
        string $title1,
        string $title2,
        ?float $duration1 = null,
        ?float $duration2 = null
    ): float {
        // Normalize strings
        $normArtist1 = self::normalizeArtist($artist1);
        $normArtist2 = self::normalizeArtist($artist2);
        $normTitle1 = self::normalizeTitle($title1);
        $normTitle2 = self::normalizeTitle($title2);

        // Calculate similarities
        $titleSimilarity = self::similarity($normTitle1, $normTitle2);
        $artistSimilarity = self::similarity($normArtist1, $normArtist2);

        // Calculate duration match if both provided
        $durationMatch = 0.0;
        if ($duration1 !== null && $duration2 !== null) {
            $durationMatch = self::durationMatch($duration1, $duration2);
        }

        // Weighted score: title 50%, artist 40%, duration 10%
        $score = ($titleSimilarity * 0.5) + ($artistSimilarity * 0.4);
        
        if ($duration1 !== null && $duration2 !== null) {
            $score += ($durationMatch * 0.1);
        } else {
            // If no duration available, redistribute weight
            $score = ($titleSimilarity * 0.55) + ($artistSimilarity * 0.45);
        }

        return $score;
    }
}
