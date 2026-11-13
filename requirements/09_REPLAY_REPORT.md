# Verified example trace and replay report

**Engine basis:** exact YAML inputs under current version 1.0.0; `score = (weighted_numerator + 50) // 100`. Test enumerated **2,268** combinations: all 14 scenarios × 3 appetites × 27 paths × 2 board checklist extremes. All outcomes fell within 0–100; minimum = **58**, maximum = **84**, mean = **71.15**. This is a software arithmetic check, **not** a psychological validation or legal compliance test.

## Worked actual rule trace
Scenario `BS-001`, `balanced` appetite, options `A,A,A`, all seven board checklist fields substantiated (a synthetic test vector). Initial ten dimension values = 70. The following explicit rules fired in deterministic stage/order:

| Stage | Rule | Nonzero dimension changes |
|---|---|---|
| S1 | [DR-001](03_DECISION_RULES.md) | DQ +5, LE +4, SM +3 |
| S1 | [DR-003](03_DECISION_RULES.md) | RJ +5, DQ +3, RG +2 |
| S1 | [DR-004](03_DECISION_RULES.md) | RJ +4, RE +3, BI -4, FJ -2 |
| S2 | [DR-005](03_DECISION_RULES.md) | RE +7, BI +3, FJ -2 |
| S2 | [DR-019](03_DECISION_RULES.md) | RG +4, RJ +4, DQ +3 |
| S2 | [DR-035](03_DECISION_RULES.md) | FJ +3, LE +4, RE +4 |
| S3 | [DR-006](03_DECISION_RULES.md) | RG +6, DQ +3, SM +2 |
| S3 | [DR-011](03_DECISION_RULES.md) | CO +6, SM +5, LE +2 |
| S3 | [DR-039](03_DECISION_RULES.md) | RG +7, DQ +3, CO +2 |
| BOARD | [DR-041](03_DECISION_RULES.md) | CO +2 |
| BOARD | [DR-042](03_DECISION_RULES.md) | BI +2 |
| BOARD | [DR-043](03_DECISION_RULES.md) | RJ +2 |
| BOARD | [DR-044](03_DECISION_RULES.md) | DQ +2 |
| BOARD | [DR-045](03_DECISION_RULES.md) | FJ +2 |
| BOARD | [DR-046](03_DECISION_RULES.md) | RE +2 |
| BOARD | [DR-047](03_DECISION_RULES.md) | LE +2, SM +2 |

**Final dimensions:** SJ=70, BI=71, RJ=85, DQ=89, LE=82, CO=80, RG=89, FJ=71, RE=86, SM=82. **Weighted numerator:** 8058. **Final score:** `81/100`.

**Reproducibility constraint:** use exact canonical policy bundle and selected structured board fields. None of these integers is generated or interpreted by an LLM. The board checklist fields are assumed synthetically substantiated for this test; an actual run must validate real structured fields before applying them.
