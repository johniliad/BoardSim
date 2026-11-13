# Editable decision-rule catalog (normative)

**These are BoardSim policy judgments, not quotations from law.** Every delta is editable, and every change must be released as a new ruleset. Evidence references describe a related governance domain but do not establish that the numeric incentive follows from the source. `DR-027` requires a separate `regulatory_status=TRIGGERED` guard in a stage mapping; it must never be used for mere suspicion.

```yaml
rules:
- id: DR-001
  title: Activate coordinated incident leadership
  deltas:
    DQ: 5
    LE: 4
    SM: 3
  rationale: Coordinated decision making and role assignment
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-002
  title: Preserve service with explicit safeguards
  deltas:
    BI: 5
    FJ: 2
    RJ: -1
  rationale: Continuity with monitored safeguards; trade-off remains
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-003
  title: Preserve evidence and narrow scope
  deltas:
    RJ: 5
    DQ: 3
    RG: 2
  rationale: Bound uncertainty with evidence before irreversible claims
  evidence_refs:
  - REG-NIST-CSF-DE
  source_kind: BOARDSIM_POLICY
- id: DR-004
  title: Immediate broad containment
  deltas:
    RJ: 4
    RE: 3
    BI: -4
    FJ: -2
  rationale: Reduces spread but can impose avoidable disruption
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-005
  title: Explicit recovery readiness and DR validation
  deltas:
    RE: 7
    BI: 3
    FJ: -2
  rationale: Protect continuity; test recoverability before switching
  evidence_refs:
  - REG-NIST-CSF-RC
  source_kind: BOARDSIM_POLICY
- id: DR-006
  title: Seek counsel and classify potential reporting
  deltas:
    RG: 6
    DQ: 3
    SM: 2
  rationale: Prompt structured legal triage, not automatic notification
  evidence_refs:
  - REG-GDPR-33
  source_kind: BOARDSIM_POLICY
- id: DR-007
  title: Accelerate growth with a published risk boundary
  deltas:
    SJ: 5
    BI: 4
    RJ: -3
  rationale: Recognized growth benefit against documented residual risk
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-008
  title: Document residual risk and accountable owner
  deltas:
    RJ: 4
    RG: 3
    LE: 3
  rationale: Named owner and written accepted exposure
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-009
  title: Quantify business and financial impact
  deltas:
    BI: 5
    FJ: 6
    SJ: 2
  rationale: Explicit tradeoff supported by defensible figures
  evidence_refs:
  - REG-NIST-CSF-ID
  source_kind: BOARDSIM_POLICY
- id: DR-010
  title: Fund minimum viable controls before acceleration
  deltas:
    RE: 5
    RJ: 4
    FJ: -2
  rationale: Resilience floor protects core services at a cost
  evidence_refs:
  - REG-NIST-CSF-PR
  source_kind: BOARDSIM_POLICY
- id: DR-011
  title: Transparent and timely stakeholder communication
  deltas:
    CO: 6
    SM: 5
    LE: 2
  rationale: Sequenced messages anchored to known facts
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-012
  title: Commission independent specialist assistance
  deltas:
    DQ: 3
    RE: 3
    FJ: -3
  rationale: Speed expertise; external cost and dependency
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-013
  title: Bounded monitoring while investigating
  deltas:
    BI: 4
    RJ: -3
    DQ: 2
  rationale: Service maintained; exposure remains pending evidence
  evidence_refs:
  - REG-NIST-CSF-DE
  source_kind: BOARDSIM_POLICY
- id: DR-014
  title: Defer escalation despite known material risk
  deltas:
    DQ: -7
    LE: -5
    SM: -5
  rationale: Poor timeliness and governance without business justification
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-015
  title: Communicate certainty unsupported by evidence
  deltas:
    CO: -8
    RJ: -5
    RG: -3
  rationale: Overclaiming under incomplete information
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-016
  title: Apply hard limits to supplier dependency
  deltas:
    RJ: 5
    RE: 5
    SJ: 3
  rationale: Concentration controls, exit plan and operational viability
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-017
  title: Demand measurable transformation milestones
  deltas:
    SJ: 5
    DQ: 4
    FJ: 4
  rationale: Portfolio gates, benefits and stage reviews
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-018
  title: Neglect known operational dependencies
  deltas:
    BI: -6
    RE: -6
    RJ: -3
  rationale: An avoidable omission of continuity interdependence
  evidence_refs:
  - REG-NIST-CSF-ID
  source_kind: BOARDSIM_POLICY
- id: DR-019
  title: Protect legal holds and evidence integrity
  deltas:
    RG: 4
    RJ: 4
    DQ: 3
  rationale: Preserve defensible incident record
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-020
  title: Make financial allocation proportional to exposure
  deltas:
    FJ: 7
    RJ: 3
    SJ: 3
  rationale: Costed prioritized investment portfolio
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-021
  title: Accept unrestricted risk with no owner
  deltas:
    RJ: -8
    LE: -4
    RG: -5
  rationale: No accountable authorization or mitigation
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-022
  title: Escalate with options not ultimatums
  deltas:
    LE: 6
    SM: 5
    CO: 4
  rationale: Respectful challenge with board decision request
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-023
  title: Adapt controls to operational context
  deltas:
    DQ: 5
    BI: 4
    RJ: 3
  rationale: Proportionate controls and documented guardrails
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-024
  title: Prioritize staff capability and sustainable load
  deltas:
    LE: 5
    RE: 3
    FJ: 2
  rationale: Teams and critical roles are manageable
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-025
  title: Unplanned outsourcing critical capability
  deltas:
    RJ: -5
    RE: -4
    FJ: -3
  rationale: New concentration and transition exposure
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-026
  title: Review compliance obligations by entity and date
  deltas:
    RG: 8
    DQ: 4
  rationale: Evaluate applicability, effective date, threshold and owner
  evidence_refs:
  - REG-NIS2-21
  source_kind: BOARDSIM_POLICY
- id: DR-027
  title: Miss an evidenced notification action
  deltas:
    RG: -9
    DQ: -5
    CO: -3
  rationale: Only when trigger affirmed by authored fact and scope
  evidence_refs:
  - REG-GDPR-33
  source_kind: BOARDSIM_POLICY
- id: DR-028
  title: Retain optionality with phased delivery
  deltas:
    SJ: 5
    FJ: 3
    RE: 3
  rationale: Stage-gated reversible program rather than irreversible bet
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-029
  title: Pause unsafe AI-driven action pending investigation
  deltas:
    RJ: 6
    BI: -2
    RG: 4
  rationale: Prevent further high-impact harm while assessing scope
  evidence_refs:
  - REG-AIA-73
  source_kind: BOARDSIM_POLICY
- id: DR-030
  title: Correct bias and human oversight controls
  deltas:
    RJ: 5
    DQ: 5
    LE: 3
  rationale: Human review and accountable response to automated errors
  evidence_refs:
  - REG-AIA-14
  source_kind: BOARDSIM_POLICY
- id: DR-031
  title: Conceal impact or bypass escalation
  deltas:
    LE: -7
    CO: -7
    SM: -6
    RG: -4
  rationale: Suppress information required for responsible governance
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-032
  title: Consider dual running and rollback
  deltas:
    BI: 5
    RE: 5
    FJ: -2
  rationale: Proportionate reversible operational transition
  evidence_refs:
  - REG-NIST-CSF-RC
  source_kind: BOARDSIM_POLICY
- id: DR-033
  title: Unverified public/customer notification
  deltas:
    CO: -3
    RG: -4
    SM: -2
  rationale: Unqualified claims can confuse stakeholders
  evidence_refs:
  - REG-GDPR-34
  source_kind: BOARDSIM_POLICY
- id: DR-034
  title: Proactively structure factual customer updates
  deltas:
    CO: 5
    SM: 4
    RG: 2
  rationale: Accurate phased updates without premature conclusions
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-035
  title: Seek emergency funds with accountable controls
  deltas:
    FJ: 3
    LE: 4
    RE: 4
  rationale: Fund constrained recovery with documented approval
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-036
  title: Over-allocate to speed without control floor
  deltas:
    SJ: 2
    BI: 3
    RJ: -6
    RE: -5
  rationale: Commercial upside but weakened critical controls
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-037
  title: One-size-fits-all freeze despite business harm
  deltas:
    RJ: 4
    BI: -7
    FJ: -5
    SM: -3
  rationale: Protective but potentially disproportionate interruption
  evidence_refs:
  - REG-NIST-CSF-RS
  source_kind: BOARDSIM_POLICY
- id: DR-038
  title: Retain learning loop and remediation ownership
  deltas:
    SJ: 5
    DQ: 4
    RE: 4
  rationale: Root cause, milestones, proof and accountable owner
  evidence_refs:
  - REG-NIST-CSF-RC
  source_kind: BOARDSIM_POLICY
- id: DR-039
  title: Explicit legal clock and evidence timestamps
  deltas:
    RG: 7
    DQ: 3
    CO: 2
  rationale: Correctly record awareness and specific rule triggers
  evidence_refs:
  - REG-NIS2-23
  source_kind: BOARDSIM_POLICY
- id: DR-040
  title: Unqualified guarantee of future safety
  deltas:
    CO: -7
    RJ: -4
    LE: -3
  rationale: Unwarranted assurance to board
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-041
  title: 'Board brief: Situation'
  deltas:
    CO: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-042
  title: 'Board brief: Business impact'
  deltas:
    BI: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-043
  title: 'Board brief: Uncertainty and risk'
  deltas:
    RJ: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-044
  title: 'Board brief: Decision and alternative'
  deltas:
    DQ: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-045
  title: 'Board brief: Investment and cost'
  deltas:
    FJ: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-046
  title: 'Board brief: Residual risk and owner'
  deltas:
    RE: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
- id: DR-047
  title: 'Board brief: Specific board decision requested'
  deltas:
    LE: 2
    SM: 2
  rationale: Structured board field is present and consistent with facts
  evidence_refs:
  - REG-NIST-CSF-GV
  source_kind: BOARDSIM_POLICY
```

## Authoring policy
Do not add text matching that claims one legally prescribed response is worth any fixed number of points. Use rule evidence to tell the player **why BoardSim values a behavior**, while the separate regulatory panel states whether obligations are actually triggered. For stage option mappings, all rules and all deltas are fully known in advance. Points not represented by a triggered rule do not exist.
