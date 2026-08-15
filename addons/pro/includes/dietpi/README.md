# dietpi/

## Purpose

Houses the DietPi management client stack — SSH client, App API client, helpers, and the service catalogue — that powers the DietPi Pro Toolkit's remote system administration tools, and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (Pro addon minimum) |
| **Loaded by** | `addons/pro/includes/dietpi-toolkit-init.php` (special-cased as slug `dietpi` in `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php`); classes autoloaded on demand |
| **Optional dependencies** | PHP SSH2 extension (for `WP_MCP_AI_DietPi_SSH_Client`); the DietPi App API is optional |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_DietPi_SSH_Client` | `class-wp-mcp-ai-dietpi-ssh-client.php` | DietPi toolkit tools (shell/service/backup operations) |
| `WP_MCP_AI_DietPi_App_Client` | `class-wp-mcp-ai-dietpi-app-client.php` | DietPi toolkit tools (DietPi App API calls) |
| `WP_MCP_AI_DietPi_Helpers` | `class-wp-mcp-ai-dietpi-helpers.php` | DietPi toolkit tools (validation + formatting) |
| `WP_MCP_AI_DietPi_Service_Catalogue` | `class-wp-mcp-ai-dietpi-service-catalogue.php` | DietPi toolkit tools (installable service list) |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** connection settings (host, credentials) from the Pro settings registry; remote DietPi systems over SSH / the App API.
- **Writes to:** remote DietPi systems (commands, service states); no local persistent state.
- **Upstream callers:** `addons/pro/includes/tools/dietpi/` tools, `addons/pro/includes/admin/class-wp-mcp-ai-dietpi-settings-page.php`, `addons/pro/includes/mcp-servers/mcp-servers-init.php`.
- **Downstream collaborators:** `addons/pro/services/dietpi-proxy/` (server-side proxy companion).
- **Events fired:** none public.
- **Events listened to:** none public.

## Conventions

- SSH and App API credentials live only in the Pro settings registry — never in tool arguments, defaults, or logs.
- Connection classes are stateless transports: settings are read per request, so changed credentials take effect without re-instantiation.
- Every remote call has an explicit timeout and returns a typed result (data or error envelope) — tools decide how to present failures.

## Tests

```bash
vendor/bin/phpunit tests/
```

No dedicated suite exists for this folder yet; the DietPi toolkit tools are covered by the Pro tool tests. Add `tests/` coverage when extending the client stack.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always; SSH credentials are secrets — see the vault/credential guidance)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only subsystem boundaries

## See Also

- Upstream parent: [`addons/pro/includes/`](../)
- Siblings worth knowing about: [`../tools/dietpi/`](../tools/dietpi/) (tool consumers), [`../admin/class-wp-mcp-ai-dietpi-settings-page.php`](../admin/class-wp-mcp-ai-dietpi-settings-page.php) (settings)
- Related: `docs/project/proposals/` DietPi Pro Toolkit phases (see the toolkit rollout proposals)
