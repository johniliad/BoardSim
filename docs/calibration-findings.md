# Calibration findings — ruleset 1.0.0

Recorded from the full Stage 3 enumeration (`php bin/enumerate.php`): 2,268 runs,
14 scenarios × 27 paths × 3 appetites × 2 board extremes.

The harness **reproduces `09_REPLAY_REPORT.md` exactly** — min 58, max 84,
sum 161,371, mean 71.1512 (71.15 at 2 dp). That confirms the engine implements
the pinned policy faithfully. The findings below are about the *policy*, not
about the implementation, and none of them is a defect in the engine.

`08_CONTENT_GOVERNANCE.md` §4 requires this check and requires any correction to
be published as a **new versioned policy** with expert review, never as a silent
edit. Nothing here has been changed.

---

## Finding C-1 — score is almost a function of how many "A" options are chosen

| A choices in path | runs | min | max | mean |
|---:|---:|---:|---:|---:|
| 0 | 672 | 58 | 76 | 66.07 |
| 1 | 1,008 | 65 | 83 | 71.16 |
| 2 | 504 | 72 | 83 | 76.21 |
| 3 | 84 | 79 | 84 | 81.31 |

Spread between always-A and never-A: **15.24 points**, and the buckets barely
overlap. In **32 of 42 scenario-stages** a single option beats both alternatives
by ≥ 1.00 on mean score, and in every one of those 32 cases the dominant option
is **A** (gaps range 2.61–7.28).

Consequence: a participant who always picks A scores 79–84, while a participant
who reasons about each stage and sometimes picks B scores 65–83. The instrument
is therefore **gameable**, which sits awkwardly with
`00_PRODUCT_AND_SCOPE.md`:

> "No objectively 'correct' A/B/C answer. Multiple options have tradeoffs and
> risk-appetite modifiers."

### Correction to the first write-up of this finding

The first version of this document claimed "B scored worse than C, so the
middle-ground option is punished harder than the worst one". **That inference was
wrong** and has been withdrawn.

Position is not semantics. Several C options are authored as *good practice* —
BS-003 S2 C is "Notify authority with known facts and phased updates", mapped to
DR-039, DR-034 and DR-006, every one of them positive. The B-versus-C ordering is
an artefact of content, not a defect. The defect is the **positional** bias toward
A, measured as the A/B gap of ~21 points per decision.

### Resolution in ruleset 1.1.0

Treated as a calibration gap, not as intended pedagogy. A rebalancing overlay
now trims the clustered bonuses, softens the heaviest penalties, widens the
appetite modifiers and re-anchors the score ladder. Measured effect: the
always-A versus never-A spread falls from **15.24 to 9.92** (−35%), the largest
dominance gap from 7.28 to 4.69, and all five score labels become reachable.

**Residual limitation:** dominant stages stay at 32 of 42, because A is authored
as the proportionate answer and no delta change can move that. Fixing it properly
requires re-authoring option-to-rule mappings as a content version — the route
the specification README sanctions explicitly. See
[`rescore-report-1.0.0-to-1.1.0.md`](rescore-report-1.0.0-to-1.1.0.md).

## Finding C-2 — risk appetite has almost no effect

| Appetite | mean |
|---|---:|
| aggressive | 71.13 |
| balanced | 71.15 |
| conservative | 71.17 |

A spread of **0.04 points**. Because `engine/CONFIG.md` attaches modifiers to
only four rules (DR-002, DR-004, DR-007, DR-010) at ±2 on `RJ` alone, and several
of those modifiers cancel when they fire together, the choice of appetite is
very nearly a no-op numerically.

### Attempted fix and its limit

Ruleset 1.1.0 widens the modifiers to ±4, which doubles the effect — to **0.09
points**. That is the ceiling of this lever: `02_SCORING_MODEL.md` confines
appetite to RJ only, four rules, and "small" values. Appetite therefore cannot
become a meaningful scoring input without a change to the scoring model itself.

Recommendation: describe appetite in the interface as a **framing device** that
changes how the board dialogue is presented, not as a scoring lever. That is
honest and needs no further policy change.

## What is *not* a finding

- **Guard behaviour is correct.** Zero guard suppressions occur anywhere in the
  2,268 runs, because both guarded scenarios establish their trigger at the stage
  where DR-027 is used. No path silently loses a rule.
- **Determinism holds.** Every one of the 2,268 runs was executed twice and the
  canonical serialisation was byte-identical.
- **Reproducibility holds.** The distribution is identical across repeated
  invocations, and the bundle hashes are unaffected by these observations.

## Recommended decision order

1. Decide whether C-1 is intentional or a gap. This determines whether a
   ruleset 1.1.0 is needed at all.
2. If 1.1.0 is needed, do it **before** the API persists runs, so no stored run
   has to be re-scored later.
3. Fix the appetite narrative (or the modifiers) in the same version bump.
