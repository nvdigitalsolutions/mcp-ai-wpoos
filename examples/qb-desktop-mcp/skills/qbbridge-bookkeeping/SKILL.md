---
name: qbbridge-bookkeeping
description: Classify and post bank/credit card statement transactions into QuickBooks Desktop via the QBBridge MCP tools, safely and without guessing. Use whenever the user provides a bank statement, credit card statement, or transaction list (CSV, QBO/OFX, or a PDF/image to transcribe) and asks to enter it, import it, reconcile it, or "do the bookkeeping" for a QuickBooks Desktop file.
---

# QBBridge bookkeeping

This skill is the judgment layer on top of the QBBridge MCP server. The
server (`qb_*` tools) only exposes safe, generic QuickBooks Desktop
operations -- it has no opinion about which vendor maps to which account,
when to trust a match, or how confident is confident enough. That
judgment lives here. Follow it exactly; it encodes rules that were learned
the hard way, by getting them wrong first against real client data.

**The one rule everything else follows from: never fabricate a vendor,
account, or classification. Every decision must trace back to either
existing evidence in the company file, or an explicit instruction from the
user in this conversation. If neither exists, flag it -- don't guess.**

**In this fork (quickbooks-desktop-mcp) the server itself enforces two rules
that override anything below:** (1) every write tool first returns a
preview and a confirm code; book only by calling the same tool again with
that code after the user approved that preview -- there is no "proceed
without stopping". (2) Existing transactions cannot be deleted or changed;
only this server's own runs can be undone, with `qb_revert_run(run_id)`.
Start every session with `qb_status`.

## 0. Before doing anything

1. Call `qb_company_info`. Confirm out loud, in your response, which
   company file is open and that it matches who the user says they're
   working on. If the user hasn't said which client/file this statement is
   for, ask -- do not infer it from the file being open.
2. Save that file path. Pass it as `expected_company_file` on **every**
   write tool call for the rest of this task. This is the single check
   that prevents posting to the wrong client's file, and it costs nothing
   to include every time.
3. If the user gives you a statement but QuickBooks isn't open, or is open
   on a file they didn't name, stop and ask -- don't guess which file they
   meant.

## 1. Parse the statement into normalized transactions

Whatever the source format (CSV, QBO/OFX, a PDF you're transcribing, a
screenshot), produce a plain list of `(date, description, amount)` before
doing anything else. Two things to get right here:

- **Sign convention.** Bank/card statements are not consistent about
  whether a purchase is positive or negative. Look at 2-3 obviously-a-
  purchase lines and 1-2 obviously-a-payment lines to determine the
  convention for *this* statement before processing the rest. State the
  convention you inferred back to the user in your summary so it can be
  caught if wrong.
- **Don't collapse near-duplicate lines.** Multiple charges from the same
  vendor on the same day for the same amount are usually real, separate
  transactions (e.g. several equipment purchases in one visit), not a
  parsing artifact. Only treat lines as duplicates per the dedup rules in
  section 3, never by eyeballing "these look similar."

## 2. Classify each transaction

For every transaction where the amount represents an outgoing
purchase/charge (see section 3 for the payment/credit direction, which is
handled differently):

1. **Look for a matching vendor.** Call `qb_list_vendors` (once per run,
   not per transaction) and match the statement description against it.
   Statement descriptions are noisy -- strip trailing city/state,
   reference numbers, POS terminal codes, and card-network prefixes
   (`SQ *`, `PST*`, `TST*`, etc.) mentally before matching. A vendor name
   existing in QuickBooks under slightly different spelling/casing than
   the statement (e.g. "STI COMPUTER SERVICES" vs. "STI Computer Services
   Inc.") is the normal case, not an exception.
2. **If a vendor matches, look up its real history.** Call
   `qb_vendor_history` for that vendor. Do not guess an account from the
   vendor's name alone (e.g. do not assume "STI Computer Services" is
   Computer Expenses just because it sounds like it) -- use what this
   specific company file has actually booked it to before.
3. **Apply the confidence rule.** If one account accounts for **70% or
   more** of that vendor's history, use it. If the history is split close
   to evenly across two or more accounts, that is a real signal that this
   vendor's transactions vary by context (e.g. a vendor billing both
   equipment and services) -- do not break the tie with a guess. Flag it
   (see section 4).
4. **If no vendor matches,** do not invent one and do not silently pick an
   account. Flag it (see section 4). Only create a new vendor if the user
   has explicitly told you to (by name, in this conversation) -- never
   create one speculatively to "resolve" a flag.
