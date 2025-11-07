# Masters Radio — Top 100 Rankings System
## Comprehensive Developer Specification (REVISED v2)

---

## EXECUTIVE SUMMARY

Build an external PHP application that monthly scans WordPress uploads for .mp3 files, matches them to Spotify tracks, captures popularity scores, generates a Top 100 ranking, and emails a CSV report to adam.pressman@mastersradio.com. Runs automatically on the last Monday of each month at 04:00 America/New_York.

---

## 1. PROJECT GOALS & SUCCESS CRITERIA

### Primary Deliverables
1. **CSV Report**: `Top100_YYYY-MM.csv` containing the top 100 most popular tracks for the label month
2. **Email Notification**: Automated delivery with CSV attachment and inline summary
3. **Persistent Database**: Historical tracking of all tracks and their monthly performance
4. **Reliable Automation**: Scheduled execution with minimal manual intervention

### Success Metrics
- Successfully matches ≥95% of MP3 files to Spotify tracks
- Generates and delivers report within 6 hours of execution start
- Zero data loss across monthly runs
- Complete audit trail in logs and database

---

## 2. SYSTEM ARCHITECTURE & ENVIRONMENT

### 2.1 Hosting & Runtime
- **Execution Model**: External PHP application (not a WordPress plugin)
- **Server**: Same host as WordPress installation
- **PHP Version Requirements**:
  - **Production Target**: PHP 8.0.30 (minimum required)
  - **Development Environment**: PHP 8.3.6 (with compatibility shims)
  - **Composer Configuration**: `"platform": {"php": "8.0"}` to ensure compatibility
- **User Context**: Runs as web server user (e.g., `www-data`)
- **Base Directory**: `/opt/top100`

### 2.2 WordPress Integration
- **WordPress Root Paths** (environment-specific):
  - **Dev**: `/var/www/mastersradio/public`
  - **Prod**: `/home/user/htdocs/mastersradio`
  - ⚠️ **Critical**: Must be configured in `.env` per environment
  
- **WordPress REST API**:
  - **Authentication**: Application Password (Basic Auth)
  - **Service User**: Dedicated WordPress user with Subscriber role (read-only)
  - **Endpoint**: `wp-json/wp/v2/media` for attachment enumeration (backup method)
  
- **Primary Scan Method**: Direct filesystem scan of `wp-content/uploads`
  - **Rationale**: More reliable than REST API for complete file discovery
  - **Fallback**: REST API can supplement if filesystem scan misses files with metadata

### 2.3 Directory Structure
```
/opt/top100/
├── Top100Rankings.php          # Main CLI runner
├── install.php                 # Installation and migration script
├── composer.json               # Dependencies
├── composer.lock
├── src/
│   ├── Config.php              # Configuration loader
│   ├── Logger.php              # Logging abstraction
│   ├── Database/
│   │   ├── Connection.php      # PDO wrapper
│   │   ├── Migrator.php        # Schema management
│   │   └── Models/
│   │       ├── Track.php
│   │       ├── WpMedia.php
│   │       ├── Run.php
│   │       ├── Observation.php
│   │       ├── Override.php
│   │       └── Ranking.php
│   ├── WordPress/
│   │   ├── ConfigReader.php   # Parse wp-config.php
│   │   ├── FileScanner.php    # Recursive uploads scan
│   │   └── RestClient.php     # Optional REST API client
│   ├── Metadata/
│   │   ├── ID3Reader.php      # getID3 wrapper
│   │   ├── Normalizer.php     # String normalization
│   │   └── MetadataExtractor.php
│   ├── Spotify/
│   │   ├── Client.php         # API client with rate limiting
│   │   ├── Authenticator.php  # Client Credentials flow
│   │   ├── Matcher.php        # Search and scoring
│   │   └── RateLimiter.php    # Throttling logic
│   ├── Ranking/
│   │   ├── RankingEngine.php  # Sort and tie-break logic
│   │   └── CsvGenerator.php   # CSV output with Excel formatting
│   ├── Email/
│   │   ├── Mailer.php         # PHPMailer wrapper
│   │   ├── OAuthManager.php   # Gmail OAuth token handling
│   │   └── Templates/
│   │       ├── success.html   # Success email template
│   │       └── failure.html   # Error alert template
│   ├── Scheduling/
│   │   ├── CronGate.php       # Last Monday detection
│   │   └── LockManager.php    # Run locking
│   └── Utilities/
│       ├── FileSystem.php     # File operations
│       └── Validator.php      # Input validation
├── config/
│   ├── .env.example           # Template configuration
│   └── schema/
│       └── migrations/        # SQL migration files
│           ├── 001_initial_schema.sql
│           └── 002_add_indexes.sql
├── scripts/
│   ├── gmail_oauth_setup.php  # One-time OAuth consent flow
│   ├── test_spotify.php       # Spotify API connectivity test
│   ├── test_wordpress.php     # WordPress connectivity test
│   └── manual_run.sh          # Helper wrapper for manual execution
├── reports/                   # Generated CSV files (organized by month)
│   └── YYYY-MM/
│       └── Top100_YYYY-MM.csv
├── logs/                      # Execution logs
│   ├── YYYY-MM.log           # Monthly log files
│   └── cron.log              # Cron execution log
└── vendor/                    # Composer dependencies
```

---

## 3. DATA FLOW & PROCESSING

### 3.1 Execution Schedule
- **Timing**: Last Monday of each month at 04:00 America/New_York
- **Rationale**: 
  - After midnight (00:10) avoided to prevent conflicts with maintenance tasks
  - Last Monday ensures full month of data collection
  - Early morning minimizes server load

- **Cron Expression** (weekly, with smart gate):
  ```bash
  0 4 * * 1 /usr/bin/php /opt/top100/Top100Rankings.php --cron >> /opt/top100/logs/cron.log 2>&1
  ```

- **Schedule Gate Logic**:
  - Runs every Monday at 04:00 ET
  - Script checks: Is today the last Monday of the current month?
  - If NO: Exit gracefully with log entry
  - If YES: Proceed with full execution
  - **Edge Case Handling**: Months with 5 Mondays vs 4 Mondays

### 3.2 Month Labeling Convention
- **Label Month**: The month being measured (when the script runs)
- **Example**: Script runs on Monday, November 25, 2025 → Label: `2025-11`
- **File Naming**: `Top100_2025-11.csv`
- **Email Subject**: `Masters Radio Top 100 for November 2025`

### 3.3 MP3 Discovery & Enumeration

#### Primary Method: Filesystem Scan
1. Read `WP_ROOT` from `.env`
2. Parse `wp-config.php` to determine exact uploads directory:
   - Check for `WP_CONTENT_DIR` constant
   - Check for `UPLOADS` constant override
   - Fallback: `{WP_ROOT}/wp-content/uploads`
3. Recursively scan for files matching `*.mp3` (case-insensitive)
4. **Exclusions**: Skip directories named `backup`, `cache`, `tmp`
5. **File Validation**: Check file size > 0 bytes and readable

#### Metadata Extraction Priority
For each discovered MP3 file, extract metadata in this order:

1. **ISRC** (International Standard Recording Code):
   - Source: ID3v2 TSRC frame
   - Validation: 12 characters, format `CC-XXX-YY-NNNNN`
   - If present and valid: Priority matching method

2. **Artist**:
   - Primary: ID3v2 TPE1 (Lead Artist)
   - Secondary: ID3v2 TPE2 (Band/Orchestra)
   - Fallback: Parse from filename (before " - ")
   - Cleanup: Trim whitespace, remove null bytes

3. **Title**:
   - Primary: ID3v2 TIT2 (Title/Songname)
   - Fallback: Parse from filename (after " - ", before file extension)
   - Cleanup: Trim whitespace, remove null bytes

4. **Duration**:
   - Source: ID3 `playtime_seconds` or audio analysis
   - Used for: Spotify match validation (±5s tolerance)

5. **Album** (optional, for logging):
   - Source: ID3v2 TALB

6. **Release Date** (optional, for tie-breaking):
   - Source: ID3v2 TDRC or TYER
   - Used for: Spotify match selection preference

#### File Identification
- **Checksum**: Calculate SHA-256 hash of first 64KB + last 64KB (fast, unique enough)
- **Purpose**: Detect duplicate files, track file changes between runs
- **Storage**: `wp_media.checksum` column

### 3.4 String Normalization (Aggressive)

