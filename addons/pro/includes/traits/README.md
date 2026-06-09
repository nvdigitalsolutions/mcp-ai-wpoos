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
