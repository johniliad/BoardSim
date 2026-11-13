# Deployment

Target: `boardsim.iliadis.net` — Apache 2.4, PHP 8.2+ with `pdo_mysql`, MySQL 8,
always HTTPS.

> **Deploying to a host without shell access?** Use
> [`docs/deploy-cpanel.md`](docs/deploy-cpanel.md) and `bin/build-deploy.php`:
> two folders, one config file, three cron entries, and no command ever run on the
> host. `/v1/health` reports the deployment state (release, schema, seeded
> bundles) without an installer, and `bin/migrate.php` reconciles the schema from
> cron. This document describes the same server contract with the manual steps.

## Directory layout

| Path | Contents |
| --- | --- |
| Apache document root (wherever the host puts it) | a copy of the repo's `public/`: the built SPA (`app/`), `.htaccess`, and `api/index.php` + `api/backend.php` installed from the shim below |
| `~/boardsim-secrets/backend/` | the full repository checkout — `src/`, `content/`, `bin/`, `migrations/`, `web/`, `config.local.php`, `public/` |
| `~/boardsim-secrets/boardsim.env` | the credentials file, and nothing else |

The document root is Apache's, **not** inside the backend checkout, so the API
front controller is installed as a shim (section 2). Everything that must not be
web-addressable — `src/`, `content/`, `bin/`, `migrations/`, `requirements/`,
`web/` — lives under `~/boardsim-secrets/`.

## 1. Prepare the host

Prerequisite modules (`.htaccess` depends on them): `mod_rewrite`, `mod_headers`,
`AllowOverride All` for the vhost, and PHP `pdo_mysql`. No `SetEnv` is required —
credentials resolution does not depend on server configuration.

## 2. Build the SPA and install the web tree

Clone the repository to `~/boardsim-secrets/backend/`, then build the SPA inside it:

```bash
cd ~/boardsim-secrets/backend/web && npm ci && npm run build
```

Deploy the **whole checkout**, not a subset: at request time the engine parses
`engine/CONFIG.md`, `requirements/03_DECISION_RULES.md`, every
`scenarios/BS-*.md`, and all of `content/`. Copying only `src/` leaves the API
answering 500 on every call.

This writes `public/app/`, the only asset path the SPA requests; without it the
site serves a blank shell.

### Install the web tree into Apache's document root

The document root is wherever Apache is configured to serve it, and it must hold
exactly these things. Assemble them from the checkout instead of copying by hand,
so nothing is missed:

```bash
php bin/build-deploy.php --version=1.2.0     # writes build/deploy/<version>/
# .../docroot/app/             the built SPA, with the metadata in its shell
# .../docroot/.htaccess        routing, security headers, dotfile denial
# .../docroot/api/index.php    the shim
# .../docroot/api/backend.php  the pointer to the backend
# .../docroot/robots.txt       crawler policy, points at the sitemap
# .../docroot/sitemap.xml      the one canonical URL, lastmod stamped at build
# .../docroot/llms.txt         what the product is, its API and its limits
```

The three discovery files must sit at the **origin root**, not inside `app/`:
`robots.txt` and `sitemap.xml` are only honoured there, and `llms.txt` is read at
`/llms.txt` by convention. They are static files, so a crawler or a language model
reads them without executing the SPA.

Upload the **contents** of the `docroot/` directory to the document root,
replacing the previous SPA build. Nothing is hand-edited: the pointer is
generated, and the same build produces the `backend/` tree that goes beside
`public_html` (see [`docs/deploy-cpanel.md`](docs/deploy-cpanel.md)).

The same three items, by hand:

1. `public/app/` — the built SPA (create `app/` at the document root and copy the
   built contents into it).
2. `public/.htaccess` — routing, security headers, dotfile denial.
3. `api/index.php` — **the shim, not the real controller**:

```bash
mkdir -p "<document root>/api"
cp deploy/api-index-shim.php "<document root>/api/index.php"
```

Then point the shim at the backend with a pointer file beside it:

