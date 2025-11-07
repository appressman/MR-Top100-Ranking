# Masters Radio — Top 100 Rankings (PHP App)

**Developer Blueprint (v1)**

Build an external PHP app that scans the WordPress uploads folder for `.mp3` files, matches each to Spotify, captures per-track **popularity**, ranks by month, and emails a CSV Top-100 to Adam. Runs monthly on the **last Monday @ 04:00 America/New\_York**.

---

## 1\) Goals & Outputs

* **Primary output:** `Top100_YYYY-MM.csv` with the Top-100 Spotify-matched tracks for that **label month** (the month of the run).  
    
* **Email:** To `adam.pressman@mastersradio.com` with the CSV **attached** and a short **summary line** \+ **inline Top-100 list**.  
    
* **Storage:**  
    
  * CSV: `/opt/top100/reports/YYYY-MM/Top100_YYYY-MM.csv`  
  * Logs: `/opt/top100/logs/YYYY-MM.log`


* **Retention:** CSVs & logs pruned after **24 months**. Database kept **forever**.

---

## 2\) Runtime & Scheduling

* **Environment:** External PHP app on the **same host** as WordPress.  
* **PHP target:** **8.0** baseline (dev is 8.3; Composer `config.platform.php=8.0` for compatibility).  
* **Schedule:** Cron **weekly** on Mondays at **04:00 ET**; app exits unless it is the **last Monday** of the month.  
* **CLI:** Supports manual runs and debugging.

**Example weekly cron (added manually later):**

```
# Runs every Monday 04:00 ET; script decides if it's the last Monday
0 4 * * 1 /usr/bin/php /opt/top100/Top100Rankings.php --cron >> /opt/top100/logs/cron.log 2>&1
```

---

## 3\) Data Sources & Matching

* **Enumeration:** Scan the **WordPress uploads filesystem** only (recursive under `WP_ROOT/wp-content/uploads`).  
    
  * App reads `wp-config.php` to discover `WP_CONTENT_DIR` or `UPLOADS` (fallback: `WP_ROOT/wp-content/uploads`).  
      
  * **Reminder:** update `WP_ROOT` per env:  
      
    * Dev: `/var/www/mastersradio/public`  
    * Prod: `/home/user/htdocs/mastersradio`


* **Metadata precedence (for matching):**  
    
  1. **ISRC** (from ID3, if available)  
  2. **Artist \+ Title** (from ID3)  
  3. **Filename parsing** (fallback)


* **Normalization (aggressive):** strip `feat./ft.`, remove edit tags (`Remastered YYYY`, `Live`, `Acoustic`, `Radio Edit`, parentheses/brackets), collapse punctuation/whitespace, case-insensitive.

---

## 4\) Spotify Integration

* **Auth:** **Client Credentials** flow (client ID/secret in `.env`).  
    
* **Endpoints:** `/v1/search` → `/v1/tracks/{id}` (to read `popularity`, `duration_ms`, `album.release_date`).  
    
* **Market:** **Global** (no `market` filter).  
    
* **Scoring & auto-pick:**  
    
  * If **ISRC** present, prefer exact ISRC match.  
  * Else compute similarity score on normalized **artist+title**.  
  * **Auto-pick threshold:** `score ≥ 0.85`; otherwise mark `no_match`.  
  * **Duration tie-breaker:** prefer candidates within **±5s** of ID3 duration (fallback ±10s); then higher `popularity`; then newer `release_date`.  
  * All auto decisions logged with `match_confidence`.


* **Rate limits & retries:**  
    
  * **Auto-throttle** to respect limits; always **run to completion**.  
  * **Exponential backoff with jitter:** up to **5 retries**, starting at **500ms**.

---

## 5\) Ranking Logic

* **Rank basis:** Spotify **`popularity`** (0–100), descending.  
    
* **Tie-breakers (in order):**  
    
  1. **Newer** Spotify `release_date` ranks higher.  
  2. If still tied, **newer file mtime** ranks higher.  
  3. If still tied, **artist (A→Z)** then **title (A→Z)**.


* **Duplicates:** If multiple MP3s map to the **same Spotify track ID**, **include all** (rare).  
    
* **Ranking numbering:** **Standard competition** (1, 2, 2, 4…) as a fallback; tie-breakers above should minimize this.

---

## 6\) CSV Specification

* **Filename:** `Top100_YYYY-MM.csv` (YYYY-MM is the month of the run).  
    
* **Encoding:** **UTF-8 with BOM**, **CRLF** line endings (Excel-friendly).  
    
* **Delimiter:** comma.  
    