5. **Never re-derive an account/vendor name from memory once you have it.**
   Use the exact string as it came back from `qb_list_vendors` /
   `qb_vendor_history` / `qb_list_accounts` when building the transaction
   to post. Do not paraphrase, re-case, or otherwise reconstruct it by
   hand.

## 3. Payment/credit-direction lines are not vendor purchases

A line where money is moving *out* of this account toward paying down a
balance (e.g. "Payment Thank You", a large round-number credit) is not a
vendor transaction and must not go through section 2's classification at
all. Instead:

1. Call `qb_check_duplicate` for that date/amount, without restricting to
   one account -- the offsetting entry, if it exists, is often on a linked
   checking account, not this one.
2. If a match is found within the tolerance window, **do not post it** --
   it's already recorded from the other side. Say so in your summary.
3. If no match is found, **post it anyway**, routed to the fallback
   account (section 4). Banks and cards do not all cycle statements on the
   same schedule; the absence of a match usually just means the other
   side hasn't been entered yet, not that this transaction is wrong. Do
   not withhold it waiting for confirmation that never comes on its own.

## 4. The fallback account (never leave a transaction unposted)

When a transaction has no vendor match, no confident account, or is a
payment with nothing to match against, post it anyway -- to whichever
account this company file uses as its "needs a human to reclassify"
bucket (commonly named "Ask My Accountant"; confirm the exact name via
`qb_list_accounts` rather than assuming). Leave the payee blank rather
than guessing one. Always put the original, unmodified statement
description in the transaction's memo, so a human has enough information
to reclassify it later without re-reading the original statement.

**Never simply omit a transaction from what you post because you're
unsure about it.** Missing entries are worse than miscategorized ones --
they break the books' completeness, whereas a flagged entry is visible,
findable, and cheap to fix.

## 5. Duplicate detection before every post

Before posting a transaction, check whether it might already exist:

- Same account, exact date+amount+memo match: this is literally re-running
  the same statement twice. Skip it.
- Cross-account, date-tolerance match (see section 3): only for
  payment/credit-direction lines.

Use `qb_check_duplicate` for this rather than trusting that "the register
looked empty when I glanced at it" -- query it explicitly for the date
range you're about to post into, every time, even if you believe this is
the first time this statement has been processed.

**This cuts both ways.** It is just as easy to wrongly *exclude* a real
line as it is to wrongly duplicate one -- e.g. seeing a similarly-named
recurring charge (same vendor, close amount) on a *different* date in
prior history, and assuming today's statement line is "already posted"
without checking. A missing charge and a duplicated charge are the same
class of bug: an unverified assumption about what's already in
QuickBooks. Never exclude a statement line from posting because it
*resembles* an existing entry -- only exclude it after `qb_check_duplicate`
(or an equivalent direct query) confirms an exact date+amount match for
*that specific line*. If you catch yourself reasoning "this looks like
one I've already done," treat that as the trigger to go verify, not as
the verification itself.

## 6. Reporting and confirmation

Before posting anything, summarize for the user:
- Total transaction count, how many will auto-classify vs. flag, and the
  dollar total of each group.
- Every flagged transaction individually: date, description, amount, and
  *why* it's flagged (no vendor match / ambiguous history / unmatched
  payment).
- Any new vendor you're about to create, named explicitly, before creating
  it -- unless the user has already told you to create it as part of this
  request.

Ask for confirmation of each preview before posting it; a general "go
ahead" is not approval of a preview you have not shown. After posting,
always report:
- How many posted successfully vs. failed, with the reason for any
  failure.
- The run_id(s) the write tools return, and remind the user these can be
  reverted with `qb_revert_run` if anything needs correcting.

## 7. What this skill does not cover

- **Reconciliation troubleshooting** (finding why a bank statement total
  doesn't match the register, correcting historical entries, rebuilding a
  month's transactions from scratch) is a different, higher-judgment task
  involving reading scanned documents and making case-by-case calls. Don't
  attempt it under this skill's autopilot -- treat it as its own
  conversation, propose a plan, and get explicit sign-off on each
  corrective action before taking it (deleting/modifying *existing*
  entries is a different risk profile than adding new ones from a fresh
  statement).
- **Never bulk-delete or rebuild a range of existing transactions**
  without the user explicitly asking for that specific operation. Adding
  new transactions from a new statement and correcting old ones are
  different requests; don't conflate them.