```bash
printf "<?php return '/home/YOURUSER/boardsim-secrets/backend';\n" > "<document root>/api/backend.php"
```

The shim answers `SHIM_NOT_CONFIGURED` when no pointer exists and
`BACKEND_NOT_FOUND` when the path does not resolve — both as JSON that lists the
paths it tried and the effective `open_basedir`, so a wrong path is a readable
diagnosis rather than a blank 500. The pointer holds a filesystem path, not a
secret, and `public/.htaccess` denies it over HTTP.

Do **not** create an `index.php` at the document root. `/` is served by the SPA
fallback in `public/.htaccess`; a stray PHP front controller there takes the root
path (and, depending on its contents, every path) away from the SPA.

A document root missing `api/index.php` is the failure that reaches the browser as
"Scoring policy unavailable" plus `Unexpected token '<', "<!doctype "... is not
valid JSON`: `/v1` falls through to the SPA fallback and is answered with
`index.html`. Diagnosis table in section 6.

`public/` remains inside the backend checkout (it is a normal part of the
repository); the document root only ever holds the three copies above. The real
front controller is written to fail loudly and tell you how to fix it if it is
ever copied straight into the document root instead of installed via the shim.

## 3. Database

Create the database and a least-privilege user, then apply the migrations in
filename order:

```sql
SOURCE migrations/001_init.sql;
SOURCE migrations/002_events.sql;
SOURCE migrations/003_share_tokens.sql;
```

