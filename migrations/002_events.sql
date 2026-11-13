-- Append-only decision log. The API user holds INSERT and SELECT only; see
-- migrations/README.md for the grants and the guard trigger.
CREATE TABLE IF NOT EXISTS run_events (
  run_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sequence            INT UNSIGNED NOT NULL,
  idempotency_key     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stage_id            CHAR(2) NOT NULL,
  option_id           CHAR(1) NOT NULL,
  rule_ids            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  metric_delta        LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  suppressed_json     LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  visible_fact_ids    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  unknown_fact_ids    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  regulatory_status   LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  pre_state_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  post_state_sha256   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prev_event_sha256   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recorded_at         TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (run_id, sequence),
  -- The idempotency guarantee: one accepted choice per key, per run.
  UNIQUE KEY uq_event_idempotency (run_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Board pack: structured selections only, so scoring stays deterministic.
CREATE TABLE IF NOT EXISTS run_board (
  run_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  field_key     VARCHAR(24) NOT NULL,
  option_id     VARCHAR(32) NOT NULL,
  substantiated TINYINT(1) NOT NULL,
  recorded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Free-form prose lives apart from the scoring chain so it can be deleted
-- without touching the hash chain (02_SCORING_MODEL.md: prose is never graded).
CREATE TABLE IF NOT EXISTS run_board_prose (
  run_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  field_key   VARCHAR(24) NOT NULL,
  prose       TEXT NULL,
  retain      TINYINT(1) NOT NULL DEFAULT 0,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id, field_key),
  CONSTRAINT fk_prose_run FOREIGN KEY (run_id) REFERENCES runs (run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('002_events.sql');
