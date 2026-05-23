# Agents — Agent Role Implementations

## Purpose

Provides the catalogue of pluggable **agent roles** (planner, executor, critic) that an assistant can adopt, defining each role's capabilities, recommended tools, and behavioural defaults — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/agents-init.php`](../agents-init.php) — pulled in from `includes/bootstrap/loader.php` |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Agent_Role_Base` (abstract) | `class-wp-mcp-ai-agent-role-base.php` | every role implementation; extension point for filters that register custom roles |
| `WP_MCP_AI_Agent_Role_Planner` | `class-wp-mcp-ai-agent-role-planner.php` | `wp_mcp_ai_get_agent_roles()`, `includes/services/` multi-agent orchestration |
| `WP_MCP_AI_Agent_Role_Executor` | `class-wp-mcp-ai-agent-role-executor.php` | `wp_mcp_ai_get_agent_roles()`, team workflows |
| `WP_MCP_AI_Agent_Role_Critic` | `class-wp-mcp-ai-agent-role-critic.php` | `wp_mcp_ai_get_agent_roles()`, `harness/`'s `Self_Refine_Loop` (default critic) |

The procedural entry points `wp_mcp_ai_get_agent_roles()`, `wp_mcp_ai_get_agent_role( $type )`, `wp_mcp_ai_get_assistant_role( $assistant_id )`, and `wp_mcp_ai_set_assistant_role( $assistant_id, $type )` live in [`agents-init.php`](../agents-init.php) and are the canonical entry points for callers — do not instantiate role classes directly.

The role contract itself is defined by `WP_MCP_AI_Agent_Role_Interface` in [`includes/interfaces/`](../interfaces/).

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta `_wp_mcp_ai_agent_role`; recommended-tool lists are static class data.
- **Writes to:** assistant post meta `_wp_mcp_ai_agent_role` (via `wp_mcp_ai_set_assistant_role()`).
- **Upstream callers:** [`includes/assistants/metaboxes/class-wp-mcp-ai-metabox-primary-roles.php`](../assistants/metaboxes/) (UI), [`includes/teams/`](../teams/) orchestration, [`includes/harness/class-wp-mcp-ai-self-refine-loop.php`](../harness/) (Critic as default critic), Pro multi-agent dashboard.
- **Downstream collaborators:** [`includes/tools/`](../tools/) (roles advertise recommended tool slugs but never invoke tools directly), [`includes/interfaces/interface-wp-mcp-ai-agent-role.php`](../interfaces/).
- **Events fired:** `wp_mcp_ai_agent_roles` (filter — register custom roles by name → instance).
- **Events listened to:** none — this folder is pure data + behaviour, with no WordPress hook subscriptions.

## Conventions

- New roles MUST extend `WP_MCP_AI_Agent_Role_Base` and be registered via the `wp_mcp_ai_agent_roles` filter — never edit `wp_mcp_ai_get_agent_roles()` to hard-code a new role.
- The `$role_type` string is the persisted identifier (passed through `sanitize_key()` before save). Treat it as an immutable contract; renaming a role type orphans every assistant already pinned to it.
- Roles describe **dispositions**, not capabilities — required WordPress capability checks belong in `assistants/` (the metabox) and `tools/` (per-tool `required_capability`), not here.
- Keep recommended-tool lists by **slug**, never by class — slugs survive Pro/Base swapping and provider refactors.

## Tests

```bash
vendor/bin/phpunit tests/test-agent-roles.php
```

Related coverage that exercises roles indirectly:

```bash
vendor/bin/phpunit tests/test-multi-agent-orchestration-integration.php
vendor/bin/phpunit tests/test-architect-agent-assistant-creation.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability checks at the call-site (always)
- [`AGENTS.md`](../../AGENTS.md) — repo-wide agent inventory + BMAD methodology
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat policy
- BMAD agent definitions: [`.bmad/agents/`](../../.bmad/agents/) — runtime BMAD personas (distinct from these code-level roles)

## See Also

- Sibling: [`assistants/`](../assistants/) — where the role is assigned per-assistant via metabox
- Sibling: [`teams/`](../teams/) — team orchestration consumes the planner/executor/critic split
- Sibling: [`harness/`](../harness/) — `Self_Refine_Loop` plugs Critic in as its default critic callable
- Interface: [`includes/interfaces/`](../interfaces/) — `WP_MCP_AI_Agent_Role_Interface`