Extend the grants from `migrations/README.md`, because the app also erases and
shares:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON iliadis_boardsim.runs         TO 'iliadis_boardsim_api'@'localhost';
GRANT SELECT, INSERT, DELETE         ON iliadis_boardsim.run_events   TO 'iliadis_boardsim_api'@'localhost';
GRANT SELECT, INSERT, DELETE         ON iliadis_boardsim.run_board    TO 'iliadis_boardsim_api'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON iliadis_boardsim.share_tokens TO 'iliadis_boardsim_api'@'localhost';
GRANT SELECT, INSERT                ON iliadis_boardsim.content_bundles TO 'iliadis_boardsim_api'@'localhost';
```

`run_events` gets `DELETE` only so whole-run erasure can remove it, never
`UPDATE` — the append-only guarantee stands. The `TRIGGER` guard in
`migrations/README.md` is still preferred where the host grants it; the hash chain
is always active regardless.

## 4. Credentials — pointer in the backend, secret in the secrets directory

The web front controller and the cron job both call
`BoardSim\Infrastructure\EnvFile::bootstrap()`, which resolves credentials in this
order:

1. `BOARDSIM_ENV_FILE`, if the process happens to set it;
2. otherwise `~/boardsim-secrets/backend/config.local.php`, which is a **pointer** —
   it returns only the path to the secrets file and holds no secret itself.

Create `~/boardsim-secrets/backend/config.local.php` from the committed template:

```bash
cp config.local.example.php config.local.php
```

Edit the one line:

```php
return [
    'secrets_file' => '/home/USER/boardsim-secrets/boardsim.env',
];
```

Create `~/boardsim-secrets/boardsim.env` (from `.env.example`), mode 600, owned by
the service account:

```
BOARDSIM_DSN=mysql:host=localhost;dbname=iliadis_boardsim;charset=utf8mb4
BOARDSIM_USER=iliadis_boardsim_api
BOARDSIM_PASS=…               # a real, non-empty password
```

`BOARDSIM_DEV=1` is absent in production; it switches both entry points to
on-disk stores and must never be set on the public host.

Why this satisfies the constraints:

- **No `SetEnv`.** The web app finds the secrets through `config.local.php`, which
  needs no Apache configuration. A value already present in the process
  environment still wins, so an orchestrator can inject secrets without disk.
- **One copy of the secret.** The pointer and the cron job resolve the same file,
  so a credential is not duplicated between two mechanisms.
- **Not web-addressable.** The secrets live outside `public/` — a misconfigured
  server cannot serve them. The pointer file is a path string, not a secret.

## 5. Cron — the retention job

The 7-day retention and share expiry are only enforced while this runs:

```cron
15 3 * * *  /usr/bin/php /home/USER/boardsim-secrets/backend/bin/purge.php >> /var/log/boardsim-purge.log 2>&1
```

`purge.php` reads the same `config.local.php` pointer, so no credentials appear in
the crontab. Run it once by hand first (`php bin/purge.php --dry-run`) to confirm
it resolves the database.

## 6. Post-deploy checks

```bash
php bin/verify-deploy.php --base=https://boardsim.iliadis.net
```

Read-only, exit 0 only on a clean pass. It asserts that `/v1` is answered by PHP
and not by the SPA fallback, that the health payload pins every artefact and is
not in dev mode, that an unknown `/v1` route answers JSON 404, that the deployed
bundle is the one built from this checkout, that the security headers are in
force and that dotfiles are denied. It also asserts that `/v1/glossary` serves the
derived glossary — a deployment whose help text cannot load is a deployment where
every code in the interface is unexplained. It is safe to point at production.

Two flags make it a deployment gate rather than a diagnostic:
`--require-ready` fails when `/v1/health` reports `status: degraded` (with the
hint that says why), and `--expect-release=<sha>` fails when the origin is not
running the tree that was built. `bin/build-deploy.php` writes both values into
`verify.txt`.

Then by hand: complete a scenario to a score, check `GET /v1/runs/{id}/explain`
returns `"chain": {"ok": true}`, delete it and confirm a second `GET` is 404, and
re-run `php bin/governance-report.php` so the sign-off register reflects this
deployment.

### When the interface reports "Scoring policy unavailable" or `Unexpected token '<'`

Both mean the browser asked `/v1` for JSON and received an HTML page. Work down
this table; every row names the evidence `curl` will show.

| Evidence | Cause | Fix |
| --- | --- | --- |
| `curl -s https://host/v1/health` returns `200 text/html`, byte-identical to `/app/` | `api/index.php` is not in the document root, so the rewrite falls through to the SPA | Install the shim (section 2) |
| `/v1/health` returns HTTP 503 | Same, and `public/.htaccess` is the current one: it refuses to answer `/v1` with the SPA | Install the shim |
| `GET /` returns 500 with an empty body while `/app/` works | A stray `index.php` at the document root — often the shim copied as `index.php` instead of `api/index.php` | Delete it; `/` belongs to the SPA fallback |
| `/v1/health` returns JSON `SHIM_NOT_CONFIGURED` | The shim is installed but `api/backend.php` is missing | Create it with the absolute backend path |
| `/v1/health` returns JSON `BACKEND_NOT_FOUND` | The pointer does not resolve — wrong path, or `open_basedir` blocks it | Fix the path; the response lists the paths tried and the effective `open_basedir` |
| `/v1/health` returns JSON `BOOTSTRAP_FAILED` | The database or `config.local.php` cannot be resolved | Sections 3 and 4 |
| `/app/` loads an asset hash different from `public/app/index.html` in the checkout | A stale build was uploaded | `cd web && npm run build`, re-upload `public/app/` |
| Everything above passes but the browser still fails | The browser cached the old bundle | Hard reload, or check the hash in the footer/network panel |

`public/.htaccess` deliberately cannot answer `/v1` with `index.html`: a missing
shim produces a 503, so this class of misconfiguration is visible to `curl` and
to `bin/verify-deploy.php` instead of only in the browser console.


## 7. Still false until done on the host

- The MySQL adapter and all three migrations have never run against a live server.
- There is no server-side login: possessing a run id or share token *is* the
  capability (documented in `docs/profiles-and-retention.md`, not an omission).
- HSTS activates only once requests arrive over HTTPS, which the vhost must
  terminate.
- The backup rotation period (7 days) is stated in the consent; the deployment
  must not silently add monthly vaulted copies without revising that sentence.