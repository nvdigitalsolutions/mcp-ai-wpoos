---
name: mcp-ai-wpoos-qb-desktop
description: Operate the QuickBooks Desktop MCP server (Proposal 113) in examples/qb-desktop-mcp — read tools, the safe write tier (customer/item/vendor adds), the two-step confirm + revert flow, and the QBBRIDGE_WRITE_MODE gate. Use when working with QuickBooks Desktop data, booking to a company file, or re-enabling writes after owner sign-off.
---

# QB Desktop MCP — operational guide

Local stdio MCP server (`nvoos-qb-desktop-mcp` in Zed context_servers)
that reads a QuickBooks Desktop company file via qbXML over COM and
writes only in tiers. Fork of `Compolaga/quickbooks-desktop-mcp` (MIT,
QBBridge lineage).

## Load order

1. `examples/qb-desktop-mcp/README.nv.md` — NV ops layer, write tiers.
2. `examples/qb-desktop-mcp/docs/zed-runbook.md` — first-connect
   authorization, tool permissions, tie-out.
3. Upstream `examples/qb-desktop-mcp/README.md` (Dutch) — tool semantics.
4. `docs/project/proposals/113-quickbooks-desktop-mcp-*.md` — the plan.

## Hard rules (never bend)

1. **No live client data anywhere in the repo** — names, amounts, ref
   numbers stay on the QB machine. Issues/commits carry counts only.
2. **Test only on a copy** of the company file until the M3 tie-out passes.
3. **Two-step always**: every write = preview → show to user → explicit
   approval → same args + `confirm_code`. The code is HMAC over
   (tool, company file, exact request), single-use.
4. **`QBBRIDGE_WRITE_MODE`** (default `safe`) is server-enforced: never
   suggest widening it; `full` requires owner sign-off per live session.
5. Writes happen with QB open as the same Windows user as Zed, single-user
   mode; the Integrated Applications entry must keep the personal-data box
   unchecked.

## Write tiers

| Mode | Tools allowed to post |
|---|---|
| `readonly` | none (revert included) |
| `safe` (default) | `qb_create_customer`, `qb_create_item`, `qb_add_vendor`, `qb_revert_run` |
| `full` | + `qb_create_invoice`, `qb_receive_payment`, `qb_deposit`, `qb_write_check`, `qb_journal_entry`, `qb_add_transaction` |

## Workflow

1. Start with `qb_status` (reachable? company file? qbXML version?).
2. Read with `qb_list_*` / `qb_search_*` / `qb_find_*` — auto-approved.
3. Before any write, confirm the open file (`qb_company_info`) matches the
   intended client.
4. Safe-tier writes: preview first, then confirm after explicit approval;
   group related postings into one `run_id`, undo with `qb_revert_run`.
5. Transactional tools in `safe` mode refuse with "safe write tier" — that
   is the gate working, not a bug; escalate to the owner, never around it.

## Verification

Offline suite (no QB needed): `cd examples/qb-desktop-mcp && uv run pytest
tests/test_offline.py -q` — 138 tests incl. the write-tier gate. Live checks
per `docs/zed-runbook.md` §5 (tie-out to the cent against QB's own reports).