To maximize Spotify match rates, apply aggressive normalization before searching:

#### Artist Normalization
1. Remove featuring credits:
   - Patterns: `feat.`, `ft.`, `featuring`, `with`, `&`, `and`, `vs.`, `vs`
   - Example: `"John Doe feat. Jane Smith"` → `"John Doe"`
2. Remove parenthetical information: `(...)`, `[...]`
3. Collapse multiple spaces to single space
4. Trim leading/trailing whitespace
5. Convert to lowercase for comparison
6. Remove common prefixes: `The `, `A `, `An `
7. Remove punctuation except hyphens: `,.!?;:'"` → ``

#### Title Normalization
1. Remove edition markers:
   - Patterns: `(Remastered)`, `(Remastered YYYY)`, `(Live)`, `(Acoustic)`, 
     `(Radio Edit)`, `(Single Version)`, `(Album Version)`, `(Deluxe)`,
     `(Bonus Track)`, `(Demo)`, `- Remastered`, `- Live`, etc.
2. Remove featuring credits (same rules as artist)
3. Remove parenthetical/bracketed content: `(...)`, `[...]`
4. Collapse multiple spaces
5. Trim whitespace
6. Convert to lowercase
7. Remove punctuation except hyphens
8. **Preserve**: Original title in database for display

#### Example Transformations
```
Original: "Hotel California - Remastered 2013"
Normalized: "hotel california"

Original: "Billie Jean (Single Version)"
Normalized: "billie jean"

Original: "Bohemian Rhapsody - 2011 Remaster"
Normalized: "bohemian rhapsody"
```

---

## 4. SPOTIFY INTEGRATION

### 4.1 Authentication (Client Credentials Flow)

#### Configuration (in `.env`)
```
SPOTIFY_CLIENT_ID=your_spotify_app_client_id
SPOTIFY_CLIENT_SECRET=your_spotify_app_client_secret
```

#### Token Management
1. **Endpoint**: `https://accounts.spotify.com/api/token`
2. **Request**:
   ```
   POST /api/token
   Authorization: Basic {base64(client_id:client_secret)}
   Content-Type: application/x-www-form-urlencoded
   
   grant_type=client_credentials
   ```
3. **Response**: 
   ```json
   {
     "access_token": "NgCXRK...MzYjw",
     "token_type": "Bearer",
     "expires_in": 3600
   }
   ```
4. **Token Caching**:
   - Store in memory for script duration
   - Refresh automatically when expired (3600 seconds = 1 hour)
   - No persistent storage needed (new token each run)

### 4.2 Track Matching Process

#### Step 1: ISRC Match (If Available)
- **Endpoint**: `GET /v1/search?q=isrc:{ISRC}&type=track`
- **Behavior**: Exact match, no scoring needed
- **Success**: If exactly 1 result, use it immediately
- **Failure**: If 0 results or >1 result, fall through to artist/title search

#### Step 2: Artist + Title Search
- **Endpoint**: `GET /v1/search?q=track:{title}%20artist:{artist}&type=track&limit=10`
- **Parameters**:
  - Use **normalized** artist and title
  - `limit=10` to get multiple candidates
  - No `market` parameter (global search)

#### Step 3: Candidate Scoring

For each Spotify result, calculate match confidence score (0.0 to 1.0):

```
match_score = (title_similarity * 0.5) + (artist_similarity * 0.4) + (duration_match * 0.1)
```

**Components**:

1. **Title Similarity** (50% weight):
   - Algorithm: Levenshtein distance ratio on normalized strings
   - Formula: `1 - (levenshtein_distance / max_string_length)`
   - Example: "hotel california" vs "hotel californa" = 0.944

2. **Artist Similarity** (40% weight):
   - Same algorithm as title
   - Compares normalized artist strings

3. **Duration Match** (10% weight):
   - Compare ID3 duration vs Spotify `duration_ms`
   - **Perfect Match** (±5 seconds): 1.0
   - **Close Match** (±10 seconds): 0.5
   - **No Match** (>10 seconds): 0.0

#### Step 4: Auto-Selection Logic
1. Sort candidates by `match_score` (descending)
2. **Accept Threshold**: If top candidate score ≥ 0.85 → Auto-select
3. **Reject Threshold**: If top candidate score < 0.85 → Mark as `no_match`
4. **Tie-Breaking** (if scores equal):
   - Prefer newer `album.release_date`
   - Prefer higher `popularity` score
   - Prefer shorter track (assume original over remix)

#### Step 5: Result Recording
Store in `observations` table:
- `track_id` → Link to `tracks` table record
- `spotify_popularity` → Current popularity (0-100)
- `spotify_release_date` → Album release date
- `match_confidence` → Calculated score
- `matched_via` → `'isrc'` or `'artist_title'`
- `status` → `'ok'`, `'auto_picked'`, `'no_match'`, `'error'`

### 4.3 Rate Limiting & Retry Strategy

#### Spotify API Limits
- **Rate Limit**: ~20 requests/second, 100,000 requests/day
- **Strategy**: Implement automatic throttling and exponential backoff

#### Throttling Implementation
```php
class RateLimiter {
    private $requests_per_second = 10;  // Conservative: 10 req/sec
    private $last_request_time = 0;
    
    public function throttle() {
        $min_interval = 1.0 / $this->requests_per_second;
        $elapsed = microtime(true) - $this->last_request_time;
        
        if ($elapsed < $min_interval) {
            usleep(($min_interval - $elapsed) * 1000000);
        }
        
        $this->last_request_time = microtime(true);
    }
}
```

#### Retry Policy (Exponential Backoff with Jitter)
```
Max Attempts: 5
Base Delay: 500ms
Multiplier: 2
Jitter: ±25%

Attempt 1: Immediate
Attempt 2: 500ms ± 125ms (375-625ms)
Attempt 3: 1000ms ± 250ms (750-1250ms)
Attempt 4: 2000ms ± 500ms (1500-2500ms)
Attempt 5: 4000ms ± 1000ms (3000-5000ms)
```

#### Retry Trigger Conditions
- HTTP 429 (Too Many Requests)
- HTTP 500-599 (Server errors)
- Network timeout
- Connection refused

