# Cloudflare origin lock (always on)

**Requirement:** REQ-CF-01 · **Status:** always on · **Enforced by:** `public/.htaccess`, `bin/build-deploy.php`, `bin/verify-deploy.php` · **Host prerequisite:** `mod_rewrite`, `AllowOverride FileInfo`

The origin — the Apache document root that serves `boardsim.iliadis.net` — answers **only** requests that Cloudflare forwarded. Cloudflare marks every forwarded request with a secret header; Apache refuses everything else with `403`. The rule is not optional, not environment-specific, and not something a build may drop.

Both ways of getting this wrong are silent, which is why the build and the checks below exist:

| If the rule is missing | If the rule ships with the placeholder |
|---|---|
| The site works perfectly — including for a client that resolved the origin address and skipped Cloudflare entirely: no WAF, no bot rules, no cache, no edge rate limiting. | The site is unreachable: Cloudflare's value never equals `__CF_ORIGIN_SECRET__`, so every request is refused. A browser shows a bare 403 that names no cause. |

## 1. The rule

`public/.htaccess`, first rule inside the rewrite block:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On

  RewriteCond %{HTTP:X-Origin-Secret} !^__CF_ORIGIN_SECRET__$
  RewriteRule ^ - [F,L]
```

`public/.htaccess` is the only web-served `.htaccess` in this project: `deploy/backend.htaccess` is copied to the backend directory **outside** the document root and denies everything, so it needs no lock of its own.

Details that are deliberate, not stylistic:

- **First rule in the file.** A request that did not come through Cloudflare must not reach `/v1`, a static file or the SPA shell, and no later rewrite may answer it `200`.
- **Inside `<IfModule mod_rewrite.c>`, with the routing.** There is no "site works but unguarded" state to worry about: without `mod_rewrite` (or without `AllowOverride FileInfo`) the whole block is skipped, so `/v1` stops answering and the SPA stops being served. The lock and the routing fail together. A second enforcement mechanism was therefore not added — it would only add a way to be wrong.
- **`403`, not `404`.** Someone who bypasses Cloudflare should see a refusal, not a convincing "not found".
- **`!^…$`, not a substring test.** The comparison is anchored, so a value that merely contains the secret is refused.
- **`__CF_ORIGIN_SECRET__` is a build placeholder.** The committed file never carries the value; see section 3.

### 1a. The one exemption: certificate validation

A certificate authority validates a domain by fetching a token **from the origin
address**, not through Cloudflare, so an ACME or DCV request can never carry
`X-Origin-Secret`. Enforcing the lock on those paths would break renewal about
thirty days before anyone noticed. Two rules sit *before* the lock and stop the
ruleset:

```apache
RewriteRule ^\.well-known(/|$) - [L]
RewriteRule ^pki-validation(/|$) - [L]
```

`[L]` with no substitution means "serve whatever is there", so a real token is
served and a missing one is a plain 404 — never the SPA shell answering 200 to a
CA.

- `/.well-known/acme-challenge/` — Let's Encrypt, certbot, acme.sh
- `/.well-known/pki-validation/` — cPanel AutoSSL and Sectigo DCV
- `/pki-validation/` — the older cPanel layout, kept because AutoSSL in the wild
  still uses it

This is the **only** exemption, and it is narrow on purpose: those directories
hold single-use challenge tokens written by the account's own certificate tooling,
not uploads. The order matters and is asserted: `bin/verify-http.php` fails if the
exemption is missing or if it appears *after* the lock (where it would exempt
nothing).

## 2. Cloudflare side (one-time, then untouched)

1. **DNS**: every record for the host is **proxied** (orange cloud). A DNS-only record pointing at the same address is a complete bypass, so none may exist — including `www`, `direct`, `dev`, or any leftover subdomain on the same IP. Test one after enabling the lock: it answers `403`, which is the correct answer.
2. **Rules → Transform Rules → Modify Request Header → Create rule**
   - When: `Hostname equals boardsim.iliadis.net` (or "All incoming requests" in a single-site zone)
   - Then: **Set static** `X-Origin-Secret` = the secret
   - **Set**, never **Add**. "Set static" replaces any client-supplied value. "Add" would let a client send its own copy alongside, and Apache would see the client's value, which would defeat the whole rule.
3. **SSL/TLS → Edge Certificates**: *Always Use HTTPS* on, minimum TLS 1.2. The lock is not a substitute for TLS: the secret travels in a header.
4. **Order when first enabling:** create the Transform Rule **first** — the origin currently ignores the header, so nothing changes — then upload the new `docroot`. The lock then takes effect with no window in which the site is unreachable for anyone.

## 3. Where the secret lives

The repository is public, so the value is **not** in it. It is substituted at build time, exactly like `__LASTMOD__` in `sitemap.xml`.

| Place | Holds | Committed? |
|---|---|---|
| `public/.htaccess` | the placeholder `__CF_ORIGIN_SECRET__` | yes |
| `deploy/host.config.php` → `cloudflare_origin_secret` | **the value** | **no** — gitignored; `bin/verify-hygiene.php` fails if a host config is ever tracked |
| built `docroot/.htaccess` | the value, substituted by `bin/build-deploy.php` | no — `build/` is gitignored, and only the built tree is uploaded |
| built `backend/cloudflare-origin.secret` | the value, for the nightly cron check | no |
| Cloudflare Transform Rule | the value | no |

So, concretely: **the value goes in `deploy/host.config.php` and nowhere else.**

```bash
# 1. generate
php -r "echo bin2hex(random_bytes(32));"

