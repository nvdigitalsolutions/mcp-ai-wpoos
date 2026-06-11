# Admin

## Purpose

Hosts every wp-admin surface for NV oOS Graphify — settings page, graph explorer, and remote-source management — so the frontend and REST tiers stay free of admin concerns.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosGraphify\Plugin::registerAdmin()` (under `is_admin()` guard) |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Admin\SettingsPage` | `SettingsPage.php` | `Plugin::registerAdmin()` |
| `NvoosGraphify\Admin\GraphExplorer` | `GraphExplorer.php` | `Plugin::registerAdmin()` |
| `NvoosGraphify\Admin\RemoteAdmin` | `RemoteAdmin.php` | `Plugin::registerAdmin()` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option, custom graph DB tables, transients
- **Writes to:** `nvoos_graphify_settings` option (via `Settings::update()`), graph build transients
- **Upstream callers:** `NvoosGraphify\Plugin` (composition root)
- **Downstream collaborators:** `src/Graph/` (build, stats, export), `src/Settings` (option access), `src/Remote/` (driver registry)
- **Events fired:** `nvoos_graphify/after_settings_saved` (via `Settings`)
- **Events listened to:** `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `admin_notices`, `wp_ajax_nvoos_graphify_build`

## Conventions

- All admin pages are registered under a single top-level menu: "NV oOS Graphify".
- Settings use WordPress Settings API with custom tab routing.
- AJAX handlers live in the same classes (not a separate file) and use the `nvoos_graphify_build_graph` nonce.
- Assets (JS/CSS) are enqueued only on the plugin's own admin pages.

## Tests

```bash
vendor/bin/phpunit --filter '/Admin/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — nonces, caps, escaping

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../Graph/`](../Graph/), [`../Remote/`](../Remote/), [`../Settings.php`](../Settings.php)
