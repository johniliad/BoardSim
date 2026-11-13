# Migrations and the append-only guarantee

Apply in filename order:

```sql
SOURCE migrations/001_init.sql;
SOURCE migrations/002_events.sql;
SOURCE migrations/003_share_tokens.sql;
```

Each file records itself in `schema_migrations`, so re-running one is harmless and
`bin/migrate.php` (the cron reconciliation job) can tell what is missing. On a host
without shell access use `bin/migrate.php` from cron, or paste `dist/install.sql`
into phpMyAdmin — see [`docs/deploy-cpanel.md`](../docs/deploy-cpanel.md).

## Integrity controls, in order of preference

`01_ARCHITECTURE.md` requires an append-only run log. Shared PHP hosting rarely
grants `TRIGGER` or custom `GRANT`, so there is a ladder. The application always
computes and stores a hash chain, so the third control is always active:

1. **Least privilege (preferred).** The API user must not be able to rewrite
   history:

   ```sql
   GRANT SELECT, INSERT, UPDATE ON iliadis_boardsim.runs         TO 'iliadis_boardsim_api'@'localhost';
   GRANT SELECT, INSERT         ON iliadis_boardsim.run_events   TO 'iliadis_boardsim_api'@'localhost';
   UPDATE iliadis_boardsim.run_events SET option_id = 'A';        -- must fail: no UPDATE
   ```

   Note that `runs` needs `UPDATE` for state transitions, while `run_events`
   must not. That asymmetry is the point.

2. **Guard trigger (if `TRIGGER` is available).**

   ```sql
   DELIMITER //
   CREATE TRIGGER run_events_append_only
   BEFORE UPDATE ON run_events FOR EACH ROW
   BEGIN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'run_events is append-only';
   END//
   CREATE TRIGGER run_events_no_delete
   BEFORE DELETE ON run_events FOR EACH ROW
   BEGIN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'run_events is append-only';
   END//
   DELIMITER ;
   ```

3. **Hash chain (always active).** Each event stores `prev_event_sha256` and
   `event_sha256` over the canonical payload. `RunService::verifyChain()`
   recomputes the chain and reports the first divergence.

## Which control is active is a deployment fact

`GET /v1/health` reports `integrity_controls`. The methodology page must state
the same list, so the claim is never stronger than the deployment.
