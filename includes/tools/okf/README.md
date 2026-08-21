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

## MCP Tools (10)

| Tool slug | File | Capability |
|---|---|---|
| `okf_read_concept` | `class-wp-mcp-ai-tool-okf-read-concept.php` | `read` |
| `okf_browse` | `class-wp-mcp-ai-tool-okf-browse.php` | `read` |
| `okf_traverse` | `class-wp-mcp-ai-tool-okf-traverse.php` | `read` |
| `okf_search` | `class-wp-mcp-ai-tool-okf-search.php` | `read` |
| `okf_list_bundles` | `class-wp-mcp-ai-tool-okf-list-bundles.php` | `read` |
| `okf_validate_attestation` | `class-wp-mcp-ai-tool-okf-validate-attestation.php` | `read` |
| `okf_validate_bundle` | `class-wp-mcp-ai-tool-okf-validate-bundle.php` | `read` |
| `okf_write_concept` | `class-wp-mcp-ai-tool-okf-write-concept.php` | `edit_posts` |
| `okf_delete_concept` | `class-wp-mcp-ai-tool-okf-delete-concept.php` | `delete_posts` |
| `okf_import_bundle` | `class-wp-mcp-ai-tool-okf-import-bundle.php` | `manage_options` |

## Conventions

- One file = one class. Tool classes live here; engine classes live in `includes/okf/`.
- Follows the two-gate sanitization rule: sanitize `$arguments[...]` at entry, escape every value at exit.
- Returns the canonical envelope: success array or `WP_Error`, never `array( 'success' => false, ... )`.
- Bundle paths resolve through `WP_MCP_AI_OKF_Bundle_Manager::resolve_bundle_root()` — tools must not re-implement path logic.
- `skill-knowledge` is auto-generated and protected: `okf_write_concept` / `okf_delete_concept` return `okf_protected_bundle` for it; write/delete append `log.md` entries (OKF v0.2 §9).
