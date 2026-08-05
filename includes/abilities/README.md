# Abilities API Integration

Registers high-value NV oOS tools as WordPress Abilities for AI agent discoverability via the MCP Adapter.

## Public Surface

- **`WP_MCP_AI_Ability_Category_Registrar`** — Registers discovery categories on `wp_abilities_api_categories_init`.
- **`WP_MCP_AI_Ability_Bridge`** — Wraps a single tool instance into a `wp_register_ability()` call.
- **`WP_MCP_AI_Ability_Registrar`** — Iterates the tool registry and bridges eligible tools.
- **`WP_MCP_AI_Ability_Security_Bridge`** — Wires ability execution hooks into the existing security infrastructure (destructive ops gate, audit logger, cost tracker, concurrency guard).
- **`abilities-init.php`** — Bootstrap script that wires everything to the correct hooks.

## Neighbors

- `includes/interfaces/` — Tool interfaces including optional `WP_MCP_AI_Tool_Ability_Interface`
- `includes/class-wp-mcp-ai-tool-registry.php` — Tool registry singleton
- `includes/security/` — Security infrastructure (destructive ops gate, audit logger, cost tracker, concurrency guard)
- `addons/embedded/includes/abilities/` — Existing Abilities registration pattern (embedded addon)

## Context Files

- `.context/tool-registry.md` — Tool registry architecture
- `.agents/skills/wp-abilities-api/SKILL.md` — Abilities API coding patterns

## Security Integration

The security bridge wires our two execution hooks into the existing security layer:

| Hook | Integrated With |
|---|---|
| `wp_mcp_ai_before_ability_execute` | `WP_MCP_AI_Concurrency_Guard` — prevents overlapping destructive ops |
| `wp_mcp_ai_after_ability_execute` | `WP_MCP_AI_Security_Audit_Logger` — records all executions for audit |
| `wp_mcp_ai_after_ability_execute` | `WP_MCP_AI_Cost_Tracker` — estimates cost for token-consuming tools |
