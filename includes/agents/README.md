# Agents — Agent Role Implementations & Audit Trail

## Purpose

Provides the catalogue of pluggable **agent roles** (planner, executor, critic) that an assistant can adopt, defining each role's capabilities, recommended tools, and behavioural defaults. Also houses the **audit trail** system (CoSAI Principle 3) that records every agent action in an immutable, queryable event log.

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
| `WP_MCP_AI_Agent_Audit_Trail` (static) | `class-wp-mcp-ai-agent-audit-trail.php` | CoSAI Principle 3 immutable audit log — `start_session()`, `log_decision()`, `log_tool_call()`, `log_output()`, `end_session()`, `get_trail()`, `get_trails_by_assistant()` |
| `WP_MCP_AI_Agent_Capability_Boundary` | `class-wp-mcp-ai-agent-capability-boundary.php` | CoSAI Principle 2 — immutable tool allow-lists, rate limiting, budget tracking per session |
| `WP_MCP_AI_Agent_Approval_Gate` (static) | `class-wp-mcp-ai-agent-approval-gate.php` | CoSAI Principle 1 — risk-tiered human approval gate (low→auto, medium→pre-approved, high→pending, critical→override) |
| `WP_MCP_AI_Agent_Code_Sandbox` | `class-wp-mcp-ai-agent-code-sandbox.php` | MCP-T3/T5 — sandboxed code execution (Python/Node/Bash/PHP), `proc_open` isolation, output caps, timeout enforcement |
| `WP_MCP_AI_Agent_Capability_Boundary_Hooks` | (same file as Capability Boundary) | Integration bridge — hooks into `wp_mcp_ai_before_tool_execution` at priority 1 to enforce all CoSAI gates before any tool executes |
| `WP_MCP_AI_Agent_Harness_Evolver` | `class-wp-mcp-ai-agent-harness-evolver.php` | Continual Harness (Karten et al., 2026) — reads audit trail trajectories, detects failure signatures, applies CRUD to prompt/roles/skills/memory mid-session. Includes `WP_MCP_AI_Agent_Harness_Evolver_Role_Adapter` for evolved role registration. |
| `WP_MCP_AI_Agent_Harness_Bootstrap` (static) | `class-wp-mcp-ai-agent-harness-bootstrap.php` | Save/load evolved harness state across sessions — bundles, lists, prunes (max 10 per assistant) |

The procedural entry points `wp_mcp_ai_get_agent_roles()`, `wp_mcp_ai_get_agent_role( $type )`, `wp_mcp_ai_get_assistant_role( $assistant_id )`, and `wp_mcp_ai_set_assistant_role( $assistant_id, $type )` live in [`agents-init.php`](../agents-init.php) and are the canonical entry points for callers — do not instantiate role classes directly.

The role contract itself is defined by `WP_MCP_AI_Agent_Role_Interface` in [`includes/interfaces/`](../interfaces/).

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta `_wp_mcp_ai_agent_role`; recommended-tool lists are static class data.
- **Writes to:** assistant post meta `_wp_mcp_ai_agent_role` (via `wp_mcp_ai_set_assistant_role()`).
- **Upstream callers:** [`includes/assistants/metaboxes/class-wp-mcp-ai-metabox-primary-roles.php`](../assistants/metaboxes/) (UI), [`includes/teams/`](../teams/) orchestration, [`includes/harness/class-wp-mcp-ai-self-refine-loop.php`](../harness/) (Critic as default critic), Pro multi-agent dashboard.
- **Downstream collaborators:** [`includes/tools/`](../tools/) (roles advertise recommended tool slugs but never invoke tools directly), [`includes/interfaces/interface-wp-mcp-ai-agent-role.php`](../interfaces/).
- **Events fired:** `wp_mcp_ai_agent_roles` (filter — register custom roles by name → instance).
- **Audit trail events:** `wp_mcp_ai_audit_trail_store_event`, `wp_mcp_ai_audit_trail_event_stored`, `wp_mcp_ai_audit_trail_retention_days`, `wp_mcp_ai_audit_trail_prune`, `wp_mcp_ai_audit_trail_pruned`.
- **Capability boundary events:** `wp_mcp_ai_capability_boundary_allow_tool`, `wp_mcp_ai_capability_boundary_rate_limit`. Hooks bridge at `wp_mcp_ai_before_tool_execution` priority 1.
- **Approval gate events:** `wp_mcp_ai_agent_approval_auto_approve_risk`, `wp_mcp_ai_agent_approval_required`, `wp_mcp_ai_agent_approval_critical_override`, `wp_mcp_ai_approval_decided` (feeds Agent Command Center).
- **Sandbox events:** `wp_mcp_ai_sandbox_allowed_languages`, `wp_mcp_ai_sandbox_max_timeout`, `wp_mcp_ai_sandbox_execution_env`.
- **Harness Evolver events:** `wp_mcp_ai_harness_evolution_enabled`, `wp_mcp_ai_harness_evolution_frequency`, `wp_mcp_ai_harness_evolution_warmup`, `wp_mcp_ai_harness_evolution_max_window` (filters — configure evolution behaviour), `wp_mcp_ai_harness_evolution_completed`, `wp_mcp_ai_harness_evolution_failed` (actions — fire after evolution passes), `wp_mcp_ai_agent_roles` (filter at priority 20 — registers evolved roles).
- **Events listened to:** `init` (CPT + cron), `wp_mcp_ai_audit_trail_prune`, `wp_mcp_ai_before_tool_execution` (CoSAI gates at priority 1), `wp_mcp_ai_agent_roles` (Harness Evolver at priority 20).

## Audit Trail (CoSAI Principle 3)

The `WP_MCP_AI_Agent_Audit_Trail` class provides a fully static API for creating immutable audit trails of agent sessions. Events are stored as the `mcp_ai_audit_event` custom post type (with an options-based fallback) and include:

- **Cryptographic chain-of-custody:** Each event carries a SHA-256 hash linking it to the previous event.
- **Immutability enforcement:** `end_session()` closes a trail permanently; closed trails reject new events.
- **Auto-pruning:** A daily cron job removes events older than the configured retention period (default 30 days, filterable via `wp_mcp_ai_audit_trail_retention_days`).
- **OTEL-compatible event schema:** `trail_id` = trace_id, `step_type` + `data` = Event, `assistant_id` / `model` = Resource.
- **External forwarding:** The `wp_mcp_ai_audit_trail_store_event` filter can redirect events to SIEM or OTEL collectors.

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
