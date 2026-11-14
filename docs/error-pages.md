# Error pages

Four statuses and one stylesheet, in the site's own design, shipped as static
files so they work when PHP does not.

| Status | Page | Shown when |
|---|---|---|
| 400 | `public/errors/400.html` | Apache could not parse the request |
| 403 | `public/errors/403.html` | the origin lock refused a request; a dotfile or `api/backend.php` was asked for |
| 404 | `public/errors/404.html` | an asset, discovery file or result token does not exist |
| 500 | `public/errors/500.html` | the server failed before the application could answer |
| 503 | `public/errors/503.html` | the `api/index.php` guard fired, or a deploy is in progress |

`public/.htaccess` binds them with `ErrorDocument <status> /errors/<status>.html`.
`bin/build-deploy.php` copies `public/errors/` into the document root and refuses
to finish unless every page is in `docroot.zip`.

## Why not just let the SPA handle it

The SPA is a client-side application behind a fallback rewrite, so it can only
render 404s for *route* mismatches — and it answers with HTTP 200, which is a lie
to a crawler and to a monitoring probe. The cases above are decided by Apache
before any JavaScript runs: the fallback never sees a refused dotfile, a missing
hashed asset, or a request the origin lock denied. Those are exactly the failures
an operator meets first, and they used to be Apache's grey page.

## Constraints that are not negotiable

1. **No inline style, no inline script.** `public/.htaccess` sends
   `default-src 'self'; style-src 'self'; style-src-attr 'none'; script-src 'self'`
   to every file, error documents included. An inline `style` attribute is
   dropped by the browser and `<script>` is refused, so a "quick" inline tweak
   silently disappears. `bin/verify-http.php` fails if any page contains `style=`
   or `<script`.
2. **One stylesheet, copied tokens.** `public/errors/error.css` carries the
   palette, spacing, type and component rules copied from `web/src/styles.css` and
   `web/src/components.css`. The SPA's own CSS cannot be referenced: its file name
   is content-hashed at build time. When a token changes in the SPA, change it
   here too — the two files are the design system, and there is no build step that
   can reconcile them.
3. **No dependency on the build.** The pages reference `/app/boardsim-mark.svg`
   and `/app/favicon.svg`, which the build produces, and nothing else.
4. **`Cache-Control: no-store`.** A stale 403 across a deploy is worse than no 403.
   The `.htaccess` `<FilesMatch>` for the error pages overrides the immutable
   asset rule.
5. **`noindex`.** They are `noindex` and must never appear in a sitemap.

## Two behaviours worth knowing

- The 403 page is also what a request that skipped Cloudflare receives, **unless**
  the request is denied by the origin lock itself: that 403 is answered by the
  same rule that exempts nothing, so Apache's ErrorDocument subrequest is refused
  as well and the client sees Apache's minimal page. That is deliberate — no bytes
  are spent on traffic that is not supposed to reach the origin.
- `deploy/smoke-router.php` serves these pages for its own 403 and 404 answers, so
  `bin/verify-deploy.php` proves they reached the artefact before an upload.
