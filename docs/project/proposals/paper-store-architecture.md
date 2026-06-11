# NVoOS Paper Store — Architecture & Implementation Plan

> **Status:** ✅ Implemented (v1.1.29) — Pro version delivered at `addons/pro/includes/paper-store/` with admin CRUD UI, Git sync, and Markdown+YAML driver.  
> **Author:** AI Coding Agent (research + analysis)  
> **Date:** 2026-05-27 (proposal), updated 2026-06-11 (status)

---

## 1. Executive Summary

The **NVoOS Paper Store** is a flat-file storage layer that lets NV oOS assistants, MCP tools, and AI workflows read and write structured JSON / Markdown records without a database. It is **not** a WordPress database replacement — it is a complementary data source for AI-managed knowledge: prompt templates, assistant configurations, generated documents, catalog snapshots, reusable workflows, and Git-versioned reference data.

### Why flat files for an AI assistant platform?

| Use case | Best storage |
|---|---|
| Users, posts, orders, permissions, plugin settings | WordPress DB (unchanged) |
| Assistant knowledge, prompt templates, workflow definitions, static catalogs, AI-generated docs, synced snapshots, Git-tracked config | **Paper Store** |

### Industry inspiration & validation

| System | Key pattern adopted |
|---|---|
| **Laravel Paper** (Eloquent driver for Markdown/JSON files) | Repository + driver abstraction; familiar query API over files |
| **Grav CMS** (flat-file CMS, YAML frontmatter, Symfony-backed) | YAML frontmatter metadata; `slug → folder/filename` convention; package management of content |
| **Statamic** (flat-file CMS, Git-friendly) | Collection → folder mapping; `.md` file as record; entries listing |
| **Orbit** (Laravel flat-file ORM) | File-based "Eloquent" driver; JSON serialization with atomic writes |
| **Obsidian / Vault pattern** | Folder = collection; file = record; frontmatter + body; plain-text queryability |

The Paper Store synthesizes these patterns into the NV oOS architecture, using the plugin's existing Symfony Filesystem service, tool registry, and repository layer.

---

## 2. Compatibility Matrix

| Distribution | Minimum PHP | Features | Symfony deps |
|---|---|---|---|
| **Base** (`includes/paper-store/`) | **PHP 7.4+** | JSON driver, core repository, read/list/search tools | `symfony/filesystem` (already vendored) |
| **Pro** (`addons/pro/includes/paper-store/`) | **PHP 8.1+** | Markdown + YAML driver, versioning (Git), admin UI, assistant knowledge browser, YAML frontmatter parsing | `symfony/yaml` (new, Pro-only), `symfony/finder` (new, Pro-only) |

---

## 3. Storage Layout

```
wp-content/uploads/mcp-ai-wpoos/paper-store/
├── assistants/
│   ├── sales-agent.json
│   ├── support-agent.json
│   └── content-writer.json
├── knowledge/
│   ├── products/
│   │   ├── dior-sauvage.json
│   │   └── creed-aventus.json
│   └── faq/
│       ├── shipping-policy.md          ← Pro driver (Markdown + YAML frontmatter)
│       └── returns-policy.md
├── prompts/
│   ├── email-campaign.json
│   ├── woocommerce-product-writer.json
│   └── blog-post-outline.json
├── workflows/
│   ├── content-pipeline.json
│   └── customer-onboarding.json
├── catalogs/
│   └── perfume-brands.json
└── _indexes/
    ├── knowledge.idx.json              ← Auto-maintained inverted index
    └── prompts.idx.json
```

### Record format (JSON — Base)

```json
{
  "id": "dior-sauvage",
  "type": "knowledge",
  "collection": "products",
  "title": "Dior Sauvage",
  "description": "Fresh spicy fragrance by Dior",
  "tags": ["perfume", "dior", "men"],
  "status": "published",
  "created_at": "2026-05-27T10:00:00+00:00",
  "updated_at": "2026-05-27T12:00:00+00:00",
  "author_id": 1,
  "meta": {
    "brand": "Dior",
    "release_year": 2015,
    "perfumer": "François Demachy"
  },
  "body": {
    "notes": {
      "top": ["Calabrian bergamot", "Pepper"],
      "heart": ["Sichuan pepper", "Lavender", "Pink pepper", "Vetiver", "Patchouli", "Geranium", "Elemi"],
      "base": ["Ambroxan", "Cedar", "Labdanum"]
    },
    "description_marketing": "Dior Sauvage is a radically fresh composition..."
  }
}
```

