# F&B Assistant Blueprints (examples/)

Curated **blueprint-format** mirrors of the four F&B assistant packs
(A1–A4) so the toolkit surfaces on the unified **Blueprints** admin page
(one-click install) and through the `import_fnb_blueprint` tool — the same
surface every other Pro toolkit exposes.

The **canonical** deliverables remain the `nvoos-assistant` v1 bundles in
`addons/pro/presets/assistant-packs/fnb/` (richer: tier metadata, guardrail
structure, the A4 image-tool opt-in seeder flag). Each blueprint's
`description` points at its bundle twin; when the two drift, the bundle is
authoritative.

## Files

| File | Assistant | Allowlist |
|---|---|---|
| `fnb-a1-manager.json` | F&B Manager's Assistant (A1) | 21 read + draft tools (full read set + audit viewer) |
| `fnb-a2-kitchen-bar-stock.json` | F&B Kitchen & Bar Stock Assistant (A2) | 15 pack tools + `fnb_pr_create`, `fnb_pr_list`, `fnb_pr_audit` |
| `fnb-a3-financial.json` | F&B Financial Assistant (A3) | 20 read + draft tools |
| `fnb-a4-content.json` | F&B Content Assistant (A4) | 7 read + draft tools (image-production tools stay off — pack seeder opt-in) |

## Tier discipline

All four blueprints follow the spec's 3-tier model — **READ on, DRAFT on,
ACT off**:

- Granted: `fnb_read_table` … `fnb_generate_report` per the pack matrix, plus
  `fnb_save_draft` / `fnb_list_drafts` everywhere.
- **Never granted**: the six ACT-tier lifecycle tools
  (`fnb_pr_submit`, `fnb_pr_return`, `fnb_pr_approve`, `fnb_pr_release`,
  `fnb_pr_supersede`, `fnb_pr_cancel`), `fnb_import_table`, and the
  `social-media` / `image-production` ACT tools. Those stay capability-gated
  (`manage_qms` / `manage_options`) and off by default.
- A2's grant of `fnb_pr_create` is the deliberate DRAFT-tier extension (it
  only creates QMS drafts; nothing can be ordered without the ACT tools).
  Its instructions carry the same guardrail.

## Validation

`tests/pro/tools/food-beverage/test-fnb-blueprints.php` asserts each file
parses, carries only `fnb_*` slugs, excludes the ACT-tier denylist, and that
A2 grants the three DRAFT/READ PR tools. The `import_fnb_blueprint` tool
round-trips through the shared `WP_MCP_AI_Blueprint_Installer`.
