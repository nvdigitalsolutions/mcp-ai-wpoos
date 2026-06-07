# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Graphify — Platform addon — composition root and all platform subsystems: agents, skills, slash-commands, harness, measurement, professions, A2A, ACP, federation, and blueprints.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-graphify` + `nvoos-graphify-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-graphify-platform.php` via `spl_autoload_register` (PSR-4 fallback) + Composer autoload |
| **Required addons** | `nvoos-graphify` (core), `nvoos-graphify-ai` (AI layer) |

## Public Surface

Root-level classes form the addon's backbone:

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyPlatform\Plugin` | `Plugin.php` | Bootstrap (singleton composition root) |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option (settings), core `NvoosGraphify\ToolRegistry`, AI addon services
- **Writes to:** Admin UI sections, REST responses
- **Upstream callers:** `nvoos-graphify-platform.php` (bootstrap), core `NvoosGraphify\Plugin`
- **Downstream collaborators:** All subdirectories — `Admin/`, future `Agents/`, `Skills/`, `A2A/`, `Federation/`, etc.
- **Events listened to:** `nvoos_graphify/admin/register_sections`, `nvoos_graphify/register_tools`, `rest_api_init`

## Conventions

- One class per file; filename matches FQCN under `src/` (PSR-4).
- `Plugin.php` is the composition root — wires all platform subsystems.
- `Admin/PlatformSettings.php` integrates with the core's `SettingsRegistry` pattern.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — security

## See Also

- Parent: [`../`](../) — plugin root
- Core dependency: [`../../nvoos-graphify/src/`](../../nvoos-graphify/src/)
- AI dependency: [`../../nvoos-graphify-ai/src/`](../../nvoos-graphify-ai/src/)
