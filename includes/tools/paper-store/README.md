# Paper Store Tools

## Purpose

Houses 6 CRUD MCP tools for the NV oOS Paper Store — a file-based JSON document store organised by collection, providing create, read, update, delete, list, and search operations.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/tools-init.php` via tool registry auto-discovery |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Paper_Store_Delete` | `class-wp-mcp-ai-tool-paper-store-delete.php` | AI assistants, admin tools (`delete_posts`) |
| `WP_MCP_AI_Tool_Paper_Store_List` | `class-wp-mcp-ai-tool-paper-store-list.php` | AI assistants, admin tools (`read`) |
| `WP_MCP_AI_Tool_Paper_Store_Read` | `class-wp-mcp-ai-tool-paper-store-read.php` | AI assistants, retrieval pipelines (`read`) |
| `WP_MCP_AI_Tool_Paper_Store_Search` | `class-wp-mcp-ai-tool-paper-store-search.php` | AI assistants, knowledge discovery (`read`) |
| `WP_MCP_AI_Tool_Paper_Store_Update` | `class-wp-mcp-ai-tool-paper-store-update.php` | AI assistants, admin tools (`edit_posts`) |
| `WP_MCP_AI_Tool_Paper_Store_Write` | `class-wp-mcp-ai-tool-paper-store-write.php` | AI assistants, agent memory persistence (`edit_posts`) |

All classes implement `WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`, and use `WP_MCP_AI_Tool_Chat_Response`. All belong to the `paper_store` toolkit.

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Paper_Store_Manager` (singleton), collection repositories (JSON file storage)
- **Writes to:** JSON record files via repository `save()` / `update()` / `delete()` methods
- **Upstream callers:** `includes/tools/` (tool registry), REST API, chat endpoint
- **Downstream collaborators:** `includes/paper-store/` (manager, repository, query builder)
- **Events fired:** none directly
- **Events listened to:** none

## Conventions

- Every tool applies the two-gate sanitisation rule: `sanitize_key()`/`sanitize_text_field()` at entry, `esc_html()` at exit.
- All tools use `format_success_response()` from the `WP_MCP_AI_Tool_Chat_Response` trait for consistent response formatting.
- Records are stored as JSON files keyed by a unique slug (`id`), with optional `tags`, `status` (`published`/`draft`/`archived`), `type`, `body`, and `meta` fields.
- Write is idempotent: attempting to write a duplicate `id` returns a `WP_Error` with `duplicate_id` code.
- Capability flags differentiate read-only tools (`read-only`, `cacheable`) from mutating tools (`write`, `state-changing`).

## Tests

```bash
vendor/bin/phpunit tests/test-paper-store-tools.php
```

Coverage targets: CRUD operations, duplicate detection, search across collections, tag/status/type filtering, and pagination.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registration and lifecycle
