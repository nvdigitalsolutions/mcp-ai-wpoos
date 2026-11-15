# 113 — QuickBooks Desktop MCP: Implementation Plan

- **Date:** 2026-11-05
- **Related:** `113-quickbooks-desktop-mcp-proposal.md` (decisions §8 pending), MS Access MCP setup (session 2026-11-05), user-level Zed `context_servers`

## Verified research facts (this session)

- **Intuit has no Desktop MCP.** The official MCP server is QuickBooks
  Online only. Desktop integrations run on the QB machine via the qbXML SDK
  (`QBXMLRP2.RequestProcessor` COM) or the Web Connector.
- **Product reality 2026:** Pro/Premier new sales ended July 2024; Desktop
  2023 support ended 2026-05-31; "QB Desktop 2026" in practice is
  **Enterprise 26.0**. Desktop 2022+ (incl. Enterprise) is 64-bit, so
  `QBXMLRP2` registers 64-bit COM and **64-bit Python works directly**
  (confirmed on Enterprise 2024 in the Schrex test matrix). The ≤2021
  32-bit trap (`0x800700C1` from 64-bit Python) does not apply to 26.0.
- **Two strong open-source bases were evaluated; one is unlicensed:**
  - `Schrex/qb-mcp` — read-only; query-only guard; trust stamps;
    sensitive-field redaction; replay-transport offline tests; qbXML XSD
    validation. **Rejected as the base: no LICENSE file** (GitHub license
    API 404 — all rights reserved). Its ideas are adopted as our own v1.1
    additions (trust stamps, redaction), not copied code.
  - `Compolaga/quickbooks-desktop-mcp` (QBBridge fork) — **chosen base
    (MIT)**: reads + writes; two-step confirm (HMAC over tool+file+
    request, one-time nonce); runs + revert (generated TxnDelRq/ListDelRq,
    own runs only); server-side business checks (duplicate invoice numbers,
    expected_total pre/post compare with immediate delete on mismatch,
    over-balance payments, unknown customer/item/account, ambiguous
    amounts); per-session qbXML version negotiation; every qbXML
    request/response logged. Offline test suite (133 upstream tests, no
    QuickBooks needed).
- **License finding (this session):** the fork-point choice was decided by
  licence, not preference — see `UPSTREAM.md`.
- **Authorization lifecycle (fixed by Intuit):** first connect shows a
  one-time in-product approval per app + per company file; the entry is
  managed under Edit > Preferences > Integrated Applications; the
  personal-data checkbox must stay unchecked; "even when QuickBooks isn't
  running" (unattended) is out of scope. Known dialog quirk: the "type
  yes" box takes no input until the "Learn more…" link is clicked once
  (QBBridge docs).