* **Columns (order):**  
    
  1. `rank`  
  2. `artist`  
  3. `title`  
  4. `isrc`  
  5. `spotify_id`  
  6. `popularity`  
  7. `release_date`  
  8. `wp_media_id` (if available; else blank)  
  9. `source_url` (uploads URL)


* **Unmatched tracks:** Keep in **database**, but **do not include** in the mailed CSV (CSV is rankings only). (App still records observations for complete history.)

---

## 7\) Email Details (Gmail via OAuth2)

* **Transport:** Gmail SMTP with **OAuth2** (no app-specific password; uses refresh token).  
    
* **Sender:** `"Masters Radio Top 100" <reports@mastersradio.com>` (or your preferred alias).  
    
* **Recipient:** `adam.pressman@mastersradio.com`  
    
* **Subject:** `Masters Radio Top 100 for {Month YYYY}` (e.g., “for November 2025”)  
    
* **Body (text or HTML):**  
    
  * **Summary line:** `Processed {total_tracks} — {matched} matched, {unmatched} unmatched; avg popularity {avg_popularity}.`  
  * **Inline Top-100** (artist — title — popularity), then note the CSV is attached.


* **Attachments:** attach `Top100_YYYY-MM.csv`.  
    
* **Failure alerts:** on fatal failure, email **“❗Top 100 run FAILED for YYYY-MM”** with last **200 log lines** attached.

---

## 8\) Directory Layout

```
/opt/top100/
  Top100Rankings.php        # main runner (cron/CLI)
  install.php               # one-command installer/migrator
  /src/
    Config.php
    Logger.php
    SpotifyClient.php
    WordPressScanner.php
    MetadataReader.php      # getID3 wrapper
    Matcher.php
    Ranker.php
    CsvWriter.php
    Mailer.php
    Db.php                  # PDO + migrations
    Lock.php
    Util.php
  /config/
    .env.example
  /reports/                 # YYYY-MM/Top100_YYYY-MM.csv
  /logs/                    # YYYY-MM.log
  /scripts/
    gmail_oauth_setup.php   # one-time CLI to capture refresh token
    run_now.sh              # optional helper for manual runs
  /vendor/                  # Composer
```

---

## 9\) Configuration (.env)

**Use `vlucas/phpdotenv`** to load this file. Keep it **out of VCS**.

```
# --- App ---
APP_ENV=production
TIMEZONE=America/New_York
BASE_DIR=/opt/top100

# --- WordPress ---
# ⚠️ Update per environment before deploy
# Dev:  /var/www/mastersradio/public
# Prod: /home/user/htdocs/mastersradio
WP_ROOT=/home/user/htdocs/mastersradio

# --- Database ---
# Installer reads WordPress DB creds from wp-config.php; these are only used
# if you choose to override. Leave blank to auto-detect from wp-config.php.
DB_HOST=
DB_NAME=top100
DB_USER=
DB_PASS=

# --- Spotify (Client Credentials) ---
SPOTIFY_CLIENT_ID=your_id
SPOTIFY_CLIENT_SECRET=your_secret

# --- Gmail SMTP (OAuth2) ---
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_FROM_NAME="Masters Radio Top 100"
SMTP_FROM_EMAIL=reports@mastersradio.com
GMAIL_OAUTH_CLIENT_ID=your_google_client_id
GMAIL_OAUTH_CLIENT_SECRET=your_google_client_secret
# Stored by the oauth setup script:
GMAIL_OAUTH_REFRESH_TOKEN=your_refresh_token

# --- Email recipients ---
REPORT_TO=adam.pressman@mastersradio.com

# --- Pruning ---
RETENTION_MONTHS=24

# --- Locking ---
LOCK_MAX_AGE_HOURS=12
```

---

## 10\) Dependencies (Composer)

* `guzzlehttp/guzzle` — HTTP client (Spotify, Gmail token exchange)  
* `vlucas/phpdotenv` — load `.env`  
* `phpmailer/phpmailer` — SMTP \+ OAuth2  
* `james-heinrich/getid3` — read ID3 and duration  
* `ramsey/uuid` — IDs for internal entities (optional)  
* **Composer config:** set `"platform": {"php": "8.0"}` for dev on 8.3

**Install:**

```shell
cd /opt/top100
composer init
composer require guzzlehttp/guzzle vlucas/phpdotenv phpmailer/phpmailer james-heinrich/getid3 ramsey/uuid
# In composer.json, set:
# "config": { "platform": { "php": "8.0" } }
```

---

## 11\) Database Schema (MySQL / MariaDB)

Schema `top100` created/migrated by installer (uses WP DB creds from `wp-config.php`).

