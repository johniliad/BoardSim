# Instructions for Scenario Rewriting (BoardSim)

## Context
You are tasked with rewriting the content of 14 cybersecurity simulation scenarios (`BS-001.md` through `BS-014.md`) for the web app "BoardSim". The target audience for this simulator is **Board-level Executives**. Currently, the text is too technical and verbose. Your goal is to shift the tone to focus on **business risk, clear stakes, and strategic decision-making**.

## 🛑 STRICT CONSTRAINTS (PRESERVE APP LOGIC) 🛑
To ensure the BoardSim web app continues to function correctly, you MUST adhere to the following rules:
1. **Preserve all File Structure:** Do not change the overall formatting, spacing, or markdown structure of the files.
2. **Do Not Alter Keys or IDs:** Do NOT change any internal IDs, file names, scenario IDs, or YAML/configuration keys.
3. **Preserve Scoring/Dimension Logic:** If a scenario option affects specific dimension variables (e.g., `Reputation`, `Budget`, `Security`), do NOT change these variables or alter their numerical impacts.
4. **Modify ONLY Text Fields:** You are strictly limited to rewriting the human-readable text values, specifically:
   - Scenario Title (keep it concise and professional)
   - Scenario Description / Prompt
   - Option Labels/Descriptions
   - Outcome/Feedback Text

## ✍️ STYLE GUIDE FOR REWRITING

### 1. Translate Cyber Jargon into Business Risk
Board members care about financial, reputational, legal, and operational impacts, not technical details.
*   *Avoid:* "A severe SQL injection vulnerability (CVE-2023-XXXX) has been detected on the CRM."
*   *Use:* "Customer data is actively exposed due to a critical software flaw, risking major regulatory fines and reputational damage."

### 2. Use the BLUF (Bottom Line Up Front) Format
Structure every scenario description clearly and concisely. Do not write mystery novels; get straight to the point:
*   **The Threat:** What is happening right now?
*   **The Stakes:** What is at risk (revenue, compliance, brand, downtime)?
*   **The Ask:** What specific strategic decision is required from the board?

### 3. Make Options Distinct and Actionable
Frame choices around strategic risk management (Accept, Mitigate, Transfer, or Investigate). Make them sound like board-level authorizations.
*   *Avoid:* "Investigate." or "Patch the server."
*   *Use:* "Authorize $50k for an external forensics firm to determine the scope." or "Approve immediate emergency downtime (causing a 4-hour sales outage) to apply the fix."

### 4. Meaningful Feedback Loops (The "So What?")
When a player reads the outcome of their decision, the text must explain the *chain reaction* and *business consequence* of that choice. It should explain *why* their score changed.
*   *Avoid:* "Incorrect. You failed compliance. -10 points."
*   *Use:* "By choosing to delay the public disclosure, the media leaked the breach first. The company lost control of the narrative, resulting in a severe drop in public trust and an immediate regulatory investigation."

## Execution
Process the provided `.md` scenario files, applying these writing rules strictly to the text fields while leaving all structural and mechanical logic completely untouched.