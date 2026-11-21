# Security policy

BoardSim is a free, accountless decision simulator. It holds no passwords, no
sessions and no admin panel; the most sensitive thing it stores is a record of
someone's exercise — their decisions, their board brief and a share token. This
document explains how to report a security problem, what is in scope, and what
happens after a report arrives.

## How to report

Two channels, in this order:

1. **GitHub private vulnerability reporting (preferred).**
   [Report a vulnerability](https://github.com/johniliad/BoardSim/security/advisories/new)
   on this repository. Reports stay private, support patch discussion and
   preview deployments, and never touch email.
2. **Email fallback: [security@iliadis.net](mailto:security@iliadis.net)**
   (read by Dr. John ILIADIS). If the report is sensitive, say so in the first
   sentence and ask for a PGP key before sending detail.

Whichever channel you use, please include: what you did, what you expected,
what happened, the affected URL or file, and — where possible — a minimal
reproduction (steps, a curl command, or one request/response pair). A report
with a concrete reproduction is triaged first.

If a scanner produced the finding, include your own analysis of the impact. A
raw scanner dump with no demonstrated or plausibly argued consequence is closed
as out of scope; the analysis is the valuable part, not the volume.

## In scope

- **The live origin, `https://boardsim.iliadis.net`** — the SPA, the JSON API
  under `/v1/`, and every static document it serves (including the error pages
  and the discovery files).
- **This repository** — the PHP engine, the front end, the deployment artefacts
  produced by `bin/build-deploy.php` (the document root, `.htaccess`, the API
  shim), and the verification tooling itself if it can be made to lie.
- Anything that **leaks, corrupts or destroys participant data** — runs,
  recorded decisions, board briefs, profiles, share tokens. There are no
  accounts to attack, so participant data is the asset.
- Anything that defeats a control this repository claims to have: the
  Cloudflare origin lock, the security-header policy and its CSP, the rate
  limiter, the run-integrity hashes, the erasure path, or the zero-dependency
  boundary of the API.

The run-integrity check covers persisted decision evidence, event links, state
transitions, immutable run configuration and release pins, board selections, and
score/dimension reconstruction. It is not a digital signature and does not defend
against a privileged attacker who can rewrite both records and hashes coherently.
Reports that demonstrate an integrity claim exceeding that scope are in scope.

The API rejects JSON request bodies above **16 KiB**, nesting deeper than **16**
levels, non-object top-level JSON, overlong identifiers, and oversized profile or
board collections before they can drive unbounded persistence work.

## Out of scope

- **Denial of service.** The rate limiter is deliberate. Volume testing,
  request flooding and expensive crawler loops attack availability, not
  correctness; they are not findings.
- **The absence of features on purpose.** There are no accounts, no sessions
  and no cookies by design; "I can use the product without logging in" is the
  product. Likewise a `403` from the origin address is the origin lock working
  as specified — `requirements/12_CLOUDFLARE_ORIGIN_LOCK.md`.
- **Unauthenticated read of data you created yourself** — every run id and
  share token is an unguessable capability issued to you; reporting that you
  can read your own run with your own token is not a finding. Reading
  *someone else's* is the highest-value report there is.
- Findings that require physical access, a compromised or jailbroken device,
  a man-in-the-middle position on someone else's traffic, social engineering
  of the author or of the hosting provider, or attacking the host's other
  tenants.
- Spam, self-XSS, and reports from automated tooling without analysis.

## Safe harbour

Good-faith research is welcome and will not be met with legal action, provided
you: use only your own data or data you have permission to use; avoid
degrading the service for others (the rate limits are a hard ceiling, not a
suggestion); stop and report as soon as you can state the impact; and give the
author a reasonable window to fix before any public disclosure. If you are
unsure whether an action crosses a line, ask first through either channel —
asking is always safe.

## What happens next

- **Acknowledgement:** within 72 hours of the report reaching either channel.
- **Triage:** an assessment — affected or not affected, severity, and plan —
  within 14 days, and a status update at least every 14 days after that.
- **Fix or statement:** a fix, a mitigation, or a reasoned "not a
  vulnerability" statement within 90 days.
- **Disclosure:** coordinated with you by default. Advisory text is shared
  with you before publication, and credit is given if you want it (anonymity
  is equally fine).

If the report shows participant data being exposed or a live attack, the
timelines compress to as-fast-as-possible and a fix may ship before you are
notified.

## Supported versions

Only the current deployment of `boardsim.iliadis.net` and the `main` branch of
this repository receive security fixes. Older releases and older tags are
historical artefacts, not supported software.

## Data protection notes

BoardSim stores participant data with no identity attached: no name, no email,
and no account. Erasure is self-service and irreversible (`DELETE /v1/runs/{id}`),
share links expire after at most seven days (sooner when the oldest selected run
reaches its deletion deadline) and can be revoked by their owner, and the full
retention model is documented in
[`docs/profiles-and-retention.md`](docs/profiles-and-retention.md). If your
report involves personal data, say so at the top: it will be handled under
GDPR timelines, not the disclosure timeline above.
