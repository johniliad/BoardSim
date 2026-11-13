-- Rate limiting for the public API. One row per (hashed client key, action,
-- window). The key is a SHA-256 digest of the client identity, never the address
-- itself, and rows older than the window are swept by the limiter, so the table
-- holds at most a minute of active clients rather than a history of traffic.
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits         INT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket_key),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('004_rate_limits.sql');
