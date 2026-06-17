# paper-store/

## Purpose

Provides the **NVoOS Paper Store** — a flat-file storage layer for AI-managed knowledge. Assistants and MCP tools can read and write structured JSON records without a database. This is a complementary data source (not a replacement) for WordPress posts, users, orders, and plugin settings.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` → `paper-store-init.php`; hooks `wp_mcp_ai_bootstrapped` at priority 30 |
| **Optional dependencies** | None (uses native PHP + existing `symfony/filesystem`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Paper_Driver_Interface` | `interface-wp-mcp-ai-paper-driver.php` | Driver contract |
| `WP_MCP_AI_Paper_Json_Driver` | `class-wp-mcp-ai-paper-json-driver.php` | JSON format driver |
| `WP_MCP_AI_Paper_Index` | `class-wp-mcp-ai-paper-index.php` | Inverted index per collection |
| `WP_MCP_AI_Paper_Query` | `class-wp-mcp-ai-paper-query.php` | Fluent query builder |
| `WP_MCP_AI_Paper_Repository` | `class-wp-mcp-ai-paper-repository.php` | CRUD repository per collection |
| `WP_MCP_AI_Paper_Store_Manager` | `class-wp-mcp-ai-paper-store-manager.php` | Singleton root manager |

### MCP Tools (6)

| Tool slug | File (in `includes/tools/paper-store/`) | Capability |
|---|---|---|
| `paper_store_list` | `class-wp-mcp-ai-tool-paper-store-list.php` | `read` |
| `paper_store_read` | `class-wp-mcp-ai-tool-paper-store-read.php` | `read` |
| `paper_store_search` | `class-wp-mcp-ai-tool-paper-store-search.php` | `read` |
| `paper_store_write` | `class-wp-mcp-ai-tool-paper-store-write.php` | `edit_posts` |
| `paper_store_update` | `class-wp-mcp-ai-tool-paper-store-update.php` | `edit_posts` |
| `paper_store_delete` | `class-wp-mcp-ai-tool-paper-store-delete.php` | `delete_posts` |

## Inputs / Outputs / Neighbors

- **Reads from:** File system at `wp-content/uploads/mcp-ai-wpoos/paper-store/` (JSON record files, index files).
- **Writes to:** Same file system (atomic writes via `WP_MCP_AI_Filesystem_Service` or native `file_put_contents` with `LOCK_EX`).
- **Upstream callers:** MCP tools (primary), any plugin code via `WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository()`.
- **Downstream collaborators:** `WP_MCP_AI_Filesystem_Service` (atomic I/O when available), `WP_MCP_AI_Logger` (error logging), `WP_MCP_AI_Tool_Registry` (tool registration).
- **Events fired:** `wp_mcp_ai_paper_store_initialized`, `wp_mcp_ai_paper_record_saved`, `wp_mcp_ai_paper_record_deleted`, `wp_mcp_ai_paper_index_rebuilt`.
- **Events listened to:** None (self-contained).

## Conventions

- One file = one class. Paper Store engine classes live here; MCP tools live in `includes/tools/paper-store/`.
- All I/O uses the driver interface — never read/write files directly in repository or query code.
- Path traversal is prevented by the Manager's `validate_path()` method using `realpath()` + prefix check.
- Index locking uses `flock()` with non-blocking 3-second timeout. Adequate for single-server deployments; Pro may add Redis-backed lock in Phase 3.
- Record IDs and collection names are sanitised via `sanitize_key()` (lowercase alphanumeric + hyphens + underscores only).
- Required record fields: `id`, `type`, `title`. JSON driver enforces this at read and write.
- Timestamps are auto-managed: `created_at` set on first write, `updated_at` updated on every write. ISO 8601 format.

## Usage Example

```php
// Get a repository for a collection.
$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
$repo    = $manager->get_repository( 'knowledge' );

// Save a record.
$repo->save( array(
    'id'          => 'dior-sauvage',
    'title'       => 'Dior Sauvage',
    'description' => 'Fresh spicy fragrance by Dior',
    'tags'        => array( 'perfume', 'dior', 'men' ),
    'status'      => 'published',
    'body'        => array( 'notes' => array( 'top' => array( 'Bergamot', 'Pepper' ) ) ),
) );

// Find a record.
$record = $repo->find( 'dior-sauvage' );

// Query with filters.
$results = $repo->where( 'tags', '=', 'perfume' )
    ->order_by( 'updated_at', 'desc' )
    ->limit( 10 )
    ->get();
```

## Tests

```bash
vendor/bin/phpunit tests/paper-store/test-paper-json-driver.php
vendor/bin/phpunit tests/paper-store/test-paper-index.php
vendor/bin/phpunit tests/paper-store/test-paper-query.php
vendor/bin/phpunit tests/paper-store/test-paper-repository.php
vendor/bin/phpunit tests/paper-store/test-paper-manager.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-list.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-read.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-search.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-write.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-update.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-delete.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP 7.4 compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — path traversal, sanitisation (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registration conventions
- [`docs/proposals/paper-store-architecture.md`](../../docs/proposals/paper-store-architecture.md) — full architecture proposal

## See Also

- Sibling folders: [`repositories/`](../repositories/) (DB-backed repositories), [`filesystem/`](../filesystem/) (Symfony Filesystem wrapper)
- Related: [`knowledge-base/`](../knowledge-base/) (plugin-bundled static knowledge — distinct from Paper Store's dynamic user content)
