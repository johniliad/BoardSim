# Editable scoring policy — normative

Edit values below to change BoardSim's **simulation** score; update version and rerun golden tests. The legal register is NOT a point source by itself.

```yaml
ruleset_version: 1.0.0
score_start_each_dimension: 70
score_lower_bound: 0
score_upper_bound: 100
rounding: half_up_positive_integer
weights_percent:
  SJ: 12
  BI: 12
  RJ: 14
  DQ: 14
  LE: 10
  CO: 8
  RG: 10
  FJ: 8
  RE: 7
  SM: 5
risk_appetite_delta:
  conservative: {DR-002: -2, DR-004: 2, DR-007: -2, DR-010: 2}
  balanced: {}
  aggressive: {DR-002: 2, DR-004: -2, DR-007: 2, DR-010: -2}
board_persona_display_only: [risk_conservative, growth, financial, regulatory, balanced]
score_ladder:
  - {min: 90, label: Excellent simulated judgment}
  - {min: 80, label: Strong simulated judgment}
  - {min: 70, label: Capable simulated judgment}
  - {min: 60, label: Developing simulated judgment}
  - {min: 0, label: Needs reflection in this simulation}
```

## Non-negotiable interpretive conventions
1. `score_start_each_dimension=70`: all participants start at a neutral simulation baseline; only triggered rule deltas alter it.
2. Rules may trigger at most once **per decision stage**; distinct stages may trigger the same rule and each is logged. The stage-specific mapping declares exactly which rules fire on the selected option.
3. Risk-appetite modifier applies once per rule firing after base deltas, to the `RJ` dimension only. It must be visible in the trace and clipped only at the final dimension score. A board persona changes dialogue, not scores; consistent with source, scoring is not secretly personalized by persuasion style.
4. Scenarios do not reward favorable roll-of-the-dice outcomes; consequences are separately displayed as deterministic business ledgers. No hidden penalties, no probabilistic scoring, no rule precedence except the explicit order below.
5. `regulatory_status` is computed from hardcoded scope and trigger predicates, never from whether users selected a notification option. Notifications and omissions can trigger BoardSim judgment rules, but **must not be labeled violations absent confirmed applicability, awareness and threshold**.