### Record format (Markdown — Pro)

```markdown
---
id: returns-policy
type: knowledge
collection: faq
title: Returns Policy
status: published
tags: [returns, shipping, faq]
updated_at: "2026-05-27T12:00:00+00:00"
---

# Returns Policy

We accept returns within 30 days of purchase...

## Eligibility

- Items must be unused and in original packaging
- Digital products are non-refundable

## Process

1. Initiate return via your account dashboard
2. Print the prepaid return label
3. Drop off at any UPS location
```

---

## 4. Class Architecture

### 4.1 Namespace & file layout

```
includes/paper-store/                              ← Base (PHP 7.4+)
├── README.md
├── paper-store-init.php                            ← Bootstrap: hooks into wp_mcp_ai_bootstrapped
├── class-wp-mcp-ai-paper-store-manager.php         ← Singleton → creates drivers, repos, validates paths
├── class-wp-mcp-ai-paper-json-driver.php           ← Read/write/delete JSON files via Filesystem Service
├── class-wp-mcp-ai-paper-index.php                 ← Inverted index builder (tag, status, date)
├── class-wp-mcp-ai-paper-query.php                 ← Fluent query builder over index
├── class-wp-mcp-ai-paper-repository.php            ← High-level CRUD for a collection
└── interface-wp-mcp-ai-paper-driver.php            ← Driver contract

includes/tools/paper-store/                         ← Base tools (PHP 7.4+)
├── class-wp-mcp-ai-tool-paper-store-list.php       ← paper_store_list
├── class-wp-mcp-ai-tool-paper-store-read.php       ← paper_store_read
├── class-wp-mcp-ai-tool-paper-store-search.php     ← paper_store_search
├── class-wp-mcp-ai-tool-paper-store-write.php      ← paper_store_write
├── class-wp-mcp-ai-tool-paper-store-update.php     ← paper_store_update
└── class-wp-mcp-ai-tool-paper-store-delete.php     ← paper_store_delete

addons/pro/includes/paper-store/                    ← Pro (PHP 8.1+)
├── class-wp-mcp-ai-paper-markdown-yaml-driver.php  ← Markdown + YAML frontmatter driver
├── class-wp-mcp-ai-paper-git-sync.php              ← Git versioning of paper-store directory
├── class-wp-mcp-ai-paper-admin-ui.php              ← Admin → Paper Store tab
└── class-wp-mcp-ai-paper-knowledge-browser.php     ← Assistant-side knowledge browser
```

### 4.2 Interface contract

```php
/**
 * Interface for Paper Store drivers.
 *
 * Each driver handles one file format (JSON, Markdown+YAML, etc.).
 * Implementations must be PHP 7.4+ compatible for base drivers.
 *
 * @package WP_MCP_AI
 * @since   1.x.0
 */
interface WP_MCP_AI_Paper_Driver_Interface {
    /**
     * Read a single record from a file.
     *
     * @param string $file_path Absolute path to the record file.
     * @return array|WP_Error  Normalized record array or error.
     */
    public function read( $file_path );

    /**
     * Write a record to a file (atomic).
     *
     * @param string $file_path Absolute path to the record file.
     * @param array  $record    Normalized record array.
     * @return bool|WP_Error    True on success.
     */
    public function write( $file_path, array $record );

    /**
     * Delete a record file.
     *
     * @param string $file_path Absolute path.
     * @return bool|WP_Error
     */
    public function delete( $file_path );

    /**
     * Get the file extension this driver handles (including dot).
     *
     * @return string e.g. '.json', '.md'
     */
    public function get_extension();
}
```

### 4.3 Key classes

#### `WP_MCP_AI_Paper_Store_Manager` (singleton)

- Owns the root path (`wp_upload_dir() . '/mcp-ai-wpoos/paper-store/'`)
- Creates driver instances (one per extension)
- Creates repository instances (one per collection)
- Validates all paths against traversal attacks (canonical `realpath()` + prefix check)
- Exposes `get_repository( $collection )` → singleton per collection
- Hooks: fires `wp_mcp_ai_paper_store_initialized` on first access
- Filter: `wp_mcp_ai_paper_store_root` allows overriding the base path

