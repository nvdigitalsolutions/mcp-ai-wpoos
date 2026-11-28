# Connection providers (`includes/connections`)

## Purpose

Projects two connection stores — the toolkit's own database records and the Pro Remote Sites option — into one namespaced id space so a tool argument means the same endpoint whichever store authored it.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 8.1+ |
| **Loaded by** | `WP_MCP_AI_Db_Runtime::load()` |
| **Optional dependencies** | `WP_MCP_AI_Pro_Remote_Site_Manager` (Pro). The Remote Sites provider reports unavailable when it is absent; no fallback exists. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Connection_Provider_Interface` | `class-wp-mcp-ai-connection-provider-interface.php` | `db_test_connection`, future providers |
| `WP_MCP_AI_Connection_Resolver` | `class-wp-mcp-ai-connection-resolver.php` | `db_test_connection`, the console (P3) |
| `WP_MCP_AI_Native_Connection_Provider` | `class-wp-mcp-ai-native-connection-provider.php` | resolver |
| `WP_MCP_AI_Remote_Site_Connection_Provider` | `class-wp-mcp-ai-remote-site-connection-provider.php` | resolver; also registers the `remote:` credential filter |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Db_Connection_Registry`, `WP_MCP_AI_Db_Scope_Registry`, the `wp_mcp_ai_pro_remote_sites` option, and `_wp_mcp_ai_pro_remote_connections` post meta.
- **Writes to:** nothing. Providers are read-only projections.
- **Upstream callers:** `addons/pro/includes/tools/database/`.
- **Downstream collaborators:** `includes/database/`, `Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor`.
- **Events fired / listened to:** registers a `wp_mcp_ai_database_resolve_credential` callback for `remote:` handles.

## Conventions

- A provider never invents a connection: every descriptor it returns has an operator record behind it.
- Visibility is scope-derived for native records. Remote Sites uses the existing assistant-enablement convention (empty list means all enabled), but enablement is **not** a grant — the executor still requires a scope.
- An unknown namespace is refused; a bare id never silently selects a Remote Sites record.
- Ids are lowercased and namespaced `db:` / `rs:`; the descriptor's id keeps its prefix everywhere (audit, cursors, scope records).

## Tests

```bash
php lib/core/vendor/bin/phpunit -c tests/database/portable/phpunit.xml.dist --filter ConnectionsTest
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md)
- [`.context/security-checklist.md`](../../.context/security-checklist.md)
- `docs/project/proposals/167-database-toolkit-scoped-access-implementation-plan.md` — D11/D12

## See Also

- `includes/database/README.md`
- `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`
