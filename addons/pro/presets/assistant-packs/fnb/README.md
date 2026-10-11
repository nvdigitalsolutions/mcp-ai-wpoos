# F&B Assistant Packs (A1–A4) — Surf Club Midigama

Four importable assistant bundles for the Food & Beverage Management Pro
toolkit (`addons/pro/includes/tools/food-beverage/`), spec-compliant with the
Surf Club Midigama assistant task spec (proposal 062:
`docs/project/proposals/062-food-beverage-management-pro-toolkit-proposal.md`).

Every pack is a **canonical v1 `nvoos-assistant` bundle** importable through
any of the five portability surfaces — WP-CLI
(`wp mcp-ai assistant import --file=…`), REST, admin Import/Export page, the
`import_assistant` tool, or the bundled seeder below.

## The four packs

| File | Assistant | Spec ID | Main users | Purpose |
|---|---|---|---|---|
| `a1-manager.json` | F&B Manager's Assistant | A1 | CEO, restaurant manager | Summarise sales, costs and operational issues; rank by LKR impact; weekly brief (R-02); audit viewer (CEO control story, open Q8) |
| `a2-kitchen-bar-stock.json` | F&B Kitchen & Bar Stock Assistant | A2 | Head chef, bar manager | Stock, use, waste and supplier prices; shortage and over-ordering flags; weekend plan (R-03); purchase-request drafts (R-04) |
| `a3-financial.json` | F&B Financial Assistant | A3 | CEO, finance | Actuals vs budget and prior month; volume vs per-cover split; duplicate-invoice flags; investor-pack drafts |
| `a4-content.json` | F&B Content Assistant | A4 | CEO | Character-bible-consistent image prompts, captions, hashtags; content brief (R-13); posts linked to stock and bookings |

## Allowlist matrix (tools actually granted)

All packs follow the spec's 3-tier permission model: **READ on, DRAFT on,
ACT off** (none of the ACT-tier tools are granted; they are capability-gated
in the tool registry and remain off by default).

| Tool | A1 | A2 | A3 | A4 |
|---|---|---|---|---|
| `fnb_read_table` | ✅ | ✅ | ✅ | ✅ |
| `fnb_search_table` | ✅ | ✅ | ✅ | ✅ |
| `fnb_calculate_metric` | ✅ | ✅ | ✅ | ✅ |
| `fnb_compare_periods` | ✅ | ✅ | ✅ | — |
| `fnb_variance_split` | ✅ | — | ✅ | — |
| `fnb_stock_used` | ✅ | ✅ | ✅ | — |
| `fnb_expected_use` | ✅ | ✅ | ✅ | — |
| `fnb_stock_variance` | ✅ | ✅ | ✅ | — |
| `fnb_waste_analysis` | ✅ | ✅ | ✅ | — |
| `fnb_days_of_cover` | ✅ | ✅ | ✅ | — |
| `fnb_weekend_demand` | ✅ | ✅ | ✅ | — |
| `fnb_price_change_impact` | ✅ | ✅ | ✅ | — |
| `fnb_budget_variance` | ✅ | — | ✅ | — |
| `fnb_dish_margin` | ✅ | ✅ | ✅ | ✅ |
| `fnb_duplicate_invoice_scan` | ✅ | — | ✅ | — |
| `fnb_labour_analysis` | ✅ | — | ✅ | — |
| `fnb_utilities_per_cover` | ✅ | — | ✅ | — |
| `fnb_generate_report` | ✅ | ✅ | ✅ | ✅ |
| `fnb_save_draft` | ✅ | ✅ | ✅ | ✅ |
| `fnb_list_drafts` | ✅ | ✅ | ✅ | ✅ |
| `fnb_audit_log` | ✅ | — | — | — |
| Image-production tools (4) | — | — | — | opt-in |

A1 (manager's broad view) and A3 (financial, reads all data tables per the
spec) get the full read set; the audit viewer stays with A1 (the CEO's
control story). A2 is scoped to the tables its spec role reads (menu through
bookings). A4 gets the content subset plus `fnb_dish_margin` (menu context).

### A4 image tools — default off, opt-in via seeder

Per spec rule A4-05 and the Tools & permissions sheet ("Generate image —
Off — none in demo"), the four `image-production` tools are **not** in A4's
bundle. Post-demo operators can add them with:

```
wp mcp-ai pro fnb seed-assistants --overwrite --include-image-tools
```

`--include-image-tools` adds `generate_image_ai`, `generate_image_variations`,
`image_inpainting` and `text_to_image_prompt_optimizer` to A4's allowlist in
one pass — a settings change, not a re-deploy. Publishing to social remains
the CEO's job regardless (A4-05); `social-media` ACT-tier tools are never
granted by the packs.

## Rules coverage

Every pack's system prompt embeds the spec Instructions sheet verbatim:

- **Global rules G-01…G-12** — in all four prompts (no invented figures,
  tool-based calculation, cite sources, answer-first, LKR ex-service,
  demo-assumption disclosure, drafts only).
- **Per-assistant rules** — A1-01…A1-04 in A1; A2-01…A2-08 in A2;
  A3-01…A3-06 in A3; A4-01…A4-05 in A4.
- Guardrails are restated at the end of each prompt (repetition reduces
  long-conversation drift; runtime enforcement comes from the tool
  allowlists + capability gates).

Prompt structure follows the identity → context → workflow → rules →
guardrails pattern recommended for restaurant AI assistants (identity first,
guardrails late and repeated).

## What the packs deliberately do NOT set

- **Provider / model / temperature** — omitted so each import inherits the
  site defaults (spec open Q9 suggests DeepSeek for analysis; set it
  site-wide or per assistant after import).
- **Credentials / MCP app tokens** — never in bundles (engine redacts
  anyway).
- **Drive folder IDs** — site-specific; configure in NV oOS → Settings →
  F&B toolkit (`data_folder_id`, `setup_folder_id`, `drafts_folder_id`) or
  via the `wp_mcp_ai_fnb_settings` option.
- **A2A agent cards** — emit per assistant post-import with
  `wp mcp-ai assistant export <id> --format=a2a` if another agent platform
  should consume them.

## Seeding

```bash
# Preview (safe, writes nothing).
wp mcp-ai pro fnb seed-assistants --dry-run

# Import all four (skips existing slugs/titles — idempotent).
wp mcp-ai pro fnb seed-assistants

# Re-import over existing assistants (preserves stored credentials).
wp mcp-ai pro fnb seed-assistants --overwrite
```

Requires the F&B toolkit toggle on (`enable_fnb_toolkit`; see
`wp mcp-ai toolkit list`). Import matching is slug-first, then title, per the
portability engine.

## Post-import wiring checklist

1. NV oOS → Settings → F&B toolkit: set the Data, Assistant setup and
   Drafts folder IDs (or CSV mode paths).
2. Verify per-assistant tool visibility matches the matrix above
   (Assistants → edit → Tools).
3. Optional MCP exposure for Claude/ChatGPT: create an MCP App per
   assistant with the read+draft subset only (spec Tools & permissions
   sheet; ACT tools stay absent).
4. A4: leave image tools off for the demo (A4-05); enable post-demo with
   `--include-image-tools` if desired.

## Validation

`tests/pro/tools/food-beverage/validate-assistant-packs.php` asserts bundle
structure (format v1, slugs, tool lists, no denylisted keys, guardrail text)
without WordPress:

```bash
php tests/pro/tools/food-beverage/validate-assistant-packs.php
```
