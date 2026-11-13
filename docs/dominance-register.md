# Dominance register — content version 1.2.0

`08_CONTENT_GOVERNANCE.md` §4 requires inspecting the distribution across all 27
paths "and check for an unfair always-best answer". This register records that
inspection stage by stage, so the finding is discharged with evidence rather
than by assertion.

Regenerate with `php tools/dominance-report.php`.

> **Content 1.3.0 note.** The fourteen scenarios were reworded for an executive
> audience (BLUF briefings, board-level authorisation language, distinct
> trade-offs). Option ids, `rules` mappings and `effects` fields are unchanged,
> and the audit below was re-run against content 1.3.0: still **28 of 42 stages
> dominant**. Option labels quoted in this register are the 1.2.0 wording; the
> same positions now carry the 1.3.0 wording in `scenarios/BS-*.md`.

## Method

A stage is *dominant* when the highest-mean option beats the next by ≥ 1.00
score point across all 54 runs that reach that stage (27 paths × 3 appetites ×
2 board extremes, aggregated over the paths that include it).

Result under ruleset 1.1.0 with content 1.2.0: **28 of 42 stages dominant**,
down from 31 in 1.1.0 and 32 in 1.0.0. The three stages closed are documented in
`docs/rescore-report-1.1.0-to-1.2.0.md`.

## What the audit actually shows

Two facts change the interpretation of the number.

**1. The winning option always maps to three rules; the alternatives map to two.**
That is an authoring asymmetry in the pinned scenarios, not a scoring defect.

**2. In most dominant stages the alternatives are authored failure modes.** They
are not defensible trade-offs that happen to lose; they are choices the scenario
exists to punish. Representative examples:

| Option text | Rule mapped | Verdict |
|---|---|---|
| "Declare 28% of all duties legally waiveable by board" | DR-015 | should lose |
| "Publish 100% readiness using self-attestations only" | DR-015, DR-031 | should lose |
| "Sign unrestricted compliance certificate regardless of evidence" | DR-015, DR-031 | should lose |
| "Conceal open issues until inspection" | DR-014, DR-031 | should lose |
| "Secretly waive critical review gates" | DR-031, DR-036 | should lose |
| "Hide exception from the board" | DR-031, DR-014 | should lose |
| "Promise policy breaches cannot ever recur" | DR-040, DR-015 | should lose |
| "Privately defer classification until normal operations return" | DR-014, DR-031 | should lose |

Making these competitive would delete the teaching signal. `00_PRODUCT_AND_SCOPE.md`
says there is no single correct answer; it does not say every answer is equally
good.

## Corrected in content 1.1.0

| Stage | Was | Now | Effect |
|---|---|---|---|
| **BS-005 S2 B** | DR-008, DR-007, **DR-018** | DR-008, DR-007, **DR-023** | Dominance **resolved** (2.98 → below 1.00). DR-018 claims the option neglects dependencies, but it explicitly documents its residual exposure. |
| **BS-012 S1 C** | **DR-012**, DR-018 | **DR-025**, DR-018 | Dominance **widened** 3.48 → 4.63. DR-012 rewards commissioning specialist advice; buying generic assurance without scoping is DR-025. Corrected for accuracy, accepting the metric cost. |

## Remaining stages — verdicts

`BS-002 S1`, `BS-004 S2` and `BS-005 S1` are resolved in content 1.2.0 and no
longer dominant. Every stage still listed here is judged **legitimate**: the
alternatives are authored as failure modes, and the gap measures how bad they are.

| Stage | Winner | Gap | Alternatives are |
|---|---|---:|---|
| BS-001 S3 | A | 4.04 | false certainty / concealment |

| BS-002 S3 | A | 3.00 | false assurance / delayed disclosure |
| BS-003 S1 | A | 3.39 | unverified public claim / unowned escalation |
| BS-003 S3 | A | 3.33 | denial / speculative disclosure |

| BS-004 S3 | A | 3.80 | hindsight claim / blame |

| BS-005 S3 | A | 4.07 | concealed variance / unplanned reversal |
| BS-006 S3 | A | 4.09 | ignored contract risk / pause everything |
| BS-007 S3 | A | 3.63 | overclaimed elimination / adversarial demand |
| BS-008 S2 | A | 2.78 | deferred exercise / total shutdown |
| BS-008 S3 | A | 4.17 | disowned accountability |
| BS-009 S1 | A | 2.28 | unplanned outsourcing / stalled milestones |
| BS-009 S2 | A | 3.44 | unearned promotion / dismissed concern |
| BS-009 S3 | A | 3.56 | hidden gate waiver / indefinite freeze |
| BS-010 S1 | A | 3.67 | waived controls / universal delay |
| BS-010 S2 | A | 3.94 | false assurance / exclusion |
| BS-010 S3 | A | 3.94 | adversarial disclosure / false consensus |
| BS-011 S1 | A | 2.78 | privileged exception / platform shutdown |
| BS-011 S2 | A | 3.93 | concealment / public blame |
| BS-011 S3 | A | 3.72 | optional-for-leadership / never-again promise |
| BS-012 S1 | A | 4.63 | waiver by assertion / unscoped assurance — *accuracy correction, see above* |
| BS-012 S2 | A | 4.48 | self-attested readiness / full freeze |
| BS-012 S3 | A | 4.31 | false certificate / concealment |
| BS-013 S1 | A | 2.72 | passive wait / untested termination |
| BS-013 S2 | A | 4.17 | missed notification / false claim |
| BS-013 S3 | A | 4.69 | unbounded capacity / blanket rejection |
| BS-014 S1 | A | 3.26 | silent continued operation / blanket stop |
| BS-014 S2 | A | 4.43 | unverified reportability / concealment |
| BS-014 S3 | A | 3.89 | untested resumption / overpromise |

Note the eight **narrow** stages (gap < 2.0) and the twelve with no dominance at
all: 11 of 42 stages already behave as genuine trade-offs, which is what the
instrument should look like.

## What would actually remove the remaining dominance

Not a scoring change and not an overlay. It requires **authoring stronger
alternative options** — rewriting the prose of options B and C so that a
defensible trade-off exists at each stage instead of a failure mode. That is
scenario authoring, it changes pinned text, and it is a separate content project.

Two routes, for the record:

1. **Author defensible alternatives** so that B and C are real strategies rather
   than caricatures. Highest value, largest effort.
2. **Randomise option presentation order per run** (deterministically from the
   run id) so that position carries no signal. Cheap, but does nothing about the
   underlying asymmetry and complicates the explanation trace.

Neither is done here. The recommendation is route 1, as a content project with
its own version and re-score report.
