-- ============================================================================
-- DATABASE: top100
-- Purpose: Store Masters Radio Top 100 rankings and historical data
-- Migration: 001 - Initial schema
-- ============================================================================


-- ----------------------------------------------------------------------------
-- TABLE: tracks
-- Purpose: Canonical identity for each unique song (deduped by Spotify ID)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tracks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist          VARCHAR(255) NOT NULL COMMENT 'Original artist name from ID3',
  title           VARCHAR(255) NOT NULL COMMENT 'Original title from ID3',
  isrc            VARCHAR(20) NULL COMMENT 'ISRC code if available',
  spotify_id      VARCHAR(64) NULL UNIQUE COMMENT 'Spotify track ID (spotify:track:xxx)',
  
  -- Metadata for reporting
  album_name      VARCHAR(255) NULL COMMENT 'Album name from Spotify',
  spotify_url     VARCHAR(255) NULL COMMENT 'Spotify web player URL',
  preview_url     VARCHAR(255) NULL COMMENT 'Spotify 30-second preview MP3',
  
  -- Audit fields
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes for performance
  INDEX idx_artist_title (artist(100), title(100)),
  INDEX idx_isrc (isrc),
  INDEX idx_spotify_id (spotify_id)
) ENGINE=InnoDB COMMENT='Canonical track identities';

-- ----------------------------------------------------------------------------
-- TABLE: wp_media
-- Purpose: Each unique MP3 file discovered in WordPress uploads
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wp_media (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_media_id     BIGINT NULL COMMENT 'WordPress attachment post ID (if resolvable)',
  source_url      TEXT NOT NULL COMMENT 'Full URL to file in wp-content/uploads',
  file_path       VARCHAR(512) NOT NULL COMMENT 'Absolute filesystem path',
  filename        VARCHAR(255) NOT NULL COMMENT 'Just the filename for reference',
  
  -- File identification
  checksum        CHAR(64) NULL COMMENT 'SHA-256 of first 64KB + last 64KB',
  file_size       BIGINT UNSIGNED NULL COMMENT 'File size in bytes',
  file_mtime      DATETIME NULL COMMENT 'File modification timestamp',
  
  -- Metadata snapshot
  metadata_json   JSON NULL COMMENT 'Full ID3 tags as JSON',
  duration        DECIMAL(10,3) NULL COMMENT 'Duration in seconds',
  
  -- Discovery tracking
  first_seen_run_id  BIGINT UNSIGNED NULL COMMENT 'Run that first discovered this file',
  last_seen_run_id   BIGINT UNSIGNED NULL COMMENT 'Most recent run that saw this file',
  
  -- Audit
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes
  UNIQUE KEY uniq_file_path (file_path(512)),
  INDEX idx_checksum (checksum),
  INDEX idx_filename (filename),
  INDEX idx_file_mtime (file_mtime)
) ENGINE=InnoDB COMMENT='WordPress MP3 file inventory';

-- ----------------------------------------------------------------------------
-- TABLE: runs
-- Purpose: Metadata for each monthly ranking execution
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS runs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label_month     CHAR(7) NOT NULL COMMENT 'YYYY-MM format',
  
  -- Execution tracking
  started_at      DATETIME NOT NULL,
  finished_at     DATETIME NULL,
  status          ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  
  -- Statistics
  total_files     INT UNSIGNED NULL COMMENT 'Total MP3 files discovered',
  matched_tracks  INT UNSIGNED NULL COMMENT 'Successfully matched to Spotify',
  unmatched_tracks INT UNSIGNED NULL COMMENT 'Could not match',
  avg_popularity  DECIMAL(5,2) NULL COMMENT 'Average popularity of matched tracks',
  
  -- Audit
  notes           TEXT NULL COMMENT 'Success message or error details',
  hostname        VARCHAR(255) NULL COMMENT 'Server that executed the run',
  php_version     VARCHAR(20) NULL COMMENT 'PHP version used',
  execution_time  INT UNSIGNED NULL COMMENT 'Total execution time in seconds',
  
  -- Constraints
  UNIQUE KEY uniq_label_month (label_month),
  INDEX idx_started_at (started_at),
  INDEX idx_status (status)
) ENGINE=InnoDB COMMENT='Monthly ranking run metadata';

