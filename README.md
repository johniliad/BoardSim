# BoardSim — implementation workspace

**Executive Decision-Making Under Pressure.** BoardSim is a board-level executive
cybersecurity decision simulator with a deterministic, server-authoritative engine.

The 29 Markdown files in `requirements/`, `scenarios/` and `engine/` are the
specification and are **SHA-256 pinned and immutable** (28 design documents plus
`10_OPEN_SOURCE.md`, the repository-hygiene requirement). Nothing here edits them;
every addition is either code or a versioned overlay.

Line endings are pinned too: `.gitattributes` normalises every text file to LF,
because a CRLF checkout on Windows would change the bytes and break verification
on a fresh clone.

## Current state

| Layer | Status |
|---|---|
| Pinned specification (29 files) | Verified, 29/29 hashes match |
| YAML allowlist parser | Done — parses all 14 scenarios, both policy blocks, flow collections |
| Content overlay layer (F-2 / F-1) | Done — bundle `1.1.0`, fail-closed |
| Regulatory evaluator, Kleene logic, clock engine | Done — 67 invariants green |
| Layer 2 facts | Done — all 14 scenarios, all five statuses exercised |
| **Scoring engine (Stage 2)** | **Done — both published goldens reproduce exactly** |
| **Board-pack rubric** | **Done — 7 fields, DR-041..DR-047 gated on substantiation** |
| **Enumeration (Stage 3)** | **Done — 2,268 runs reproduce min 58 / max 84 / mean 71.15** |
| **Calibration (ruleset 1.1.0)** | **Done — positional bias −35%, all five score labels reachable** |
| **Content v1.1.0 (option remaps)** | **Done — 2 mis-mappings corrected; dominance register published** |
| **Content 1.3.0 (executive wording)** | **Done — all 14 scenarios rewritten to an executive register (BLUF, board-level asks); mappings, effects and scores unchanged; 28/42 dominant re-confirmed** |
| **Content 1.2.0 (dominance)** | **Done — 3 narrow gaps closed, 31→28 dominant; mean 70.78/70.44, all five bands reachable** |
| **API + MySQL store (Stage 4)** | **Done — 99 invariants green; MySQL adapter unverified on host** |
| **SPA (Stage 5)** | **Done — builds, serves same-origin, full run verified over HTTP** |
| **Difficulty views (F-5)** | **Done — leadership 7 fields, executive 4, board_crisis refused with 422** |
| **Enumeration baselines** | **Done — leadership and executive measured separately, never merged** |
| **Portfolio aggregation** | **Done — 3–5 run window, segmented by ruleset, content and difficulty** |
| **"What would change it?" (05)** | **Done — alternative substituted and replayed, identity-checked against the recorded score** |
| **Security headers + CSP** | **Done — one policy, Apache mirror, drift-tested** |
| **In-product glossary** | **Done — `/v1/glossary` derived from the pinned sources, drift-tested; 47 rules, 28 instruments, 10 dimensions** |
| **NIST CSF function per rule** | **Done — authored overlay, all 47 rules mapped, surfaced in the glossary** |
| **Methodology page** | **Done — `#/methodology` renders the live formula, weights, appetite modifiers and bands** |
| **Rate limiting** | **Done — per-client fixed window on run creation, decisions, shares and reads; 429 with `Retry-After`** |
| **Accessibility measurement** | **Done — contrast and target sizes computed and asserted, not asserted** |
| **Static metadata and discovery** | **Done — author/canonical/Open Graph/JSON-LD `WebApplication` in the served HTML, plus `robots.txt`, stamped `sitemap.xml` and `llms.txt` at the origin root** |
| **Acceptance traceability (07)** | **Done — 41 requirements mapped, gaps named: `docs/acceptance-matrix.md`** |
| **Public demo (06)** | **Done — `DEMO-001`, unscored and unpersisted; the scorer is never constructed** |
| **Board room (06)** | **Done — 3 challenge cards, advisory 5-minute timer, no 8th field** |
| **Erasure (06)** | **Done — model A: physical delete, self-service, irreversible; consent v3 drafted** |
| **Profiles + share links (06)** | **Done — 7-day hashed token, revocation, read-only shared view, days-left counter** |
| Deployment target | PHP 8.2+ / MySQL on `boardsim.iliadis.net` |

## Running the app locally (no MySQL needed)

```bash
cd web && npm install && npm run build && cd ..
php bin/dev.php                  # http://127.0.0.1:8100
```

`bin/dev.php` starts PHP's built-in server with the router script and
`BOARDSIM_DEV=1` already set, because those are the two arguments it is easy to
forget by hand. The equivalent manual command is:

```bash
BOARDSIM_DEV=1 php -S 127.0.0.1:8100 -t public web/dev-router.php
```

