# DEMO-001 — Five-minute board challenge

**DEMONSTRATION ONLY.** This scenario is never scored, never persisted and never
appears in the scored catalogue. It exists so a visitor can see how a decision
maps to rules and dimensions before committing to a full run.

Because the demo answer cannot be scored, it is not in `scenarios/`, it is not part
of the pinned scored catalogue, and it does not move `content_version`. A separate
namespace keeps the fourteen scored scenarios' pins — and the 2,268-run
baselines — exactly where they are.

```yaml
scenario_id: DEMO-001
version: 1.0.0
scenario_name: Five-minute board challenge
track: Demonstration
difficulty: leadership
persisted: false
scored: false
disclaimer: Fictional organization. This challenge is not scored, nothing is stored, and no legal applicability is asserted.
stages:
  - id: S1
    time: 09:40
    inject: A supplier reports that a staging database was exposed for several hours. Nobody yet knows whether customer records were in it, and the supplier is asking you to stay quiet until they finish their own review.
    options:
      - id: A
        label: Preserve the systems and establish what data is involved before anyone is told anything
        rules:
          - DR-003
          - DR-006
          - DR-019
      - id: B
        label: Tell every affected customer immediately, before the data set is understood
        rules:
          - DR-015
          - DR-033
      - id: C
        label: Accept the supplier's request and wait for their completed analysis
        rules:
          - DR-014
  - id: S2
    time: 11:05
    inject: The supplier confirms the staging copy held customer records. Your own engineers can show that the exposure window is real, and the regulator has not been contacted.
    options:
      - id: A
        label: Record the time we became aware and prepare the notification against that clock
        rules:
          - DR-006
          - DR-011
          - DR-039
      - id: B
        label: Keep investigating and notify once the full picture is established
        rules:
          - DR-014
      - id: C
        label: Call in the regulator's incident team now and document the handover
        rules:
          - DR-034
          - DR-039
```