#### `WP_MCP_AI_Paper_Json_Driver` (base)

- Implements `WP_MCP_AI_Paper_Driver_Interface`
- Delegates disk I/O to `WP_MCP_AI_Filesystem_Service` (atomic `dumpFile()`)
- Encodes/decodes via `wp_json_encode()` / `json_decode()` — native PHP, no Symfony dep needed
- Enforces a minimum JSON schema: `id`, `type`, `title` required
- Validates UTF-8 encoding on read

#### `WP_MCP_AI_Paper_Index` (base)

- Maintains an inverted index file per collection at `_indexes/{collection}.idx.json`
- Index keys: `tags`, `status`, `type`, `author_id`, date buckets (`created_at` year-month)
- Auto-rebuilds on write/delete operations
- Lazily builds on first query if missing
- TTL-based cache via `wp_cache_*()` or transients (falls back to in-memory array on PHP 7.4)
- Locking: `flock()` on index file during rebuild (non-blocking timeout 3s)
- Filter: `wp_mcp_ai_paper_index_max_tags` (default 1000 unique tags per collection)
- Action: `wp_mcp_ai_paper_index_rebuilt` fires after each rebuild

#### `WP_MCP_AI_Paper_Query` (base)

- Fluent interface for querying indexed collections
- Methods: `where( $field, $operator, $value )`, `where_in( $field, array $values )`, `order_by( $field, $direction )`, `limit( $num )`, `offset( $num )`, `get()`, `first()`, `count()`
- Operators: `=`, `!=`, `IN`, `NOT IN`, `LIKE`, `>`, `<`, `>=`, `<=`
- Delegates to `WP_MCP_AI_Paper_Index` for tag/status/type queries; falls back to file scan for full-text
- Returns arrays of record IDs → `WP_MCP_AI_Paper_Repository` hydrates

#### `WP_MCP_AI_Paper_Repository` (base)

- High-level CRUD over a named collection of records
- Methods: `find( $id )`, `all()`, `where( $field, $value )` (returns Query builder), `save( array $record )`, `update( $id, array $data )`, `delete( $id )`, `truncate()`
- Normalizes records: assigns `id` if missing (from filename), sets `updated_at`
- Validates required fields before write
- Sanitizes: strips PHP from JSON, enforces UTF-8
- Returns canonical envelopes: `WP_Error` on failure

#### `WP_MCP_AI_Paper_Markdown_Yaml_Driver` (Pro, PHP 8.1+)

- Implements `WP_MCP_AI_Paper_Driver_Interface` for `.md` files
- Parses YAML frontmatter into record metadata
- Body becomes record `body.markdown` field
- Requires `symfony/yaml` (Pro vendored dependency)
- Matches Grav's `slug.md` → frontmatter + Markdown body convention
- Frontmatter keys map to record fields: `id`, `title`, `tags`, `status`, `updated_at`, etc.

#### `WP_MCP_AI_Paper_Git_Sync` (Pro)

- Periodic `git add/commit/push` of the paper-store directory
- Configurable via admin: auto-commit interval, remote URL, branch
- Uses `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service` for file ops
- Uses `Symfony\Component\Process\Process` for `git` binary invocation (already vendored)
- Respects `FS_METHOD` — warns but doesn't block when WP credential system is in use

---

## 5. MCP Tools

All tools follow the canonical return-envelope pattern (`success → array`, `failure → WP_Error`) and the two-gate sanitisation rule. All tools use `WP_MCP_AI_Tool_Envelope` or `WP_MCP_AI_Tool_Chat_Response` traits.

### Phase 1 — Read-only (Base)

| Tool slug | Capability | Description |
|---|---|---|
| `paper_store_list` | `read` | List records in a collection, optionally filtered by tags/status |
| `paper_store_read` | `read` | Read a single record by ID |
| `paper_store_search` | `read` | Full-text search across collections using the index |

### Phase 2 — Write (Base)

| Tool slug | Capability | Description |
|---|---|---|
| `paper_store_write` | `edit_posts` | Create a new record in a collection |
| `paper_store_update` | `edit_posts` | Update an existing record by ID |
| `paper_store_delete` | `delete_posts` | Delete a record by ID |