# 2. paste it into deploy/host.config.php
#    'cloudflare_origin_secret' => '<the value>',

# 3. paste the same string into the Cloudflare Transform Rule

# 4. build: the placeholder is replaced, and a build without a usable value fails
php bin/build-deploy.php --version=1.3.2
```

`deploy/host.config.php` is already the home of everything host-specific (account, document root, database names); the origin secret belongs there for the same reason a path does. The template `deploy/host.config.example.php` carries the key with an empty value, and the build refuses to run until it is filled — an empty key cannot be forgotten, because forgetting it stops the release instead of shipping an unreachable origin.

Constraints on the value:

- **32–128 characters**, `A`–`Z` `a`–`z` `0`–`9` `_` `-`. The value is compared inside a `RewriteCond` pattern, so `.`, `$`, `+` or `\` would change the match; restricting the alphabet means no escaping is needed and no secret can accidentally weaken the pattern.
- One value for the whole origin. It is a path credential, not a user credential.

Rejected alternatives, and why:

| Alternative | Why not |
|---|---|
| Hard-code the value in `.htaccess` | It would be committed. The repository is public and history is permanent. |
| `.env` file, or `SetEnv` in `.htaccess` | Apache cannot read a PHP `.env`, and a `SetEnv` line would itself be a committed value. |
| `SetEnv` / `Header` in the vhost, outside the repository | Would work, but this host is administered through cPanel File Manager with no shell: the value would live where the build and `bin/verify-deploy.php` cannot see it, and there would then be two sources of truth. |
| A GitHub Actions secret | The deployment artefact is assembled and uploaded from the operator's machine, not from CI. |
| Check the header in PHP instead | The SPA shell, `robots.txt`, `sitemap.xml`, `llms.txt` and every hashed asset never run PHP, and those are exactly the files an origin bypass exposes first. |

## 4. Verification

| Check | Proves | Runs |
|---|---|---|
| `bin/verify-http.php` | the rule exists, is the first rewrite, denies with `403`, and the committed value is the placeholder | `composer check`, CI |
| `bin/build-deploy.php` | refuses a build with no usable secret, an unusable one, or a `.htaccess` whose placeholder cannot be substituted | every release |
| `bin/verify-deploy.php` | the live origin: `403` without the header, everything else with it | after every upload, and nightly from cron |

```bash
# from this machine, after uploading; --config supplies the secret
php bin/verify-deploy.php --config=deploy/host.config.php --require-ready
```

```bash
# through Cloudflare — 200
curl -sS -o /dev/null -w '%{http_code}\n' https://boardsim.iliadis.net/

# straight at the origin address, same Host — 403
curl -sS -o /dev/null -w '%{http_code}\n' \
  --resolve boardsim.iliadis.net:443:198.51.100.10 https://boardsim.iliadis.net/
