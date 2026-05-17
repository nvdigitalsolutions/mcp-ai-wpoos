# Bootstrap

## Purpose

Defines and runs the plugin's boot sequence — constants, autoloader, helpers, cron, hooks, the class loader, and lifecycle handlers — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base (always loaded; Pro reuses without re-running) |
| **PHP target** | 7.4+ |
| **Loaded by** | `mcp-ai-wpoos.php` (entry point) — files are `require_once`d in dependency order |
| **Optional dependencies** | none (must boot cleanly with zero optional plugins) |

## Public Surface

These files are intentionally procedural and side-effectful. Other folders depend on the constants, hooks, and helper functions they define — not on the files themselves.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_VERSION`, `WP_MCP_AI_PATH`, `WP_MCP_AI_URL` (constants) | `constants.php` | every folder |
| Composer autoloader bootstrap + dev-deps notice | `autoload.php` | all classes that rely on `vendor/` |
| `wp_mcp_ai_core_loaded()`, `wp_mcp_ai_is_base_version()`, early helpers | `helpers.php` | add-ons, gating logic |
| `wp_mcp_ai_ensure_cleanup_cron_scheduled()` + cron handlers | `cron.php` | WP-Cron, `services/` cleanup jobs |
| Upload MIME / size filters, cache-invalidation hooks, admin notices | `hooks.php` | `assistants/`, attachments pipeline |
| `require_once` chain for every plugin class file | `loader.php` | implicit — runs at load time |
| `register_activation_hook` / deactivation / uninstall callbacks | `activation.php` | wired up from `mcp-ai-wpoos.php` |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_FILE` (set in entry point), `WP_MCP_AI_BASE_VERSION` flag, `vendor/autoload.php`, `is_multisite()` state.
- **Writes to:** plugin options (initial defaults on activation), scheduled WP-Cron events, output-buffer state during class loading.
- **Upstream callers:** `mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, and `class-wp-mcp-ai-plugin.php` (singleton init).
- **Downstream collaborators:** every other folder — `loader.php` is the single point that wires up `admin/`, `services/`, `tools/`, `rest/`, `integrations/`, etc.
- **Events fired:** `wp_mcp_ai_cleanup_gemini_files`, `wp_mcp_ai_cleanup_openai_files`, activation/deactivation actions (via WordPress core).
- **Events listened to:** `plugins_loaded` (priority 25 — ensure cron scheduled), `upload_mimes`, `wp_handle_upload_prefilter`, assistant `save_post_*` invalidation hooks.

## Conventions

- This folder is the **only** place where top-level `require_once` chains and `register_activation_hook` calls are permitted. New classes must be added to `loader.php`, not loaded ad-hoc from elsewhere.
- Bootstrap files must run on a fresh install **without** Composer dev dependencies present, without optional plugins (JetEngine, WooCommerce, Elementor) active, and on multisite.
- Order matters — never reorder the includes in `mcp-ai-wpoos.php`. `constants.php` → `autoload.php` → `helpers.php` → `cron.php` → `hooks.php` → `loader.php` → `activation.php` is the only supported sequence.
- The output buffer opened in `loader.php` must be balanced before any HTTP response is flushed; do not leave `ob_start()` calls inside leaf classes.

## Tests

There is no dedicated `tests/bootstrap/` slice — the boot sequence is exercised implicitly by every PHPUnit run via `tests/bootstrap.php`. Related lifecycle tests:

```bash
vendor/bin/phpunit tests/test-activation-tracker.php
vendor/bin/phpunit tests/test-pro-providers-autoloader.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, file organization (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability/nonce rules (always)
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat policy referenced by the Tier table
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — relevant when touching `wp_mcp_ai_is_base_version()` semantics

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders worth knowing about: [`admin/`](../admin/), [`services/`](../services/), [`tools/`](../tools/) — all loaded from `loader.php`
- Entry points: [`mcp-ai-wpoos.php`](../../mcp-ai-wpoos.php), [`mcp-ai-wpoos-base.php`](../../mcp-ai-wpoos-base.php)
