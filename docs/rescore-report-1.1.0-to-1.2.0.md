# Content 1.1.0 → 1.2.0

Three narrow dominance gaps closed. Everything else is untouched.

## What changed

Three stages had a winning option mapped to three positive rules while the
runner-up mapped to fewer, so part of the margin was authored rule-count
asymmetry rather than content quality. Each runner-up received a third rule it
genuinely earns; no winner was lowered and no failure-mode rule was softened.

| Stage | Runner-up | Added | Gap before | Gap after |
|---|---|---|---:|---:|
| BS-002 S1 | C — observe under increased logging | DR-003 evidence preservation | 1.46 | 0.68 |
| BS-004 S2 | B — documented, time-bounded exception | DR-023 adapt controls to context | 1.65 | 0.73 |
| BS-005 S1 | B — migrate rapidly, thin but documented safeguards | DR-023 adapt controls to context | 1.43 | 0.46 |

All three fall below the 1.00 dominance threshold; all three stay behind the
winner. The negative rules on the failure-mode options (`DR-036`, `DR-037`) were
left alone — the teaching signal is intact.

## Re-baselined numbers (content 1.2.0, ruleset 1.1.0)

| View | Min | Max | Mean | Always-A spread |
|---|---|---|---|---|
| leadership | 61 | 79 | 70.7160 → **70.7800** | 9.87 → **9.78** |
| executive | 61 | 79 | 70.3818 → **70.4431** | 9.81 → **9.71** |

Three properties survived: min/max unchanged at 61/79, all **five score bands
still reachable**, and both enumerations still pass their structural checks
(determinism, stage monotonicity, guard behaviour). Dominant stages: **31 → 28
of 42**.

## What this does not change

- Runs are pinned to their content version: a run scored under 1.1.0 replays
  unchanged; only new runs use 1.2.0 mappings.
- The 1.0.0 oracle (58 / 84 / 71.1512) is unaffected — it pins both ruleset and
  content 1.0.0 and bypasses all overlays.
- Profiles never merge content versions, so a participant with runs on both 1.1.0
  and 1.2.0 sees two segments and may need a third run on either to be eligible.