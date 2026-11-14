# BoardSim — Executive Decision-Making Under Pressure

**Specification release:** 1.0.0 · **Prepared:** 2026-11-12 · **Status:** implementation-ready simulation design; requires regulatory counsel review before legal reliance and calibration before assessment use.

BoardSim is a **board-level executive decision simulator**, not a cyber quiz. It evaluates risk-proportionate choices across security, commercial continuity, regulation, finance, resilience and stakeholder priorities. A technically defensible response can perform poorly if it neglects business consequences; a business-preserving response can perform well if accompanied by bounded containment, accountable acceptance and regulatory action.

## Navigate the package

| File | Use |
|---|---|
| [00_PRODUCT_AND_SCOPE.md](00_PRODUCT_AND_SCOPE.md) | Product contracts, boundaries, roles, content taxonomy |
| [01_ARCHITECTURE.md](01_ARCHITECTURE.md) | Trust boundaries, state machine, API contracts, storage and replay |
| [02_SCORING_MODEL.md](02_SCORING_MODEL.md) | Exact integer scoring algorithm, weights, consistency and overrides |
| [03_DECISION_RULES.md](03_DECISION_RULES.md) | **Editable** `DR-xxx` scoring rules with dimension impact and evidence |
| [04_REGULATORY_REGISTER.md](04_REGULATORY_REGISTER.md) | Legal / guidance register, applicability and linked primary sources |
| [05_EXPLANATIONS_AND_METHOD.md](05_EXPLANATIONS_AND_METHOD.md) | Public methodology and deterministic 'Why 72?' explanation template |
| [06_INTERFACE_AND_ACCESSIBILITY.md](06_INTERFACE_AND_ACCESSIBILITY.md) | User journeys, board dialogue, profiles and accessible UI |
| [07_ACCEPTANCE_TESTS.md](07_ACCEPTANCE_TESTS.md) | Goldens, invariants, tamper tests and legal timing tests |
| [08_CONTENT_GOVERNANCE.md](08_CONTENT_GOVERNANCE.md) | Authoring, version pinning, approvals and source limitations |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [09_REPLAY_REPORT.md](09_REPLAY_REPORT.md) | Executed scoring enumeration and actual rule-level score trace |
| [12_CLOUDFLARE_ORIGIN_LOCK.md](12_CLOUDFLARE_ORIGIN_LOCK.md) | **Always on** — the origin answers only Cloudflare-forwarded traffic: the header rule, where the secret lives, rotation and verification |

| [engine/CONFIG.md](engine/CONFIG.md) | Single editable scoring policy and risk modifiers |
| [engine/RULE_SCHEMA.md](engine/RULE_SCHEMA.md) | Allowed syntax, semantic validation, deterministic evaluation |
| [scenarios/BS-001.md](scenarios/BS-001.md) – [BS-014.md](scenarios/BS-014.md) | Fourteen runnable, versioned scenario contracts |
| [SCENARIO_INDEX.md](SCENARIO_INDEX.md) | Scenario catalog and coverage matrix |

### How to customize scoring (without giving the LLM control)

1. Edit **engine/CONFIG.md** (weights, bounds, severity multipliers), **03_DECISION_RULES.md** (rule score deltas), or scenario option-to-rule mappings.
2. Validate against `engine/RULE_SCHEMA.md` and `07_ACCEPTANCE_TESTS.md`; changes that break invariants **must fail build**.
3. Bump `ruleset_version`, compute the SHA-256 of all scoring inputs, publish the new policy and **do not rewrite old runs**. The same facts, answers, pinned content and scoring version must reproduce the same totals and trace.
4. Do not present uncalibrated scores as empirical measures of professional capability. Labels are illustrative, not psychometrically validated.

### Source boundaries

**From the supplied DOCX:** overarching purpose; ten dimensions; the fourteen scenario names/order and core dilemmas; risk appetite, board personas, three difficulty levels; final seven-element board brief; evidence → principles → rules → consequences → judgment; free-form defense; transparency and non-employment disclaimer.

**New BoardSim design choices, not claims in DOCX or law:** numeric weights and point values, invented company identities, scenario dates and thresholds, option wording, indicators, test cases, fictional losses and simulated follow-on outcomes. These are intentionally editable and should always be tagged `SIMULATION_ASSUMPTION`.

**Regulatory rules:** primary source text is authoritative, not BoardSim's paraphrase. Regulation applicability must be gated by jurisdiction, entity type, role, materiality, time and specific trigger. An unconfirmed trigger is **UNKNOWN**, never automatically TRUE. See the regulatory register for verification status.
