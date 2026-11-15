# Zed runbook — qb-desktop MCP (Proposal 113, M2)

One-time setup, done on the machine that runs QuickBooks Desktop. Adapted
from the upstream QBBridge install flow for a **uv-run** server instead of
the upstream PyInstaller installer.

## 0. Prerequisites

- QuickBooks Desktop **2026 (Enterprise 26.0)** installed, 64-bit.
- QuickBooks opened **as Admin, single-user mode**, with a **copy** of the
  company file (never the live file for the first runs).
- Zed with the `nvoos-qb-desktop-mcp` context server entry in user-level
  settings (see `README.nv.md`). Start with `"enabled": false`, then
  enable it here.

## 1. Verify the environment

```powershell
reg query HKCR\QBXMLRP2.RequestProcessor
# must resolve; a missing key = QB SDK components not registered (repair QB)
C:\Users\rasta\AppData\Local\hermes\bin\uv.exe run --project F:\GITHUB\mcp-ai-wpoos\examples\qb-desktop-mcp qbbridge-mcp
# stdio server starts; Ctrl+C after it prints nothing (it waits for MCP input)
```

## 2. First connect — the authorization prompt

1. Enable `nvoos-qb-desktop-mcp` in Zed settings, restart Zed.
2. In the Zed agent panel ask: **"call qb_status"**.
3. QuickBooks shows a one-time prompt **"NV oOS QB Desktop MCP … is
   requesting permission"** (name set by `QBBRIDGE_APP_NAME`). Choose:
   - **"Yes, always allow access; even when my QuickBooks isn't running"**,
     or at minimum "Yes, whenever this QuickBooks company file is open".
   - Known QuickBooks bug: the "type yes" box takes no input until you
     click the **"Learn more about permissions…"** link once, close the
     browser, then type `yes` → Continue → Confirm.
   - The **personal-data box must stay unchecked**.
4. If Zed reported a timeout (the server waits 120 s) or "access denied"
   for the request that raised the prompt, ask **"call qb_status"** again.
5. Verify what QuickBooks recorded: **Edit > Preferences > Integrated
   Applications > Company Preferences** → select the app → **Properties**:
   - personal-data box: **unchecked** (it can come out checked — fix it);
   - "even when QuickBooks isn't running": unchecked is the safe posture.
6. Check `qb_status` output: `qbxml_version_in_use` — for QB 2026 raise
   `QBBRIDGE_QBXML_MAX` (default 13.0) to the highest version `qb_status`
   lists, and restart the server.

Permission is **per company file and per program version** — a new build or
a different file raises the prompt again. Revoke under Integrated
Applications.

## 3. Client-side tool permissions (mandatory)

In Zed's agent tool permissions:

- Read tools (`qb_status`, `qb_list_*`, `qb_search_*`, `qb_find_*`,
  `qb_query_*`, `qb_check_duplicate`, `qb_company_info`,
  `qb_account_balance`, `qb_vendor_history`, `qb_list_currencies`,
  `qb_list_runs`): **allow**.
- Write tools (`qb_create_*`, `qb_add_*`, `qb_receive_payment`,
  `qb_deposit`, `qb_write_check`, `qb_journal_entry`, `qb_revert_run`):
  **per-call approval (ask)** — never "always allow". The confirm code
  proves the posted request equals the preview; the Zed approval prompt is
  the human consent.

## 4. First write (safe tier) on the copy

Ask the agent: **"create customer 'ZZZ Test' and show me the preview"**.
The agent must show a preview first; approve; then ask **"revert that run"**.
Verify in QuickBooks that the customer appeared and then disappeared.

## 5. Live tie-out (Proposal 113 M3) — before the live file

On the **copy**, run the tie-out from the proposal (closed-month P&L on
both bases, A/P aging, per-bill open balances) and check every total
against QuickBooks' own reports to the cent. Record counts only in the
tracker issue `#qb-desktop-mcp`; never names or amounts.

## Troubleshooting (short)

| Message | Fix |
|---|---|
| QuickBooks isn't running / no file open | Open QB (same Windows user as Zed, **not** as administrator) and the copy file |
| Authorization prompt was denied | Integrated Applications → remove the app → redo §2 as Admin |
| Request processor isn't registered | Repair QuickBooks (Control Panel → Programs) |
| SDK missing / 32-bit mismatch | QB 2026 must be the 64-bit edition; confirm `reg query` in §1 |
| Write refused "safe write tier" | Expected for transactional tools — see `QBBRIDGE_WRITE_MODE` in `README.nv.md`; do not widen without owner sign-off |
