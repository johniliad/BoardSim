# Security headers

One policy, two delivery channels, one drift check.

| Where | Channel | Source |
| --- | --- | --- |
| `/v1/*` (JSON) | PHP `header()` | `BoardSim\Http\SecurityHeaders::forApi()` |
| SPA + hashed assets | Apache `mod_headers` | `public/.htaccess` |

The CSP string is defined once in `SecurityHeaders::CONTENT_SECURITY_POLICY` and
mirrored into `.htaccess`, because Apache cannot read a PHP constant. That
duplication is a real drift risk, so `bin/verify-http.php` parses the header out of
`.htaccess` and fails if the two strings differ. The mirror is a test, not a hope.

## What is locked

| Directive | Value | Why |
| --- | --- | --- |
| `script-src` | `'self'` | No inline scripts, no `eval`. This is the one that matters. |
| `object-src` | `'none'` | No plugin content. |
| `base-uri` | `'none'` | A hijacked `<base>` would re-point every relative URL. |
| `form-action` | `'self'` | No off-origin form posts. |
| `frame-ancestors` | `'none'` | Clickjacking. Mirrored as `X-Frame-Options: DENY` for old browsers. |
| `connect-src` | `'self'` | The SPA talks to `/v1` and nothing else. |
| `default-src` | `'self'` | Deny-by-default for everything not named. |
| `Permissions-Policy` | all denied | The app needs no device capability. |
| `Cache-Control` | `no-store` on `/v1` | A scored run is participant data. |

`Strict-Transport-Security` is keyed to the actual request scheme. A browser that
receives HSTS over plain HTTP ignores it, but an origin that later drops back to
HTTP would be unreachable for the max-age window, so the header is only sent when
`requestIsHttps()` is true (`$_SERVER['HTTPS']` or `X-Forwarded-Proto`).

## The former deviation, now closed

The policy used to allow `style-src 'unsafe-inline'`, because the SPA set roughly
eighty React inline `style` attributes for spacing and type scale. Those are now
single-purpose utility classes (`.bs-mt-4`, `.bs-muted-90`, `.bs-h-stage`, …), so
the directive is:

```
style-src 'self'; style-src-attr 'none'
```

Two details worth keeping in mind:

- The one remaining dynamic style — the glossary popover's anchor position — is
  written through the CSSOM (`element.style.top = …`), which the CSP does not
  govern. It needs no exemption, and `style-src-attr 'none'` still fails closed
  if a style *attribute* is ever reintroduced.
- The `<noscript>` shell used an inline `style` attribute; it now uses
  `.bs-noscript`, so the shell is equally clean.

`bin/verify-http.php` asserts `style-src 'self'` and `style-src-attr 'none'`, and
`bin/verify-contrast.php` measures the tokens that replaced the inline values, so
the closure is checked rather than announced.

## The one request header this origin requires

Everything above is a **response** header. The origin also demands one request
header, `X-Origin-Secret`, and refuses with `403` when it is absent — that is the
Cloudflare origin lock, not a security header in the browser sense, and it lives in
the same file as the first rewrite rule. It is documented separately because the
secret has to stay out of the repository:
[`requirements/12_CLOUDFLARE_ORIGIN_LOCK.md`](../requirements/12_CLOUDFLARE_ORIGIN_LOCK.md).
`bin/verify-http.php` asserts the rule is present, is first, and still carries the
build placeholder rather than a committed value.

## What is not verified

The Apache `.htaccess` directives are **not** exercised by any test here. PHP's
built-in server ignores `.htaccess`, so only the API channel is checked directly;
the static-asset channel is checked as text (presence and CSP parity). A real
Apache instance is the only true verifier — the same untested seam as MySQL. Flag
it when deploying.
