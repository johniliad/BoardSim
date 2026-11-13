-- BoardSim — share tokens
--
-- A share link is a pointer, not a copy. This table holds no score, no dimension
-- and no participant identifier: just the hash of the token, the run ids it
-- exposes, and three timestamps. Revoking a token therefore cannot change what a
-- run says; it only removes access to it.
--
-- The raw token is never stored. Only its SHA-256 is, so a database read cannot
-- reconstruct a working link.
--
-- Expiry is a hard 7 days (content/overlays/retention.yaml,
-- share_ttl_days) and is enforced in code as well as here. bin/purge.php removes
-- dead rows; the WHERE clause below is the schema's own reminder that nothing is
-- truncated.
--
-- Verify before relying on it: this migration, like 001 and 002, has never been
-- run against a live MySQL.

CREATE TABLE IF NOT EXISTS share_tokens (
  token_sha256 CHAR(64)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  run_ids      LONGTEXT    NOT NULL,
  created_at   DATETIME    NOT NULL,
  expires_at   DATETIME    NOT NULL,
  revoked_at   DATETIME    NULL,
  PRIMARY KEY (token_sha256),
  KEY idx_share_expiry (expires_at),
  KEY idx_share_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('003_share_tokens.sql');
