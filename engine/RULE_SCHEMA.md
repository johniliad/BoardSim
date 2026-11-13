# Normative scoring/rule syntax

## Parser contract
The first YAML fenced block in each scenario is the normative `scenario` object. The YAML fenced block in `03_DECISION_RULES.md` is the normative `rules` array. The YAML fenced block in `engine/CONFIG.md` is normative configuration. **No other Markdown prose is executable.** Use a safe YAML subset (no aliases, anchors, custom tags, embedded expressions, environment references or Python objects). The implementation reads only whitelisted properties and rejects unknown properties. Treat all markdown as untrusted author input.

## Supported scenario subset
```yaml
scenario_id: BS-001
version: 1.0.0
industry: healthcare
role: CISO
track: CISO
risk_appetite_default: balanced
board_persona_default: balanced
regulatory_refs: [REG-NIS2-21]
organization: {employees: 1800, annual_revenue_eur_m: 240}
facts:
  - {id: F01, stage: S1, text: 'A fact', kind: SIMULATION_ASSUMPTION}
stages:
  - id: S1
    time: '08:00'
    question: 'What action?'
    reveal: [F01]
    options:
      - id: A
        label: 'Proportional action'
        rules: [DR-001]
        effects: {service_disruption_hours: 2, residual_risk: contained}
board:
  question: 'Why do you recommend it?'
  checklists: [situation, impact, uncertainty, action, funding, residual_risk, ask]
```

## Exact computation order
1. Validate scenario version, policy hash, stage order, available option and all referenced rules.
2. Initialize ten integer dimensions to baseline 70.
3. For stages `S1`, `S2`, `S3`, for each listed rule ID **in canonical ascending lexicographical order**: add each rule's integer `deltas` by dimension; add configured risk-appetite modifier to `RJ` (if any); log every addition, including zero.
4. For board checklists, add the configured response rules DR-041..DR-047 once each if the corresponding checkbox is explicitly ticked **and substantiated by structured preauthored board fields**. Narrative text does not authorize the point. For version 1.0 scenarios, board checklist selections are collected independently of narrative.
5. Clamp **once after all rules** each dimension to [0,100]. Overall `round_half_up(sum(weight * dimension)/100)`, where `weight` is integer percent and all arithmetic is exact integer. Implement as `floor((sum_weighted + 50) / 100)` (all dimension scores nonnegative). No floating point.
6. Order the trace by stage, then rule ID; include base additions, risk modifiers, pre-clamp and post-clamp scores, each weight contribution, the final numerator and rounded score. Generate explanations from this trace, not from a separate estimation.

## Provenance semantics
`rule.evidence_refs` can link framework guidance and law but **does not assert that a particular rule is mandatory by law**. A legal source never mechanically determines points; the policy's editable values do. `source_kind=SIMULATION_ASSUMPTION` for every invented business impact or preference. If an applicable legal duty is found, show it in a separately computed legal panel and link original provision.

## Forbidden constructs
`eval`, arbitrary formulas, `random`, free-form LLM classification for assessed criteria, dynamic URLs in scoring, changes to archived bundles, hidden fallback rules or implicit points for not choosing an option. Missing `unknown` facts must not be treated as a negative regulatory conclusion.