### Phase 3 — Management (Pro)

| Tool slug | Capability | Description |
|---|---|---|
| `paper_store_import` | `manage_options` | Bulk import records from CSV/JSON |
| `paper_store_export` | `manage_options` | Export collection as JSON/CSV/ZIP |
| `paper_store_version` | `manage_options` | View/restore record versions (Git-backed) |

### Example tool: `paper_store_read`

```php
/**
 * Tool: paper_store_read — Read a single Paper Store record.
 *
 * @package WP_MCP_AI
 * @since   1.x.0
 */
class WP_MCP_AI_Tool_Paper_Store_Read implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
    use WP_MCP_AI_Tool_Envelope;

    public function get_slug() { return 'paper_store_read'; }
    public function get_name() { return __( 'Paper Store — Read', 'mcp-ai-wpoos' ); }
    public function get_description() {
        return __( 'Reads a single record from the NV oOS Paper Store by collection and record ID.', 'mcp-ai-wpoos' );
    }

    public function get_parameters_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'collection' => array(
                    'type'        => 'string',
                    'description' => __( 'Collection name (e.g. "knowledge", "prompts").', 'mcp-ai-wpoos' ),
                ),
                'record_id'  => array(
                    'type'        => 'string',
                    'description' => __( 'Record ID (slug).', 'mcp-ai-wpoos' ),
                ),
            ),
            'required'   => array( 'collection', 'record_id' ),
        );
    }

    public function get_required_capability() { return 'read'; }

    public function execute( array $arguments = array(), array $context = array() ) {
        // Gate 1 — Sanitize at entry
        $collection = sanitize_key( $arguments['collection'] );
        $record_id  = sanitize_key( $arguments['record_id'] );

        if ( empty( $collection ) || empty( $record_id ) ) {
            return new WP_Error( 'missing_params', __( 'Collection and record_id are required.', 'mcp-ai-wpoos' ) );
        }

        if ( ! current_user_can( 'read' ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }

        $manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
        $repo    = $manager->get_repository( $collection );
        $record  = $repo->find( $record_id );

        if ( is_wp_error( $record ) ) {
            return $record;
        }

        if ( null === $record ) {
            return new WP_Error(
                'not_found',
                sprintf(
                    /* translators: 1: record ID, 2: collection name */
                    __( 'Record "%1$s" not found in collection "%2$s".', 'mcp-ai-wpoos' ),
                    $record_id,
                    $collection
                )
            );
        }

        // Gate 2 — Escape at exit
        return $this->format_success_response(
            sprintf( __( 'Record "%s" retrieved.', 'mcp-ai-wpoos' ), $record_id ),
            array( 'record' => $record )
        );
    }

    public function get_capability_flags() {
        return array( 'read-only', 'local-only', 'cacheable', 'requires-capability' );
    }
}
```

---

## 6. Integration Points

### 6.1 Bootstrap (in `includes/paper-store/paper-store-init.php`)

```php
add_action(
    'wp_mcp_ai_bootstrapped',
    function () {
        // Register Paper Store tools.
        WP_MCP_AI_Tool_Registry::get_instance()
            ->register_tool( 'WP_MCP_AI_Tool_Paper_Store_List' )
            ->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Read' )
            ->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Search' );
        // Phase 2 writes are registered behind capability gates.
    },
    30  // After default tool init at 20
);
```

Loaded in `includes/bootstrap/loader.php` after `repositories-init.php`:

```php
// Paper Store (flat-file storage layer)
require_once WP_MCP_AI_PATH . 'includes/paper-store/paper-store-init.php';
```

### 6.2 Existing service reuse

| Existing service | Paper Store usage |
|---|---|
| `WP_MCP_AI_Filesystem_Service` | All disk I/O (atomic writes, directory creation, clean-up) |
| `WP_MCP_AI_Tool_Registry` | Tool registration (standard `register_tool()`) |
| `WP_MCP_AI_Logger` | Error logging, audit trail entries |
| `WP_MCP_AI_Container` | Resolve the Paper Store Manager singleton |
| `symfony/filesystem` | Underlying atomic I/O (already vendored) |
| `symfony/cache` | Index caching (Pro — PSR-6) |

### 6.3 Lifecycle hooks fired

