# Proposal 093 — F&B Multi-Connection Source Routing (per-table Drive catalog + auto-map review UI)

**Status:** Implemented (phase 1 & 2 shipped)
**Date:** 2026-10-27
**Related:** PR #7244 (Drive auto-fallback), Proposal 078 (F&B universalisation)

## Problem

Pro operators split the SCM data across several Google Drive connections
(sales, kitchen-bar, finance, assistant-setup), one folder each. The F&B
toolkit read everything through a **single** toolkit-global `connection_id`,
so:

1. With several enabled Drive connections and no global default, the Drive
   client's auto-fallback (PR #7244) correctly refused to guess —
   `wp_mcp_ai_drive_ambiguous_connection` — but the F&B tools surfaced that
   raw error. The assistant then bypassed the metric engine entirely
   (`search_drive`/`get_drive_file` + manual arithmetic), producing the right
   number through the wrong path.
2. `fnb_read_table`/`fnb_search_table` had **no `connection_id` parameter at
   all** — a prompt instructing "always pass the connection ID" was dead on
   arrival.
3. Cross-connection calculations (dish margin = Menu price from one
   connection + Recipes/Supplier prices from another + formula from a third)
   were impossible by construction: one calculation, one connection.
4. `table_map` (the operator-authored, highest-trust mapping) had **no admin
   UI** and no connection/folder fields.

## Industry research

- **Never let the model resolve infrastructure.** Multi-tenant agent
  guidance is consistent: "the agent's tool schema doesn't include tenant
  parameters — every downstream query is tenant-scoped by the architecture,
  not by the agent's behavior" (dnxtsolutions.com); "a tenant_id in a prompt
  or model-generated argument is not isolation" (cohesivity.ai). Connection
  IDs belong in a deterministic catalog, not in prompts.
- **Federated-query catalogs.** Trino/Gravitino-style semantic layers map
  each entity/table to its source catalog; the engine joins across catalogs
  transparently and the client never resolves where data lives
  (contextawareanalytics.com, stackable.tech). This is the exact analogue of
  what the F&B metric engine needs.
- **Tiered review for auto-mapping.** Dataversity's LLM data-mapping pattern:
  high-confidence mappings are presented in a summary view for rapid bulk
  approval; medium/low-confidence mappings are surfaced individually with
  the proposer's rationale, similarity score and side-by-side sample data.
  Reviewers accept, reject, or edit each entry; accept/reject actions are
  audited and improve future suggestions. (The universal reader's existing
  manifest — file → role → confidence → sample rows — is the "proposer" to
  which the review UI attaches.)

## Design

### Phase 1 — deterministic per-table source catalog (core routing)

- `table_map` entries gain optional `connection_id` + `folder_id` keys
  (alongside the existing `sheet`/`file` hints). Operator-authored = highest
  trust, preserved by auto-map unless overwrite is explicit.
- New `WP_MCP_AI_Fnb_Data_Source::resolve_source( $table_id,
  $connection_override )` — precedence: (1) tool-argument override,
  (2) `table_map` entry, (3) toolkit-global settings (`connection_id`,
  `data_folder_id` / `setup_folder_id` for assumptions, `data_workbook_id`),
  (4) empty → the Drive client's own auto-fallback (single enabled resolves;
  several = loud ambiguity error, now re-wrapped as
  `wp_mcp_ai_fnb_source_unresolved` with "map each table under Table Sources"
  guidance).
- Readers (`WP_MCP_AI_Fnb_Table_Reader::read_table( $table_id,
  $connection_override = '' )`): the universal and Google Sheets readers
  mint and **cache tokens per connection** and list folders per table source;
  fixture and CPT readers accept and ignore the new parameter.
- `get_table()`/`search_table()` include the resolved source in the
  transient cache key (per-connection rows can never collide) and return
  `connection_id`/`connection_name` for observability.
- `fnb_read_table`/`fnb_search_table` expose an optional `connection_id`
  argument (an override, not the routing mechanism) and echo the resolved
  connection in their responses.
- **Metric tools need no changes**: `fnb_dish_margin`, `fnb_calculate_metric`
  and the report builders all read through `get_table()`, so a single
  calculation can now join tables across several connections.

### Phase 2 — Table Sources UI + tiered auto-map review

- New **Table Sources** section on the F&B settings page: a 18-row grid
  (table → Drive connection select populated from enabled Remote Sites
  `google_drive` connections, folder ID, file/sheet hint) saving into
  `table_map` through the normal settings sanitizer.
- **Scan & propose** (admin-post, `manage_options`): scans the default source
  plus every distinct mapped (connection, folder) pair via the new
  `WP_MCP_AI_Fnb_Universal_Reader::scan_folder()`, keeps the best proposal
  per table, stores them in `wp_mcp_ai_fnb_table_map_proposals`.
- **Tiered review** (Dataversity pattern): high-confidence (≥ 0.7) proposals
  render in a summary table pre-checked for bulk apply; low-confidence rows
  render individually with sample headers as the proposer's evidence.
  **Apply** writes `{ connection_id, folder_id, file }` per accepted table —
  existing operator mappings win unless overwrite is checked; **Discard**
  clears proposals. Nothing is applied automatically.
- The Drive Connection field description now documents the fallback order
  and points multi-connection operators at Table Sources.

## Deliberate non-goals (v1)

- Recursive folder browsing / folder pickers (operators paste folder IDs;
  auto-map reads them from mapped rows).
- Per-table sources for the workbook ingest mode (the workbook is one file;
  only its connection can be per-table overridden).
- Proposal provenance persistence / ML feedback loop (manifest is rebuilt on
  demand; accept/reject is not yet used to train future scores).

## Validation

- New `tests/pro/tools/food-beverage/test-fnb-multi-connection-routing.php`
  (9 tests): whitelist, precedence, global fallback, empty source,
  per-source cache keys, tool overrides, sanitizer merge.
- Full F&B cluster + oracle re-run (fixtures remain byte-identical — the
  routing layer is additive).
