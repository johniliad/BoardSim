# Profiles, retention and erasure

Decision status: **share links expire after 7 days** (agreed). Retention default and
deletion model still open — this document exists to close the second one.

## 1. What "no data kept unless selected" has to mean

A scored run cannot be computed without a server-side record: the event log *is* the
evidence, and `explain()` rebuilds the numbers from it rather than re-deriving them.
So "no data kept" cannot mean "no run exists on the server" while the run is in
progress.

The honest version is a **retention choice made at the end of the run**:

| Choice | What is stored | How long | Legal basis |
| --- | --- | --- | --- |
| Default, not selected | the run and its events, so the results page can be shown | **purged 7 days after finalisation** | necessary to deliver the requested result |
| "Add to my profile" | the run stays, plus an anonymous profile id in the browser | until the user deletes it | consent |
| "Share with a link" | a pseudonymous token pointing at the profile segment | **7 days, then dead** | consent |
| Demo (`#/demo`) | nothing at all — no store is ever touched | n/a | n/a |

Three consequences worth stating plainly:

- A run that is purged is **gone**: the share link loses its target, and the
  profile's aggregate changes because it is recomputed from the runs that remain.
  Profiles must therefore say "recomputed on <date> from N scenarios", not pretend
  to be a stable historical figure.
- The share token must be **opaque and unguessable**, not a run id. Run ids are
  already UUIDs, but a token should be independent so revoking it cannot be undone
  by guessing.
- Purging must be a real deletion of rows and events, not a hidden flag. A "deleted"
  run that still sits in a table is the exact failure this section exists to avoid.

## 2. Deletion versus the append-only log

This is the collision you asked about. `06` requires that a user can delete data.
The architecture says the event log is append-only and hash-chained, and that claim
is load-bearing: it is why a score can be replayed years later, and why tampering is
detectable.

A hash chain is a sequence where each event hashes the previous event's hash. That
has two properties that matter here:

1. You cannot remove an event from the middle without breaking every later link.
   `verifyChain()` would then report `broken_at` — **the same output it produces for
   deliberate tampering**. Deleting a user's data would look exactly like an attack.
2. The hash covers the payload. Changing a name or a timestamp *in place* also breaks
   the chain, for the same reason.

So there is no way to both "delete the content" and "keep the chain verifiable"
unless the two are separated. That gives three real options.

### Option A — Physical delete of the whole run

Delete the run row and all its events.

- Satisfies erasure completely and simply. Nothing left to leak.
- The chain for that run disappears; nobody can verify it, because there is nothing
  to verify. That is fine: erasure means the record no longer exists.
- Cost: the integrity claim becomes *"every run you hold is verifiable"* rather than
  *"every run ever scored is still here"*. The latter is stronger but is not
  compatible with erasure, and pretending otherwise is worse than choosing.
- Cost: any profile that included the run silently changes. Must be disclosed.
- Effort: small.

### Option B — Tombstone plus crypto-shred

Encrypt the payload fields of each run with a per-run key. On erasure, delete the
key. The hashes are computed over ciphertext, so the chain still verifies; the
content becomes unrecoverable.

- The only design that keeps *both* erasure and a verifiable chain.
- Cost: real work — per-run key generation, a key store, encryption at rest, and a
  key-destruction path that itself has to be audited.
- Cost: key loss and key deletion become indistinguishable accidents, so you need
  operational care that a single-host PHP app may not have.
- Effort: large.

### Option C — Delete the share token only

The run is kept; the public access is revoked.

- Fully satisfies "revocation of public share" and "expire by default".
- Does **not** satisfy "deletion of my data". It must be described as what it is:
  *you can remove public access at any time; full erasure is available on request.*
- Effort: small.

### Decision: Option A — physical delete of the run

Chosen. Implemented as `DELETE /v1/runs/{id}` → `RunService::delete()` →
`RunStore::delete()`, which removes the run row, its events and its board
selections, and returns how many events actually went so the confirmation is
truthful. MySQL deletes children first inside one transaction, so it works whether
or not the foreign keys cascade. There is no soft-delete flag.

What this buys, and what it costs:

- **Erasure is self-service and immediate.** No request, no waiting period, no
  discretion on our side. That is stronger than the "on request" variant and it is
  why the consent text below can promise deletion rather than offer it.
- **The integrity claim narrows truthfully** to *"every run you hold is verifiable"*.
  It cannot be *"every run ever scored is still here"*, because erasure and
  permanent retention are opposites. The acceptance record says so rather than
  leaving the stronger claim implied.
- **A deleted run cannot be verified — because it no longer exists.** The
  confirmation says exactly that, so nobody later reads a 404 as data loss.
- **Profiles recompute.** A profile that included the erased run changes, so profiles
  must be labelled "recomputed from N scenarios" and never presented as a fixed
  historical figure.
