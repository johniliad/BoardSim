# BoardSim overlay layer — authored, versioned, never part of the pinned bundle

The 29 Markdown files under `requirements/`, `scenarios/` and `engine/` are
SHA-256 pinned and immutable (`SHA256_MANIFEST.md`, `08_CONTENT_GOVERNANCE.md`).
Everything in this directory is additive: the loader merges it with the pinned
files at load time and never rewrites them.

## Files

| File | Layer | Purpose |
|---|---|---|
| `predicate_vocabulary.yaml` | 0 | Closed vocabulary of predicate names, timestamp facts and timer events. Expressions may not reference anything else. |
| `regulatory_instruments.yaml` | 1 | The 28 `REG-*` anchors, each defined once with scope/trigger predicates, verification tier, reporting duty and statutory timers. |
| `scenario_regulatory_facts.yaml` | 2 | `REGULATORY_ASSERTION` and `SCENARIO_INJECT` facts per scenario, with the pinned text each value derives from. |
| `rule_guards.yaml` | 3 | Binds the symbolic guard names carried by the pinned scenarios to concrete instruments. |
| `board_checklist.yaml` | 4 | The seven board-pack fields as structured choices, each option authored as substantiated or not. Supplies the definition of "substantiated" that `02_SCORING_MODEL.md` requires but does not provide. |
| `scenario_option_remap.yaml` | content 1.3.2 | Current option-to-rule corrections, including the changes first introduced in content 1.1.0. Historical runs resolve their exact retained content release. |
| `scoring_calibration.yaml` | ruleset 1.3.2 | Current rebalanced deltas, appetite modifiers and score ladder. Historical runs resolve their exact retained ruleset and a 1.0.0 run never receives this calibration. |
| `difficulty_views.yaml` | 5 | `leadership` / `executive` / `board_crisis` as read-only projections. The executive board subset is 4 fields instead of 7, which genuinely lowers the attainable score; `board_crisis` is unavailable and rejected with 422. |

## Deliberate divergences from the first F-2 draft, and why

**1. BS-003 reaches TRIGGERED at Stage 2.**
The draft held BS-003 at `PENDING_CLASSIFICATION` for all stages and proposed
substituting DR-014 or DR-026 for DR-027. The pinned S2 inject states:

> "Logs now show export of an identified personal dataset; controller became
> aware at 10:30. Risk to individuals is assessed as plausible."

That establishes controller role, a personal data breach, a known awareness
moment and risk that is not unlikely. Forcing `PENDING` would render a legal
panel saying "scope unconfirmed" beside an on-screen inject saying "controller
became aware at 10:30". BS-003's unresolved question is at S3 (potential
trade-secret compromise), not Art. 33 scope.

**2. BS-013 is a DORA scenario, not a GDPR/NIS2 scenario.**
The draft set `dora_financial_entity: false` and expected `REG-GDPR-33` and
`REG-NIS2-23` to trigger. The pinned file declares only DORA references, its S2
inject establishes a "predefined DORA major classification", its S2 Option A
effect is `legal: dora_major`, and its guard is
`DORA_MAJOR_CLASSIFIED_AND_REPORTABLE`. No personal data breach is asserted
anywhere in the file, so GDPR Art. 33 stays `PENDING_CLASSIFICATION`.

**3. No rule substitution.**
`on_guard_unmet` is `SUPPRESS_AND_RECORD` and `substitution_permitted` is false.
`engine/RULE_SCHEMA.md` forbids hidden fallback rules, and a substituted rule
would appear in the trace without any author binding it to that option. With
divergences 1 and 2 applied, suppression never actually occurs in either
scenario — the guards are satisfied — which also keeps the published
`09_REPLAY_REPORT.md` distribution reproducible.

**4. The guard keys on reporting duties, not on any instrument.**
Standing governance duties (NIS2 Art. 21, GDPR Art. 32, DORA Art. 5/6/28) become
`TRIGGERED` simply by being in scope. Had the guard been
`any_instrument_status == TRIGGERED`, DR-027 could fire at Stage 1 on mere
suspicion — exactly what `03_DECISION_RULES.md` prohibits. The guard therefore
requires at least one instrument with `reporting_duty: true` to be `TRIGGERED`.

**5. Statutory clocks are not faked with hour approximations.**
The draft used 792 hours for the NIS2 final report and 76 hours for the DORA
intermediate report. Both are anchored on filing events and are modelled as
calendar offsets with `projection_from_timer`, flagged `projection: true` when no
filing has occurred, so the UI can never show an invented deadline as overdue.
GDPR Art. 34 is `WITHOUT_UNDUE_DELAY` with no countdown at all.

**6. Provenance values are typed by layer.**
Real statute citations carry `source_kind: AUTHORITATIVE_SOURCE`; authored
predicates, facts, guards, formatters and dimension weights carry
`source_kind: SIMULATION_ASSUMPTION`. Labelling GDPR itself a simulation
assumption would be false. Both carry `counsel_reviewed: false` and
`psychometrically_calibrated: false`.

## Scope of Layer 2

Every one of the fourteen scenarios now carries an authored fact set, but the
fact sets are deliberately minimal: each asserts only the predicates referenced
by that scenario's own `regulatory_refs`. Regimes a scenario does not cite are
left untouched, so they are neither displayed nor silently adjudicated.

The resulting spread across the corpus exercises all five statuses:

| Status | Demonstrated by |
|---|---|
| `TRIGGERED` | BS-002 NIS2 Art. 23 from S2; BS-003 GDPR Art. 33 from S2; BS-013 DORA from S2 |
| `PENDING_CLASSIFICATION` | BS-001 (breach and significance unconfirmed); BS-003 GDPR Art. 34 and NIS2 Art. 23 |
| `APPLICABLE_NOT_TRIGGERED` | BS-012 (in scope, no reportable incident) |
| `NOT_APPLICABLE` | BS-010 (retail is not a listed NIS2 sector) |
| `REQUIRES_HUMAN_REVIEW` | every AI Act article; Greek Law 5160/2024 |

Three assertions are authored judgements rather than quotations, and are flagged
for counsel review accordingly:

- **BS-002** is the only scenario where `significant_incident_confirmed` is
  asserted `true`, at S2, because the inject confirms unauthorised access and
  quantifies EUR 2.5M/day exposure. BS-001 is deliberately left `unknown`
  because its S3 offers a significance assessment as a live choice.
- **BS-010** asserts `entity_in_scope: false`: retail trade is not a NIS2 Annex
  I or II sector. This is the only path that produces `NOT_APPLICABLE`, and it
  exists to show that a cited framework does not become a duty by association.
- **BS-009** asserts `entity_in_scope: true` on the basis that a B2B technology
  provider supplies managed platform services. A reviewer could reasonably
  reclassify this as `unknown`; the rationale string records the reasoning.

## Authoring rule followed throughout

A predicate is asserted `true` only where the pinned text establishes the fact.
Where the scenario explicitly defers the question — "significance must be
assessed", "the legal definition of serious incident still needs assessment",
"evidence of personal data exposure is still unconfirmed" — the fact stays
`unknown`, and the panel says so.
