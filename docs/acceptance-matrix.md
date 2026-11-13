# Acceptance traceability matrix

`07_ACCEPTANCE_TESTS.md` defines the gates; this document says which assertion
proves each one, and — more usefully — which ones nothing proves yet.

Reproduce everything with:

```bash
php bin/verify-manifest.php && php bin/verify-regulatory.php \
  && php bin/verify-scoring.php && php bin/verify-api.php \
  && php bin/verify-http.php
php bin/enumerate.php --difficulty=leadership
php bin/enumerate.php --difficulty=executive
```

Statuses: **proven** (an assertion fails if it regresses) · **partial** ·
**gap** (nothing checks it) · **by design** (deliberately not implemented).

## 02 / 07 — exact arithmetic golden

| Requirement | Status | Evidence |
| --- | --- | --- |
| `80,70,75,75,70,70,70,65,70,65` at weights `12,12,14,14,10,8,10,8,7,5` → numerator 7195, score 72 | proven | `verify-scoring`: golden reproduces 72 |
| `raw=101→100`, `raw=-1→0` | proven | `verify-scoring`: single post-clamp |
| No rounding before the aggregate | proven | `verify-scoring`: integer-only, one `floor` |
| Score in `[0,100]` | proven | `verify-scoring`, and 2×2268 enumeration runs |
| Weights sum to 100; deltas integer; rule ids unique | proven | `verify-scoring` |
| Board-best-case golden BS-001 A,A,A → 81 under 1.0.0 | proven | `verify-api`: archived baseline still 81 |

## 07 — scenario graph golden

| Requirement | Status | Evidence |
| --- | --- | --- |
| Each stage has exactly A/B/C, all 27 vectors replay | proven | 2,268 runs per difficulty, each replayed twice, byte-identical |
| Selected option maps to nonempty valid rules | proven | `enumerate`: every `DR-xxx` resolves in the pinned ruleset |
| Idempotency key prevents double-scoring | proven | `verify-api`: one event recorded, replay adds none |
| Wrong stage rejects | proven | `verify-api`: 409 |
| Unrecognised option rejects | proven | `verify-api`: 400, not a 500 |
| Canonical serialisation, key-order invariance | proven | `CanonicalJson` sorted keys; `verify-http` event hash stable |

## 05 — explanation contract

| Requirement | Status | Evidence |
| --- | --- | --- |
| Concrete `scenario`, `ruleset_version`, `weighted_numerator`, `rounding`, `trace`, `legal` keys | proven | `verify-api` machine-readable contract block (`floor((7822+50)/100)`) |
| Every printed number equals engine data | proven | `explain()` rebuilds from the stored event log, never re-evaluates |
| Positive and negative deltas with pre/post and evidence | proven | `trace[].deltas`, `pre_clamp`, `evidence_refs` |
| **"What would change it?" with exact numerical effect** | proven | `GET /v1/runs/{id}/counterfactuals` — substitution + full replay, identity-checked against the recorded score |
| Separate legal assessment, never mixed into the score | proven | `legal.assessment` = "never alters the score"; panel covers 28 instruments with resolvable `/v1/refs/` links |
| Human-review badge for unresolved applicability | proven | `REQUIRES_HUMAN_REVIEW` rendering in `statusBadge()` |
| LLM may not write the factual body | by design | No model is called anywhere. A redacted coaching layer would need its own validator; not built. |
| **Link out to the methodology and rule catalogue** | proven | `#/glossary` renders all 47 rule titles with their rationale and all 28 instruments with their official source links, derived from the same pinned artefacts (`GET /v1/glossary`, drift-tested by `bin/verify-glossary.php`); `/v1/methodology` and `/v1/refs/` remain for API clients. |
| **NIST function named per rule** | proven | `content/overlays/rule_nist_functions.yaml` maps all 47 rules to a CSF 2.0 function (GV/ID/PR/DE/RS/RC), surfaced in `/v1/glossary` and the rule popover; drift-tested by `bin/verify-glossary.php` |
| **Business consequences quoted in the explanation** | proven | `explain().consequences` repeats each decision's authored, non-scoring `effects`; rendered as "What your decisions did"; asserted in `bin/verify-api.php` |

## 07 — regulation applicability and clocks

| Requirement | Status | Evidence |
| --- | --- | --- |
| GDPR anomaly only → `PENDING_CLASSIFICATION` | proven | `verify-http`: BS-003 S1 reports pending |
| GDPR clock from awareness, not the anomaly | proven | `verify-regulatory`: corrected awareness-based timer |
| NIS2 out of scope → `NOT_APPLICABLE` | proven | BS-010 retail |
| NIS2 in scope → distinct early warning and notification | proven | `verify-regulatory` |
| DORA `incident_awareness` vs `major_classification`, 4h/72h/1 month | proven | `verify-regulatory` |
| DORA classifies >24h later → special handling | proven | `verify-regulatory` |
| AI Act: no blind 15 days for exceptional triggers | proven | `REQUIRES_HUMAN_REVIEW` on AI Act articles |
| CRA: no Art 14 reporting before 2026-09-11 | proven | `verify-regulatory` date gate |
| ENISA / NIST never shown as binding law | proven | `GUIDANCE_NOT_LAW` + `isGuidance()` |
| Greek procedural detail `REQUIRES_HUMAN_REVIEW` | proven | BS-013, Law 5160 |
| Guard suppression never silently substitutes a rule | proven | `verify-scoring`: `SUPPRESS_AND_RECORD`, no substitution |

## 07 — tamper and authoring regression

