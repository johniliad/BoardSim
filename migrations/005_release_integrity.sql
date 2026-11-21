-- Complete release identity and decision evidence snapshots.
-- Existing rows deliberately remain NULL: the service fails closed rather than
-- guessing which difficulty projection or historical prose applied to them.
ALTER TABLE runs
  ADD COLUMN difficulty_views_version VARCHAR(20) NULL AFTER content_sha256,
  ADD COLUMN difficulty_views_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER difficulty_views_version,
  ADD COLUMN board_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER dimensions;

ALTER TABLE run_events
  ADD COLUMN request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER idempotency_key,
  ADD COLUMN option_label TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER option_id,
  ADD COLUMN effects_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER option_label,
  ADD COLUMN content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER effects_json,
  MODIFY COLUMN recorded_at VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('005_release_integrity.sql');