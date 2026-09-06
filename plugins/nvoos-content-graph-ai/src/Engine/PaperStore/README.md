# PaperStore

## Purpose

Wave E6 port surface (sub-cluster 3 — paper-store). The **NVoOS Paper
Store** flat-file storage layer for AI-managed knowledge from the base
plugin's `includes/paper-store/` — assistants and MCP tools read and
write structured JSON records without a database (a complementary data
source, not a replacement, for posts/users/orders/settings). Ported
into the AI addon per decision D4 (engine pieces fold into
`nvoos-content-graph-ai` under the `Engine\` namespace).

## Tier

| | |
|---|---|
| **Distribution** | AI addon (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerEngine()` → `PaperStoreBootstrap::register()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader (`includes/bootstrap/loader.php` → `paper-store-init.php`) owns the same tool registration monolith |
| **Optional dependencies** | None (native PHP; the base `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service` for atomic writes when available — monolith-only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Engine\PaperStore\PaperDriverInterface` | `PaperDriverInterface.php` | Driver contract (`read`/`write`/`delete`/`get_extension`) |
| `NvoosContentGraphAi\Engine\PaperStore\PaperJsonDriver` | `PaperJsonDriver.php` | JSON format driver (required fields, timestamps, atomic writes) |
| `NvoosContentGraphAi\Engine\PaperStore\PaperIndex` | `PaperIndex.php` | Inverted index per collection (`_indexes/{collection}.idx.json`) |
| `NvoosContentGraphAi\Engine\PaperStore\PaperRepository` | `PaperRepository.php` | CRUD repository per collection |
| `NvoosContentGraphAi\Engine\PaperStore\PaperQuery` | `PaperQuery.php` | Fluent query builder |
| `NvoosContentGraphAi\Engine\PaperStore\PaperStoreManager` | `PaperStoreManager.php` | Singleton root manager (paths, drivers, repositories, traversal guard) |
| `NvoosContentGraphAi\Engine\PaperStore\PaperStoreRemoteTrait` | `PaperStoreRemoteTrait.php` | Remote-proxy contract shared by the future paper_store_* tools |
| `NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap` | `PaperStoreBootstrap.php` | Wraps the base `paper-store-init.php` hook surface |

## Inputs / Outputs / Neighbors

- **Reads from:** file system at `wp-content/uploads/mcp-ai-wpoos/paper-store/`
  (JSON record files, index files).
- **Writes to:** same file system (atomic writes via the base Filesystem
  Service monolith, native `file_put_contents` with `LOCK_EX`
  standalone) plus `.htaccess` / `index.php` security files and the
  `_indexes/` directory.
- **Upstream callers:** any addon code via
  `PaperStoreManager::get_instance()->get_repository()`; the future
  paper_store_* tool wave (the base's six MCP tools + REST controller
  remain base-owned until then — documented deferral).
- **Downstream collaborators:** `PaperDriverInterface` implementations;
  (monolith) `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service` and
  `WP_MCP_AI_Pro_Remote_Site_Manager` (trait path).
- **Events fired:** `wp_mcp_ai_paper_store_initialized`,
  `wp_mcp_ai_paper_record_saved`, `wp_mcp_ai_paper_record_deleted`,
  `wp_mcp_ai_paper_index_rebuilt`.
- **Events listened to:** `wp_mcp_ai_bootstrapped` (priority 30,
  dormant standalone — no standalone emitter yet).

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone).
- One file = one class. All I/O goes through the driver interface —
  never read/write files directly in repository or query code.
- Path traversal is prevented by the Manager's `validate_path()`
  (`realpath()` + prefix check).
- Index locking uses `flock()` with a non-blocking 3-second timeout
  (single-server deployments).
- Record IDs and collection names are sanitised via `sanitize_key()`.
- Required record fields: `id`, `type`, `title` — the JSON driver
  enforces this at read and write.
- Timestamps are auto-managed: `created_at` set on first write,
  `updated_at` on every write (ISO 8601).
- Byte-identical constants/option keys/error codes/hook names with the
  base — including the base's preserved quirks (the `max_tags` cap
  applies on the rebuild path only; the query builder's intentional
  loose comparisons; `tags` `IN` resolving to the first array value).
  Deviations documented in the class docblocks (PSR-4 class names,
  inline requires → autoload + static `register()`, text domain,
  per-mode collaborator seams).
- The base's six `paper_store_*` MCP tools and the `paper-store` REST
  controller are deferred to the Paper Store tool wave — the engine is
  consumed directly until then.

## Tests

- `tests/Ecosystem/test-paper-store-core.php` — driver, index, manager
  (paths, security files, traversal guard, caching, filters/actions).
- `tests/Ecosystem/test-paper-store-repository-query.php` — repository
  CRUD + query builder + bootstrap hook surface.

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-paper-store-core.php
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-paper-store-repository-query.php
```

## Also Load

- [`../README.md`](../README.md) — the Engine wave (OOS + markup + paper-store)
- [`../../README.md`](../../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — path traversal + sanitisation

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E6 row status
- [`includes/paper-store/`](../../../../includes/paper-store/) + [`includes/tools/paper-store/`](../../../../includes/tools/paper-store/) — the base subsystem (the port's origin) and its tool surface