```

`bin/verify-deploy.php` prints its origin-lock result **first**, because a missing or wrong header turns every later check into the same 403 and reads like a broken deployment rather than a missing header. It cannot make that assertion against a loopback smoke server (`deploy/smoke-router.php` deliberately does not reproduce the lock, so a browser can walk the artefact), and it says so instead of passing silently.

## 5. Rotation

1. Generate a new value.
2. Update `cloudflare_origin_secret` in `deploy/host.config.php`.
3. Rebuild, upload `docroot`. The new `.htaccess` expects the new value, so the site answers `403` until step 4.
4. Update the Cloudflare Transform Rule. Traffic flows again.

To avoid the window entirely, accept both values for one release — deny only if the value is **neither** of them:

```apache
RewriteCond %{HTTP:X-Origin-Secret} !^<new>$
RewriteCond %{HTTP:X-Origin-Secret} !^<old>$
RewriteRule ^ - [F,L]
```

Build and upload with that, change Cloudflare, then remove the old line and build again. Either way the rotation is a release, not a hand edit: **never** edit the deployed `.htaccess`, because the artefact must stay reproducible from `deploy/host.config.php` and the next upload overwrites it.

Rotate when the value may have been seen. It sits in a file on a shared host, in the Cloudflare dashboard, and in any screenshot of either.

## 6. Failure modes

| Symptom | Cause | Fix |
|---|---|---|
| `403` on every URL through Cloudflare | placeholder still in `.htaccess`, or the Cloudflare value differs from the deployed one | rebuild from the config; make both strings identical |
| `403` on some paths only | a second DNS record or hostname whose Transform Rule does not match | scope the rule to every hostname in the zone, and proxy the records |
| `200` from the `--resolve` test | the rule was removed, or `.htaccess` is not applied (`AllowOverride`, or a cPanel "security" toggle that ignores it) | restore the rule; ask the host to confirm `AllowOverride All` |
| `bin/verify-deploy.php` 403s everywhere | no secret was available to the check | pass `--origin-secret-file=`, `--config=`, or set `BOARDSIM_CF_ORIGIN_SECRET` |
| cron `verify.log` full of 403 | `backend/cloudflare-origin.secret` was not uploaded, or is not readable by the cron user | re-upload the backend tree; check the file is `0600` and owned by the account |
| site fine, but the browser loads a stale shell | unrelated to the lock — the SPA fallback answers any path | check the asset hash in the served shell against the build |

## 7. What this does not do

- It does not authenticate **users**. It authenticates the **path**: one shared value, no identity, no per-client revocation.
- It does not hide the origin address, and it is not a firewall. If both the address and the value are known, the origin answers. The stronger options are Cloudflare **Authenticated Origin Pulls** (a client certificate the origin requires with `SSLVerifyClient require` and `SSLCACertificateFile`) or a firewall rule allowing only Cloudflare's published ranges — both are recommended next steps, and neither is possible on a host where the only instrument is a file upload. The header check is the one that works here, today.
- It does not replace the WAF, bot rules, cache or rate limiting in Cloudflare, and it does not replace the application's own limiter: the origin lock is the outer boundary, the limiter the inner one.
- A legitimate visitor who receives `403` is reaching the origin without Cloudflare in front — a DNS-only record, a hosts-file override, or a health monitor configured with the address rather than the hostname.

## 8. Must not regress

- [ ] `public/.htaccess` has exactly one `RewriteCond %{HTTP:X-Origin-Secret}` and it carries the placeholder.
- [ ] That guard precedes `RewriteRule ^v1(/.*)?$ api/index.php`.
- [ ] The ACME/DCV exemption is present and precedes the guard, so certificate renewal is never refused by the lock.
- [ ] No real value in any tracked file: `git grep -F '<the value>'` returns nothing.
- [ ] `bin/build-deploy.php` exits non-zero when the secret is empty, too short, or absent, and refuses a `.htaccess` without the placeholder.
- [ ] `bin/verify-deploy.php` reports the origin-lock check as `PASS` against the live origin.
- [ ] This file is pinned in `SHA256_MANIFEST.md`; after editing it, run `php bin/verify-manifest.php` and update the hash it reports as mismatched.

