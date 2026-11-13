# Interface, content design and accessibility

## Landing / onboarding
Headline: **Executive Decision-Making Under Pressure**. Summary: Make the decision. Defend the decision. See the consequences. Two routes: 'Explore a five-minute board challenge' (unscored/public demo) and 'Start scenario'. Explain the nature of the fictional model, editable published scoring and privacy statement before assessment. Track selector: CISO, CIO, Executive; industry and difficulty visible before start.

## Scenario screen layout
Header: role/industry/risk appetite/board persona, simulated clock (not live deadline), current stage and accessible progress. Left: briefing and stakeholder quotes. Right: three equal-weight choice cards, each with business/security impact descriptions **without pre-revealing raw point totals**. 'Known/unknown' drawer; legal banner shows `PENDING_CLASSIFICATION` when scope is not established. One decision at a time, with confirm and no coercive timer. Screen-reader announcements for state changes; keyboard choice shortcuts, focus control, WCAG 2.2 AA contrast and touch targets.

## Consequence panel
Clearly mark 'simulated', display the selected rule IDs and fixed ledger effects and transition to next inject. Allow user to inspect previous facts and decisions; no retroactive editing in a scored run. Risk appetite settings are locked on run creation to prevent opportunistic changes.

## Board room
Five-minute **suggested** preparation timer (not scoring). Seven mandatory structured fields, including explicit unknowns and board action request. Three challenge cards from chair, CFO and CEO. Free text is for dialog/coaching only, never an undisclosed grading input. Clear 'graded structured checks' / 'ungraded coaching' language. If response text is submitted, provide controllable retention and deletion.

## Results page
Score with ten-dimensional table; actual delta audit; explicit 'Why this score?' button; consequences and financial ledger; separate regulatory panel with direct hyperlinks; board pack export; further reading; version/hash. A user must be able to expand every `DR-xxx`, see numerical deltas and source status. Show no fake decimal precision or unsupported percentile ranking.

## Profiles and shareables
3–5 completed scenarios per profile, with track breakdown and coverage; consent-based, pseudonymous share link. Never label the user qualified for CIO/CISO work. Allow export, deletion and revocation of public share; expire links by default.

## Internationalization
English first; Greek legal titles may appear with a translated UI summary, but the original act link remains present. Dates display timezone explicitly, simulated schedule and legal-awareness timestamp separately. Euros and percentages use locale formatting without changing stored integer calculations.
