# mcp-wordpress Tool Parity (Port from docdyhr/mcp-wordpress)

> Status: **Complete (v1.1.87)** · 30 tools ported into the Base plugin ·
> Upstream: [docdyhr/mcp-wordpress](https://github.com/docdyhr/mcp-wordpress) (MIT, © 2025 Aionda GmbH)

This document records the gap analysis between the upstream
[mcp-wordpress](https://github.com/docdyhr/mcp-wordpress) MCP server (71 tools)
and NV oOS's native tool registry, and the decisions behind every port.

## Why a port instead of bundling

mcp-wordpress is a **standalone Node.js (TypeScript) MCP server** that connects
*to* WordPress over the REST API with Application Passwords — it is built for
external MCP clients (Claude Desktop, VS Code, Cline). NV oOS already runs
*inside* WordPress and already exposes the same capability surface natively
(posts, pages, media, users, terms, cache purge, site health, SEO metadata).
Porting the genuinely missing tools as native PHP classes gives us:

- **Direct WP APIs** instead of an HTTP round-trip back into the same site
  (no Application Password, no REST auth, no Node runtime requirement).
- **Native security** — capability checks, nonce-protected surfaces, audit
  logging, multisite guards, and the chat-client restriction trait.
- **Base distribution** — everything below uses WordPress core APIs only
  (two SEO tools optionally use the Brave key already consumed by
  `web_search`), so all 30 tools ship in the Base plugin (PHP 7.4+).

## The 71-tool matrix

### Ported (30 tools → 30 new native tools)

| Upstream tool | Native tool | Notes |
|---|---|---|
| `wp_list_comments` | `list_comments` | WP_Comment_Query; status/search/pagination |
| `wp_get_comment` | `get_comment` | |
| `wp_create_comment` | `create_comment` | `wp_new_comment()` |
| `wp_update_comment` | `update_comment` | also covers approve/spam via `status` |
| `wp_delete_comment` | `delete_comment` | trash or permanent (`force`) |
| `wp_list_users` | `list_users` | `get_users()` with role/search filters |
| `wp_create_user` | `create_user` | role assignment gated on `promote_users` |
| `wp_update_user` | `update_user` | self-edit allowed; others need `edit_users` |
| `wp_delete_user` | `delete_user` | `reassign` support; self-delete blocked |
| `wp_get_post_revisions` | `get_post_revisions` | also covers `wp_get_page_revisions` (any post type) |
| `wp_get_category` / `wp_get_tag` | `get_term` | one tool for all taxonomies |
| `wp_delete_category` / `wp_delete_tag` | `delete_term` | taxonomy-level `delete_terms` cap |
| `wp_get_media` | `get_media` | attachment + alt/caption/meta |
| `wp_upload_media` | `upload_media` | URL sideload, uploads-contained path, or base64 |
| `wp_update_media` | `update_media` | title/alt/caption/description |
| `wp_delete_media` | `delete_media` | trash or permanent |
| `wp_get_site_settings` | `get_site_settings` | core general settings |
| `wp_update_site_settings` | `update_site_settings` | **allowlist-only**, validated per option |
| `wp_get_application_passwords` | `list_application_passwords` | metadata only — hashes never exposed |
| `wp_create_application_password` | `create_application_password` | shown once; `password` declared a sensitive result field |
| `wp_delete_application_password` | `delete_application_password` | revoke by UUID |
| `wp_seo_analyze_content` | `seo_analyze_content` | readability/keywords/structure, pure PHP |
| `wp_seo_generate_schema` | `seo_generate_schema` | JSON-LD for 14 schema types, generate-only |
| `wp_seo_validate_schema` | `seo_validate_schema` | structural checks, no network |
| `wp_seo_bulk_update_metadata` | `seo_bulk_update_metadata` | dry-run default; SEO-plugin-aware meta keys |
| `wp_seo_site_audit` | `seo_site_audit` | bounded WP_Query crawl, pure PHP |
| `wp_seo_track_serp` | `seo_track_serp` | Brave Search API (same key as `web_search`) |
| `wp_seo_keyword_research` | `seo_keyword_research` | Brave-backed n-gram/question extraction |
| `wp_seo_test_integration` | `seo_test_integration` | Yoast/Rank Math/AIOSEO/SEOPress detection |
| `wp_seo_get_live_data` | `seo_get_live_data` | live SEO meta from the active plugin |

### Already covered natively (0 new tools — mapped in the table above where applicable)

| Upstream tool | Native equivalent |
|---|---|
| `wp_list_posts` | `get_recent_posts` |
| `wp_get_post` | `get_post` |
| `wp_create_post` | `create_post` / `create_post_validated` |
| `wp_update_post` | `save_post` / `save_post_validated` |
| `wp_delete_post` | `delete_post` |
| `wp_list_pages`, `wp_get_page`, `wp_create_page`, `wp_update_page`, `wp_delete_page` | same post tools with `post_type: "page"` |
| `wp_list_categories`, `wp_list_tags` | `list_terms` (taxonomy + search) |
| `wp_create_category`, `wp_create_tag` | `create_term` |
| `wp_update_category`, `wp_update_tag` | `update_term` |
| `wp_list_media` | `search_attachments` |
| `wp_get_current_user` | `get_user_info` |
| `wp_seo_generate_metadata` | `seo_meta_optimizer` (+ `generate_post_excerpt`) |
| `wp_seo_suggest_internal_links` | `suggest_internal_links` |
| `wp_search_site` | `search_content` |
| `wp_update_site_settings` (partial) | Pro `update_option` for non-core options |
| `wp_check_version` | `get_environment_status` |
| `wp_cache_clear` | `purge_cache` / `purge_cloudflare_cache` / `purge_varnish_cache` |

### Not applicable (by design — no port)

| Upstream tool | Why |
|---|---|
| `wp_test_auth`, `wp_get_auth_status`, `wp_switch_auth_method` | Manage the *external server's own* credentials to the WP REST API. Inside WordPress there is nothing to authenticate; the Remote Sites/MCP-bridge "Test Connection" covers cross-site cases. |
| `wp_cache_stats`, `wp_cache_warm`, `wp_cache_info` | Instrument mcp-wordpress's internal LRU client cache. NV oOS has its own cache layers (`wp_mcp_ai_cache_helper`, semantic cache, REST cache) surfaced through `get_environment_status` and the purge tools. |
| `wp_performance_stats`, `wp_performance_history`, `wp_performance_benchmark`, `wp_performance_alerts`, `wp_performance_optimize`, `wp_performance_export` | Instrument the Node server *process*. NV oOS observability is `get_system_logs`, `get_environment_status`, `openai_usage_analytics`, and Pro's `benchmark_tool_performance`. |

## Design decisions

- **Base, not Pro.** Every port uses WordPress core APIs only; no new
  dependencies. The two network tools (`seo_track_serp`, `seo_keyword_research`)
  reuse the existing Brave key from `WP_MCP_AI_Settings_Registry`, identical to
  `web_search`.
- **Parity of shape, not of mechanism.** Tool names, parameters, and result
  fields mirror the upstream tools where sensible, but implementations use
  direct WP APIs and the canonical NV oOS envelope (`message` + data keys,
  `WP_Error` for failures — never `array( 'success' => false )`).
- **Sensitive operations.** User CRUD, comment writes, application passwords,
  and site-settings updates carry `WP_MCP_AI_Tool_Restrict_From_Chat_Client`.
  `create_application_password` implements
  `WP_MCP_AI_Tool_Sensitive_Result_Interface` so the one-time password is
  masked in logs.
- **Uploads are containment-checked.** `upload_media` sideloads URLs through
  `download_url()` (WP's own HTTP validation), requires server paths to
  `realpath()` inside the uploads directory, and caps base64 payloads at
  25 MB.
- **Application passwords.** `create_application_password` honours
  `wp_is_application_passwords_available_for_user()` and returns the password
  exactly once; WordPress only stores the hash, matching upstream's "shown
  once" contract.

## Maintenance

- Attribution: each ported file carries `@link`/`@credit` tags; the index
  lives in the repo-root [`CREDITS.md`](../../CREDITS.md).
- If upstream adds new tools in a future release, re-run the matrix in this
  file and port only the rows that fall into the "Ported" bucket's categories.
