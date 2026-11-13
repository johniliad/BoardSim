# Acceptance tests, golden examples and compliance gates

## Exact arithmetic golden
The illustrative ten dimension values `80,70,75,75,70,70,70,65,70,65` at weights `12,12,14,14,10,8,10,8,7,5` produce numerator 7195 and score **72** (half-up integer). Test `raw=101→100`, `raw=-1→0`; no rounding before aggregate. Assert score is in [0,100]. Assert weights sum to 100; all rule deltas are integers and all IDs unique.

## Scenario graph golden
For each of BS-001..BS-014: each stage has exactly A/B/C; selected option has nonempty valid rule IDs; exactly 3 stages, 27 choice vectors; check all option sequences replay identically. One option submitted twice with same idempotency key must not double-score; wrong stage must reject; unrecognized option must reject. Test serializations are canonical; preserve data across restart; scores invariant under map key ordering.

## Explanation correctness
For every rule firing, render positive and negative deltas, pre/post values, risk modifier, evidence, source type and stage. Every printed number must equal engine data. All DR links resolve to `03_DECISION_RULES.md`; legal links resolve to official URLs; explain panel includes policy version/hash, methodology and consequences distinct from point totals. A language-model response containing wrong score or extra legal assertion must be discarded.

## Regulation applicability and clocks
- GDPR: anomaly only -> `PENDING_CLASSIFICATION`; confirmed personal breach with relevant controller/individual risk and awareness -> clock from awareness, not original network anomaly.
- NIS2: out-of-scope entity -> `NOT_APPLICABLE`; in-scope *significant* incident -> distinct early warning and incident notification, both awareness based, final one month after notification.
- DORA: financial entity classification must meet 2024/1772; clock distinguishes `incident_awareness` and `major_classification`; initial 4 hours after classification, special handling where classification occurs more than 24 hours later; intermediate 72 h after first report, final one month after latest intermediate.
- AI Act: not every biased/erroneous answer is a reportable Art.73 serious incident; scope, provider role, serious event, effective date must be proven; never force 15 days blindly for exceptional triggers.
- CRA: manufacturer product reporting before 2026-09-11 -> no CRA Art.14 reporting; after date check precise product/incident scope; main requirements typically from 2027-12-11.
- ENISA/NIST guidance must never be displayed as binding law. Local Greek procedural specifics remain `REQUIRES_HUMAN_REVIEW` until versioned expert sign-off.

## Test implementation sample (language neutral)
```text
for scenario in catalog:
  assert len(stages)==3
  for choices in cartesian_product([A,B,C], repeat=3):
    r1 = replay(scenario,choices,appetite='balanced',board_checks=[])
    r2 = replay(scenario,choices,appetite='balanced',board_checks=[])
    assert canonical_json(r1)==canonical_json(r2)
    assert 0 <= r1.score <= 100
    assert r1.score == floor((sum(weight[d]*r1.dimension[d] for d in dimensions)+50)/100)
  assert all(known(rule) for rule in all_option_rules(scenario))
```

## Tamper/authoring regression
Changing a rule delta in a new policy must produce **only** the documented corresponding dimension change and derived overall change; old archived scores unchanged. Editing risk modifiers affects only RJ and only matching DR IDs. Altering an LLM prompt cannot change any replay result. A legal register source update does not silently change a pinned run.

## Launch blockers
Do not launch assessed free-form prose grading, national legal deadline assertions, psychometric employment claims or AI Act/Greek law compliance certifications without additional validated specifications. Do not use unreviewed ENISA workbook mappings as if article-level binding requirements. Check actual live URL redirects and legal consolidation before public publication.
