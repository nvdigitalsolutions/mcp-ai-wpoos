# Database adapters (`includes/database`)

## Purpose

Turns the host-free database kernel into a working WordPress service: one PDO connection factory, one engine adapter per SQL dialect, one scoped executor, and the registries, probe, catalogue, audit log and write path around it.

## Tier

| | |
|---|---|
| **Distribution** | Base files, Pro runtime. The classes ship in the base plugin, but they only load when the Pro add-on's kernel (`addons/pro/lib/database`) is present — see `WP_MCP_AI_Db_Runtime`. |
| **PHP target** | 8.2+ (the kernel uses `readonly` classes and enums) |
| **Loaded by** | `WP_MCP_AI_Db_Runtime::load()`, called from `addons/pro/includes/tools/database/init.php` and from the Pro tool-registry block |
| **Optional dependencies** | `pdo_pgsql`, `pdo_mysql`, `pdo_sqlite` (per connection; a missing driver is `db_extension_missing`, not a fatal) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Db_Runtime` | `class-wp-mcp-ai-db-runtime.php` | base loader, Pro toolkit, Site Health |
| `WP_MCP_AI_Db_Executor` | `class-wp-mcp-ai-db-executor.php` | Pro `db_*` tools, the console (P3) |
| `WP_MCP_AI_Db_Writer` | `class-wp-mcp-ai-db-writer.php` | `db_propose_write`, `db_apply_write`, the approval screen |
| `WP_MCP_AI_Db_Write_Compiler` | `class-wp-mcp-ai-db-write-compiler.php` | writer; pure, unit-testable |
| `WP_MCP_AI_Db_Connection_Registry` | `class-wp-mcp-ai-db-connection-registry.php` | connection provider, settings page, probe |
| `WP_MCP_AI_Db_Scope_Registry` | `class-wp-mcp-ai-db-scope-registry.php` | executor, connection provider, settings page |
| `WP_MCP_AI_Db_Catalogue` | `class-wp-mcp-ai-db-catalogue.php` | executor, `db_describe` |
| `WP_MCP_AI_Db_Probe` | `class-wp-mcp-ai-db-probe.php` | `db_test_connection`, settings page |
| `WP_MCP_AI_Db_Connection_Factory` | `class-wp-mcp-ai-db-connection-factory.php` | everything that opens a connection |
| `WP_MCP_AI_Db_Engine_*` | `class-wp-mcp-ai-db-engine-*.php` | factory; pure DSN/options/session SQL |
| `WP_MCP_AI_Db_Audit_Log` | `class-wp-mcp-ai-db-audit-log.php` | executor, writer, `db_audit_tail` |
| `WP_MCP_AI_Db_Redactor` | `class-wp-mcp-ai-db-redactor.php` | executor, `db_list_connections` |
| `WP_MCP_AI_Db_*_Cache`, `WP_MCP_AI_Db_Credential_Interface`, `WP_MCP_AI_Db_Encrypted_Credential_Store` | per file | catalogue, factory |
| `WP_MCP_AI_Db_Site_Database` | `class-wp-mcp-ai-db-site-database.php` | registry save, factory open |
| `WP_MCP_AI_Db_Limit_Defaults` | `class-wp-mcp-ai-db-limit-defaults.php` | settings page (prefill), console overview (effective defaults) |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (toolkit toggle), options `wp_mcp_ai_database_connections`, `wp_mcp_ai_database_scopes`, `wp_mcp_ai_database_audit`, `wp_mcp_ai_database_write_proposals`, the master key (`WP_MCP_AI_Encryption`), and the target database itself.
- **Writes to:** those four options, the uploads directory (exports, from the tool layer), and the target database only through `WP_MCP_AI_Db_Writer`.
- **Upstream callers:** `includes/connections/`, `addons/pro/includes/tools/database/`, `addons/pro/includes/admin/class-wp-mcp-ai-database-settings-page.php`, `addons/pro/includes/rest/class-wp-mcp-ai-pro-database-console-rest-controller.php`.
- **Downstream collaborators:** `Nvoos\Database\…` (the kernel in `addons/pro/lib/database`), `WP_MCP_AI_Encryption`.
- **Events fired:** `wp_mcp_ai_database_audit_recorded`.
- **Events listened to:** `wp_mcp_ai_database_resolve_credential` (external secret stores), `wp_mcp_ai_database_tenant_id` (tenant resolution), `wp_mcp_ai_database_sqlite_roots`.

## Conventions

- Never call `PDO::exec()` or `PDO::query()` with a statement that contains a caller value. `exec()` is used only for constant transaction/session verbs (`BEGIN`, `PRAGMA query_only = ON`); everything else is `prepare()` + `execute()`.
- Never read `PDOException::getMessage()`; map `errorInfo` through `Nvoos\Database\Domain\Service\Database\ErrorMapper`.
- Never `fetchAll()` on a result that can be large; stream with the executor's deadline/byte/row caps and `closeCursor()`.
- Every class here is tolerant of a missing WordPress function only where it must be (`Db_Runtime`); the registries and audit log assume the host.
- The engine adapters are pure: no I/O, no WordPress. Keep them that way so the timeout-unit tests stay meaningful.

## Tests

```bash
# Portable adapter suite (no WordPress, no database server; SQLite file fixtures)
php lib/core/vendor/bin/phpunit -c tests/database/portable/phpunit.xml.dist

# Kernel suite (host-free)
php lib/core/vendor/bin/phpunit -c addons/pro/lib/database/phpunit.xml.dist
```

The Postgres-dependent gates (L2 removal, hostile corpus against a live engine) require `NVOOS_TEST_PGSQL_DSN` and are skipped without it.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md)
- [`.context/security-checklist.md`](../../.context/security-checklist.md)
- [`.context/tool-registry.md`](../../.context/tool-registry.md)
- `docs/project/proposals/167-database-toolkit-scoped-access-implementation-plan.md` — component contracts, engine tables, gates

## See Also

- `addons/pro/lib/database/README.md` — the host-free kernel
- `includes/connections/README.md` — provider namespacing
