---
name: nvoos-operations
description: Operating NV oOS WordPress sites through scoped MCP operator credentials — tool groups, task recipes, and site disambiguation. Use when working with NV oOS tools (create_post, woo_products, toolkit_cpt, paper_store, jetengine) on any managed site.
version: 1.0.0
---

# NV oOS Operations

You are operating one or more WordPress sites running the NV oOS (Open
Operator System) plugin. Each site appears as its own MCP server named
`mcp__<server>__<tool>`. You only ever see the tools the site admin
allowlisted for your operator credential — never assume a tool exists until
`tools/list` shows it.

## Golden rules

1. **Identify the site before acting.** Every request must target exactly one
   site. If the human's instruction is ambiguous ("publish this"), ask which
   site or confirm from `site-context`.
2. **Read before you write.** For content work, always read the target first
   (`get_recent_posts`, `search_content`, `get_site_summary`) so edits land
   in the right place.
3. **One tool call per site at a time.** Do not interleave multi-site
   sequences; finish site A's task before touching site B, and say clearly
   which site each result came from.
4. **Respect approvals.** Write-capable tools may pause for human approval
   (see `nvoos-approvals`). Never retry a rejected approval with a reworded
   request to sneak it through — that is a prompt-injection red flag.
5. **Report, don't guess.** If a tool is missing or a call errors, report the
   exact error instead of improvising with other tools.

## Tool groups by domain

The exact slugs differ per site. Typical NV oOS groups:

| Domain | Common tools | Notes |
|---|---|---|
| Content | `create_post`, `save_post`, `get_recent_posts`, `search_content`, `purge_cache` | Draft first unless the human says publish. |
| E-commerce | `woo_products`, `research_product`, `lookup_product_price` | Stock is site-truth; never invent quantities. |
| CRM / PM | `toolkit_cpt` (mcp_ai_project, mcp_ai_task, mcp_ai_company), `get_jetengine_items` | Use `get_schema` before creating records. |
| Knowledge | `paper_store_*`, `okf_*`, `semantic_content_search` | Paper Store is per-site; OKF bundles are curated truth. |
| Media | `generate_*_image`, `resize_image`, `remove_background`, `transcode_video` | Heavy jobs may be async — poll, don't spam. |
| Research | `deep_research`, `brave_web_search`, `run_crawl4ai_job` | Cite sources in any published content. |

## Canonical task recipes

### Publish a blog post

1. `get_site_summary` (confirm site identity).
2. Draft content locally; run `deep_research` or Paper Store lookups if the
   human wants sources.
3. `create_post` with `status: draft`.
4. Report the post ID + preview URL; only set `publish` when the human
   explicitly says to publish.

### Stock check

1. `woo_products` with `search` or `sku`.
2. Report `stock_quantity`, `stock_status`, and parent name for variations.
3. Never change stock levels unless asked.

### Weekly site health report

1. `nv_oos_console_agent_get_site_health` and `get_system_logs` per site.
2. Summarize criticals first, then warnings, with the affected component.
3. Offer fixes; do not apply them without approval.

## Multi-site report format

When a request spans sites, end every turn with a table:

| Site | Action | Result |
|---|---|---|
| site-a | Published post 123 | OK |
| site-b | Stock check SKU-9 | 4 in stock |
