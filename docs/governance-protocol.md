# Governance protocol and sign-off record

`08_CONTENT_GOVERNANCE.md` names five accountable roles and requires each `REG-xxx`
predicate to be signed before production. This document says what each signature
actually covers, how one is recorded, and what a release is.

Nothing here is signed yet. That is the point of writing it down.

## Roles and what each one is signing for

| Role | Signs for | Evidence they must check | Can block a release |
| --- | --- | --- | --- |
| **Scenario editor** | the fictional facts, alternatives, consequences and rule mappings | every invented figure marked `SIMULATION_ASSUMPTION`; no option claims a legal outcome; `docs/dominance-register.md` verdicts acknowledged | yes |
| **Scoring steward** | weights, dimensions, base/delta values, appetite, the ladder | both published goldens reproduce; the 2,268-run enumeration reproduces; calibration documented in `docs/calibration-findings.md` | yes |
| **Legal subject-matter reviewer** | every enabled legal scope and threshold | accurate consolidated act, Member State implementation, effective date, report clock, and that guidance is never presented as law | yes |
| **Engineering reviewer** | determinism, immutability, traceability, access control, data minimisation | 67 regulatory + 51 scoring + 203 API + 100 HTTP assertions green; replay byte-identical; erasure works; retention enforced; CSP locked | yes |
| **Release manager** | the archive and the version number | manifest 29/29, bundle hash, policy hash, content hash recorded; rollback possible only by choosing an older version | yes |

A signature is not "I glanced at it". It is a dated statement, in the log below, of
which **version** was reviewed and which **specific claims** the signer verified.

## Recording a sign-off

Append to the log, one row per signature. Never edit or delete an existing row: a
withdrawn signature is a new row that says it was withdrawn.

```
| date (UTC) | role | scope | version | verdict | note |
| 2026-11-20 | Legal reviewer | REG-GDPR-33, REG-GDPR-34 | bundle 1.1.0 | SIGNED | consolidation checked 2026-11-20 |
| 2026-11-21 | Scoring steward | ruleset 1.1.0 | policy b2135b59 | SIGNED | 2268 runs reproduced |
```

## Current state

`bin/governance-report.php` regenerates `reports/governance-signoff.md` from the
resolved bundle, so the register cannot drift from what is actually loaded. As of the
last run: **0 of 28 instruments signed**. `counsel_signed_off` is `false` everywhere
and `REQUIRES_HUMAN_REVIEW` is set by design on Greek Law 5160 and the AI Act
articles. Nothing in this repository may be described as reviewed.

## What a release is

1. Bump the version in the overlay and in `composer.json`.
2. Run every gate: `verify-manifest`, `verify-regulatory`, `verify-scoring`,
   `verify-api`, `verify-http`, both enumerations, and the 1.0.0 oracle.
3. Rebuild the bundle and record its hash.
4. Write the sign-off rows for that version.
5. Tag the commit with the three pins (bundle, policy, content).

**Rollback is selection, not editing.** An older version stays loadable forever
(`load('1.0.0')`, `PinnedScenarios($root, '1.0.0')`); a rollback means choosing it,
never mutating a newer one in place.

## Deliberately out of scope

No model is called anywhere in the scoring path, no free text is graded, and no
national legal deadline is asserted. Those are not gaps awaiting a sign-off — they
are things this build does not do, and a reviewer should be told that plainly rather
than asked to approve them.
