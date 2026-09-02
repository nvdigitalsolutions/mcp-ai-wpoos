# Cli

## Purpose

WP-CLI surface for the AI addon — `wp nvoos-cg-ai …`. Command classes hold the data logic in plain static methods (testable without the WP-CLI runtime); the `run*()` methods are thin wrappers around `WP_CLI::*` output helpers. `NvoosContentGraphAi\Cli` (the `Cli.php` hub, hooked to `cli_init`) registers every command.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Cli::registerCommands()` on `cli_init` |

## Public Surface

| Command | Class | Method |
|---|---|---|
| `wp nvoos-cg-ai status` | `StatusCommand` | `run()` / `get_items()` |
| `wp nvoos-cg-ai tools list` | `ToolsCommand` | `run()` / `get_tools()` |
| `wp nvoos-cg-ai providers list` | `ProvidersCommand` | `run()` / `get_providers()` |
| `wp nvoos-cg-ai settings list` | `SettingsCommand` | `run_list()` / `get_settings_map()` |
| `wp nvoos-cg-ai settings get <key>` | `SettingsCommand` | `run_get()` / `get_setting()` |
| `wp nvoos-cg-ai graph stats` | `GraphCommand` | `run()` / `get_stats()` |
| `wp nvoos-cg-ai migrate-keys` / `key-status` | `Cli` (hub, pre-extraction) | static |

## Inputs / Outputs / Neighbors

- **Reads from:** `CoreBridge` (settings store, provider router, tool registry), `CredentialResolver`/`CredentialStore` (credentials), the base `WP_MCP_AI_Tool_Registry` in monolith installs, parent-plugin `NvoosContentGraph\Graph\Db` table names (stats)
- **Writes to:** `WP_CLI` output (tables/json/yaml)
- **Upstream callers:** WP-CLI
- **Downstream collaborators:** `src/CoreBridge.php`, `src/Adapter/CredentialResolver.php`, `src/Security/CredentialStore.php`
- **Events fired:** None
- **Events listened to:** `cli_init`

## Conventions

- Registration is guarded by `class_exists( 'WP_CLI' )`; the command classes never reference `WP_CLI_*` symbols at class-load time, so the autoloader stays safe when WP-CLI is absent.
- `WP_CLI::*` calls live only inside `run*()` wrappers — `get_*()` methods return plain arrays/strings for unit testing.
- Secrets (`*api_key*` settings keys) are refused by the settings surface; credential inspection goes through `key-status` / `providers list`.
- Monolith installs read the base registry for `tools list` (the base hub owns the agent tool surface); standalone reads the nvoos/core registry via `CoreBridge`.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — secrets handling

## See Also

- Parent: [`../`](../) — src root
- Hub: [`../Cli.php`](../Cli.php)
- Credentials: [`../Adapter/CredentialResolver.php`](../Adapter/CredentialResolver.php), [`../Security/CredentialStore.php`](../Security/CredentialStore.php)
