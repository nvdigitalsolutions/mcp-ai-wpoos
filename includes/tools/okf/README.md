# tools/okf/

## Purpose

Provides MCP tools for interacting with **Open Knowledge Format (OKF v0.1)** bundles. These tools allow AI assistants to read, browse, traverse, search, write, and delete OKF concepts — enabling deterministic, link-based knowledge retrieval and curation.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/okf/okf-init.php` |
| **Optional dependencies** | None |

## MCP Tools (6)

| Tool slug | File | Capability |
|---|---|---|
| `okf_read_concept` | `class-wp-mcp-ai-tool-okf-read-concept.php` | `read` |
| `okf_browse` | `class-wp-mcp-ai-tool-okf-browse.php` | `read` |
| `okf_traverse` | `class-wp-mcp-ai-tool-okf-traverse.php` | `read` |
| `okf_search` | `class-wp-mcp-ai-tool-okf-search.php` | `read` |
| `okf_write_concept` | `class-wp-mcp-ai-tool-okf-write-concept.php` | `edit_posts` |
| `okf_delete_concept` | `class-wp-mcp-ai-tool-okf-delete-concept.php` | `delete_posts` |

## Conventions

- One file = one class. Tool classes live here; engine classes live in `includes/okf/`.
- Follows the two-gate sanitization rule: sanitize `$arguments[...]` at entry, escape every value at exit.
- Returns the canonical envelope: success array or `WP_Error`, never `array( 'success' => false, ... )`.