```sql
-- Schema (create if not exists)
CREATE DATABASE IF NOT EXISTS top100 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE top100;

-- tracks: canonical track identity
CREATE TABLE IF NOT EXISTS tracks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist          VARCHAR(255) NOT NULL,
  title           VARCHAR(255) NOT NULL,
  isrc            VARCHAR(20) NULL,
  spotify_id      VARCHAR(64) UNIQUE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_artist_title (artist, title),
  INDEX idx_isrc (isrc)
);

-- wp_media: each file seen in uploads
CREATE TABLE IF NOT EXISTS wp_media (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_media_id     BIGINT NULL,           -- if resolvable from DB; else NULL
  source_url      TEXT NOT NULL,
  file_path       TEXT NULL,
  checksum        CHAR(64) NULL,         -- optional SHA-256
  metadata_json   JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_checksum (checksum)
);

-- runs: one per monthly run
CREATE TABLE IF NOT EXISTS runs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label_month     CHAR(7) NOT NULL,      -- YYYY-MM (month being measured)
  started_at      DATETIME NOT NULL,
  finished_at     DATETIME NULL,
  notes           TEXT NULL,
  UNIQUE KEY uniq_label_month (label_month)
);

-- observations: one per file per run (full snapshot)
CREATE TABLE IF NOT EXISTS observations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id              BIGINT UNSIGNED NOT NULL,
  wp_media_id         BIGINT UNSIGNED NOT NULL,
  track_id            BIGINT UNSIGNED NULL,
  spotify_popularity  TINYINT NULL,
  spotify_release_date DATE NULL,
  match_confidence    DECIMAL(4,3) NULL,
  matched_via         ENUM('isrc','artist_title') NULL,
  status              ENUM('ok','auto_picked','no_match','error') NOT NULL DEFAULT 'ok',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (run_id) REFERENCES runs(id),
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id),
  FOREIGN KEY (track_id) REFERENCES tracks(id),
  INDEX idx_run_media (run_id, wp_media_id)
);

-- overrides: sticky manual decisions (future UI)
CREATE TABLE IF NOT EXISTS overrides (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_media_id     BIGINT UNSIGNED NOT NULL,
  spotify_id      VARCHAR(64) NOT NULL,
  reason          VARCHAR(255) NULL,
  chosen_by       VARCHAR(128) NULL,
  chosen_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_media (wp_media_id),
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id)
);

-- rankings: Top 100 per run
CREATE TABLE IF NOT EXISTS rankings (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id          BIGINT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL,   -- 1..100
  wp_media_id     BIGINT UNSIGNED NOT NULL,
  track_id        BIGINT UNSIGNED NOT NULL,
  popularity      TINYINT NOT NULL,
  tiebreak_release_date DATE NULL,
  FOREIGN KEY (run_id) REFERENCES runs(id),
  FOREIGN KEY (wp_media_id) REFERENCES wp_media(id),
  FOREIGN KEY (track_id) REFERENCES tracks(id),
  UNIQUE KEY uniq_run_pos (run_id, position)
);
```

**Migration policy:** installer **adds new fields** with `ALTER TABLE` when needed (never drops data).

---

## 12\) Main Flow (Pseudocode)

1. **Startup & Lock**  
     
   * Create lockfile `/opt/top100/run.lock`.  
   * If lock exists and **age \> 12h**, **break** and continue; else exit.

   

2. **Env & Setup**  
     
   * Load `.env`; set timezone.  
   * Read `wp-config.php` (via `WP_ROOT`) → get WP DB creds (host, db, user, pass).  
   * Ensure `top100` schema exists; run **migrations**.  
   * Open log file `/opt/top100/logs/YYYY-MM.log`.

   

3. **Scheduling Gate (cron mode)**  
     
   * If `--cron` and **today is not last Monday** of the month at 04:00 ET → **exit 0**.

   

4. **Begin Run**  
     
   * Insert `runs` row with `label_month=YYYY-MM`, `started_at=now()`.

   

5. **Scan Uploads**  
     
   * Recursively list `.mp3` files under uploads.  
   * For each file, build/insert **`wp_media`** record (path, URL, checksum optional).  
   * Extract metadata via **getID3** → `artist`, `title`, `isrc`, **duration**.

   

6. **Match to Tracks**  
     
   * If **override** exists → use that Spotify ID.  
   * Else if **ISRC** → search match by ISRC.  
   * Else normalized **artist+title** search; compute **confidence**.  
   * Apply **duration ±5s / ±10s** filter.  
   * If best candidate **score ≥ 0.85** → **auto-pick**; else `no_match`.  
   * Upsert into **`tracks`** (spotify\_id, artist, title, isrc).  
   * Insert **`observations`** row with popularity, release\_date, status.

   

