# Re-score report — ruleset 1.0.0 → 1.1.0

Required by `08_CONTENT_GOVERNANCE.md` §6: a policy correction is published with
explicit old and new versions, and historical scores are never rewritten.

| | old | new |
|---|---|---|
| Ruleset version | `1.0.0` | `1.1.0` |
| Policy SHA-256 | `dd533b45…8268a` | `b2135b59…4fe54` |
| Source | pinned `03_DECISION_RULES.md` + `engine/CONFIG.md` | same, plus `content/overlays/scoring_calibration.yaml` |
| Pinned files edited | none | none |

Regenerate both columns with `php bin/rescore.php 1.0.0`.

## Why the correction was made

The Stage 3 enumeration found that the score was driven mainly by *how many times
option A was chosen*, not by the quality of the reasoning. Measured over the same
2,268 runs:

| Position | mean net delta per firing (1.0.0) |
|---|---|
| A | **+11.58** |
| B | −9.61 |
| C | −5.91 |

`00_PRODUCT_AND_SCOPE.md` states there is *"no objectively correct A/B/C answer"*,
but the authored catalogue made position A worth roughly 21 points more per
decision than position B.

**A correction to my own earlier reading.** I initially reported that "B scored
worse than C, so the middle-ground option is punished harder than the worst one".
That was wrong, and the report it came from should not be relied on. Position is
not semantics: several C options are authored as *good practice* — BS-003 S2 C is
"Notify authority with known facts and phased updates", mapped to DR-039, DR-034
and DR-006, all strongly positive. The B-versus-C ordering is therefore an
artefact of content, not a defect. The real defect is the **positional** bias
toward A, which is what this version addresses.

## What changed

Twenty-five delta overrides, in `content/overlays/scoring_calibration.yaml`:

- **16 bonuses trimmed** — the governance cluster that fired almost exclusively on
  A-options (for example DR-022 from +15 to +8, DR-011 from +13 to +7).
- **9 penalties softened** — the heaviest negative rules, which compressed the
  whole scale (DR-031 from −24 to −13, DR-015 from −16 to −9).
- **Risk appetite widened** from ±2 to ±4 on the same four rules, still RJ-only
  as `02_SCORING_MODEL.md` requires.
- **Score ladder re-anchored** (78/74/70/64) because rescaling the deltas moved
  the distribution; without this the top two labels became unreachable.

No rule changed the set of dimensions it touches, and no delta changed sign. That
is asserted in `bin/verify-scoring.php`.

## Measured effect

| Metric | 1.0.0 | 1.1.0 | Change |
|---|---:|---:|---|
| Runs | 2,268 | 2,268 | — |
| Min | 58 | 61 | +3 |
| Max | 84 | 79 | −5 |
| Mean | 71.15 | 70.68 | −0.47 |
| **Always-A vs never-A spread** | **15.24** | **9.92** | **−35%** |
| Largest dominance gap | 7.28 | 4.69 | −36% |
| Dominant stages (of 42) | 32 | 32 | unchanged |
| Appetite spread | 0.04 | 0.09 | ×2.3 |
| Distinct score bands reached | 4 | 5 | +1 |

Mean by number of A choices:

| A choices | 1.0.0 | 1.1.0 |
|---:|---:|---:|
| 0 | 66.07 | 67.36 |
| 1 | 71.16 | 70.69 |
| 2 | 76.21 | 74.01 |
| 3 | 81.31 | 77.29 |

Label distribution:

| Label | 1.0.0 | 1.1.0 |
|---|---:|---:|
| Excellent | 0 | 78 |
| Strong | 187 | 459 |
| Capable | 1,170 | 877 |
| Developing | 893 | 777 |
| Needs reflection | 18 | 77 |

The instrument now uses all five labels instead of four, and the spread between a
fixed "always pick A" strategy and reasoning-based play is roughly a third
smaller.

## What this version does *not* fix

**Dominant stages remain at 32 of 42.** The magnitude of the bias fell by a third,
but A still wins wherever it appears, because the scenario authors wrote A as the
proportionate, board-ready answer. No delta change can alter that: it is a
property of the **option-to-rule mappings**, which live in the pinned scenarios.

The sanctioned route is the one listed in the specification README — editing
*scenario option-to-rule mappings* — published as a **content version 1.1.0**
overlay alongside this scoring version. That is a separate, larger piece of
authoring work and is deliberately not attempted here.

**Risk appetite remains marginal.** Doubling the modifiers moved the corpus mean
by 0.09 points instead of 0.04. Within `02_SCORING_MODEL.md` — RJ only, four
rules, "small" modifiers — appetite cannot become a meaningful scoring lever. The
honest options are to describe it in the UI as a framing device, or to revisit the
model in a future version.

## Reproducibility guarantee

- The 99 pinned files listed in `SHA256_MANIFEST.md` are unchanged.
- `php bin/enumerate.php --ruleset=1.0.0` still reproduces `09_REPLAY_REPORT.md`
  exactly: min 58, max 84, mean 71.1512.
- `php bin/verify-scoring.php` still reproduces the worked example — BS-001 with
  `A,A,A` and all seven board fields — as numerator 8,058 and score 81 under
  `1.0.0`, and 7,753 / 78 under `1.1.0`.
- Any run stored under `1.0.0` can be replayed against `1.0.0` indefinitely,
  because the version is pinned on the run and the baseline is never overwritten.

## Governance

`source_kind: SIMULATION_ASSUMPTION`, `counsel_reviewed: false`,
`psychometrically_calibrated: false`. This is an arithmetic re-score, not a
psychometric validation. The 1.1.0 deltas remain authored judgement and require
executive expert review before being described as measuring capability.