- **Machine state (verified):** Python 3.11 64-bit, pywin32 312, `mcp`
  2.0.0 SDK; `uv` 0.12.3 already installed (hermes bin). `QBXMLRP2` not yet
  registered (no QB installed). Old `.qbw` backups (2011–2014) in
  `C:\Users\rasta\Downloads\Jaya Backup May23\` — one-way upgrade on first
  open with QB 2026; must be backed up first.
- **Approval tiers (industry norm):** reads auto-approved; writes need
  per-call human approval **plus** server-enforced payload integrity
  (confirm code). The code proves the posted request equals the preview;
  the client's approval prompt is the human consent. Neither substitutes
  for the other.
- **Audit rule:** every qbXML request/response logged locally; no live
  data (names, amounts, ref numbers) ever in the repo, issues, or commits —
  counts and patterns only.

## Milestones

### M0 — Environment + fork (DONE, except the QB-install items)

- [owner] Install **QuickBooks Desktop 2026 (Enterprise 26.0)**; open once
  with an **Admin** login and a **copy** of a company file in **single-user
  mode** (never the live file, never the 2011–2014 backups without a prior
  backup).
- [owner/M1.5] Verify `reg query HKCR\QBXMLRP2.RequestProcessor` resolves
  with 64-bit registration.
- [done] Tracked copy of `Compolaga/quickbooks-desktop-mcp` into
  `examples/qb-desktop-mcp` (no submodule/subtree): upstream `.git`
  removed; fork-point SHA `fee09147af…` recorded in `UPSTREAM.md`;
  `README.nv.md` marks the folder a Python/uv foreign-stack project.
- [done] Repo hygiene: `.gitignore` (logs, `xsd/`, company files,
  `.venv`, `__pycache__`); scan exclusions added to the repo
  `file_scan_exclusions`; path-filtered CI
  `.github/workflows/qb-desktop-mcp-tests.yml` (offline suite, pinned uv
  0.12.3); `uv.lock` generated.
- **Gate met:** `uv sync && uv run pytest tests/test_offline.py` — **138
  passed** (133 upstream + 5 gate tests), no QuickBooks needed. The
  `qb_status`-reaches-the-copy check waits on M1.5 (QB install).

### M1 — v1 server + write-tier gate (DONE)

- [done] Base read/write surface shipped as-is from the fork.
- [done] **`QBBRIDGE_WRITE_MODE`** gate (readonly/safe/full, default
  **safe**) at the `_two_step` chokepoint; safe = list adds + revert;
  transactional tools refuse with a typed error until `full`.
- [done] uv-0.12 pyproject adaptation (`[tool.uv] dev-dependencies`);
  autouse full-mode fixture so upstream tests keep their semantics; 5 new
  gate tests.
- [deferred → v1.1] Trust stamps, redaction mapper, starter presets,
  qbXML XSD validation (proposal §4.1.2) — land with the tie-out work.
- **Gate met:** offline suite green in CI shape (`uv sync && uv run pytest`).

### M2 — Zed wiring + first-connect authorization (PARTIAL)

- [done] `nvoos-qb-desktop-mcp` context server added to user-level Zed
  settings (`enabled: false` until QB is installed + authorized; `uv run
  --project` with absolute uv path; `QBBRIDGE_WRITE_MODE=safe` and
  `QBBRIDGE_APP_NAME` pinned in env).
- [done] `docs/zed-runbook.md`: authorization click-through (incl. the
  "type yes" dialog quirk), Integrated Applications verification,
  personal-data box, tool-permission matrix, troubleshooting.
- [done] `.agents/skills/mcp-ai-wpoos-qb-desktop/` operational skill.
- [waiting on QB] Enable + first `qb_status`; authorization prompt;
  read tools auto-approved / write tools per-call in Zed permissions.
- **Gate (waiting on QB):** the Zed agent answers one real lookup question
  and the answer ties to the QuickBooks screen.

### M3 — Live tie-out on the copy

- Pick a **closed month** (Company > Set Closing Date) and 2–3 known
  jobs/vendors.
- Run the four checks: menu-path check list (paths validated against the
  actual QB 26.0 menus — the Schrex list was written from Enterprise 2024
  memory); job profitability summary; A/P aging summary + per-bill open
  balances (incl. bills with discounts/credits/partial payments); closed-
  month P&L on both bases. Every total must match QuickBooks **to the
  cent**; every stamp field (`basis`, `dates`, `filters`, `complete`)
  checked.
- Mismatch protocol: stop, note the pattern locally (no live data in
  issues), file a bug with placeholders only.
- Record the tie-out table in the repo tracker (counts only).
- **Gate:** all figures match; stamp checks hold; sign-off recorded.

### M4 — Safe writes (list adds only)

- Port the QBBridge write layer onto the fork, limited to **list adds**:
  `qb_create_customer`, `qb_create_item`, `qb_create_vendor` — additive
  master data, no ledger impact, clean `ListDelRq` revert. **No updates or
  deletes of existing records** (not additive).
- Two-step confirm: HMAC over (tool, open company file, exact qbXML
  request) + one-time nonce; preview-first, single-use, invalidated by any
  argument change.
- Runs + revert: every add lands in a run; `qb_revert_run` deletes exactly
  what the run created, reverse order, wrong-file guard.
- Duplicate-ref check before posting; ambiguous amounts refused.
- Zed: the three write tools overridden to **per-call approval** (repo's
  global `tool_permissions.default` is `allow` — the override is
  mandatory, not optional); reads stay auto-approved.
- Replay tests: confirm-code generation/invalidation/replay, revert
  ordering, duplicate refusal, negative paths on the copy (wrong code,
  changed args, wrong company file open).
- **Gate:** create customer/item/vendor → verify in QB → `qb_revert_run`
  → verify gone, all on the copy.

### M5 — Transactional writes (gated on M4 proof)

- `qb_create_invoice`, `qb_receive_payment`, `qb_deposit`,
  `qb_write_check`, `qb_journal_entry` with the full §4.3.2 business
  checks: duplicate invoice numbers; `expected_total` pre/post compare
  (mismatch deleted immediately); unknown customer/item/account refused;
  over-balance payments refused (incl. duplicate `apply_to`); ambiguous
  amounts refused. (Owner sub-decision: all five, or invoice + payment
  first.)
- Same confirm/revert/approval guardrails as M4; write tools remain
  per-call approval.
- **Gate:** invoice → verify in QB (incl. posted total vs expected_total)
  → revert → verify gone, on the copy. **Live books:** transactional tools
  stay copy-only until owner signs off each live session (policy, not
  config).

## Environment prerequisites (checklist)

| Item | State |
|---|---|
| QB Desktop 2026 (Enterprise 26.0) installed | **owner — M1.5** |
| `QBXMLRP2` 64-bit registration verified | **owner — M1.5** |
| `uv` installed (0.12.3, hermes bin) | **done** |
| Fork tracked in `examples/qb-desktop-mcp`, `UPSTREAM.md` SHA | **done** |
| Company-file copy (not live, not upgraded-from-2014 without backup) | **owner — M1.5** |
| Zed user-level `context_servers` entry `nvoos-qb-desktop-mcp` | **done (enabled: false)** |
| Offline test suite green (138) | **done** |

## Failure-contract taxonomy (adopt from the QBBridge/Schrex runbooks, verify against QB 26.0)

QuickBooks not running · no company file open · different file open ·
unauthorized (prompt denied) · dialog/window blocking (120 s timeout) ·
busy/starting · SDK components missing (repair QB) · request processor not
registered (no QB / bitness mismatch) · qbXML schema mismatch · QB error
code passthrough (3260 = insufficient permission level) · unexpected
response shape · **write tier refusal ("safe write tier" — the Proposal
113 gate, not a bug)**. Each maps to one typed error + remediation line;
the log (`%LOCALAPPDATA%\QBBridge\nv\log\`) carries request types and errors
only — never QuickBooks data.

## Tracker note (this repo)

File an issue `#qb-desktop-mcp` under the repo's standard tracker labels:
M0–M5 checklist, tie-out results (counts only), and the owner decisions
from proposal §8. No client names, amounts, or file paths of company data
in any issue/commit.