| Hook | When | Payload |
|---|---|---|
| `wp_mcp_ai_paper_store_initialized` | Manager first accessed | `$root_path` |
| `wp_mcp_ai_paper_record_saved` | After write/update | `$collection, $record_id, $record` |
| `wp_mcp_ai_paper_record_deleted` | After delete | `$collection, $record_id` |
| `wp_mcp_ai_paper_index_rebuilt` | After index rebuild | `$collection, $index_stats` |

### 6.4 Filters

| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_paper_store_root` | `wp_upload_dir() . '/mcp-ai-wpoos/paper-store/'` | Override storage path |
| `wp_mcp_ai_paper_record_before_save` | `$record` | Modify record before write |
| `wp_mcp_ai_paper_index_max_tags` | `1000` | Cap unique tags per collection index |
| `wp_mcp_ai_paper_index_ttl` | `300` | Index cache TTL (seconds) |

---

## 7. Security Design

### 7.1 Path traversal prevention

Every path from user input goes through:

```php
/**
 * Validate that a resolved path is within the paper-store root.
 *
 * @param string $path Absolute resolved path.
 * @param string $root Paper Store root path.
 * @return bool|WP_Error True if safe, WP_Error if traversal detected.
 */
function wp_mcp_ai_paper_validate_path( $path, $root ) {
    $real_path = realpath( $path );
    $real_root = realpath( $root );

    if ( false === $real_path || false === $real_root ) {
        return new WP_Error( 'path_error', __( 'Invalid path.', 'mcp-ai-wpoos' ) );
    }

    if ( 0 !== strpos( $real_path, $real_root . DIRECTORY_SEPARATOR )
        && $real_path !== $real_root ) {
        return new WP_Error(
            'path_traversal',
            __( 'Path traversal detected.', 'mcp-ai-wpoos' )
        );
    }

    return true;
}
```

### 7.2 Collection & record ID sanitisation

- `collection` → `sanitize_key()` (lowercase alphanumeric + hyphens + underscores only)
- `record_id` → `sanitize_key()` (same constraints)
- No dots, no slashes, no absolute paths allowed

### 7.3 File extension enforcement

- Base: only `.json` files are read/written by the JSON driver
- Pro: `.md` files handled by YAML frontmatter driver
- Any other extension is rejected by the driver lookup

### 7.4 JSON injection prevention

- All write paths go through `wp_json_encode()` only — never `json_encode()` directly
- On read, JSON is decoded to array; PHP object wrapping is prevented (`json_decode(..., true)`)
- Record field values are sanitised per type before write (tags array values via `sanitize_text_field`, etc.)

### 7.5 Capability gates (tool-level)

| Tool | Required capability |
|---|---|
| `paper_store_list` | `read` |
| `paper_store_read` | `read` |
| `paper_store_search` | `read` |
| `paper_store_write` | `edit_posts` |
| `paper_store_update` | `edit_posts` |
| `paper_store_delete` | `delete_posts` |

### 7.6 ABSPATH guard + `.htaccess`

- `paper-store/.htaccess`: `Deny from all` (prevent direct HTTP access)
- `web.config` equivalent for IIS
- `index.php` with `<?php // Silence is golden.` in the root

---

## 8. Testing Strategy

### Unit tests (Base)

```bash
vendor/bin/phpunit tests/paper-store/test-paper-json-driver.php
vendor/bin/phpunit tests/paper-store/test-paper-index.php
vendor/bin/phpunit tests/paper-store/test-paper-query.php
vendor/bin/phpunit tests/paper-store/test-paper-repository.php
vendor/bin/phpunit tests/paper-store/test-paper-path-validation.php
```

### Integration tests (Base)

```bash
vendor/bin/phpunit tests/paper-store/test-paper-tool-list.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-read.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-search.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-write.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-update.php
vendor/bin/phpunit tests/paper-store/test-paper-tool-delete.php
```

### Key test scenarios

- JSON driver reads/writes valid records
- JSON driver rejects malformed JSON
- JSON driver rejects records missing required fields
- Index rebuilds correctly on write/update/delete
- Query builder filters by tag, status, date range
- Path traversal is blocked (`../../../etc/passwd` is rejected)
- Collection names with special characters are rejected
- Concurrent index writes are safe (flock)
- Empty collections return empty results (not errors)
- Large records (>1MB) are handled gracefully

