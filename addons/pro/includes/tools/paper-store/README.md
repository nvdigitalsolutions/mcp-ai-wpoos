# Paper Store

## Purpose

Houses 2 Pro tools for the Paper Store document management system: `paper_store_export` (exports records from a named collection as JSON, optionally filtered by tag or status) and `paper_store_import` (imports a JSON array into a named collection with upsert support). Both require `manage_options` and use the `WP_MCP_AI_Paper_Store_Manager` repository pattern.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry |
| **Optional dependencies** | `WP_MCP_AI_Paper_Store_Manager` must be loaded |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Paper_Store_Export` | `class-wp-mcp-ai-tool-paper-store-export.php` | tool registry |
| `WP_MCP_AI_Tool_Paper_Store_Import` | `class-wp-mcp-ai-tool-paper-store-import.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** Paper Store collections via `WP_MCP_AI_Paper_Store_Manager::get_repository()`
- **Writes to:** Paper Store collections (import upserts records)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Paper_Store_Manager`
- **Events fired:** None
- **Events listened to:** None

## Conventions

- Both tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Export uses `WP_MCP_AI_Tool_Chat_Response` trait for `format_success_response()` canonical envelope.
- Both require `manage_options` capability.
- Abides by the two-gate rule: sanitize at entry (`sanitize_key`, `sanitize_text_field`), escape at exit.
- Export supports optional `tags` and `status` filters.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/paper-store/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Pro tools index
