# Difficulty baselines and portfolio aggregation

Two artefacts are measured separately and never merged:

- the **leadership** baseline (seven board fields), which is the one compared to the
  published oracle in `09_REPLAY_REPORT.md`;
- the **executive** baseline (four board fields), which has its own distribution.

Both are produced by `bin/enumerate.php --difficulty=<view>` over content 1.1.0 with
ruleset 1.1.0 (the calibration overlay applied), 14 scenarios × 27 stage paths ×
3 risk appetites × 2 board extremes.

## Measured enumerations

| View | Board fields | Runs | Min | Max | Mean | Always-A spread |
| --- | --- | --- | --- | --- | --- | --- |
| leadership | 7 | 2268 | 61 | 79 | 70.7160 | 9.87 |
| executive | 4 | 2268 | 61 | 79 | 70.3818 | 9.81 |

Mean-by-number-of-A-choices, which is where the projection shows up:

| A choices | Runs | Leadership mean | Executive mean | Difference |
| --- | --- | --- | --- | --- |
| 0 | 672 | 67.42 | 67.09 | −0.33 |
| 1 | 1008 | 70.71 | 70.40 | −0.31 |
| 2 | 504 | 74.02 | 73.64 | −0.38 |
| 3 | 84 | 77.29 | 76.90 | −0.39 |

Every bucket shifts down. Both views replay byte-identically, and the executive
enumeration satisfies the same structural checks as the leadership one (path
coverage, stage monotonicity, guard behaviour).

### Why the maxima coincide at 79

The executive view drops exactly three board rules — the substantiation tests for
board impact, uncertainty and residual risk. Each is a `+2` delta, and the weights
are BI 12, RJ 14, RE 7, so the pre-rounding numerator differs by
`2 × (12 + 14 + 7) = 66` hundredths.

`bin/verify-api.php` asserts this exactly: for BS-001 A,A,A with a fully
substantiated board, leadership numerator 7753 → score 78, executive numerator
7687 → score 77, delta 66.

Sixty-six hundredths is less than one point, and the single integer clamp at the end
absorbs it in most cases. So the honest statement is:

> The executive ceiling is strictly lower in exact arithmetic, but after the one
> permitted rounding step the corpus maximum coincides at 79 for both views. The
> effect is systematic and visible in the mean (−0.3342) and in every A-count
> bucket, not in the extremes.

A claim that "executive tops out below leadership" would be wrong about the
extremes across a corpus, and marginally-sampled extremes are exactly the kind of
number that invites over-reading. The per-path comparison is the defensible one.

## Portfolio aggregation

`src/Domain/Profile/ProfileAggregator.php`, exposed at `POST /v1/profiles` with
`{"run_ids": [...]}`.

Arithmetic follows `02_SCORING_MODEL.md` exactly:

- each reported dimension is the **unweighted** arithmetic mean of the run dimension
  scores, rounded half-up;
- the profile **overall** is `round_half_up(mean of the run overall scores)` — not the
  weighted mean of the aggregated dimensions, which would be a different and
  unratified number;
- the window is **3 to 5** completed scenarios.

Segmentation. A segment key is `ruleset_version | content_version | difficulty`.
Scores are never merged across any component of that key, because each changes what
a score means:

- a ruleset change moves the weights or the calibration;
- a content change moves option semantics;
- a difficulty change moves the board pack, and the 66-hundredths result above
  proves the two are not on one scale.

Behaviour at the edges:

| Condition | Status | Aggregate |
| --- | --- | --- |
| 3–5 runs in a segment | `eligible` | produced |
| fewer than 3 | `insufficient` | `null` — no number is fabricated |
| more than 5 | `over_window` | `null` — the caller must choose which runs |
| run not finalized | 409 | — |
| unknown run | 404 | — |
| empty list | 400 | — |

The over-window case is refused rather than truncated because `02_SCORING_MODEL.md`
defines the window but not a selection rule for run six onwards. Picking "the most
recent five" would be an invented policy presented as arithmetic.

Coverage is always reported alongside the numbers: distinct scenarios, scenario IDs,
tracks, scenario versions and risk appetites. Repeated scenarios are allowed but
flagged — counting one scenario three times is legitimate practice, and it is also a
way to look more covered than you are, so it is stated rather than silently accepted.

Every response carries `merged_across_versions: false` and the governance flags
(`SIMULATION_ASSUMPTION`, `counsel_reviewed: false`,
`psychometrically_calibrated: false`), because a portfolio profile is the single
most likely artefact to be forwarded as if it were an assessment.

Demo runs are excluded by construction: the five-minute demo is public and
unscored, so it never reaches the run store.