`BOARDSIM_DEV=1` swaps the MySQL store for `FileRunStore` (a JSON file under
`var/`) and reports `mode=dev` on `/v1/health`. It exists only so the front end
can be developed without a database; never enable it on a public host.

For front-end hot reload, run `npm run dev` in `web/` (Vite proxies `/v1` to the
PHP server on port 8100, so start that server first).

The router argument (`web/dev-router.php`) is mandatory: PHP's built-in server
ignores `.htaccess`, so without it every `/v1/...` request is answered by PHP's
own HTML 404 page. The SPA still renders, but the footer reads "Scoring policy
unavailable" and the API calls report `NON_JSON_RESPONSE`. Similarly, opening the
built `public/app/index.html` through a plain static file server (or through a
server rooted at `public/app`) serves the SPA's HTML for `/v1/...` and fails the
same way. The interface and the API must be served from one origin by PHP.

Three artefacts are pinned per run and never merged:

| Pin | 1.0.0 | Latest | Contents |
|---|---|---|---|
| Regulatory bundle | `4124f451…` | unchanged | instruments, predicates, facts, guards |
| Scoring policy | `dd533b45…` | `b2135b59…` | rules, weights, appetite, board rubric, ladder |
| Scenario content | pinned option graph | option remaps | scenario versions + mapping corrections |

A run pins all three, so it replays against exactly the policy that scored it.
`load('1.0.0')` and `PinnedScenarios($root, '1.0.0')` remain available forever.

Calibration evidence lives in [`docs/calibration-findings.md`](docs/calibration-findings.md)
and the required re-score report in
[`docs/rescore-report-1.0.0-to-1.1.0.md`](docs/rescore-report-1.0.0-to-1.1.0.md).

Per-difficulty enumeration baselines and the portfolio-aggregation rules are in
[`docs/difficulty-baselines.md`](docs/difficulty-baselines.md). The short version:
executive drops three board rules, so its pre-rounding numerator is exactly 66
hundredths lower on the same path — which the integer clamp usually absorbs, so the
two views must never be merged into one profile.

## Commands

```bash
php bin/dev.php                  # local server with router + dev store
php bin/build-deploy.php --version=1.2.0   # two upload folders + zips (host needs no shell)
php bin/migrate.php --check      # schema + pinned-bundle reconciliation (run from cron)
php bin/verify-hygiene.php       # no host paths committed
php bin/verify-manifest.php      # re-hash the 29 pinned files
php bin/verify-lint.php          # syntax-check every PHP file, including the MySQL adapters
php tools/smoke-yaml.php         # parse every pinned scenario YAML block
php tools/smoke-policy.php       # parse CONFIG.md + 03_DECISION_RULES.md
php bin/content-build.php        # compile and write the regulatory bundle
php bin/verify-regulatory.php    # F-2 / F-1 invariants (67)
php bin/verify-scoring.php       # Stage 2 arithmetic + guard chain (51)
php bin/enumerate.php            # Stage 3: 2,268 runs + determinism replay
php bin/enumerate.php --difficulty=executive   # its own baseline, never merged
php bin/enumerate.php --ruleset=1.0.0   # pin the historical baseline
php bin/rescore.php 1.0.0        # re-score report: old vs new policy
php tools/dominance-report.php   # per-stage dominance with option labels
php bin/verify-api.php           # lifecycle, idempotency, chain, profiles
php bin/verify-glossary.php      # glossary covers every dimension, rule, instrument, setting
php bin/verify-ratelimit.php     # rate-limit decisions per client, action and window
php bin/verify-meta.php          # static metadata: title, canonical, OG, JSON-LD, robots, llms.txt
php bin/verify-contrast.php      # WCAG contrast ratios and target sizes, measured
php bin/verify-http.php          # HTTP contract for the SPA
php bin/verify-deploy.php --base=https://boardsim.iliadis.net   # a live origin
php bin/verify-refs.php          # resolve every official source URL (host only)
php bin/purge.php --dry-run      # retention: show the 7-day cutoff, delete nothing
php bin/purge.php                # retention: physically delete runs past the ceiling
php bin/governance-report.php    # regenerate the per-REG-xxx sign-off register
```

### Authoring aids

Not part of the check suite and never run by the application: they read the
pinned corpus and print, so a content change can be reasoned about before it is
made.

```bash
php tools/rule-usage.php                 # which stage-option position uses which rule
php tools/narrow-stage.php BS-002 S1     # which third rule would close a dominance gap
php tools/dump-scenario.php [BS-00X]     # decision-relevant content of each scenario
php tools/dump-org.php                   # organisation profile per scenario
```

## Deploying

Two supported shapes, same server:

- **No shell on the host** (cPanel, FTP, phpMyAdmin, cron): follow
  [`docs/deploy-cpanel.md`](docs/deploy-cpanel.md). `php bin/build-deploy.php`
  produces the two folders to upload — the document root (`.htaccess`, `app/`,
  `api/`) and the backend outside it — plus `install.sql`, `cron.txt` and
  `verify.txt`. Nothing runs on the host except the three cron jobs, and
  `GET /v1/health` reports the release, the schema and the seeded bundles.
