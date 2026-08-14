# export/

## Purpose

Houses the three Pro export providers that plug into the base Backup & Restore pipeline — remote sites, license data, and JetEngine CCTs — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (Pro addon minimum) |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` — registered on the `wp_mcp_ai_register_export_providers` hook with `file_exists()` guards per provider |
| **Optional dependencies** | JetEngine (for the CCT provider); none for the other two |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Export_Provider_Remote_Sites` | `class-wp-mcp-ai-export-provider-remote-sites.php` | Base export registry (Backup & Restore UI + export tools) |
| `WP_MCP_AI_Export_Provider_License` | `class-wp-mcp-ai-export-provider-license.php` | Base export registry |
| `WP_MCP_AI_Export_Provider_JetEngine_CCTs` | `class-wp-mcp-ai-export-provider-jetengine-ccts.php` | Base export registry |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** remote-site connection data, Pro license data, JetEngine CCT definitions (only when the plugin is active).
- **Writes to:** export artifacts through the base export pipeline (provider contract, not direct file I/O).
- **Upstream callers:** `addons/pro/mcp-ai-wpoos-pro.php` (registration hook), the base export system via `includes/admin/export/` and `includes/admin/sections/class-wp-mcp-ai-section-advanced.php`.
- **Downstream collaborators:** `WP_MCP_AI_Export_Provider_Base` (abstract base in `includes/admin/export/`) and the `WP_MCP_AI_Export_Provider` interface.
- **Events fired:** none public.
- **Events listened to:** `wp_mcp_ai_register_export_providers`.

## Conventions

- Every provider extends `WP_MCP_AI_Export_Provider_Base` and implements the provider contract — no bespoke export paths.
- Registration is conditional (`file_exists()` + dependency checks) so a missing JetEngine never breaks the backup pipeline.
- Providers are passive data adapters: scheduling, encryption, and delivery are the base pipeline's responsibility.

## Tests

```bash
vendor/bin/phpunit tests/test-all-import-export-tools.php
vendor/bin/phpunit tests/test-csv-export.php
vendor/bin/phpunit tests/test-excel-data-export-schema.php
```

No dedicated provider suite exists; the export pipeline and its tools are covered by the base import/export tests. Add `tests/` coverage when a new provider lands.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always; exports contain sensitive site data)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base pipeline vs Pro provider boundaries

## See Also

- Upstream parent: [`addons/pro/includes/`](../)
- Base pipeline: [`includes/admin/export/`](../../../../includes/admin/export/) — provider interface + base class
