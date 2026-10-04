# Traits

## Purpose

Shared PHP traits for Pro addon toolkits — reusable behaviour that crosses toolkit boundaries without inheritance coupling.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon |
| **PHP target** | 8.1+ |
| **License** | Proprietary |
| **Loaded by** | Explicit `require_once` in consuming classes |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_CRM_Relevance_Search` | `trait-wp-mcp-ai-relevance-search.php` | CRM + Healthcare search tools |
| `WP_MCP_AI_Sharp_Image_Processing` | `trait-wp-mcp-ai-sharp-image-processing.php` | Image-production `enhance_image_quality` + `upscale_image_ai` (also hosts the sidecar/upload plumbing for the Wave 2 tools) |
| `WP_MCP_AI_Provider_Image_Edit` | `trait-wp-mcp-ai-provider-image-edit.php` | Image-production `colorize_image` + `apply_artistic_style` |

## Inputs / Outputs / Neighbors

- **Reads from:** Post content / meta (for TF-IDF term frequency computation), `wp_mcp_ai_settings` (relevance search toggle), search query arguments from consuming tool `execute()` calls
- **Writes to:** Nothing directly — compute-only trait. Returns relevance-ordered result arrays to consuming tools.
- **Upstream callers:** CRM email search tools (`tools/crm/`), healthcare search tools (`tools/healthcare/`), base content search tools (`includes/tools/`)
- **Downstream collaborators:** `WP_MCP_AI_Toolkit_Data_Store` (data retrieval), `WP_MCP_AI_Vector_Context_Service` (optional semantic scoring)
- **Events fired:** None — pure computation trait
- **Events listened to:** None

## Conventions

- One trait per file, named `trait-wp-mcp-ai-{name}.php`.
- Traits are NOT autoloaded — consuming classes must `require_once` them explicitly.
- Each trait must declare `@subpackage Traits` in its file header.
- TF-IDF and BM25 computations are idempotent and stateless — no side effects, no DB writes.
- `WP_MCP_AI_Sharp_Image_Processing` composes the base-owned
  `WP_MCP_AI_NodeJS_Subprocess` and `WP_MCP_AI_Media_Worker_Client` traits;
  consuming tools therefore need no additional trait requires. The
  `wp_mcp_ai_local_sharp_available` and `wp_mcp_ai_sharp_process_image`
  filters are the test seams for the two processing paths.
- `WP_MCP_AI_Provider_Image_Edit` promotes the harmonization
  `ai_edit_image()` helper with an upfront `provider_has_credentials()`
  gate; the no-key case is an honest `wp_mcp_ai_no_api_key` error.

## Tests

```bash
vendor/bin/phpunit --filter '/Relevance|TFIDF/'
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../tools/crm/README.md`](../tools/crm/README.md) — CRM toolkit index (primary consumer)
- [`../tools/healthcare/README.md`](../tools/healthcare/README.md) — Healthcare toolkit index (secondary consumer)

## See Also

- Parent: [`../`](../) — pro includes root
- Consumers: [`../tools/crm/`](../tools/crm/), [`../tools/healthcare/`](../tools/healthcare/)
- Base counterpart: [`includes/traits/trait-wp-mcp-ai-relevance-search.php`](../../../includes/traits/trait-wp-mcp-ai-relevance-search.php)