- **Shell on the host**: [`DEPLOYMENT.md`](DEPLOYMENT.md) — document root,
  database setup, least-privilege grants, where credentials live, cron.

Host-specific paths belong in `deploy/host.config.php` (gitignored); the committed
`deploy/host.config.example.php` carries placeholders and the build refuses to run
until they are replaced. `php bin/verify-hygiene.php` fails if a real host path
ever reaches the repository.

## Applying the schema

```sql
SOURCE migrations/001_init.sql;
SOURCE migrations/002_events.sql;
SOURCE migrations/003_share_tokens.sql;
```

Then follow `migrations/README.md` for the least-privilege grants and the optional
append-only trigger. `GET /v1/health` reports which integrity controls are active,
so the methodology page never claims more than the deployment provides.

## License

BoardSim is Apache-2.0 — see [`LICENSE`](LICENSE). The PHP engine has no
third-party runtime dependencies; the web build embeds React (MIT) and uses
MIT/ISC/BSD/CC/Apache-2.0 build tooling. Attributions and the reasoning for the
absence of a `NOTICE` file are in [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

## Repository hygiene

This repository is public, so three files carry real weight:

- **`.gitignore`** excludes credentials (`.env*`, `*.pem`, `*.key`, `*.p12`, SSH
  keys, `.npmrc`, `config.local.php`, `deploy/host.config.php`), database files
  and dumps, archives, `vendor/`, `node_modules/`, build output, coverage, agent
  and editor state, local notes (`*.local.*`) and Office exports. `package-lock.json`
  and `content/bundles/` are deliberately tracked. Nothing in the repository
  needs a secret at build time.
- **`.gitattributes`** normalises every text file to LF. This is not cosmetic:
  the specification is SHA-256 pinned, and a CRLF checkout on Windows would
  change the bytes and make `bin/verify-manifest.php` fail on a fresh clone.
- **`bin/verify-hygiene.php`** enforces both lists in the other direction: it
  fails if anything tracked matches the never-publish set (build output, host
  config, archives, credentials, editor state) and fails if a file a fresh clone
  needs — `.env.example`, `deploy/host.config.example.php`, the lockfile,
  `public/robots.txt`, `public/sitemap.xml`, `public/llms.txt`, the CI workflow —
  is **not** tracked. A publish is therefore checked, not trusted.

### Discovery and metadata

The SPA is client-rendered, so everything a crawler, a social-preview bot or a
language model needs is written into the served HTML by hand, not injected at
runtime: exact title, description, `author` meta and link, canonical URL,
Open Graph and Twitter tags, and a schema.org `WebApplication` graph. Three
static files sit at the origin root — `robots.txt`, `sitemap.xml` (lastmod
stamped by `bin/build-deploy.php`) and `llms.txt` (the llmstxt.org shape: what the
product is, its endpoints, and what it is not). `bin/verify-meta.php` asserts the
whole set in source, in the build output and in the routing rules;
`bin/verify-deploy.php` re-asserts it against the live origin.

### Licence

**Apache License 2.0** — see [`LICENSE`](LICENSE). Copyright 2026 Dr. John Iliadis.

Note that the licence covers the code and the authored overlays. The regulatory
material referenced in `requirements/04_REGULATORY_REGISTER.md` and the pinned
scenario documents remain subject to their own sources; nothing here re-licenses
third-party legal text.

No runtime dependencies: `src/bootstrap.php` is a zero-dependency PSR-4
autoloader, because the production host offers PHP and MySQL only. Node is a
dev-time tool for the front-end build, never a runtime requirement.

## Architecture

```
requirements/ scenarios/ engine/   PINNED specification (never edited)
content/overlays/                  authored layer, versioned
  predicate_vocabulary.yaml        Layer 0  closed predicate vocabulary
  regulatory_instruments.yaml      Layer 1  28 REG-* anchors, defined once
  scenario_regulatory_facts.yaml   Layer 2  REGULATORY_ASSERTION facts
  rule_guards.yaml                 Layer 3  DR-027 guard bindings
  README.md                        divergence record and legal posture notes
content/bundles/                   compiled, hashed, immutable artefacts
src/Content/                       YAML parser, loader, canonical JSON
src/Domain/Regulation/             TriBool, predicates, clocks, evaluator, guards
bin/                               CLI entry points
tools/                             developer checks
```

### Why the engine is PHP

`01_ARCHITECTURE.md` requires the server to own scoring. The host runs PHP and
MySQL, so the engine is implemented once in PHP rather than duplicated in
TypeScript. The SPA is a pure renderer that never computes a score and never
receives score deltas.
