-- BoardSim — initial schema (MySQL 8.0+)
--
-- Design constraints, each of which is load-bearing:
--
--  1. Canonical JSON is NEVER stored in a MySQL JSON column. MySQL reorders and
--     normalises JSON keys, which would silently change every stored hash and
--     break replay. Payloads live in LONGTEXT with a binary collation.
--
--  2. Every hash column is ascii + ascii_bin. The default utf8mb4_0900_ai_ci
--     collation is case- and accent-insensitive, so a tamper check comparing
--     hashes under it could treat two different digests as equal.
--
--  3. Event immutability is enforced by denying UPDATE and, where available, an
--     UPDATE guard trigger. Whole-run DELETE remains permitted for physical
--     erasure. See migrations/README.md for the privilege statements.
--
--  4. This historical base migration is left structurally unchanged. Later
--     release-integrity columns are added by 005_release_integrity.sql, so a fresh
--     install and an upgraded install follow the same ordered migration path.

CREATE TABLE IF NOT EXISTS content_bundles (
  bundle_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  bundle_kind           ENUM('REGULATORY','SCORING','SCENARIO_CONTENT') NOT NULL,
  version               VARCHAR(20) NOT NULL,
  canonical_payload     LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (bundle_sha256),
  KEY idx_bundle_kind (bundle_kind, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS runs (
  run_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scenario_id             VARCHAR(10) NOT NULL,
  scenario_version        VARCHAR(20) NOT NULL,
  ruleset_version         VARCHAR(20) NOT NULL,
  policy_sha256           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  regulatory_bundle_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content_version         VARCHAR(20) NOT NULL,
  content_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  difficulty              ENUM('executive','leadership','board_crisis') NOT NULL,
  risk_appetite           ENUM('conservative','balanced','aggressive') NOT NULL,
  board_persona           ENUM('risk_conservative','growth','financial','regulatory','balanced') NOT NULL,
  date_of_simulation      DATE NOT NULL,
  state                   ENUM('CREATED','PROFILE_LOCKED',
                               'STAGE_1_OPEN','STAGE_1_LOCKED',
                               'STAGE_2_OPEN','STAGE_2_LOCKED',
                               'STAGE_3_OPEN','STAGE_3_LOCKED',
                               'BOARD_OPEN','BOARD_LOCKED',
                               'SCORED','FINALIZED') NOT NULL,
  current_stage           CHAR(2) NULL,
  state_sha256            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  score                   SMALLINT UNSIGNED NULL,
  dimensions              LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalized_at            TIMESTAMP NULL,
  PRIMARY KEY (run_id),
  KEY idx_runs_scenario (scenario_id),
  KEY idx_runs_policy (ruleset_version, policy_sha256),
  CONSTRAINT fk_runs_policy FOREIGN KEY (policy_sha256)
    REFERENCES content_bundles (bundle_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Migration bookkeeping. Created here, not in 002, so that every migration can
-- record itself and a deployment with no shell can answer "what has been
-- applied?" from GET /v1/health and bin/migrate.php. INSERT IGNORE makes the
-- whole file safe to re-run.
CREATE TABLE IF NOT EXISTS schema_migrations (
  migration  VARCHAR(64) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('001_init.sql');
