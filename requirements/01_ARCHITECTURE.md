# Deterministic application architecture and state model

## Authority boundary

`immutable authored facts + policy version + player structured choices → validated rules engine → consequences + scores + append-only trace → renderer / LLM (non-authoritative prose)`.

**The LLM may** rephrase explanations, ask simulated board questions and provide optional coaching. **The LLM must not** assign scores, evaluate numeric importance, invent legal applicability, resolve unknown facts, set risk flags, choose rule matches, adjust the final mark, write run history or change the rule catalog. If LLM outputs conflict with engine values, discard output and display deterministic templates.

## Deterministic state machine
`CREATED → PROFILE_LOCKED → STAGE_1_OPEN → STAGE_1_LOCKED → STAGE_2_OPEN → STAGE_2_LOCKED → STAGE_3_OPEN → STAGE_3_LOCKED → BOARD_OPEN → BOARD_LOCKED → SCORED → FINALIZED`.

For each stage: (1) compute visible facts from scenario and prior stage effects; (2) snapshot known/unknown facts; (3) render unscored options; (4) accept one option ID once with a stable idempotency key; (5) atomically append event and apply **only** matching option rules; (6) update deterministic consequence flags/financial ledger; (7) progress to authored next stage. Server rejects late edits except explicit reset to a new run ID. Never use randomness or wall-clock timing for an assessed outcome: simulated event time advances by authored ticks.

## Proposed data objects
```yaml
Run:
  run_id: uuid
  scenario_id: BS-001
  scenario_version: 1.0.0
  ruleset_version: 1.0.0
  scoring_bundle_sha256: string
  legal_register_version: 1.0.0
  date_of_simulation: 2026-11-12
  risk_appetite: balanced
  board_persona: balanced
  difficulty: leadership
  event_log: [Event]
Event:
  sequence: integer
  stage_id: S1
  decision_id: D1
  selected_option: A
  visible_fact_ids: [F001]
  rule_ids: [DR-001]
  pre_state_sha256: string
  post_state_sha256: string
  metric_delta: {SJ: 0, BI: 0, RJ: 0, DQ: 0, LE: 0, CO: 0, RG: 0, FJ: 0, RE: 0, SM: 0}
  consequence_ids: [C1A]
  source_refs: [REG-NIS2-21]
```

## Typed facts and uncertainty
Facts are `{id,type,value,visible_from_stage,source_kind}`. `source_kind` is one of `SIMULATION_ASSUMPTION`, `PLAYER_ACTION`, `REGULATORY_ASSERTION`, `SCENARIO_INJECT`. `unknown` must remain distinct from `false`. An external reporting threshold is never inferred from a player's cyber choice.

## APIs (server authoritative)
- `GET /v1/scenarios/:id?version=...`: public content minus answer deltas/hidden facts (except in admin/editor).
- `POST /v1/runs`: returns run ID, pinned version/hash, visible S1.
- `POST /v1/runs/:id/decisions`: `{idempotency_key, stage_id, choice_id}`; only server validates and applies rules.
- `POST /v1/runs/:id/board`: structured checklist options; optional prose stored separately.
- `GET /v1/runs/:id/explain`: calculation and rule traces, with citations, assumptions and archived policies.
- `GET /v1/methodology/:version`: weights, formulas, editable ruleset changelog, limitations.
- `GET /v1/refs/:id`: scoped official source URL, article and nature (`LAW`/`GUIDANCE`).

## Storage and privacy
Append-only run events; immutable authored bundle; content signatures; hash-chained or signed run audit; role-based author/admin/end-user permissions. Store only necessary IDs and text; redact secrets and personal data from LLM prompts; default pseudonymous profile, limited retention configurable, encryption at rest/in transit, access and export/delete controls according to applicable obligations. AI prompts never contain hidden score keys. Archive exact policies to replay historical runs.

## Handling regulatory ambiguity
Use enum `NOT_APPLICABLE`, `APPLICABLE_NOT_TRIGGERED`, `TRIGGERED`, `PENDING_CLASSIFICATION`, `REQUIRES_HUMAN_REVIEW`. **Only `TRIGGERED` may start a legal deadline timer**, according to regulation-specific trigger and awareness facts. `PENDING_CLASSIFICATION` starts an internal escalation timer, **not** an invented legal deadline.

## Fail-closed conditions
Unknown rule ID, duplicate rule ID, invalid fact path, impossible transition, missing policy hash, non-integer delta, duplicate decision event, unknown legal source, applicability conflict, or unexpected LLM field affecting score -> deterministic rejection; never quietly substitute a default.