| Requirement | Status | Evidence |
| --- | --- | --- |
| A delta change moves only the corresponding dimension | proven | `verify-scoring` policy-delta test |
| Appetite modifiers affect only RJ, only matching DR ids | proven | `verify-scoring` |
| A source update never changes a pinned run | proven | 1.0.0 pin replays to 81 forever |
| Event tampering is detected and located | proven | `verify-api`: first broken event named |
| Changing an LLM prompt cannot change a replay | by design | No model in the path. |

## 06 — interface and accessibility

| Requirement | Status | Note |
| --- | --- | --- |
| Header shows role / industry / appetite / persona / stage / progress | partial | Header exists; no accessible progress indicator |
| Three equal-weight cards without pre-revealing point totals | proven | Client never receives rule ids or deltas |
| `PENDING_CLASSIFICATION` banner | proven | Status badges |
| Screen-reader announcements on state change | partial | `role="status"` / `role="alert"` on the lead and notices; no `aria-live` region for stage transitions |
| Keyboard shortcuts for choices | gap | Cards are focusable buttons, but A/B/C shortcuts are not bound |
| WCAG 2.2 AA contrast and touch targets | measured | `bin/verify-contrast.php` computes every token pair used by the interface (all ≥ 4.5:1 for text, ≥ 3:1 for UI) and the declared target height of every control; a browser pass at zoom and 320 px is still required |
| Static metadata for crawlers and LLMs | proven | `web/index.html` carries title, description, author meta and link, canonical, Open Graph and a schema.org `WebApplication` graph in the served bytes; `robots.txt`, `sitemap.xml` and `llms.txt` sit at the origin root. `bin/verify-meta.php` (static) and `bin/verify-deploy.php` (live HTTP) both assert it |
| Public-repository guard | proven | `bin/verify-hygiene.php` fails if a never-publish path is tracked or a clone-critical file is not |
| Five-minute board-room timer (not scoring) | proven | `preparation.scoring: false`, no auto-start, no hard stop; advisory client-side only |
| Three challenge cards from chair / CFO / CEO | proven | `board_challenges.yaml`; 3 cards, one per role, never a board field |
| Free text never graded | proven | Board pack accepts structured selections only |
| Landing offers demo **and** scenario routes | proven | `#/demo` challenge + "Start a scenario"; demo cannot produce a total because the scorer is never constructed |
| Results: expand every `DR-xxx` with deltas and source status | partial | Trace is tabulated with deltas; no per-rule drawer and no link to the catalogue |
| Profiles, share links, expiry, revocation | proven | Profile screen shows count **and** days left; `POST /v1/shares` mints a hashed 7-day token, `DELETE` revokes, `/v1/shares/{token}` is read-only. Token is shown once; only its SHA-256 is stored |
| Dates/currency locale-correct, integers stored | partial | UTC ISO timestamps stored; no locale formatting layer |

## 08 / 09 — governance and replay

| Requirement | Status | Note |
| --- | --- | --- |
| Five accountable roles with sign-off per `REG-xxx` | partial | `docs/governance-protocol.md` + derived `reports/governance-signoff.md`; **0 of 28 signed** — the register exists so the absence is visible |
| Immutable versioned release, rollback by version only | proven | Bundle `4124f451…`, three pins per run |
| Replay report published | partial | `reports/enumeration-*.json` + `docs/difficulty-baselines.md`; no rendered page |

## 07 — launch blockers

| Blocker | Status |
| --- | --- |
| Assessed free-form prose grading | by design — not implemented |
| National legal deadline assertions | by design — `REQUIRES_HUMAN_REVIEW`, no advice given |
| Psychometric / employment claims | mitigated — footer, governance flags, `psychometrically_calibrated: false` |
| AI Act or Greek law compliance certification | by design — not claimed |
| Unreviewed ENISA mapping treated as binding | mitigated — `GUIDANCE_NOT_LAW` |
| **Live URL redirects and legal consolidation checked** | **partial — see below** |

### Source reachability

`bin/verify-refs.php` resolves every distinct `source_url` (19 unique URLs behind 28
anchors) and reports status plus final destination; `--strict` also fails on any
redirect. It never edits the register: a moved source is a versioned content
change, not a patch.

This machine has no outbound network, so the script exits 0 with an explicit
"re-run on the deployment host" notice rather than pretending to pass. The three
most-cited sources were checked manually on 2026-11-13; all resolve to the correct
act with no redirect:

| Anchors | URL | Result |
| --- | --- | --- |
| GDPR | `CELEX:32016R0679` | OK — Regulation (EU) 2016/679, OJ L 119/1 |
| NIS2 | `CELEX:32022L2555` | OK — Directive (EU) 2022/2555, OJ L 333/80 |
| DORA | `CELEX:32022R2554` | OK — Regulation (EU) 2022/2554, OJ L 333/1 |

Two structural risks are visible without any request, and both must be fixed by
content version rather than edited into the pinned register:

1. Most anchors use `/legal-content/EN/TXT/HTML/?uri=CELEX:…`, the legacy search
   endpoint, rather than the stable ELI form (`/eli/reg/2016/679/oj`). It resolves
   today but is more fragile under EUR-Lex reorganisation.
2. One anchor points at a **dated consolidation of the AI Act**
   (`CELEX:02024R1689-20260727`). A consolidation is a snapshot, and the AI Act is
   the instrument most likely to be amended — `07` already requires human review of
   its timing after later amendments. That anchor belongs on the base act plus a
   versioned note, not on a frozen consolidation.

The remaining 16 sources — the Greek `cyber.gov.gr` PDFs, ENISA, NIST, and the
recent implementing regulations — are unverified here and must be resolved on the
deployment host before publication.