- **Possession of the run id is the capability.** Run ids are random UUIDs and no
  endpoint lists them, so this is the same bearer model as a share link. It is
  stated in the API docblock and in the consent text, not left implicit.

Two follow-ups this creates:

1. **Grants.** Least privilege now needs `DELETE` on `runs`, `run_events` and
   `run_board`, not just `INSERT`/`SELECT`. Verify that on the host alongside the
   MySQL seam.
2. **Backups.** We do not exclude run tables from backups, and rotation is 7 days. The consent text
   therefore states the real ceiling: 7 days live plus up to 7 days in backup, so a copy can exist
   for 14 days. "Not recoverable" is true of every interface we offer and false of the backup until
   it rotates — the text now says exactly that instead of overclaiming.


## 3. Consent and privacy wording — version 1 (SUPERSEDED)

> Superseded by version 2 in section 4, because v1 assumed Option C plus
> erasure-on-request. Kept rather than deleted: a governance document that silently
> rewrites its own history is not a governance document.

> **DRAFT — NOT LEGAL ADVICE. Requires review by a qualified data-protection adviser
> before publication.** Items in [square brackets] are decisions only you can make.
> Written for the Greek/EU context of `boardsim.iliadis.net`.

Suggested placement: a panel on the results screen, shown *before* any "add to
profile" or "create share link" action, with both checkboxes unticked by default and
a plain "keep nothing" path that needs no action at all.

---

**Before you save anything**

BoardSim is a simulated decision-making exercise. Your score measures how your
choices compare with one published scoring policy — not your competence, and not your
suitability for any role. Nothing here is a psychological or employment assessment.

If you do nothing, the scenario you just completed is deleted within 24 hours.

You can choose two separate things, and each is optional:

☐ **Keep this in my profile.** Stores this scenario's result under a random profile
identifier held in this browser, so that three to five scenarios can be combined into
a profile. Kept until you delete it. No name, no email, no account.

☐ **Create a share link.** Creates an unguessable link that shows your profile to
anyone who has it. It stops working after **7 days**, and you can revoke it before
then.

**What is stored:** the scenario identifier, your three choices, the board fields you
selected, the resulting dimension scores, the total, and the policy and content
versions used. If you create a share link, the link token and its expiry. That is
all. No IP address, no device fingerprint, no advertising identifiers, and no free
text — the board room does not accept free text at all.

**What is not stored:** the five-minute challenge (nothing is saved), the pages you
viewed, and anything you typed outside the structured board fields.

**Who is responsible:** [LEGAL ENTITY], [ADDRESS], [CONTACT EMAIL], with details at
[PRIVACY URL]. Data protection officer, if appointed: [DPO CONTACT].

**Why we are allowed to process it:** to produce the result you asked for, and — only
if you tick a box above — on the basis of your consent, which you can withdraw at any
time by deleting your profile.

**Who else sees it:** only the hosting provider acting on our instructions
([HOST, COUNTRY]). Nothing is sold, and nothing is transferred outside the EU/EEA.

**Your rights:** you can ask for a copy, correction or deletion of what is held about
you, object to processing, or withdraw consent, at [PRIVACY URL] or
[CONTACT EMAIL]. You can complain to the Hellenic Data Protection Authority
(dpa.gr). Because profiles are pseudonymous, we may need the profile identifier from
your browser to find your data — it is shown on your profile page for exactly that
reason.


## 4. Consent and privacy wording — version 3 (decisions A + 7-day ceiling)

> **DRAFT — NOT LEGAL ADVICE. Requires review by a qualified data-protection adviser
> before publication.** Items in [square brackets] are yours to fill. Supersedes
> version 1: deletion is now self-service and permanent, and the text says so.

Suggested placement: a results-screen panel shown *before* any "add to profile" or
"create share link" action, both boxes unticked by default, with "keep nothing" as
the path that needs no action.

---

**Before you save anything**

BoardSim is a simulated decision-making exercise. Your score measures how your
choices compare with one published scoring policy — not your competence, and not your
suitability for any role. Nothing here is a psychological or employment assessment,
and no automated decision is made about you.

**If you do nothing, the scenario you just completed is deleted 7 days after you finish it.**

Seven days is the ceiling for the live system: a run, and the link that shares it. Nothing stays
available longer than that. **Backups are the exception:** we do not exclude these tables from
backups, so a deleted run may survive inside a backup for up to **7 days** until that backup
rotates. With a 7-day live ceiling and a 7-day rotation, the worst case is that a copy exists
for **14 days** after you finish a scenario. It is never served, never used, and never restored.

You can choose two separate things, and each is optional:

☐ **Keep this in my profile.** Stores this scenario's result under a random profile
identifier held in this browser, so three to five scenarios can be combined into a
profile. No name, no email, no account.

