# Scoring model: exact repeatable arithmetic

**Source of truth:** `engine/CONFIG.md` and `03_DECISION_RULES.md` YAML fenced blocks, pinned to version/hash. This document explains the formula rather than introducing independent numbers.

## Dimensions and weighting
| Dimension | Code | Weight | Direct observable behavior |
|---|---|---:|---|
| Strategic judgment | SJ | 12% | Multi-period tradeoffs, governance and options |
| Business impact | BI | 12% | Continuity, criticality and stakeholder harm |
| Risk judgment | RJ | 14% | Materiality, proportionality and uncertainty |
| Decision quality | DQ | 14% | Timeliness, reversibility, information use |
| Leadership | LE | 10% | Ownership, direction, respectful escalation |
| Communication | CO | 8% | Evidence-based board/customer briefing |
| Regulatory judgment | RG | 10% | Correct scope, clocks and reporting triage |
| Financial judgment | FJ | 8% | Economic tradeoffs and capital discipline |
| Resilience | RE | 7% | Recovery capability and dependency readiness |
| Stakeholder management | SM | 5% | Sequencing, balancing and governance |

## Formula
For each `dimension d`: `raw[d] = 70 + sum(rule_delta[d]) + sum(risk_appetite_RJ_modifier if d==RJ)`; `dimension[d] = min(100,max(0,raw[d]))`. Then `numerator = Σ weights_percent[d]*dimension[d]`; `overall = floor((numerator+50)/100)` using exact integers. This is **not** a percentage of right answers. No model inference, random noise or hindsight modification. Weights sum to 100.

## Example: why the score can be exactly 72
A run whose final dimensions after all explicitly logged rule deltas and clamping are `SJ=80, BI=70, RJ=75, DQ=75, LE=70, CO=70, RG=70, FJ=65, RE=70, SM=65` has numerator `12*80 + 12*70 + 14*75 + 14*75 + 10*70 + 8*70 + 10*70 + 8*65 + 7*70 + 5*65 = 7,195`, so `floor((7,195+50)/100)=72`. This is an **arithmetic illustration**, not a claim that this vector corresponds to a particular scenario path; actual traces must contain the rules producing each dimension.

## Risk appetite without changing hard law
The three appetite settings add small, explicit `RJ` modifiers only to DR-002, DR-004, DR-007 and DR-010. They do not amend legal obligations. Persona affects wording, never evaluation. Stage conditions and legal thresholds are immutable *within a run*. Users may change author policy for a **new policy version**, not the score for existing assessments.

## Board scoring without LLM classification
Board pack seven structured fields are graded using rule IDs DR-041–DR-047, when populated and checked against a fixed, machine-testable checklist (e.g., selected factual incident summary ID; selected business impact amount from known options; explicit 'unknown' where appropriate; selected decision ID; requested funding drawn from allowed approved amounts; residual risk flag from engine; selected board resolution ID). Optional prose is **never graded** in v1.0. User-facing copy must not claim prose was evaluated quantitatively. Natural-language board defense is coached, not scored.

## Consequences are not points
A decision may add `service_disruption_hours`, `estimated_exposure_eur_m`, `vendor_lock_in`, `recovery_state`, or `customer_confidence` to the simulated ledger. They are **authored teaching assumptions**, not real actuarial probabilities and not second-order hidden deductions. Do not penalize a good choice merely because a prewritten outage happens later.

## Profile aggregation
For 3–5 completed scenarios, aggregate each reported dimension as the unweighted arithmetic mean of completed, non-demo scenario dimension scores, rounded half-up. The profile overall is `round_half_up(mean of scenario overall scores)`. Show coverage by track, scenario version and difficulty; do not silently merge different ruleset versions. Comparability is unvalidated; label as a self-development simulation only.
