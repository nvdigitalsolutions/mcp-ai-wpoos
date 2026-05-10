# Phase 2 Bulk-Tool Audit

> Scope: identify every tool that issues `posts_per_page => -1` (or equivalent)
> against `WP_Query` / `get_posts`, so the Phase 2 refactor can replace each
> site with the `WP_MCP_AI_Batch_Iterator` + `WP_MCP_AI_Tool_Artifact_Helper`
> pattern shipped in Phase 1.

## Summary

- **Total offending sites**: 60 (across 58 files).
- **Base plugin (`includes/tools/`)**: 1 file, 2 sites (`media-library-optimizer.php`).
- **Pro addon (`addons/pro/includes/tools/`)**: 57 files, ~58 sites.
- **Categorisation** (Pro): calendar-booking (5), ECA (10), healthcare (12),
  law-firm (12), regulatory-registration (15), ecommerce (1), dj-management (1),
  misc Pro (2 — quiz, generate-eca-participation-report multi-site).

## Status

| Tool | Sites | Status | PR |
|------|-------|--------|----|
| `media_library_optimizer` (base) | 2 | ✅ refactored — both `analyze` and `detect_unused` use `WP_MCP_AI_Batch_Iterator`; opportunity / unused lists stream to artifact when >100 rows; `max_items` schema added | This PR |
| `image_format_batch_converter` (base) | 1 | ✅ refactored — `image_ids` path no longer falls back to `-1`; capped via `wp_mcp_ai_tool_max_items` | This PR |
| `export_fhir_data` (Pro) | 3 | ✅ refactored — `build_medication_resources`, `build_allergy_resources`, `build_immunization_resources` all iterator-driven | This PR |
| `mine_agent_memory` (base) | 0 (already capped) | ✅ confirmed safe — schema clamps `posts_per_page` to `MAX_RECORDS_PER_RUN` | n/a |
| Calendar-booking (5 tools) | 5 | ⏳ follow-up PR | — |
| ECA suite (10 tools) | 10 | ⏳ follow-up PR | — |
| Healthcare (12 tools) | 12 | ⏳ follow-up PR | — |
| Law-firm (12 tools) | 12 | ⏳ follow-up PR | — |
| Regulatory-registration (15 tools) | 15 | ⏳ follow-up PR | — |
| Ecommerce / DJ / misc (3) | 3 | ⏳ follow-up PR | — |

## Refactor recipe

For each remaining offender:

1. Replace the `posts_per_page => -1` query with a
   `WP_MCP_AI_Batch_Iterator::paged_iterate( $query_args )` loop.
2. Add a `max_items` parameter to the JSON schema (default tuned per tool, e.g.
   500 for analyse, 1000 for export). Resolve via
   `WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items()` so site owners can
   clamp via the `wp_mcp_ai_tool_max_items` filter.
3. If the tool returns row collections that may exceed `wp_mcp_ai_max_inline_rows`
   (default 100), wrap the response in
   `WP_MCP_AI_Tool_Artifact_Helper::stream_to_artifact()` and return the
   `{ summary, count, artifact_id, artifact_url }` envelope instead of inlining
   the full payload.
4. For tools that operate on very large datasets, also implement
   `WP_MCP_AI_Tool_Bulk_Operation_Interface` so the registry can auto-dispatch
   to the async queue when the Phase 4 Action Scheduler integration ships
   (gated behind `WP_MCP_AI_BULK_AUTO_ASYNC`).

## How to re-run the audit

```bash
git grep -nE "posts_per_page['\"]?\s*=>\s*-1|'numberposts'\s*=>\s*-1" \
    includes/tools addons/pro/includes/tools | wc -l
```