**Each result is still deleted 7 days after you finish it**, even when it is in your
profile. A profile needs three to five scenarios, so it has to be completed inside
that week — the profile page shows how many days you have left on your oldest result.
If a result expires, the profile is recalculated from the ones that remain and says
how many that is.

☐ **Create a share link.** Creates an unguessable link that shows your profile to
anyone who has it. It stops working after **7 days** — the same seven days — and you
can revoke it sooner.

**You can delete any result yourself, immediately and permanently.** The results page
has a delete button. It removes the run, your three decisions and your board
selections from our server. It is not hidden or flagged — it is gone. We cannot restore
it from the live system, and we will not restore it on request. A copy may remain inside a
backup for up to 7 days, until that backup rotates; it is not used for anything and is not
recoverable through any interface we offer.

Because the record is gone, a deleted result can no longer be replayed or verified.
That is not a fault: it is what deletion means. If a deleted result was part of a
profile, the profile is recalculated from the scenarios that remain, and the profile
page shows how many that is.

**One thing to be aware of:** your result lives at a web address that contains its
run identifier. Anyone you give that address to can open the result **and delete it**.
Treat it like a document link. Share links are separate, expire after 7 days, and can
be revoked without deleting anything.

**What is stored:** the scenario identifier, your three choices, the board fields you
selected, the dimension scores, the total, and the policy and content versions used.
If you create a share link, the link token and its expiry. That is all. No IP
address, no device fingerprint, no advertising identifiers, and no free text — the
board room does not accept free text at all.

**What is never stored:** the five-minute challenge (nothing is saved), the pages you
viewed, and anything typed outside the structured board fields.

**Who is responsible:** [LEGAL ENTITY], [ADDRESS], [CONTACT EMAIL], with details at
[PRIVACY URL]. Data protection officer, if appointed: [DPO CONTACT].

**Why we are allowed to process it:** to produce the result you asked for, and — only
if you tick a box — on the basis of your consent. You can withdraw consent at any
time by deleting the result or the profile.

**Who else sees it:** only the hosting provider acting on our instructions
([HOST, COUNTRY]). Nothing is sold, and nothing is transferred outside the EU/EEA.

**Your rights:** copy, correction, deletion, objection, and withdrawal of consent, at
[PRIVACY URL] or [CONTACT EMAIL]. Since deletion is built in, most requests are
unnecessary — use the delete button and it happens now. You can complain to the
Hellenic Data Protection Authority (dpa.gr). Because profiles are pseudonymous we may
need the profile identifier from your browser to find your data, which is why it is
shown on your profile page.

---

### What the adviser still needs to settle

1. **Legal entity, address, contact and a privacy URL that resolves.**
2. **Backup rotation is 7 days — confirm the mechanism matches the sentence.** The text now says
   a copy survives "for up to 7 days, until that backup rotates", worst case 14 days total. That
   is only true if rotation actually discards the oldest set on a 7-day cycle. If your provider
   keeps weekly *and* monthly sets, the honest number is longer and the text has to say so.
3. **Whether 7 days is right, or merely cautious.** A week is a demanding ceiling for
   a three-to-five-scenario profile; it is defensible on minimisation and awkward for
   anyone using this in a workshop spread over a month. Either way it needs the purge
   job scheduled and monitored — it now exists as `bin/purge.php` and must be in cron.
4. **Whether consent is the right basis** for the profile, or whether it is necessary
   to provide the service the user asked for.
5. **Record of processing** and the purge job's audit trail — documents, not code.
6. **Whether a storage banner is needed** for the profile identifier. It is strictly
   functional and holds only an opaque id, but that is the adviser's call.

### One consequence to accept deliberately

With Option A, someone who holds a result address can erase that result. That is the
honest cost of a bearer-capability model with no accounts, and the text above does not
hide it. If you would rather that copying a result address *not* confer deletion, the
fix is the same layer the profile already needs — an owner token in the browser,
checked on `DELETE` — and it should be added when the profile ships, not before.

**Automated decisions:** none are made about you. The score is arithmetic over a
published policy. It decides nothing, is never shared with an employer, and carries
no legal or similarly significant effect.

---

### Points the adviser will want to settle

1. **Legal entity, address and contact** — the placeholders above, plus a privacy URL
   that actually resolves.
2. **Whether the 24-hour default purge is necessary or merely cautious.** Holding a
   run only long enough to render the results page is arguably better; it needs a
   purge job that is scheduled and monitored, which is the real cost either way.
3. **Whether consent is the right basis** for the profile, or whether it is
   "necessary to provide the service" the user asked for. Consent is the easier
   position to defend and the easier one to withdraw, which is why it is proposed.
4. **The controller's record of processing** and the purge job's audit trail — both
   are documents, not code, and neither exists yet.
5. **Whether a storage banner is needed** for the profile identifier. It is strictly
   functional (it holds only an opaque id), which usually falls outside consent
   requirements, but that is the adviser's call, not mine.