-- ----------------------------------------------------------------------------
-- TABLE: observations
-- Purpose: Snapshot of every file in every run (full history)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS observations (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id                  BIGINT UNSIGNED NOT NULL,
  wp_media_id             BIGINT UNSIGNED NOT NULL,
  track_id                BIGINT UNSIGNED NULL COMMENT 'NULL if no match found',
  
  -- Spotify data at time of observation
  spotify_popularity      TINYINT UNSIGNED NULL COMMENT '0-100 or NULL if unmatched',
  spotify_release_date    DATE NULL,
  spotify_duration_ms     INT UNSIGNED NULL,
  
  -- Matching metadata
  match_confidence        DECIMAL(4,3) NULL COMMENT '0.000-1.000',
  matched_via             ENUM('isrc','artist_title','override') NULL,
  status                  ENUM('ok','auto_picked','no_match','error') NOT NULL DEFAULT 'ok',
  error_message           VARCHAR(512) NULL COMMENT 'Error details if status=error',
  
  -- Search details (for debugging)
  search_query            VARCHAR(512) NULL COMMENT 'Actual Spotify search string used',
  candidates_found        TINYINT UNSIGNED NULL COMMENT 'Number of Spotify results',
  
  -- Audit
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Foreign keys
  FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE,
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id) ON DELETE CASCADE,
  FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL,
  
  -- Indexes
  UNIQUE KEY uniq_run_media (run_id, wp_media_id),
  INDEX idx_track_id (track_id),
  INDEX idx_status (status),
  INDEX idx_matched_via (matched_via),
  INDEX idx_popularity (spotify_popularity)
) ENGINE=InnoDB COMMENT='Historical snapshot of all tracks per run';

-- ----------------------------------------------------------------------------
-- TABLE: overrides
-- Purpose: Manual corrections for ambiguous matches (future feature)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overrides (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_media_id     BIGINT UNSIGNED NOT NULL,
  spotify_id      VARCHAR(64) NOT NULL COMMENT 'Manually chosen Spotify ID',
  reason          VARCHAR(255) NULL COMMENT 'Why override was needed',
  
  -- Audit
  chosen_by       VARCHAR(128) NULL COMMENT 'User/admin who made override',
  chosen_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Foreign keys
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id) ON DELETE CASCADE,
  
  -- Constraints
  UNIQUE KEY uniq_media (wp_media_id),
  INDEX idx_spotify_id (spotify_id)
) ENGINE=InnoDB COMMENT='Manual match overrides for future UI';

-- ----------------------------------------------------------------------------
-- TABLE: rankings
-- Purpose: Top 100 for each run (denormalized for fast CSV generation)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rankings (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id                  BIGINT UNSIGNED NOT NULL,
  position                SMALLINT UNSIGNED NOT NULL COMMENT '1-100',
  
  -- Denormalized data for fast reporting
  wp_media_id             BIGINT UNSIGNED NOT NULL,
  track_id                BIGINT UNSIGNED NOT NULL,
  artist                  VARCHAR(255) NOT NULL,
  title                   VARCHAR(255) NOT NULL,
  isrc                    VARCHAR(20) NULL,
  spotify_id              VARCHAR(64) NOT NULL,
  popularity              TINYINT UNSIGNED NOT NULL,
  release_date            DATE NULL,
  source_url              TEXT NOT NULL,
  
  -- Tie-breaking info
  file_mtime              DATETIME NULL,
  
  -- Foreign keys
  FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE,
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id) ON DELETE CASCADE,
  FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  
  -- Constraints
  UNIQUE KEY uniq_run_position (run_id, position),
  INDEX idx_track_id (track_id),
  INDEX idx_popularity (popularity)
) ENGINE=InnoDB COMMENT='Top 100 rankings per run (denormalized)';

-- ----------------------------------------------------------------------------
-- TABLE: migrations
-- Purpose: Track applied schema migrations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
  version     VARCHAR(20) PRIMARY KEY,
  applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  description VARCHAR(255) NULL
) ENGINE=InnoDB COMMENT='Schema version tracking';

-- Record this migration
INSERT INTO migrations (version, description) VALUES ('001', 'Initial schema creation')
  ON DUPLICATE KEY UPDATE version=version;
