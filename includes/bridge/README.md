# Bridge

## Purpose

Bridges NV oOS's provider architecture into the WordPress 7.0 Connectors API and AI Client infrastructure so that API keys configured in **Settings → Connectors** are available to NV oOS without double-entry — and so that NV oOS-managed providers appear on the core Connectors screen.

## Tier

| | |
|---|---|
| **Distribution** | Base (no Pro dependency; classes short-circuit on WP < 7.0) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (after provider client classes, before tool infrastructure) |
| **Optional dependencies** | WordPress 7.0+ (`wp_supports_ai`, `wp_connectors_init`, `WP_Connector_Registry`) — entire folder is a no-op when absent |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WP70_Bridge` | `class-wp-mcp-ai-wp70-bridge.php` | `bridge-init.php` (bootstrap); indirectly via `Credential_Resolver` |
| `WP_MCP_AI_WP70_Bridge::is_available()` | — | `Credential_Resolver`, tests, any code that needs to know if WP 7.0 AI infra is live |
| `WP_MCP_AI_WP70_Bridge::get_connector_setting_name()` | — | `Credential_Resolver` (for DB fallback key lookup) |
| `WP_MCP_AI_Credential_Resolver` | `class-wp-mcp-ai-credential-resolver.php` | `WP_MCP_AI_Model_Config::get_available_providers()`, all provider client classes that read API keys |
| `WP_MCP_AI_Credential_Resolver::get_api_key( $provider )` | — | Any code that previously read `$settings['{provider}_api_key']` |
| `WP_MCP_AI_Credential_Resolver::has_credentials( $provider )` | — | `get_available_providers()`, admin settings sections |
| `wp_mcp_ai_use_wp70_bridge` (filter) | — | Site owners / plugins that want to opt out of the bridge |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option (existing NV oOS keys), `connectors_ai_{id}_api_key` options (WP 7.0 Connector DB), `{PROVIDER}_API_KEY` env vars and PHP constants, `wp_mcp_ai_use_wp70_bridge` filter
- **Writes to:** nothing directly (stateless); connector registration writes to `WP_Connector_Registry` (in-memory only) via `wp_connectors_init`
- **Upstream callers:** `includes/bootstrap/loader.php` (loads `bridge-init.php`); `WP_MCP_AI_Model_Config` (calls `Credential_Resolver`); eventually provider client classes
- **Downstream collaborators:** `WP_Connector_Registry` (WP 7.0 core), `wp_get_connector()` / `wp_get_connectors()` / `wp_is_connector_registered()` (WP 7.0 core), `wp_supports_ai()` (WP 7.0 core)
- **Events fired:** none (the bridge registers on, not fires, hooks)
- **Events listened to:** `wp_connectors_init` (at priority 20 — after official/community connector plugins)

## Conventions

- **Early-bail gate:** Every public method in `WP_MCP_AI_WP70_Bridge` checks `is_available()` before touching WP 7.0 APIs. `is_available()` itself guards on `function_exists('wp_supports_ai')` — no polyfills, no back-compat shims.
- **Credential priority is immutable:** NV oOS settings ALWAYS take priority over Connector DB. This ensures that a site owner who has deliberately configured keys in NV oOS's own UI doesn't accidentally pick up a different key from the Connectors screen. Documented in the resolver's inline comments.
- **No autoloader dependency:** `bridge-init.php` uses plain `require_once` and does not rely on PSR-4 / Composer autoloading. The classes are tiny and will always be loaded together.
- **Sync is one-way (read-only):** The bridge reads from Connectors DB but never writes back. If a bidirectional sync is needed later, it must be a separate class gated by a user-facing toggle.
- **Connector IDs** follow WP 7.0's relaxed regex `/^[a-z0-9_-]+$/` (Trac #64861). Hyphens are reserved for community connectors; NV oOS uses underscores where needed.

## Tests

```bash
# Run the full test suite (requires WordPress test environment):
vendor/bin/phpunit

# When bridge-specific tests are added:
vendor/bin/phpunit tests/test-wp70-bridge.php
vendor/bin/phpunit tests/test-wp70-credential-resolver.php
```

Bridge tests must cover:
- `is_available()` returning `false` on WP < 7.0 and with `WP_AI_SUPPORT=false`
- `Credential_Resolver` priority chain (NV settings → Connector DB → ENV → constant)
- `has_credentials()` returning `true` for `none`-auth providers regardless of key state
- Connector registration skipping already-registered IDs
- `get_connector_setting_name()` fallback when `wp_get_connector()` is unavailable

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns
- [`docs/developer/folder-readme-convention.md`](../../docs/developer/folder-readme-convention.md) — this file's shape

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`../admin/`](../admin/) (provider settings UI), [`../services/`](../services/) (model discovery, async scheduler), [`../repositories/`](../repositories/)
- Core references: [Connectors API dev note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/), [AI Client dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/), [Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/), [Community AI Connectors](https://make.wordpress.org/ai/2026/03/25/call-for-testing-community-ai-connector-plugins/)
- Reference implementation: [OpenRouter connector plugin](https://github.com/aiiddqd/ai-connector-openrouter-wordpress)
