# Public-facing BoardSim methodology and explanation contract

## Evidence → principles → decision rules → consequences → executive judgment
1. **Facts:** show exactly what the player knew at each decision time, with confidence/unknown flags and provenance.
2. **Principles:** link NIST function, applicable legislation where scope established, and BoardSim's executive trade-off framework. Identify which is **nonbinding guidance**, which is **statute**, and which is **simulation preference**.
3. **Decision rules:** show IDs (e.g. [DR-014](03_DECISION_RULES.md), [DR-027](03_DECISION_RULES.md), [DR-031](03_DECISION_RULES.md)) and each numerical dimension delta; the example IDs here do not imply they always trigger together.
4. **Consequences:** show state flags, business impacts, assumed cost, changed operational posture, and next-event inject; never back-solve scores from outcomes.
5. **Judgment:** show dimension baseline, triggered additions, applicable appetite modifiers, post-clamp score, weights, numerator and rounding to one integer. Public [scoring model](02_SCORING_MODEL.md) is the canonical calculation description.

## Mandatory response template to “Why did I get X?”

> **BoardSim score: {overall}/100** · Scenario: {scenario_id}@{scenario_version} · Policy: {ruleset_version} · Bundle hash: {sha256}.

> You started at 70 in each of ten dimensions. On stage {stage} you selected {option}, triggering rules {IDs} because the authored option maps to those rules. The rules produced [table of each dimension adjustment]. Your {appetite} risk appetite added {explicit RJ modifier}. [Other stage and board entries follow.]

> Business consequences: {fact-based simulated outcomes}; these are independent of the scoring formula.

> Regulatory references: {legal applicability status, exact source/article, direct URL and reason}. A guidance citation is not a legal breach finding.

> Dimension scores = {ten scores}. Weighted numerator = {integer}, score = floor((numerator + 50)/100) = {overall}. [Open methodology](02_SCORING_MODEL.md) / [Rule catalog](03_DECISION_RULES.md) / [Regulatory register](04_REGULATORY_REGISTER.md).

> What would change it? {different authored choice option or editable delta + exact expected numerical effect}; changes to policy are available only as a separate version.

## Machine-readable explanation data contract
```json
{
  "score": 72,
  "scenario": "DEMONSTRATION_ONLY",
  "ruleset_version": "1.0.0",
  "dimensions": {"SJ":80,"BI":70,"RJ":75,"DQ":75,"LE":70,"CO":70,"RG":70,"FJ":65,"RE":70,"SM":65},
  "weighted_numerator": 7195,
  "rounding": "floor((7195+50)/100)",
  "trace": "Must be populated with actual rule firings; not fabricated from this illustrative dimension vector.",
  "legal": "Separate assessment with instrument/article/scope/trigger and status"
}
```

## LLM output hard constraints
Do not use LLM to write the factual body from scratch. Server renders the calculation and linked evidence itself; optional LLM coaching receives a **redacted, read-only** explanation JSON, never hidden scores/weights or authority to overwrite it. Validate every generated numeric amount and rule ID against the authoritative trace; if one mismatches, discard generated text and fall back to the fixed template. Do not show speculative legal penalties. Show a human-review badge for unresolved legal applicability.

## Methodological limits
This is a **simulated executive decision self-development aid**: its fictional cases, invented weights and authored decisions have not been validated as psychometric, hiring, promotion or regulatory-compliance assessment instruments. Multiple choices can be defensible. Later events cannot demonstrate that earlier decisions were necessarily wrong. Scores are relative to the published BoardSim policy, not universal executive competence.
