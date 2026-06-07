# NV oOS Graphify — Platform

## Purpose

Platform layer for NV oOS Graphify — adds agents, skills, slash-commands, harness, measurement, professions, A2A (Agent-to-Agent), ACP (Agent Communication Protocol), federation, and blueprints on top of the AI addon.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-graphify` + `nvoos-graphify-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-graphify-platform.php` → `plugins_loaded` priority 10 (after AI addon at priority 5) |
| **Requires Plugins** | `nvoos-graphify`, `nvoos-graphify-ai` (WP 6.5+ header) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyPlatform\Plugin` | `src/Plugin.php` | Bootstrap (singleton composition root) |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option, core `NvoosGraphify\ToolRegistry`, AI addon services
- **Writes to:** Admin UI sections, REST responses
- **Upstream callers:** `nvoos-graphify-platform.php` (bootstrap), WordPress admin
- **Downstream collaborators:** `nvoos-graphify` core, `nvoos-graphify-ai` addon
- **Events listened to:** `nvoos_graphify/admin/register_sections`, `nvoos_graphify/register_tools`, `rest_api_init`

## Subsystems (extracted incrementally)

| Subsystem | Source (base plugin) | Status |
|---|---|---|
| Agent role system | `includes/assistants/` | ✅ Framework (2.2a) |
| Skills | `includes/skills/` + skill-*.php | ✅ Bridged (2.2b) |
| Slash Commands | `includes/slash-commands/` | ✅ Bridged (2.2c) |
| Harness | `includes/harness/` | ✅ Bridged (2.2d) |
| Measurement | `includes/measurement/` | ✅ Bridged (2.2e) |
| Professions | `includes/professions/` | ✅ Bridged (2.2f) |
| A2A | `includes/a2a/` | ✅ Bridged (2.2g) |
| ACP | `includes/acp/` | ✅ Bridged (2.2h) |
| Federation | `includes/` federation-*.php | ✅ Bridged (2.2i) |
| Blueprints | `includes/blueprints/` | ✅ Bridged (2.2j) |

## Conventions

- Namespace: `NvoosGraphifyPlatform\` — PSR-4 mapped to `src/`.
- `Plugin.php` is the composition root — wires platform subsystems incrementally.
- Admin tabs and sections register via the core's `SettingsRegistry` pattern (hook: `nvoos_graphify/admin/register_sections`).

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../.context/conventions.md`](../../.context/conventions.md) — naming + style
- [`../../.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`../../CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns

## See Also

- Required parent: [`../nvoos-graphify/`](../nvoos-graphify/) — core knowledge graph plugin
- Required addon: [`../nvoos-graphify-ai/`](../nvoos-graphify-ai/) — AI chat assistant addon
- [`src/`](src/) — source code root
