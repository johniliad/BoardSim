# Product, goals and scope

## Positioning and target executives
**Tagline:** Executive Decision-Making Under Pressure. **Promise:** Make the decision. Defend the decision. See the consequences. Primary audiences: CIOs, CISOs, CTOs and technology executives; tracks: CISO / CIO / Executive. Not a certification, hiring test, regulatory opinion or security incident-response system.

## What is measured — source-derived ten dimensions
`SJ` strategic judgment; `BI` business impact; `RJ` risk judgment; `DQ` decision quality; `LE` leadership; `CO` communication; `RG` regulatory judgment; `FJ` financial judgment; `RE` resilience; `SM` stakeholder management. Each is reported 0–100, with source-preserving narrative strengths and growth areas. Do not silently swap dimensions for NIST functions.

## Core workflows
1. Choose track, scenario, difficulty, enterprise risk appetite (`conservative`, `balanced`, `aggressive`) and board persona (`risk_conservative`, `growth`, `financial`, `regulatory`, `balanced`).
2. Present scenario profile: sector, entity, size, technology, footprint, regulated-entity applicability assertions, priorities, constraints and initially known facts. Display only facts visible at that stage.
3. Make discrete decisions, with three defensible but consequential options per stage. Record each option atomically. Reveal information sequentially based on **pre-authored** state transitions; never use an LLM to invent causal events.
4. Conduct board briefing (situation; business impact; risk; decision; investment; residual risk; exact board action) and three board challenges. Capture structured selections (must determine numeric score) and optionally narrative answers (coaching only).
5. Produce a signed explainable trace, dimension scores, business outcome summary, board pack, and evidence links. After 3–5 completed scenarios, produce a portfolio executive profile with itemized versions.

## Three difficulty levels
- `executive`: ~10–15 minutes; a primary decision; abbreviated but still auditable board prompt.
- `leadership`: ~25–30 minutes; three sequenced decisions and stakeholders; default fully authored level here.
- `board_crisis`: ~45–60 minutes; same canonical rules but additional pre-authored injects, board/media pressure, longer narratives; stage count must be declared explicitly before availability.

**Status of this package:** all fourteen have **three canonical choice stages**, a board challenge set and a seven-part board pack. They are full `leadership` scenario specifications, with `executive` implemented as a marked subset and `board_crisis` reserved as an extension (not falsely claimed as already authored). The board dialogue itself may be generated, but cannot change scores.

## UX principles
- Incomplete information is intentional; never reveal latent flags early.
- No objectively 'correct' A/B/C answer. Multiple options have tradeoffs and risk-appetite modifiers.
- Evaluate choice quality at decision time; do not use outcome hindsight.
- Deliberately differentiate simulated business consequences from score deltas.
- No real people, production secrets or actual personal data in scenario payloads.
- For audit display, separate **legal obligation**, **framework guidance** and **BoardSim preference** with a legend.