---

## 9. Performance Considerations

| Concern | Mitigation |
|---|---|
| Large collections (10K+ records) | Index file avoids full directory scan; paginated tools |
| Index rebuild cost | Lazy rebuild on first query; batched full-rebuild via WP-Cron (Pro) |
| Concurrent writes | `flock()` on index file; atomic `dumpFile()` for records (never clobber) |
| Memory for full reads | Records streamed one-at-a-time; `limit()` + `offset()` on queries |
| Disk I/O pressure | Symfony Cache for index (Pro); object cache / transients for hot records (Base) |

---

## 10. Migration Path

### WordPress.org / plugin update

- Paper Store is **opt-in** — no migration required for existing users
- Directory is created on first access, not during activation
- No database tables are added; no options table bloat
- Clean-up: delete the `paper-store/` directory and the feature is gone

### Future data migration

- Pro tool `paper_store_import` can seed from WP posts, CSV, or JSON upload
- Pro tool `paper_store_export` can dump to ZIP for backup or migration

---

## 11. Phase Rollout

| Phase | Milestone | Components |
|---|---|---|
| **Phase 1** | v1.x.0 | JSON driver, Repository, Index, Query, 3 read tools (`list`, `read`, `search`) |
| **Phase 2** | v1.x.1 | 3 write tools (`write`, `update`, `delete`), record validation, lifecycle hooks |
| **Phase 3** | v1.x.2 (Pro) | Markdown+YAML driver, Git sync, admin UI, knowledge browser, import/export tools |

Phase 1 delivers immediate value: assistants can query flat-file knowledge without any database setup.

---

## 12. Open Questions & Decisions Needed

1. **Should Paper Store directories be created under `wp-content/uploads/mcp-ai-wpoos/paper-store/` or a custom path?** Recommended: under `wp-content/uploads/` for automatic backup coverage (many hosts back up `uploads/`), and it mirrors the existing agent-skills convention. Filter `wp_mcp_ai_paper_store_root` allows overrides.

2. **Should the index use JSON files or SQLite?** Recommended: JSON files. SQLite adds a PHP extension dependency; JSON is already in the plugin's native toolchain. Index files are typically < 100KB even for large collections (just IDs + tags).

3. **Should we add symfony/finder for the base plugin?** No — `glob()` and `scandir()` are sufficient for listing directories. Save `symfony/finder` for Pro features that benefit from recursive filtering (Git sync, bulk import).

4. **Naming: "Paper Store" vs "Flat Store" vs something else?**
   - **Paper Store**: Evokes the Laravel Paper lineage; distinctive; suggests documents/records. ✅ Recommended.
   - **Flat Store**: Accurate but generic.
   - **NVoOS Archive**: Suggests read-only archives only.
   - **MCP Vault**: Good but conflicts with HashiCorp Vault.

5. **File-level locking:** `flock()` for index writes is adequate for WordPress single-server deployments. For multi-server / load-balanced setups, the Pro tier could add a Redis-backed lock. The base implementation should document this limitation.

---

## 13. References

- [Laravel Paper](https://laravel-news.com/laravel-paper-a-flat-file-eloquent-driver) — Eloquent driver for flat files (inspiration)
- [Grav CMS Architecture](https://getgrav.org/) — YAML frontmatter, folder-based collections (pattern)
- [Orbit](https://github.com/ryangjchandler/orbit) — Laravel flat-file ORM (driver abstraction)
- [NV oOS CLAUDE.md](../../CLAUDE.md) — Naming, security, tool patterns
- [NV oOS conventions](../../.context/conventions.md) — PHP 7.4/8.1 compat, naming
- [NV oOS security checklist](../../.context/security-checklist.md) — Sanitisation, escaping, paths
- [NV oOS tool interface](../../includes/interfaces/interface-wp-mcp-ai-tool.php) — Tool contract
- [Symfony Filesystem docs](https://symfony.com/doc/6.4/components/filesystem.html) — Already vendored at 6.4
- [Symfony YAML docs](https://symfony.com/doc/6.4/components/yaml.html) — Pro-only, PHP 8.1+

---

*End of proposal. Review and sign-off requested before Phase 1 implementation begins.*