7. **Build Rankings**  
     
   * Filter to **matched** observations for the run.  
   * Sort by popularity desc → tiebreakers (release\_date, mtime, alpha).  
   * Write **Top 100** into `rankings` (positions 1..100).  
   * Write **CSV** with the specified columns **(UTF-8 BOM, CRLF)**.

   

8. **Email**  
     
   * Build summary stats (processed, matched, unmatched, avg popularity).  
   * Attach CSV; send via **PHPMailer (Gmail OAuth2)**.

   

9. **Finish**  
     
   * Update `runs.finished_at=now()`.  
   * Prune old CSVs/logs older than **24 months**.  
   * Remove lockfile.

   

10. **Failure Handling**  
      
    * On fatal error: log; send **failure email** with last 200 log lines.

---

## 13\) CLI Options

```
php Top100Rankings.php [--cron] [--run-now] [--month=YYYY-MM] [--dry-run] [--verbose] [--limit=N]

--cron        Run in cron mode; exit unless last Monday 04:00 ET.
--run-now     Bypass schedule gate and run immediately.
--month=..    Force label month (YYYY-MM); default = current month at runtime.
--dry-run     Do everything except DB writes and sending email/CSV.
--verbose     Detailed logging (every match attempt, API calls, scores).
--limit=N     Process only N files (debugging).
```

---

## 14\) Installer (`install.php`) — One Command

**What it does:**

1. **Checks** PHP version (≥8.0) and required extensions (`curl`, `openssl`, `mbstring`, `json`, `pdo_mysql`, `iconv`).  
2. **Creates** `/opt/top100/{reports,logs,config,scripts}` with proper perms (owned by web user).  
3. **Writes** `.env` from `config/.env.example` (prompts or uses existing).  
4. **Reads** `WP_ROOT/wp-config.php` → extracts WP DB creds.  
5. **Creates** schema `top100` (if missing) and **runs migrations**.  
6. **Installs Composer deps** (or verifies installed).  
7. **Echoes** manual cron line (but **doesn’t** install it).

**Manual steps (for troubleshooting):**

* Verify PHP modules: `php -m | egrep 'curl|openssl|mbstring|pdo_mysql'`  
    
* Create base dirs:

```
sudo mkdir -p /opt/top100/{reports,logs,config,scripts}
sudo chown -R www-data:www-data /opt/top100
sudo chmod -R 750 /opt/top100
```

* Copy `.env.example` → `.env` and fill values.  
    
* Composer install.  
    
* Ensure `WP_ROOT` is correct (see **.env** reminder).  
    
* Run `php scripts/gmail_oauth_setup.php` to capture **Gmail refresh token** and **save to `.env`**.  
    
* Test: `php Top100Rankings.php --dry-run --verbose --limit=10`

---

## 15\) Gmail OAuth Setup Script (CLI outline)

* Launch local device-code or installed-app flow.  
* Prompt: paste the returned **authorization code**.  
* Exchange code for tokens (access \+ refresh).  
* **Write** `GMAIL_OAUTH_REFRESH_TOKEN` into `.env`.  
* On success: send a **test email** to Adam and log result.

---

## 16\) Security & Ops

* **Secrets:** `.env` chmod **640** (owner web user; group restricted).  
* **Least access:** Uses **existing WP DB creds** only to create/use `top100` schema.  
* **Locking:** `/opt/top100/run.lock`; break if **older than 12h**, then proceed.  
* **Logs:** rotate monthly (`YYYY-MM.log`); include timestamps and levels.  
* **Backups:** include `/opt/top100/reports` in backup set.

---

## 17\) Test Plan

1. **Unit smoke:** run `--dry-run --limit=20 --verbose`; verify matches and logs.  
     
2. **End-to-end (dev):** `--run-now` on a small uploads subset; confirm CSV written, email sent.  
     
3. **Edge cases:**  
     
   * No ISRC, messy titles, “Remastered”, “feat.”  
   * Same popularity, tie-breakers applied.  
   * API rate-limit simulation (throttle).  
   * Network hiccups (retries/backoff).

   

4. **Prod readiness:** update `.env` (`WP_ROOT`\!), run `install.php`, run `--dry-run`, then add **cron**.

---

## 18\) Future Enhancements (deferred)

* Minimal **review UI** for low-confidence matches (writes to `overrides`).  
* **JSON** export alongside CSV for web embed.  
* **Artist reports** over time from `observations`.

---

If this looks good, I can convert it into a **scaffolded codebase** next (with `install.php`, `Top100Rankings.php`, and class stubs), or we can iterate on any section here first.  
