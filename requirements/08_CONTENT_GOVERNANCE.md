# Content governance and human-verified authority

## Chain of responsibility
- **Scenario editor:** drafts fictional facts, alternatives, consequences and explicit rule mappings; marks all invented values as `SIMULATION_ASSUMPTION`.
- **Scoring steward (you/admin):** chooses weights, dimensions, base/delta values and appetite modifiers; documents rationale and runs golden replay tests.
- **Legal subject-matter reviewer:** confirms every enabled legal scope and threshold, accurate consolidated act, Member State implementation, effective date and report clock; signs each `REG-xxx` legal predicate before production.
- **Engineering reviewer:** checks parser allowlist, deterministic calculation, immutability, traceability, test coverage, access control and data minimization.
- **Release manager:** archives exact Markdown source, normalized data, manifest SHA-256 hashes and version number; can roll back only by selecting an older immutable version.

## Authoring and update protocol
1. Read source and record whether exact rule is legal text, nonbinding guidance or BoardSim opinion.
2. Verify the official published version and current applicability; do not rely on summaries for legal triggers.
3. For scenario options, author clear executive dilemmas; avoid ridiculous choices unless a plausible pressure makes them defensible. All authored branches must state consequences, and no later outcome can change the evaluation of the prior information set.
4. For scoring, inspect distribution across all 27 paths and each appetite/board persona; check for an unfair always-best answer and adjust documented scoring *as a versioned policy*. Calibrate through executive expert review before claiming assessment validity.
5. Pin a bundle SHA-256 of normalized source. Run parser, link, replay, invariants, legal scope tests and human sign-off. Publish readable methodology and changelog alongside executable bundle.
6. Archive bundles and old traces; never retroactively edit scores. If policy correction needed, publish a re-score report with explicit old/new versions and consent.

## Scope of research completed versus deferred
Primary EUR-Lex legislation used to establish top-level requirements for NIS2, DORA, GDPR, CRA, AI Act and selected delegated acts, plus NIST CSF. The other many supplied Greek PDFs and EU sources are indexed in the register, **not represented as fully read or legally validated**. Reference to any external material is not a claim that every annex, exception or national implementation detail has been exhaustively checked. The requests explicitly prioritized authoritative sources; no non-authoritative secondary source is used to create an enforceable obligation.

## Assumptions and decisions needing eventual validation
- Numeric weights/deltas are invented starting policy, not empirically calibrated.
- GDPR, NIS2, DORA and AI reporting must operate through distinct trigger predicates; these examples are not full legal automation.
- Human review is needed for Greek incident workflow, any actual enforcement advice, and AI Act legal timing after 2026 amendments.
- Free-form board answers cannot be both automatically and deterministically scored without a predefined structured rubric; v1 scores checked fields only, and labels prose coaching as non-scoring.
- Scenarios here use authored 3-stage convergent branching; extension to full board-crisis narrative branching requires new content, not just LLM invention.
