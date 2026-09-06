# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Content Graph — Platform addon — composition root and all platform subsystems: agents, skills, slash-commands, harness, measurement, professions, A2A, ACP, federation, blueprints, and the ported operator-runtime waves (Workflows, Queues, Approvals, Rest, Tenant, Integrations, Google, ContentAssistant).

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-content-graph` + `nvoos-content-graph-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-content-graph-ai-platform.php` via `spl_autoload_register` (PSR-4 fallback) + Composer autoload |
| **Required addons** | `nvoos-content-graph` (core), `nvoos-content-graph-ai` (AI layer) |

## Public Surface

Root-level classes form the addon's backbone:

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Plugin` | `Plugin.php` | Bootstrap (singleton composition root) |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph_settings` option (settings), core `NvoosContentGraph\ToolRegistry`, AI addon services
- **Writes to:** Admin UI sections, REST responses
- **Upstream callers:** `nvoos-content-graph-ai-platform.php` (bootstrap), core `NvoosContentGraph\Plugin`
- **Downstream collaborators:** All subdirectories — `Admin/`, future `Agents/`, `Skills/`, `A2A/`, `Federation/`, etc.; the ported waves `Workflows/`, `Queues/`, `Approvals/`, `Rest/`, `Tenant/`, `Integrations/`, `Google/`, `ContentAssistant/` are wired by `Plugin::register*()` methods.
- **Events listened to:** `nvoos_content_graph/admin/register_sections`, `nvoos_content_graph/register_tools`, `rest_api_init`

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
- Core dependency: [`../../nvoos-content-graph/src/`](../../nvoos-content-graph/src/)
- AI dependency: [`../../nvoos-content-graph-ai/src/`](../../nvoos-content-graph-ai/src/)
