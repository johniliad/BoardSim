# Migrations and the append-only guarantee

Apply in filename order:

```sql
SOURCE migrations/001_init.sql;
SOURCE migrations/002_events.sql;
SOURCE migrations/003_share_tokens.sql;
SOURCE migrations/004_rate_limits.sql;
SOURCE migrations/005_release_integrity.sql;
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
   event history, but it must be able to physically erase a whole run:

   ```sql
   GRANT SELECT, INSERT, UPDATE ON iliadis_boardsim.runs         TO 'iliadis_boardsim_api'@'localhost';
   GRANT SELECT, INSERT, DELETE ON iliadis_boardsim.run_events   TO 'iliadis_boardsim_api'@'localhost';
   UPDATE iliadis_boardsim.run_events SET option_id = 'A';        -- must fail: no UPDATE
   ```

   Note that `runs` needs `UPDATE` for state transitions, while `run_events`
   deliberately does not. `DELETE` exists only for `DELETE /v1/runs/{id}` and
   retention purge, both of which remove the entire run and its complete event
   chain. The API has no operation that deletes an individual event.

2. **No-update guard trigger (if `TRIGGER` is available).**

   ```sql
   DELIMITER //
   DROP TRIGGER IF EXISTS run_events_no_delete//
   DROP TRIGGER IF EXISTS run_events_append_only//
   CREATE TRIGGER run_events_no_update
   BEFORE UPDATE ON run_events FOR EACH ROW
   BEGIN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'run_events cannot be updated';
   END//
   DELIMITER ;
   ```

   Do **not** install a `BEFORE DELETE` trigger on `run_events`: it blocks the
   required physical-erasure and retention paths. The two `DROP` statements also
   repair a deployment that followed the earlier no-delete guidance.

3. **Hash chain (always active).** Each event stores `prev_event_sha256` and
   `event_sha256` over the canonical payload. `RunService::verifyChain()`
   recomputes the chain and reports the first divergence.

## Which control is active is a deployment fact

`GET /v1/health` reports `integrity_controls`. The methodology page must state
the same list, so the claim is never stronger than the deployment.
