# Phase 2 Bulk-Tool Audit

> **Status:** ✅ Complete (v1.1.29) — All 85 unbounded query sites across 57 files bounded. Audit document serves as historical record.

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
| Calendar-booking (5 tools) | 5 | ✅ refactored — every site clamped via `WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items()` (default 500) | This PR |
| ECA suite (10 tools) | 19 | ✅ refactored — clamped via helper (default 1000) | This PR |
| Healthcare (8 tools) | 16 | ✅ refactored — clamped via helper (default 1000) | This PR |
| Law-firm (15 tools) | 21 | ✅ refactored — clamped via helper (default 1000) | This PR |
| Regulatory-registration (19 tools) | 22 | ✅ refactored — clamped via helper (default 1000) | This PR |
| Ecommerce / DJ / misc (2) | 2 | ✅ refactored — clamped via helper (default 500) | This PR |

> **Phase 2 status (live audit, May 2026):** the actual offender count came in at
> **85 sites across 57 Pro files** — higher than the original ~56-site estimate,
> because several tools (`generate-eca-participation-report`, `get-health-timeline`,
> `guide-health-record-creation`, etc.) had multiple `-1` queries each. All 85 sites
> are now bounded; `git grep "posts_per_page['\"]?\\s*=>\\s*-1" addons/pro/includes/tools`
> returns zero matches.

## Refactor recipe

For each remaining offender, choose **one of two approaches** based on the call
site:

### A. Lightweight cap (used for the 85 Pro sites in this PR)

Replace `'posts_per_page' => -1,` inline with:

```php
'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' )
    ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( '<tool_slug>', 0, <default> )
    : <default>,
```

This:

- Eliminates the unbounded query (the audit's primary goal).
- Preserves the surrounding query plumbing (`new WP_Query`, `get_posts`, etc.).
- Exposes the documented `wp_mcp_ai_tool_max_items` filter so site owners can
  clamp specific tools without code changes.
- Defaults used in this PR: `500` for calendar-booking and ecommerce/DJ
  (smaller working sets), `1000` for ECA, healthcare, law-firm, and
  regulatory-registration (analytics / export tools).

### B. Full iterator + artifact spill (used for the Phase 1 base + FHIR sites)

For tools that aggregate or stream very large datasets:

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
