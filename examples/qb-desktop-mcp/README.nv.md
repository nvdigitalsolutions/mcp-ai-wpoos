# qb-desktop-mcp — NV operational readme

QuickBooks Desktop MCP server for NV use (Proposal 113). A tracked copy of
[Compolaga/quickbooks-desktop-mcp](https://github.com/Compolaga/quickbooks-desktop-mcp)
(MIT, QBBridge lineage) — see `UPSTREAM.md` for fork point and adaptations.

**Upstream README.md (Dutch) stays authoritative for tool semantics.**
This file covers only the NV operational layer.

## What it is

A local stdio MCP server that lets an agent (Zed, Claude Desktop, …) read a
QuickBooks Desktop company file through qbXML over COM, and — in tiers —
write to it with two safety layers:

1. **Client-side approval** — write tools must be set to per-call approval
   in the client (never "always allow").
2. **Server-side confirm code** — every write is two-step: preview + HMAC
   `confirm_code` over (tool, company file, exact request), single-use.
   What gets booked is provably exactly what was previewed.

Every booking is undo-logged into a run; `qb_revert_run` deletes only what
the server itself created, newest first. The server cannot modify or delete
any transaction it did not create.

## Write tiers (QBBRIDGE_WRITE_MODE)

| Mode | Allowed to post | Use when |
|---|---|---|
| `safe` (**default**) | `qb_create_customer`, `qb_create_item`, `qb_add_vendor` + `qb_revert_run` | Day-to-day; Proposal 113 M4 tier |
| `readonly` | nothing (revert included) | Strict read-only posture; audits |
| `full` | all write tools (invoice, payment, deposit, check, journal entry, generic add) | Only after owner sign-off; Proposal 113 M5 tier |

The gate is enforced in `server._write_gate` at the single `_two_step`
chokepoint — a client misconfiguration cannot widen it.

## Run locally (dev / smoke test)

```powershell
uv sync
uv run pytest tests/test_offline.py   # 138 tests, no QuickBooks needed
uv run qbbridge-mcp                   # starts the stdio server
```

## Wire into Zed

User-level Zed settings → `context_servers` (see `docs/zed-runbook.md` for
the first-connect authorization click-through, which is mandatory once
QuickBooks Desktop 2026 is installed):

```jsonc
"nvoos-qb-desktop-mcp": {
    "enabled": false,  // enable after QB Desktop 2026 is installed + authorized (docs/zed-runbook.md)
    "command": "C:/Users/rasta/AppData/Local/hermes/bin/uv.exe",
    "args": ["run", "--project", "F:/GITHUB/mcp-ai-wpoos/examples/qb-desktop-mcp", "qbbridge-mcp"],
    "env": {
        "QBBRIDGE_WRITE_MODE": "safe",
        "QBBRIDGE_APP_NAME": "NV oOS QB Desktop MCP",
        "QBBRIDGE_DATA_DIR": "C:/Users/rasta/AppData/Local/QBBridge/nv"
    }
}
```

Notes:
- The MCP server identifies as **`nvoos-qb-desktop-mcp`** in client UIs;
  the console script stays `qbbridge-mcp` (upstream name, compatibility).
- `QBBRIDGE_APP_NAME` is the name QuickBooks shows in the authorization
  dialog and the Integrated Applications list. Set **before** the first
  authorization — the permission is per app name, so changing it later
  forces re-authorization.
- `QBBRIDGE_WRITE_MODE` defaults to `safe`; set it explicitly anyway.
- `QBBRIDGE_QBXML_MAX` defaults to 13.0 (upstream, for QB 2016). For QB
  Desktop 2026 raise it to the version QuickBooks supports — check with
  `qb_status` and set e.g. `16.0` only after `qb_status` shows it listed.
- Logs: `%LOCALAPPDATA%\QBBridge\nv\log\` — every qbXML request/response.

## Rules

1. **No live client data** in this folder, in issues, or in commits —
   counts and patterns only (`UPSTREAM.md` rule 1). Company names, amounts,
   ref numbers, customer/vendor names stay on the QB machine.
2. Test only on a **copy** of a company file, never the live file, until
   the tie-out (Proposal 113 M3) passes and the owner signs off.
3. Writes happen in single-user mode with an Admin logged in for the first
   authorization; verify the Integrated Applications entry afterwards
   (personal-data box **must** be unchecked).
4. `QBBRIDGE_WRITE_MODE=full` requires owner sign-off per live session
   (policy, not config).