#### Non-Retryable Errors
- HTTP 401 (Unauthorized - fix credentials)
- HTTP 404 (Not Found - track doesn't exist)
- HTTP 400 (Bad Request - invalid query)

---

## 5. RANKING ALGORITHM

### 5.1 Sorting Rules (Applied in Order)

**Primary Sort**: Spotify `popularity` score (0-100), **descending**

**Tie-Breakers** (applied sequentially until tie is broken):

1. **Spotify Release Date**: Newer release wins
   - Source: `album.release_date` from Spotify API
   - Format: ISO 8601 date (YYYY-MM-DD)
   - Missing dates treated as oldest (1900-01-01)

2. **File Modified Time**: Newer file wins
   - Source: `filemtime()` on MP3 file
   - Use case: Newly uploaded versions of same song

3. **Artist Name**: Alphabetical A-Z (case-insensitive)
   - Use normalized artist name for comparison

4. **Title**: Alphabetical A-Z (case-insensitive)
   - Use normalized title for comparison

### 5.2 Duplicate Handling

**Scenario**: Multiple MP3 files map to the same Spotify track ID

**Decision**: **Include all files** in the ranking

**Rationale**: 
- Rare occurrence (expected <1% of library)
- Provides visibility into duplicate uploads
- Artist may want to know about duplicates
- Easy to manually deduplicate in post-processing

**Example**:
```
Rank 5: Eagles - Hotel California (spotify:track:40riOy7x9W7GXjyGp4pjAv) - Popularity 89
  - File 1: /uploads/2023/05/hotel-california.mp3
Rank 6: Eagles - Hotel California (spotify:track:40riOy7x9W7GXjyGp4pjAv) - Popularity 89
  - File 2: /uploads/2024/01/eagles-hotel-california-remaster.mp3
```

### 5.3 Rank Numbering

**Method**: Standard competition ranking (also called "1224" ranking)

**Rules**:
- Items with identical sort values receive identical rank
- Next rank number is incremented by number of tied items
- Due to comprehensive tie-breaking, true ties should be rare

**Example**:
```
Position  Popularity  Release    Rank
1         95          2024-01    1
2         93          2023-05    2
3         93          2023-05    2    <- Same popularity and date
4         92          2024-02    4    <- Skip rank 3
5         92          2023-12    5    <- Different date breaks tie
```

### 5.4 Top 100 Selection

1. Apply all sorting rules
2. Take first 100 ranked items
3. **Edge Case**: If fewer than 100 tracks have Spotify matches:
   - CSV contains only matched tracks (e.g., Top 87)
   - Email summary notes actual count: "Top 87 of 150 tracks"
   - All 150 tracks still recorded in database for future matching

---

## 6. DATABASE SCHEMA

### 6.1 Schema Configuration
- **Database Name**: `top100`
- **Character Set**: `utf8mb4`
- **Collation**: `utf8mb4_unicode_ci`
- **Engine**: InnoDB (for foreign key support)
- **Credentials**: Inherit from WordPress `wp-config.php`

### 6.2 Complete Schema Definition

```sql
-- ============================================================================
-- DATABASE: top100
-- Purpose: Store Masters Radio Top 100 rankings and historical data
-- ============================================================================

CREATE DATABASE IF NOT EXISTS top100 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE top100;

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
```

### 6.3 Migration Strategy

#### Initial Setup (install.php)
1. Check if schema `top100` exists
2. If NO: Create schema and all tables
3. If YES: Check each table structure and add missing columns

#### Version Management
- Track schema version in a `migrations` table:
  ```sql
  CREATE TABLE IF NOT EXISTS migrations (
    version     VARCHAR(20) PRIMARY KEY,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NULL
  );
  ```

#### Migration Files
Store in `config/schema/migrations/`:
- `001_initial_schema.sql`
- `002_add_preview_url_column.sql` (example future migration)

#### Auto-Migration Logic
```php
// In install.php or Migrator class
$current_version = getSchemaVersion();  // Read from migrations table
$required_version = '002';              // Hardcoded in code

if ($current_version < $required_version) {
    foreach (getMigrationFiles() as $migration) {
        if ($migration['version'] > $current_version) {
            executeMigration($migration['file']);
            recordMigration($migration['version']);
        }
    }
}
```

---

## 7. CSV OUTPUT SPECIFICATION

### 7.1 File Format Details

**Encoding**: UTF-8 with BOM (Byte Order Mark)
- **Purpose**: Ensures Excel opens with correct encoding
- **BOM Bytes**: `EF BB BF` at start of file

**Line Endings**: CRLF (`\r\n`)
- **Purpose**: Windows/Excel compatibility

**Delimiter**: Comma (`,`)

**Quoting**: RFC 4180 compliant
- Quote fields containing: comma, double-quote, newline
- Escape double-quotes by doubling: `"` → `""`
- Example: `"Artist, The"` or `"He said ""Hello"""`

### 7.2 Column Schema (Exact Order)

| Position | Column Name       | Data Type     | Description                                  | Example                          |
|----------|-------------------|---------------|----------------------------------------------|----------------------------------|
| 1        | rank              | Integer       | Position in ranking (1-100)                  | `5`                              |
| 2        | artist            | String        | Original artist name from ID3                | `Eagles`                         |
| 3        | title             | String        | Original title from ID3                      | `Hotel California`               |
| 4        | isrc              | String or Empty | ISRC code if available                      | `USIR19900123` or (empty)        |
| 5        | spotify_id        | String        | Spotify track ID                             | `40riOy7x9W7GXjyGp4pjAv`         |
| 6        | popularity        | Integer       | Spotify popularity (0-100)                   | `89`                             |
| 7        | release_date      | Date or Empty | Album release date (ISO 8601)                | `1976-12-08` or (empty)          |
| 8        | wp_media_id       | Integer or Empty | WordPress attachment ID (if available)    | `12345` or (empty)               |
| 9        | source_url        | URL           | Full URL to MP3 in uploads directory         | `https://mastersradio.com/...`   |

### 7.3 Header Row
```csv
rank,artist,title,isrc,spotify_id,popularity,release_date,wp_media_id,source_url
```

### 7.4 Example CSV Output
```csv
rank,artist,title,isrc,spotify_id,popularity,release_date,wp_media_id,source_url
1,"Queen","Bohemian Rhapsody","GBUM71029604","4u7EnebtmKWzUH433cf5Qv",95,"1975-10-31",12345,"https://mastersradio.com/wp-content/uploads/2023/05/bohemian-rhapsody.mp3"
2,"Eagles","Hotel California","USIR19900123","40riOy7x9W7GXjyGp4pjAv",93,"1976-12-08",12346,"https://mastersradio.com/wp-content/uploads/2023/06/hotel-california.mp3"
3,"Led Zeppelin","Stairway to Heaven",,"5CQ30WqJwcep0pYcV4AMNc",92,"1971-11-08",12347,"https://mastersradio.com/wp-content/uploads/2023/07/stairway-to-heaven.mp3"
```

### 7.5 File Storage
- **Path**: `/opt/top100/reports/YYYY-MM/Top100_YYYY-MM.csv`
- **Permissions**: `0644` (owner read/write, group read, world read)
- **Owner**: Web server user (e.g., `www-data`)

---

## 8. EMAIL DELIVERY

### 8.1 Gmail OAuth2 Configuration

#### Prerequisites
1. Google Cloud Project with Gmail API enabled
2. OAuth 2.0 Client ID (type: Desktop App)
3. Client ID and Client Secret stored in `.env`

#### One-Time Setup Script: `scripts/gmail_oauth_setup.php`

**Purpose**: Obtain refresh token for server-side email sending

**Flow**:
1. Generate authorization URL
2. User opens URL in browser and grants consent
3. User copies authorization code from redirect
4. Script exchanges code for tokens
5. Script saves refresh token to `.env`
6. Script sends test email to verify setup

**Example Script Output**:
```
Gmail OAuth2 Setup Wizard
=========================

Step 1: Visit this URL in your browser:
https://accounts.google.com/o/oauth2/auth?client_id=...&scope=https://mail.google.com...

Step 2: After authorizing, copy the authorization code here:
Code: 4/0AfgeXvv...

Step 3: Exchanging code for tokens...
✓ Success! Refresh token obtained.

Step 4: Saving to .env file...
✓ GMAIL_OAUTH_REFRESH_TOKEN saved.

Step 5: Sending test email to adam.pressman@mastersradio.com...
✓ Test email sent successfully!

Setup complete. You can now run Top100Rankings.php
```

#### Token Management in Production
```php
// In Email/OAuthManager.php
class OAuthManager {
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $access_token = null;
    private $token_expires_at = 0;
    
    public function getAccessToken() {
        if (time() < $this->token_expires_at - 300) {  // 5-minute buffer
            return $this->access_token;
        }
        
        // Refresh token
        $response = $this->httpClient->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ]);
        
        $this->access_token = $response['access_token'];
        $this->token_expires_at = time() + $response['expires_in'];
        
        return $this->access_token;
    }
}
```

### 8.2 Success Email Template

**Subject**: `Masters Radio Top 100 for {Month YYYY}`
- Example: `Masters Radio Top 100 for November 2025`

**From**: `"Masters Radio Top 100" <reports@mastersradio.com>`

**To**: `adam.pressman@mastersradio.com`

**Content-Type**: `multipart/mixed` (text + CSV attachment)

**Body (HTML)**:
```html
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .summary { background: #f4f4f4; padding: 15px; border-radius: 5px; margin: 20px 0; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        tr:hover { background: #f5f5f5; }
        .rank { font-weight: bold; color: #e74c3c; }
        .footer { margin-top: 30px; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <h1>Masters Radio Top 100 - {Month YYYY}</h1>
    
    <div class="summary">
        <strong>Run Summary:</strong><br>
        Processed {total_tracks} tracks — {matched} successfully matched, {unmatched} unmatched<br>
        Average popularity: {avg_popularity}/100<br>
        Execution time: {execution_time} seconds<br>
        Generated: {timestamp}
    </div>
    
    <h2>Top 100 Rankings</h2>
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Artist</th>
                <th>Title</th>
                <th>Popularity</th>
            </tr>
        </thead>
        <tbody>
            {foreach rankings as rank, artist, title, popularity}
            <tr>
                <td class="rank">{rank}</td>
                <td>{artist}</td>
                <td>{title}</td>
                <td>{popularity}</td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    
    <p><strong>Full details are available in the attached CSV file.</strong></p>
    
    <div class="footer">
        <p>This report was automatically generated by the Masters Radio Top 100 Rankings System.</p>
        <p>Server: {hostname} | PHP {php_version}</p>
    </div>
</body>
</html>
```

### 8.3 Failure Alert Email

**Subject**: `❗ Top 100 Run FAILED for {YYYY-MM}`

**From**: Same as success email

**To**: Same as success email

**Priority**: High

**Body**:
```html
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .error { background: #ffebee; border-left: 5px solid #e74c3c; padding: 15px; margin: 20px 0; }
        .log { background: #f5f5f5; padding: 15px; font-family: monospace; font-size: 0.85em; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>⚠️ Top 100 Rankings Failed</h1>
    
    <div class="error">
        <strong>Error Details:</strong><br>
        Run Month: {YYYY-MM}<br>
        Started: {started_at}<br>
        Failed: {failed_at}<br>
        Error: {error_message}
    </div>
    
    <h2>Last 200 Log Lines</h2>
    <div class="log">
        <pre>{last_200_log_lines}</pre>
    </div>
    
    <p><strong>Action Required:</strong> Please investigate and re-run manually if needed.</p>
    <p>Command: <code>php /opt/top100/Top100Rankings.php --run-now --verbose</code></p>
</body>
</html>
```

---

## 9. LOGGING SYSTEM

### 9.1 Log Levels

```php
enum LogLevel: string {
    case DEBUG = 'DEBUG';     // Detailed debugging (only with --verbose)
    case INFO = 'INFO';       // General informational messages
    case NOTICE = 'NOTICE';   // Notable events (file scanned, match found)
    case WARNING = 'WARNING'; // Non-fatal issues (low confidence match)
    case ERROR = 'ERROR';     // Errors that don't stop execution
    case CRITICAL = 'CRITICAL'; // Fatal errors that stop execution
}
```

### 9.2 Log Format

**Format**: `[YYYY-MM-DD HH:MM:SS] [LEVEL] [Component] Message {context}`

**Example Log Entries**:
```
[2025-11-25 04:00:01] [INFO] [Bootstrap] Starting Top 100 Rankings for 2025-11
[2025-11-25 04:00:02] [INFO] [Lock] Acquired run lock: /opt/top100/run.lock
[2025-11-25 04:00:03] [INFO] [Config] Loaded configuration from .env
[2025-11-25 04:00:04] [INFO] [Database] Connected to database: top100
[2025-11-25 04:00:05] [INFO] [Scanner] Scanning uploads directory: /var/www/.../uploads
[2025-11-25 04:00:15] [NOTICE] [Scanner] Found 1,247 MP3 files
[2025-11-25 04:00:16] [DEBUG] [MetadataReader] Reading ID3 from: hotel-california.mp3 {"duration": 391.2, "bitrate": 320}
[2025-11-25 04:00:17] [DEBUG] [Spotify] Searching: track:"hotel california" artist:"eagles"
[2025-11-25 04:00:18] [INFO] [Matcher] Auto-picked: Eagles - Hotel California (confidence: 0.97)
[2025-11-25 04:00:19] [WARNING] [Matcher] Low confidence match: Unknown Artist - Unknown Title (confidence: 0.72)
[2025-11-25 04:05:30] [INFO] [Ranker] Generated Top 100 rankings
[2025-11-25 04:05:31] [INFO] [CSV] Written: /opt/top100/reports/2025-11/Top100_2025-11.csv
[2025-11-25 04:05:35] [INFO] [Email] Sent report to adam.pressman@mastersradio.com
[2025-11-25 04:05:36] [INFO] [Bootstrap] Run completed successfully in 334 seconds
```

### 9.3 Log Files

**Monthly Log**: `/opt/top100/logs/YYYY-MM.log`
- Contains all execution logs for runs in that month
- Retention: 24 months (auto-pruned)
- Permissions: `0640` (owner read/write, group read only)

**Cron Log**: `/opt/top100/logs/cron.log`
- Contains stdout/stderr from cron executions
- Useful for debugging cron schedule issues
- Retention: Keep last 12 runs (rolling)

### 9.4 Verbosity Modes

**Normal Mode** (default):
- Log levels: INFO, NOTICE, WARNING, ERROR, CRITICAL
- Summary statistics only

**Verbose Mode** (`--verbose` flag):
- Log levels: All (including DEBUG)
- Every file scanned
- Every Spotify API call
- Every match attempt with scores
- Every tie-breaking decision

**Example Verbose Output**:
```
[2025-11-25 04:00:16] [DEBUG] [Scanner] File 1/1247: /uploads/2023/05/hotel-california.mp3
[2025-11-25 04:00:16] [DEBUG] [MetadataReader] ID3v2.4 tags found
[2025-11-25 04:00:16] [DEBUG] [MetadataReader]   Artist: Eagles (TPE1)
[2025-11-25 04:00:16] [DEBUG] [MetadataReader]   Title: Hotel California (TIT2)
[2025-11-25 04:00:16] [DEBUG] [MetadataReader]   ISRC: USIR19900123 (TSRC)
[2025-11-25 04:00:16] [DEBUG] [MetadataReader]   Duration: 391.2 seconds
[2025-11-25 04:00:17] [DEBUG] [Normalizer] Normalized artist: "eagles"
[2025-11-25 04:00:17] [DEBUG] [Normalizer] Normalized title: "hotel california"
[2025-11-25 04:00:17] [DEBUG] [Spotify] API Call: GET /v1/search?q=isrc:USIR19900123&type=track
[2025-11-25 04:00:18] [DEBUG] [Spotify] Response: 200 OK (1 result)
[2025-11-25 04:00:18] [DEBUG] [Matcher] ISRC exact match: spotify:track:40riOy7x9W7GXjyGp4pjAv
[2025-11-25 04:00:18] [DEBUG] [Matcher]   Title similarity: 1.000
[2025-11-25 04:00:18] [DEBUG] [Matcher]   Artist similarity: 1.000
[2025-11-25 04:00:18] [DEBUG] [Matcher]   Duration match: 1.000 (391.2s vs 391.0s)
[2025-11-25 04:00:18] [DEBUG] [Matcher]   Overall confidence: 1.000
[2025-11-25 04:00:18] [INFO] [Matcher] Auto-picked: Eagles - Hotel California (confidence: 1.00)
```

---

## 10. CLI INTERFACE

### 10.1 Command Syntax

```bash
php /opt/top100/Top100Rankings.php [OPTIONS]
```

### 10.2 Available Options

| Option | Arguments | Description | Example |
|--------|-----------|-------------|---------|
| `--cron` | None | Cron mode: Exit unless last Monday 04:00 ET | `--cron` |
| `--run-now` | None | Bypass schedule gate and run immediately | `--run-now` |
| `--month` | YYYY-MM | Force specific label month (for testing/reruns) | `--month=2025-11` |
| `--dry-run` | None | Execute full process but skip DB writes and email | `--dry-run` |
| `--verbose` | None | Enable DEBUG-level logging | `--verbose` |
| `--limit` | Integer | Process only N files (for testing/debugging) | `--limit=50` |
| `--help` | None | Display usage information | `--help` |
| `--version` | None | Display version information | `--version` |

### 10.3 Usage Examples

**Standard cron execution**:
```bash
php /opt/top100/Top100Rankings.php --cron
```

**Manual test run** (full execution):
```bash
php /opt/top100/Top100Rankings.php --run-now --verbose
```

**Dry run with limited files** (testing):
```bash
php /opt/top100/Top100Rankings.php --run-now --dry-run --limit=20 --verbose
```

**Rerun a specific month** (backfill):
```bash
php /opt/top100/Top100Rankings.php --run-now --month=2025-10
```

**Help output**:
```
Masters Radio Top 100 Rankings
Version 1.0.0

Usage:
  php Top100Rankings.php [OPTIONS]

Options:
  --cron              Run in cron mode (exit unless last Monday 04:00 ET)
  --run-now           Bypass schedule gate and run immediately
  --month=YYYY-MM     Force specific label month (default: current month)
  --dry-run           Execute without DB writes or email sending
  --verbose           Enable detailed DEBUG logging
  --limit=N           Process only N files (for testing)
  --help              Display this help message
  --version           Display version information

Examples:
  php Top100Rankings.php --cron
  php Top100Rankings.php --run-now --verbose
  php Top100Rankings.php --run-now --dry-run --limit=50

For more information, see: /opt/top100/README.md
```

### 10.4 Exit Codes

| Code | Meaning | Description |
|------|---------|-------------|
| 0 | Success | Run completed successfully |
| 1 | General Error | Unspecified error occurred |
| 2 | Configuration Error | Missing or invalid configuration |
| 3 | Lock Error | Could not acquire run lock |
| 4 | Schedule Gate | Not the scheduled time (cron mode only) |
| 5 | Database Error | Database connection or query failed |
| 6 | Spotify Error | Spotify API authentication or rate limit |
| 7 | Email Error | Failed to send email |

---

## 11. LOCKING MECHANISM

### 11.1 Purpose
Prevent overlapping executions which could cause:
- Database write conflicts
- Double-counting tracks
- Resource exhaustion

### 11.2 Lock File
**Path**: `/opt/top100/run.lock`

**Contents** (JSON):
```json
{
  "pid": 12345,
  "started_at": "2025-11-25T04:00:00-05:00",
  "hostname": "mastersradio-prod",
  "command": "php /opt/top100/Top100Rankings.php --cron"
}
```

### 11.3 Lock Acquisition Logic

```php
class LockManager {
    private $lockFile = '/opt/top100/run.lock';
    private $maxAge = 12 * 3600;  // 12 hours in seconds
    
    public function acquire(): bool {
        // Check if lock file exists
        if (!file_exists($this->lockFile)) {
            return $this->createLock();
        }
        
        // Read existing lock
        $lock = json_decode(file_get_contents($this->lockFile), true);
        $lockAge = time() - strtotime($lock['started_at']);
        
        // Check if process is still running
        if (posix_kill($lock['pid'], 0)) {
            // Process exists
            if ($lockAge < $this->maxAge) {
                // Fresh lock, respect it
                $this->logger->warning("Run already in progress (PID {$lock['pid']})");
                return false;
            } else {
                // Stale lock but process still running (should be killed)
                $this->logger->warning("Stale lock detected but process still running");
                return false;
            }
        } else {
            // Process doesn't exist
            if ($lockAge >= $this->maxAge) {
                // Stale lock, break it
                $this->logger->warning("Breaking stale lock (age: " . round($lockAge/3600, 1) . " hours)");
                unlink($this->lockFile);
                return $this->createLock();
            } else {
                // Recently dead process, wait for cleanup
                return false;
            }
        }
    }
    
    private function createLock(): bool {
        $lock = [
            'pid' => getmypid(),
            'started_at' => date('c'),
            'hostname' => gethostname(),
            'command' => implode(' ', $_SERVER['argv'] ?? [])
        ];
        
        return file_put_contents($this->lockFile, json_encode($lock, JSON_PRETTY_PRINT)) !== false;
    }
    
    public function release(): bool {
        if (file_exists($this->lockFile)) {
            return unlink($this->lockFile);
        }
        return true;
    }
}
```

### 11.4 Error Handling
- Lock acquisition failure: Exit with code 3
- Stale lock broken: Log warning, continue execution
- Lock release failure: Log error but don't fail the run

---

## 12. CONFIGURATION MANAGEMENT

### 12.1 Environment Variables (.env)

**File**: `/opt/top100/.env`
**Permissions**: `0640` (read/write owner, read group)
**Format**: Standard dotenv syntax

```ini
# ============================================================================
# APPLICATION CONFIGURATION
# ============================================================================

# Application environment (development, production)
APP_ENV=production

# Timezone for scheduling (America/New_York, UTC, etc.)
TIMEZONE=America/New_York

# Base directory for the application
BASE_DIR=/opt/top100

# ============================================================================
# WORDPRESS INTEGRATION
# ⚠️ CRITICAL: Update WP_ROOT per environment before deployment
# ============================================================================

# WordPress root directory (absolute path)
# Dev:  /var/www/mastersradio/public
# Prod: /home/user/htdocs/mastersradio
WP_ROOT=/home/user/htdocs/mastersradio

# WordPress REST API (optional, for metadata fallback)
WP_REST_API_URL=https://mastersradio.com/wp-json
WP_REST_API_USER=api-service-user
WP_REST_API_PASSWORD=app_password_here

# ============================================================================
# DATABASE CONFIGURATION
# ============================================================================

# Database credentials (leave empty to auto-detect from wp-config.php)
DB_HOST=
DB_NAME=top100
DB_USER=
DB_PASS=
DB_CHARSET=utf8mb4
DB_COLLATE=utf8mb4_unicode_ci

# ============================================================================
# SPOTIFY API CONFIGURATION
# ============================================================================

# Spotify Client Credentials (from Spotify Developer Dashboard)
SPOTIFY_CLIENT_ID=your_spotify_client_id_here
SPOTIFY_CLIENT_SECRET=your_spotify_client_secret_here

# Spotify API settings
SPOTIFY_RATE_LIMIT_PER_SECOND=10
SPOTIFY_RETRY_MAX_ATTEMPTS=5
SPOTIFY_RETRY_BASE_DELAY_MS=500

# ============================================================================
# GMAIL SMTP CONFIGURATION (OAuth2)
# ============================================================================

# Gmail SMTP settings
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls

# Email sender identity
SMTP_FROM_NAME=Masters Radio Top 100
SMTP_FROM_EMAIL=reports@mastersradio.com

# Gmail OAuth2 credentials (from Google Cloud Console)
GMAIL_OAUTH_CLIENT_ID=your_google_oauth_client_id.apps.googleusercontent.com
GMAIL_OAUTH_CLIENT_SECRET=your_google_oauth_client_secret

# Gmail OAuth2 refresh token (generated by scripts/gmail_oauth_setup.php)
GMAIL_OAUTH_REFRESH_TOKEN=your_refresh_token_here

# ============================================================================
# EMAIL RECIPIENTS
# ============================================================================

# Primary report recipient
REPORT_TO_EMAIL=adam.pressman@mastersradio.com
REPORT_TO_NAME=Adam Pressman

# Error alert recipients (comma-separated)
ERROR_ALERT_EMAILS=adam.pressman@mastersradio.com

# ============================================================================
# MATCHING & RANKING CONFIGURATION
# ============================================================================

# Minimum confidence score for auto-matching (0.0 - 1.0)
MATCH_CONFIDENCE_THRESHOLD=0.85

# Duration tolerance for matching (seconds)
MATCH_DURATION_TOLERANCE_STRICT=5
MATCH_DURATION_TOLERANCE_LOOSE=10

# Number of top tracks to include in rankings
TOP_N_TRACKS=100

# ============================================================================
# FILE RETENTION
# ============================================================================

# Number of months to retain CSV reports and logs
RETENTION_MONTHS=24

# ============================================================================
# LOCKING & SCHEDULING
# ============================================================================

# Maximum age of lock file before considering it stale (hours)
LOCK_MAX_AGE_HOURS=12

# Schedule check: Only run on last Monday of month at 04:00 ET
SCHEDULE_ENABLED=true
SCHEDULE_DAY_OF_WEEK=1
SCHEDULE_HOUR=4
SCHEDULE_MINUTE=0

# ============================================================================
# LOGGING CONFIGURATION
# ============================================================================

# Log level (DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL)
LOG_LEVEL=INFO

# Log format (text, json)
LOG_FORMAT=text

# Enable separate error log file
LOG_ERRORS_SEPARATE=false

# ============================================================================
# DEVELOPMENT/DEBUGGING
# ============================================================================

# Enable debug mode (more verbose logging, no email on error)
DEBUG_MODE=false

# Dry run mode (process but don't write to DB or send email)
DRY_RUN=false

# Limit number of files to process (0 = no limit)
PROCESS_LIMIT=0
```

### 12.2 Configuration Validation

**Validation Script**: `src/Config.php`

```php
class Config {
    private array $required = [
        'WP_ROOT',
        'SPOTIFY_CLIENT_ID',
        'SPOTIFY_CLIENT_SECRET',
        'GMAIL_OAUTH_CLIENT_ID',
        'GMAIL_OAUTH_CLIENT_SECRET',
        'GMAIL_OAUTH_REFRESH_TOKEN',
        'REPORT_TO_EMAIL',
    ];
    
    public function validate(): array {
        $errors = [];
        
        foreach ($this->required as $key) {
            if (empty($_ENV[$key])) {
                $errors[] = "Missing required configuration: {$key}";
            }
        }
        
        // Validate WP_ROOT exists
        if (!empty($_ENV['WP_ROOT']) && !is_dir($_ENV['WP_ROOT'])) {
            $errors[] = "WP_ROOT directory does not exist: {$_ENV['WP_ROOT']}";
        }
        
        // Validate email format
        if (!empty($_ENV['REPORT_TO_EMAIL']) && !filter_var($_ENV['REPORT_TO_EMAIL'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address: REPORT_TO_EMAIL";
        }
        
        // Validate numeric values
        $numeric = ['RETENTION_MONTHS', 'LOCK_MAX_AGE_HOURS', 'TOP_N_TRACKS'];
        foreach ($numeric as $key) {
            if (!empty($_ENV[$key]) && !is_numeric($_ENV[$key])) {
                $errors[] = "{$key} must be a number";
            }
        }
        
        // Validate confidence threshold
        $threshold = $_ENV['MATCH_CONFIDENCE_THRESHOLD'] ?? 0.85;
        if ($threshold < 0 || $threshold > 1) {
            $errors[] = "MATCH_CONFIDENCE_THRESHOLD must be between 0 and 1";
        }
        
        return $errors;
    }
}
```

---

## 13. DEPENDENCIES & INSTALLATION

### 13.1 Composer Dependencies

**File**: `composer.json`

```json
{
    "name": "mastersradio/top100-rankings",
    "description": "Monthly Spotify-based Top 100 rankings for Masters Radio",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": "^8.0",
        "ext-curl": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-pdo": "*",
        "ext-pdo_mysql": "*",
        "guzzlehttp/guzzle": "^7.8",
        "vlucas/phpdotenv": "^5.6",
        "phpmailer/phpmailer": "^6.9",
        "james-heinrich/getid3": "^2.0 || ^1.9",
        "ramsey/uuid": "^4.7"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "phpstan/phpstan": "^1.10"
    },
    "autoload": {
        "psr-4": {
            "MastersRadio\\Top100\\": "src/"
        }
    },
    "config": {
        "platform": {
            "php": "8.0"
        },
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit",
        "analyze": "phpstan analyse src/"
    }
}
```

### 13.2 PHP Extension Requirements

**Required Extensions**:
- `curl` - HTTP client for Spotify/Gmail APIs
- `json` - JSON encoding/decoding
- `mbstring` - Multi-byte string handling (for UTF-8)
- `openssl` - HTTPS/TLS support
- `pdo` - Database abstraction
- `pdo_mysql` - MySQL driver
- `iconv` - Character encoding conversion (for ID3 tags)
- `posix` - Process management (for locking)

**Check Command**:
```bash
php -m | grep -E 'curl|json|mbstring|openssl|pdo|pdo_mysql|iconv|posix'
```

### 13.3 System Requirements

**Operating System**: Linux (Ubuntu 20.04+ or equivalent)

**PHP Version**: 8.0.30 minimum (8.3.6 tested)

**MySQL**: 5.7+ or MariaDB 10.3+

**Disk Space**: 
- Application: ~50 MB
- Logs: ~100 MB per year
- CSV Reports: ~10 MB per year
- Total recommended: 500 MB

**Memory**: 512 MB minimum, 1 GB recommended

**Network**: Outbound HTTPS (443) to:
- `api.spotify.com`
- `accounts.spotify.com`
- `smtp.gmail.com`
- `oauth2.googleapis.com`

---

## 14. INSTALLATION PROCEDURE

### 14.1 Installer Script: `install.php`

**Purpose**: Automated setup of directory structure, dependencies, database, and configuration

**Usage**:
```bash
cd /opt
sudo mkdir top100
sudo chown www-data:www-data top100
cd top100

# Copy application files here first
# Then run:
php install.php
```

**Installer Flow**:

1. **Pre-flight Checks**
   - Verify PHP version ≥ 8.0
   - Check required extensions
   - Verify write permissions on /opt/top100

2. **Directory Creation**
   ```
   /opt/top100/
   ├── reports/
   ├── logs/
   ├── config/
   └── scripts/
   ```

3. **Composer Dependencies**
   - Check if `vendor/` exists
   - If not: Run `composer install --no-dev --optimize-autoloader`

4. **Configuration Setup**
   - Copy `.env.example` to `.env`
   - Prompt for required values or use existing .env
   - Validate configuration

5. **WordPress Integration**
   - Read `WP_ROOT` from `.env`
   - Parse `wp-config.php` to extract:
     - `DB_HOST`
     - `DB_NAME`
     - `DB_USER`
     - `DB_PASSWORD`
   - Test database connection

6. **Database Setup**
   - Create `top100` schema (if not exists)
   - Run all migrations in `config/schema/migrations/`
   - Verify table structure

7. **File Permissions**
   - Set directory permissions: 0750
   - Set file permissions: 0640 for .env, 0644 for others
   - Ensure web server user owns all files

8. **OAuth Setup Reminder**
   - Display instructions for running `scripts/gmail_oauth_setup.php`

9. **Cron Reminder**
   - Display recommended cron entry
   - Explain manual installation requirement

10. **Test Run**
    - Offer to run test with `--dry-run --limit=10`

### 14.2 Manual Installation Steps

For troubleshooting or custom setups:

#### Step 1: Create Base Directory
```bash
sudo mkdir -p /opt/top100
sudo chown www-data:www-data /opt/top100
sudo chmod 750 /opt/top100
```

#### Step 2: Copy Application Files
```bash
cd /opt/top100
# Upload or git clone application files here
```

#### Step 3: Install Dependencies
```bash
# Ensure Composer is installed
composer --version

# Install PHP dependencies
composer install --no-dev --optimize-autoloader
```

#### Step 4: Configure Environment
```bash
# Copy template
cp config/.env.example .env

# Edit configuration
nano .env

# CRITICAL: Update these values:
# - WP_ROOT=/home/user/htdocs/mastersradio  (prod) or /var/www/mastersradio/public (dev)
# - SPOTIFY_CLIENT_ID=...
# - SPOTIFY_CLIENT_SECRET=...
# - GMAIL_OAUTH_CLIENT_ID=...
# - GMAIL_OAUTH_CLIENT_SECRET=...

# Set permissions
chmod 640 .env
chown www-data:www-data .env
```

#### Step 5: Create Directories
```bash
mkdir -p reports logs scripts config/schema/migrations
chmod 750 reports logs scripts config
```

#### Step 6: Database Setup
```bash
# Manual SQL execution (if not using install.php)
php -r "
require 'vendor/autoload.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->load();
// Connect and run migrations
"

# OR use installer
php install.php
```

#### Step 7: Gmail OAuth Setup
```bash
php scripts/gmail_oauth_setup.php

# Follow prompts:
# 1. Open URL in browser
# 2. Authorize application
# 3. Copy authorization code
# 4. Paste code when prompted
# 5. Verify test email received
```

#### Step 8: Test Run
```bash
# Dry run with limited files
php Top100Rankings.php --run-now --dry-run --limit=20 --verbose

# Check logs
tail -f logs/$(date +%Y-%m).log
```

#### Step 9: Install Cron Job
```bash
# Edit crontab as root
sudo crontab -e

# Add this line (runs every Monday at 04:00 ET):
0 4 * * 1 sudo -u www-data /usr/bin/php /opt/top100/Top100Rankings.php --cron >> /opt/top100/logs/cron.log 2>&1

# Verify cron entry
sudo crontab -l
```

#### Step 10: Monitor First Run
```bash
# Wait for first scheduled run or trigger manually
php Top100Rankings.php --run-now --verbose

# Check success
ls -lh reports/$(date +%Y-%m)/

# Verify email received
```

---

## 15. ERROR HANDLING & RECOVERY

### 15.1 Error Categories

#### Critical Errors (Stop Execution)
- Database connection failure
- Invalid configuration (.env missing required values)
- Cannot acquire run lock
- WordPress wp-config.php not found
- Spotify authentication failure

#### Recoverable Errors (Continue with Logging)
- Individual file read failure
- ID3 tag parsing error
- Spotify API rate limit (throttle and retry)
- Single track match failure

#### Warnings (Log Only)
- Low confidence match (< 0.85 threshold)
- Missing metadata fields
- Duplicate files detected

### 15.2 Exception Handling Strategy

```php
// In Top100Rankings.php main execution
try {
    $runner = new Runner($config, $logger);
    $runner->execute();
    exit(0);  // Success
    
} catch (\MastersRadio\Top100\Exception\ConfigurationException $e) {
    $logger->critical("Configuration error: " . $e->getMessage());
    sendFailureEmail($e, $logger);
    exit(2);  // Configuration error
    
} catch (\MastersRadio\Top100\Exception\LockException $e) {
    $logger->critical("Lock error: " . $e->getMessage());
    exit(3);  // Lock error
    
} catch (\MastersRadio\Top100\Exception\DatabaseException $e) {
    $logger->critical("Database error: " . $e->getMessage());
    sendFailureEmail($e, $logger);
    exit(5);  // Database error
    
} catch (\MastersRadio\Top100\Exception\SpotifyException $e) {
    $logger->critical("Spotify API error: " . $e->getMessage());
    sendFailureEmail($e, $logger);
    exit(6);  // Spotify error
    
} catch (\Throwable $e) {
    $logger->critical("Unexpected error: " . $e->getMessage(), [
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    sendFailureEmail($e, $logger);
    exit(1);  // General error
    
} finally {
    // Always release lock
    if (isset($lockManager)) {
        $lockManager->release();
    }
}
```

### 15.3 Recovery Procedures

#### Database Connection Failure
```
1. Check MySQL/MariaDB service status
   systemctl status mysql

2. Verify credentials in wp-config.php
   cat /path/to/wp-config.php | grep DB_

3. Test connection manually
   mysql -h HOST -u USER -pPASS -e "USE top100; SHOW TABLES;"

4. Rerun with verbose logging
   php Top100Rankings.php --run-now --verbose
```

#### Spotify API Issues
```
1. Verify credentials are valid
   Check Spotify Developer Dashboard

2. Test authentication manually
   php scripts/test_spotify.php

3. Check rate limits
   Look for HTTP 429 in logs
   
4. Rerun (will resume from last checkpoint)
   php Top100Rankings.php --run-now
```

#### Gmail OAuth Errors
```
1. Check refresh token expiration
   Refresh tokens can be revoked by user or Google

2. Re-run OAuth setup
   php scripts/gmail_oauth_setup.php

3. Verify SMTP connectivity
   telnet smtp.gmail.com 587

4. Test email sending
   php scripts/test_email.php
```

#### Incomplete Run
```
1. Check if lock file exists
   ls -l /opt/top100/run.lock

2. If stale, remove manually
   rm /opt/top100/run.lock

3. Rerun with same month
   php Top100Rankings.php --run-now --month=YYYY-MM
```

---

## 16. TESTING STRATEGY

### 16.1 Pre-Deployment Tests

#### Test 1: Configuration Validation
```bash
php Top100Rankings.php --help
# Should display usage without errors
```

#### Test 2: Database Connectivity
```bash
php scripts/test_database.php
# Expected output: "✓ Database connection successful"
```

#### Test 3: WordPress Integration
```bash
php scripts/test_wordpress.php
# Should list sample of MP3 files found
```

#### Test 4: Spotify API
```bash
php scripts/test_spotify.php
# Should authenticate and search for a sample track
```

#### Test 5: Gmail OAuth
```bash
php scripts/test_email.php
# Should send test email to configured recipient
```

#### Test 6: Dry Run (Limited Files)
```bash
php Top100Rankings.php --run-now --dry-run --limit=10 --verbose
# Should process 10 files without DB writes or email
```

### 16.2 Integration Tests

#### Test 7: Full Dry Run
```bash
php Top100Rankings.php --run-now --dry-run --verbose
# Process all files, check logs for errors
```

#### Test 8: Full Run (Test Month)
```bash
# Use test label month to avoid affecting production data
php Top100Rankings.php --run-now --month=2024-01 --verbose

# Verify outputs:
# - CSV created in reports/2024-01/
# - Email sent to adam.pressman@mastersradio.com
# - Database records created
```

### 16.3 Edge Case Tests

#### Test 9: Missing Metadata
```bash
# Create test MP3 with no ID3 tags
# Verify filename parsing works
```

#### Test 10: Duplicate Files
```bash
# Copy same MP3 to different locations
# Verify both included in ranking
```

#### Test 11: Unmatched Tracks
```bash
# Create MP3 with nonsense artist/title
# Verify marked as no_match, not in CSV
```

#### Test 12: Schedule Gate
```bash
# Run with --cron on non-last-Monday
php Top100Rankings.php --cron
# Should exit with code 4
```

#### Test 13: Lock Mechanism
```bash
# Start run in background
php Top100Rankings.php --run-now &

# Immediately try second run
php Top100Rankings.php --run-now
# Should fail with lock error
```

---

## 17. MONITORING & MAINTENANCE

### 17.1 Success Indicators

**Immediate (After Each Run)**:
- Exit code 0
- CSV file created in `/opt/top100/reports/YYYY-MM/`
- Email received by adam.pressman@mastersradio.com
- No CRITICAL entries in log

**Monthly Metrics**:
- Match rate ≥ 95% (matched / total files)
- Average execution time < 6 hours
- Zero failed runs

### 17.2 Monitoring Checklist

**Weekly** (Manual):
- Check cron.log for unexpected errors
- Verify disk space: `df -h /opt/top100`

**Monthly** (Automated):
- Review match confidence distribution
- Check for increase in unmatched files
- Audit Spotify API usage

**Quarterly**:
- Review retention policy (24-month pruning)
- Update dependencies: `composer update`
- Review error patterns

### 17.3 Maintenance Tasks

#### Log Rotation
```bash
# Handled automatically by retention policy
# Manual cleanup if needed:
find /opt/top100/logs -name "*.log" -mtime +730 -delete
```

#### Database Optimization
```sql
-- Run annually
OPTIMIZE TABLE top100.tracks;
OPTIMIZE TABLE top100.wp_media;
OPTIMIZE TABLE top100.observations;
ANALYZE TABLE top100.rankings;
```

#### Dependency Updates
```bash
cd /opt/top100
composer update --no-dev
# Test thoroughly after updates
php Top100Rankings.php --run-now --dry-run --limit=50
```

---

## 18. SECURITY CONSIDERATIONS

### 18.1 Credential Management

**Never commit to version control**:
- `.env` file
- OAuth tokens
- Database passwords

**Use .gitignore**:
```
.env
.env.local
*.log
reports/
vendor/
composer.lock
run.lock
```

### 18.2 File Permissions

**Recommended Permissions**:
```bash
# Base directory
chmod 750 /opt/top100

# Sensitive files
chmod 640 /opt/top100/.env
chmod 640 /opt/top100/logs/*.log

# Scripts (executable)
chmod 750 /opt/top100/Top100Rankings.php
chmod 750 /opt/top100/install.php
chmod 750 /opt/top100/scripts/*.php

# Reports (readable)
chmod 644 /opt/top100/reports/*/*.csv
```

### 18.3 Database Security

**Principle of Least Privilege**:
- Use WordPress DB credentials (already configured)
- App only accesses `top100` schema
- No DROP or ALTER privileges needed in production

**Connection Security**:
- Use `localhost` connection (no network exposure)
- Encrypted connections if remote DB (SSL/TLS)

### 18.4 API Security

**Spotify**:
- Client credentials stored in `.env` only
- Never log full access tokens
- Rotate credentials annually

**Gmail OAuth**:
- Refresh token stored in `.env` only
- Review Google Cloud Console for suspicious activity
- Revoke and regenerate if compromised

---

## 19. FUTURE ENHANCEMENTS (Deferred)

These features are **out of scope** for initial release but documented for future development:

### 19.1 Manual Match Override UI

**Purpose**: Web interface for reviewing and correcting low-confidence matches

**Features**:
- List unmatched tracks (status = 'no_match')
- Show Spotify search results with confidence scores
- Allow admin to select correct match
- Store decision in `overrides` table

**Tech Stack**: Simple PHP admin panel or WordPress plugin

### 19.2 Artist Performance Reports

**Purpose**: Provide individual artists with their track performance over time

**Features**:
- Filter observations by artist
- Show popularity trends (line chart)
- Display best-performing tracks
- Export artist-specific CSV

### 19.3 JSON API Export

**Purpose**: Make ranking data available for web display

**Features**:
- REST API endpoint: `GET /api/rankings/YYYY-MM`
- JSON response with full ranking data
- CORS headers for browser consumption
- Optional API key authentication

### 19.4 Slack/Discord Notifications

**Purpose**: Real-time notifications for successful runs and failures

**Features**:
- Webhook integration
- Rich message formatting
- Include summary statistics
- Link to CSV report

### 19.5 Historical Trend Analysis

**Purpose**: Identify rising/falling tracks month-over-month

**Features**:
- Calculate rank delta (e.g., +5, -12)
- Flag "biggest movers"
- Detect new entries and dropouts
- Email separate "Movers & Shakers" report

---

## 20. APPENDICES

### 20.1 Glossary

| Term | Definition |
|------|------------|
| **ISRC** | International Standard Recording Code - unique identifier for sound recordings |
| **Popularity** | Spotify's 0-100 metric indicating relative listener engagement |
| **Match Confidence** | 0.0-1.0 score indicating certainty of MP3→Spotify match |
| **Label Month** | YYYY-MM identifier for the month being measured |
| **Observation** | Single snapshot of a track's popularity in a specific run |
| **Ranking** | Sorted position in Top 100 for a specific month |
| **Override** | Manual correction of an automatic match |
| **Run** | Single execution of the ranking process |
| **Normalization** | Process of cleaning/standardizing artist and title strings |

### 20.2 File Size Estimates

| Component | Typical Size | Notes |
|-----------|--------------|-------|
| Application Code | 5 MB | Excluding vendor/ |
| Composer Dependencies | 30-50 MB | Guzzle, PHPMailer, getID3, etc. |
| Single CSV Report | 50-100 KB | ~100 tracks × 9 columns |
| Monthly Log File | 5-20 MB | Depends on verbosity and file count |
| Database (per month) | 1-5 MB | Observations + rankings |
| Total First Year | ~200 MB | All components |

### 20.3 Typical Execution Timeline

**Sample Run** (1,250 MP3 files):

```
00:00:00  Start execution
00:00:01  Acquire lock
00:00:02  Load configuration
00:00:03  Connect to database
00:00:05  Start file scan
00:01:30  Scan complete (1,250 files found)
00:01:31  Begin metadata extraction
00:05:00  Metadata extraction complete
00:05:01  Begin Spotify matching
00:05:01    - ISRC matches: ~200 files (instant)
00:10:00    - Artist/title matches: ~1,000 files (throttled)
00:10:05    - Unmatched: ~50 files
00:10:06  Generate rankings
00:10:10  Write CSV
00:10:11  Send email
00:10:15  Cleanup and prune old files
00:10:16  Release lock
00:10:17  Exit (success)

Total: ~10 minutes
```

**Factors affecting duration**:
- File count (scales linearly)
- Network latency to Spotify API
- Rate limiting (self-imposed throttling)
- Database query performance

### 20.4 Common Troubleshooting Scenarios

#### Scenario 1: No email received
```
Check:
1. Gmail OAuth refresh token valid
2. SMTP connectivity: telnet smtp.gmail.com 587
3. Email not in spam folder
4. Check logs for email errors
5. Test manually: php scripts/test_email.php
```

#### Scenario 2: Low match rate (<80%)
```
Check:
1. Spotify API credentials valid
2. ID3 tags present in files
3. Normalization too aggressive (review logs)
4. Network issues (API timeouts)
5. Run with --verbose to see match attempts
```

#### Scenario 3: Database out of sync
```
Fix:
1. Backup database: mysqldump top100 > backup.sql
2. Re-run migrations: php install.php
3. Verify table structure: SHOW CREATE TABLE tracks;
4. Restore from backup if needed
```

#### Scenario 4: Cron not executing
```
Check:
1. Cron service running: systemctl status cron
2. Cron entry correct: sudo crontab -l
3. PHP path correct: which php
4. Permissions on Top100Rankings.php
5. Review cron.log for errors
```

---

## 21. DEPLOYMENT CHECKLIST

### 21.1 Pre-Deployment (Development Environment)

- [ ] All dependencies installed via Composer
- [ ] `.env` configured with dev settings
- [ ] Gmail OAuth completed (refresh token obtained)
- [ ] Database schema created and migrated
- [ ] Dry run successful (--dry-run --limit=50)
- [ ] Full test run successful (--run-now --month=2024-01)
- [ ] Email delivery verified
- [ ] Code reviewed and tested

### 21.2 Production Deployment

- [ ] Copy application files to production server
- [ ] Update `.env` with production values:
  - [ ] `WP_ROOT=/home/user/htdocs/mastersradio`
  - [ ] Production Spotify credentials
  - [ ] Production Gmail OAuth credentials
- [ ] Run `composer install --no-dev --optimize-autoloader`
- [ ] Run `php install.php` (creates database, directories)
- [ ] Run `php scripts/gmail_oauth_setup.php` (if needed)
- [ ] Set file permissions (750 directories, 640 .env, 644 others)
- [ ] Test run: `php Top100Rankings.php --run-now --dry-run --limit=10`
- [ ] Install cron job: `sudo crontab -e`
- [ ] Verify cron entry: `sudo crontab -l`
- [ ] Monitor first scheduled run

### 21.3 Post-Deployment Validation

- [ ] Check first successful email delivery
- [ ] Verify CSV created in reports/
- [ ] Review logs for errors
- [ ] Confirm database populated
- [ ] Test manual run: `php Top100Rankings.php --run-now --verbose`
- [ ] Document any issues or deviations

---

## 22. CHANGE LOG

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2025-11-25 | Initial specification | ChatGPT + Adam |
| 2.0 | 2025-11-07 | Revised comprehensive spec | Claude Sonnet 4.5 |

---

## 23. MISSING ELEMENTS IDENTIFIED & ADDED IN v2

The following critical details were absent or unclear in the original specification:

### 23.1 Technical Gaps
1. **CSV Format Details**: Added UTF-8 BOM, CRLF line endings, RFC 4180 quoting rules
2. **Column Order**: Specified exact column order and data types
3. **File Permissions**: Added specific chmod values for security
4. **Error Exit Codes**: Defined standard exit codes for different failure types
5. **Lock File Contents**: Specified JSON structure with PID, timestamp, hostname
6. **Configuration Validation**: Added validation rules for .env values

### 23.2 Process Gaps
1. **File Checksum Strategy**: Specified SHA-256 of first+last 64KB (not full file)
2. **Normalization Examples**: Provided concrete before/after examples
3. **Match Scoring Formula**: Defined exact weighted algorithm
4. **Duration Tolerance**: Clarified ±5s strict, ±10s loose, >10s reject
5. **Tie-Breaking Order**: Detailed 4-step sequential tie-breaking process
6. **Schedule Gate Logic**: Explained "last Monday" detection algorithm

### 23.3 Database Gaps
1. **Foreign Key Constraints**: Added ON DELETE CASCADE/SET NULL rules
2. **Index Strategy**: Specified indexes for performance optimization
3. **Column Comments**: Added MySQL COMMENT for documentation
4. **Migration Tracking**: Added migrations table for version control
5. **Audit Fields**: Added hostname, PHP version, execution time to runs table

### 23.4 Operational Gaps
1. **Testing Procedures**: Added 13-step test plan with commands
2. **Monitoring Metrics**: Specified weekly/monthly/quarterly checks
3. **Recovery Procedures**: Added step-by-step troubleshooting guides
4. **Deployment Checklist**: Created 20+ item pre/post deployment checklist
5. **Log Verbosity Examples**: Showed exact log output for normal vs verbose modes

### 23.5 Integration Gaps
1. **WordPress REST API Fallback**: Clarified when/how to use REST API vs filesystem
2. **wp-config.php Parsing**: Specified constants to extract (WP_CONTENT_DIR, UPLOADS)
3. **Gmail OAuth Token Refresh**: Explained automatic refresh logic with 5-minute buffer
4. **Spotify Rate Limiting**: Defined conservative 10 req/sec limit (not 20)
5. **Email Template Structure**: Provided complete HTML templates with CSS

### 23.6 Security Gaps
1. **Credential Storage**: Clarified .env-only storage (no database credentials)
2. **File Permission Matrix**: Specified perms for every file type
3. **.gitignore Rules**: Listed all files to exclude from version control
4. **API Security**: Added credential rotation recommendations

### 23.7 Documentation Gaps
1. **Glossary**: Defined all technical terms
2. **File Size Estimates**: Provided realistic size projections
3. **Execution Timeline**: Added sample 10-minute run breakdown
4. **Common Error Scenarios**: Documented 4 typical problems with solutions

---

**END OF SPECIFICATION**

This revised specification is comprehensive, unambiguous, and ready for developer handoff.
