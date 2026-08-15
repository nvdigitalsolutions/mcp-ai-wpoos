# Abilities API Integration

## Purpose

Registers high-value NV oOS tools as WordPress Abilities (machine-readable plugin operations with JSON Schema contracts) so AI agents can discover and execute them through the wp.org Abilities API surface.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (Abilities API calls are guarded by `function_exists()` — no-ops on WordPress < 6.9) |
| **Loaded by** | `includes/bootstrap/loader.php` → `abilities-init.php` (after the tool registry is available) |
| **Optional dependencies** | none |

## Public Surface

- **`WP_MCP_AI_Ability_Category_Registrar`** — Registers discovery categories on `wp_abilities_api_categories_init`.
- **`WP_MCP_AI_Ability_Bridge`** — Wraps a single tool instance into a `wp_register_ability()` call.
- **`WP_MCP_AI_Ability_Registrar`** — Iterates the tool registry and bridges eligible tools.
- **`WP_MCP_AI_Ability_Security_Bridge`** — Wires ability execution hooks into the existing security infrastructure (destructive ops gate, audit logger, cost tracker, concurrency guard).
- **`abilities-init.php`** — Bootstrap script that wires everything to the correct hooks.

## Inputs / Outputs / Neighbors

- **Reads from:** the tool registry (definitions + capability metadata); the Abilities API registry on WP 6.9+.
- **Writes to:** the Abilities API registry (`wp_register_ability()` entries + categories).
- **Upstream callers:** plugin bootstrap; the MCP adapter (agent discovery).
- **Downstream collaborators:** `includes/interfaces/` (optional `WP_MCP_AI_Tool_Ability_Interface`), `includes/class-wp-mcp-ai-tool-registry.php`, `includes/security/`.
- **Events fired:** `wp_mcp_ai_before_ability_execute`, `wp_mcp_ai_after_ability_execute`.
- **Events listened to:** `wp_abilities_api_categories_init`.

## Neighbors

- `includes/interfaces/` — Tool interfaces including optional `WP_MCP_AI_Tool_Ability_Interface`
- `includes/class-wp-mcp-ai-tool-registry.php` — Tool registry singleton
- `includes/security/` — Security infrastructure (destructive ops gate, audit logger, cost tracker, concurrency guard)
- `addons/embedded/includes/abilities/` — Existing Abilities registration pattern (embedded addon)

## Conventions

- Every registration is a no-op when the Abilities API functions are absent (WP < 6.9) — keep the `function_exists()` guards in every registrar.
- Security wiring goes exclusively through the `WP_MCP_AI_Ability_Security_Bridge` hooks; never bypass the security pipeline from an ability callback.

### Security Integration

The security bridge wires our two execution hooks into the existing security layer:

| Hook | Integrated With |
|---|---|
| `wp_mcp_ai_before_ability_execute` | `WP_MCP_AI_Concurrency_Guard` — prevents overlapping destructive ops |
| `wp_mcp_ai_after_ability_execute` | `WP_MCP_AI_Security_Audit_Logger` — records all executions for audit |
| `wp_mcp_ai_after_ability_execute` | `WP_MCP_AI_Cost_Tracker` — estimates cost for token-consuming tools |

## Tests

```bash
vendor/bin/phpunit tests/abilities/
```

Coverage: bridge wrapping, registrar iteration, category registration, and backward-compat no-op behavior (`test-ability-backward-compat.php`). Uses the mock tool helper in `tests/abilities/class-wp-mcp-ai-ability-bridge-mock-tool.php`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registry architecture
- [`.agents/skills/wp-abilities-api/SKILL.md`](../../.agents/skills/wp-abilities-api/SKILL.md) — Abilities API coding patterns

## See Also

- Upstream parent: [`includes/`](../)
- Siblings worth knowing about: [`includes/tools/`](../tools/) (the tools being registered), [`includes/security/`](../security/) (the hooks being wired)
