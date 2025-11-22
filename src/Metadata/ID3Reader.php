<?php

namespace MastersRadio\Top100\Metadata;

use getID3;

/**
 * Wrapper for getID3 library to extract metadata from MP3 files
 */
class ID3Reader
{
    private getID3 $getID3;

    public function __construct()
    {
        $this->getID3 = new getID3();
    }

    /**
     * Extract metadata from MP3 file
     * 
     * @return array Metadata array with artist, title, album, isrc, duration, etc.
     */
    public function read(string $filePath): array
    {
        $info = $this->getID3->analyze($filePath);

        if (isset($info['error'])) {
            throw new \Exception("Failed to read MP3 metadata: " . implode('; ', $info['error']));
        }

        // Extract artist
        $artist = $this->extractArtist($info);
        
        // Extract title
        $title = $this->extractTitle($info);

        // Fallback to filename parsing if metadata missing
        if (empty($artist) || empty($title)) {
            $parsed = $this->parseFilename(basename($filePath));
            $artist = $artist ?: $parsed['artist'];
            $title = $title ?: $parsed['title'];
        }

        return [
            'artist' => $this->cleanup($artist),
            'title' => $this->cleanup($title),
            'album' => $this->cleanup($this->extractField($info, 'album')),
            'isrc' => $this->extractISRC($info),
            'duration' => $this->extractDuration($info),
            'year' => $this->extractField($info, 'year'),
            'genre' => $this->extractField($info, 'genre'),
            'raw' => $info, // Store raw metadata for debugging
        ];
    }

    private function extractArtist(array $info): string
    {
        // Try TPE1 (lead artist) first
        $artist = $this->extractField($info, 'artist');
        
        // Fallback to TPE2 (band/orchestra)
        if (empty($artist)) {
            $artist = $this->extractField($info, 'band');
        }

        return $artist;
    }

    private function extractTitle(array $info): string
    {
        return $this->extractField($info, 'title');
    }

    private function extractISRC(array $info): ?string
    {
        // ISRC is usually in ID3v2 TSRC frame
        if (isset($info['tags']['id3v2']['isrc'][0])) {
            $isrc = $info['tags']['id3v2']['isrc'][0];
            
            // Validate ISRC format (12 characters: CC-XXX-YY-NNNNN)
            $isrc = preg_replace('/[^A-Z0-9]/', '', strtoupper($isrc));
            
            if (strlen($isrc) === 12) {
                return $isrc;
            }
        }

        return null;
    }

    private function extractDuration(array $info): ?float
    {
        if (isset($info['playtime_seconds'])) {
            return (float) $info['playtime_seconds'];
        }

        return null;
    }

    private function extractField(array $info, string $field): string
    {
        // Check ID3v2 first (preferred)
        if (isset($info['tags']['id3v2'][$field][0])) {
            return (string) $info['tags']['id3v2'][$field][0];
        }

        // Fallback to ID3v1
        if (isset($info['tags']['id3v1'][$field][0])) {
            return (string) $info['tags']['id3v1'][$field][0];
        }

        // Fallback to any tag format
        if (isset($info['tags'])) {
            foreach ($info['tags'] as $tagType => $tags) {
                if (isset($tags[$field][0])) {
                    return (string) $tags[$field][0];
                }
            }
        }

        return '';
    }

    /**
     * Parse artist and title from filename
     * Expected format: "Artist - Title.mp3" or "Artist-Title.mp3"
     */
    private function parseFilename(string $filename): array
    {
        // Remove file extension
        $name = preg_replace('/\.mp3$/i', '', $filename);

        // Try to split on " - " or "-"
        if (strpos($name, ' - ') !== false) {
            list($artist, $title) = explode(' - ', $name, 2);
        } elseif (strpos($name, '-') !== false) {
            list($artist, $title) = explode('-', $name, 2);
        } else {
            // Can't parse, use filename as title
            return [
                'artist' => 'Unknown Artist',
                'title' => $name,
            ];
        }

        return [
            'artist' => trim($artist),
            'title' => trim($title),
        ];
    }

    /**
     * Clean up metadata string (remove null bytes, trim whitespace)
     */
    private function cleanup(string $value): string
    {
        return trim(str_replace("\0", '', $value));
    }
}
