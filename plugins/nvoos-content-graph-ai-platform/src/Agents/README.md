<?php
/**
 * Agent system README — documents the extracted agent subsystem.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 */

// This file is intentionally a markdown document.
// See the @package tag for namespace context.
__halt_compiler();

# Agents/

## Purpose

Agent subsystem for NV oOS Content Graph — the admin UI for AI agents (formerly "assistants" in the base plugin) plus the full ported **agent role system**: planner/executor/critic roles, the CoSAI capability boundary + approval gate, the immutable audit trail, the code sandbox, the continual-harness bootstrap/evolver, and the evolved-prompt resolver.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon — requires `nvoos-content-graph` + `nvoos-content-graph-ai` |
| **PHP target** | 8.1+ |
| **License** | Proprietary |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::register()` → `Agents::instance()->register()` |
| **Source** | Admin UI extracted from base plugin `includes/assistants/` + `includes/admin/class-wp-mcp-ai-assistant-*.php`; role system ported from `includes/agents/` (extraction Wave C) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its copy owns runtime wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Agents\Agents` | `Agents.php` | `Plugin::register()` — singleton service; wires admin UI + standalone role-system hooks |
| `NvoosContentGraphAiPlatform\Agents\AgentRoleInterface` | `AgentRoleInterface.php` | `AgentRoleBase`, `AgentHarnessEvolverRoleAdapter` |
| `NvoosContentGraphAiPlatform\Agents\AgentRoleBase` | `AgentRoleBase.php` | `AgentRolePlanner`, `AgentRoleExecutor`, `AgentRoleCritic` |
| `NvoosContentGraphAiPlatform\Agents\AgentRolePlanner` | `AgentRolePlanner.php` | consumers of the role registry |
| `NvoosContentGraphAiPlatform\Agents\AgentRoleExecutor` | `AgentRoleExecutor.php` | consumers of the role registry |
| `NvoosContentGraphAiPlatform\Agents\AgentRoleCritic` | `AgentRoleCritic.php` | consumers of the role registry |
| `NvoosContentGraphAiPlatform\Agents\AgentApprovalGate` | `AgentApprovalGate.php` | `AgentCapabilityBoundaryHooks` |
| `NvoosContentGraphAiPlatform\Agents\AgentAuditTrail` | `AgentAuditTrail.php` | `AgentHarnessEvolver`, `Agents::register()` (standalone) |
| `NvoosContentGraphAiPlatform\Agents\AgentCapabilityBoundary` | `AgentCapabilityBoundary.php` | `AgentCapabilityBoundaryHooks`, chat loops |
| `NvoosContentGraphAiPlatform\Agents\AgentCapabilityBoundaryHooks` | `AgentCapabilityBoundary.php` | `Agents::register()` (standalone) |
| `NvoosContentGraphAiPlatform\Agents\AgentCodeSandbox` | `AgentCodeSandbox.php` | code-execution tooling |
| `NvoosContentGraphAiPlatform\Agents\AgentHarnessBootstrap` | `AgentHarnessBootstrap.php` | harness tooling (save/load cross-session bundles) |
| `NvoosContentGraphAiPlatform\Agents\AgentHarnessEvolver` | `AgentHarnessEvolver.php` | harness tooling (mid-session evolution) |
| `NvoosContentGraphAiPlatform\Agents\AgentHarnessEvolverRoleAdapter` | `AgentHarnessEvolver.php` | evolved-role registry |
| `NvoosContentGraphAiPlatform\Agents\EvolvedPromptResolver` | `EvolvedPromptResolver.php` | `Agents::register()` (standalone) |
| `NvoosContentGraphAiPlatform\Agents\Admin\AgentsAdmin` | `Admin/AgentsAdmin.php` | `Agents::registerAdmin()` |
| `NvoosContentGraphAiPlatform\Agents\Agents::POST_TYPE` | `Agents.php` | Post type constant (`mcp_ai_assistant`) |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_assistant` posts and meta (`_wp_mcp_ai_system_prompt`, `_wp_mcp_ai_agent_role`, `_wp_mcp_ai_enabled_tools`, `_wp_mcp_ai_tool_weights`, `_wp_mcp_ai_harness_profile`, `_wp_mcp_ai_harness_generation_count`, `_wp_mcp_ai_evolved_system_prompt`), options (`wp_mcp_ai_audit_*`, `wp_mcp_ai_harness_bootstrap_*`, `wp_mcp_ai_pre_approved_patterns`), transients (`wp_mcp_ai_approval_*`, `wp_mcp_ai_boundary_*`), audit events (`mcp_ai_audit_event` CPT or options fallback)
- **Writes to:** the same option/transient/meta keys, `mcp_ai_audit_event` posts, transient-scoped sandbox temp dirs
- **Upstream callers:** `Plugin::register()` → `Agents::register()`, harness/CoSAI tooling, chat loops
- **Downstream collaborators:** `AgentCapabilityBoundaryHooks` ↔ `AgentCapabilityBoundary` ↔ `AgentApprovalGate`; `AgentHarnessEvolver` → `AgentAuditTrail`; `AgentHarnessBootstrap` ↔ role classes
- **Events emitted:** `wp_mcp_ai_agent_approval_required`, `wp_mcp_ai_approval_decided`, `wp_mcp_ai_audit_trail_event_stored`, `wp_mcp_ai_harness_bootstrap_saved|loaded|deleted` (base hook names preserved)
- **Events listened to:** `wp_mcp_ai_before_tool_execution` (capability boundary + approval gate, priority 1), `wp_mcp_ai_resolved_system_prompt` (evolved-prompt resolver, priority 15), `init` (audit CPT + pruning cron), `wp_mcp_ai_audit_trail_prune`

## Extraction Status

| Source file (base plugin) | Target | Status |
|---|---|---|
| `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` | `MetaKeys.php` + `CptBridge.php` | ✅ Bridged |
| `includes/admin/class-wp-mcp-ai-add-assistant-page.php` | `Admin/AddAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-build-assistant-page.php` | `Admin/BuildAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-admin-test-assistant.php` | `Admin/TestAgentPage.php` | ✅ Extracted |
| `includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php` | `Admin/CreateAgentButton.php` | ✅ Extracted |
| `includes/assistants/metaboxes/` | `Metaboxes/` | ⚠️ Bridged (delegates to base plugin) |
| `includes/interfaces/interface-wp-mcp-ai-agent-role.php` | `AgentRoleInterface.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-role-base.php` | `AgentRoleBase.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-role-{planner,executor,critic}.php` | `AgentRole{Planner,Executor,Critic}.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-approval-gate.php` | `AgentApprovalGate.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-audit-trail.php` | `AgentAuditTrail.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-capability-boundary.php` | `AgentCapabilityBoundary.php` (+ `AgentCapabilityBoundaryHooks`) | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-code-sandbox.php` | `AgentCodeSandbox.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-harness-bootstrap.php` | `AgentHarnessBootstrap.php` | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | `AgentHarnessEvolver.php` (+ `AgentHarnessEvolverRoleAdapter`) | ✅ Ported (Wave C) |
| `includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php` | `EvolvedPromptResolver.php` | ✅ Ported (Wave C) |

## Dependencies

- **Core**: `NvoosContentGraph\ToolRegistry` (tool availability for agents)
- **AI addon**: Provider client registry (model/provider selection)
- **Professions**: `mcp_ai_profession` CPT (agent templates)
- **Base plugin (optional)**: `WP_MCP_AI_Logger`, `WP_MCP_AI_Tool_Registry`, provider clients, harness artifact classes — every reference is `defined( 'WP_MCP_AI_PATH' ) &&`-gated and degrades in standalone mode

## Conventions

- Agent post type constant: `Agents::POST_TYPE` (references `mcp_ai_assistant`)
- All meta keys accessed via constants (not bare strings)
- Admin pages register via `SettingsRegistry` + standalone `admin_menu` hooks
- Ported 1:1 from `includes/agents/` (extraction Wave C). Option keys, transient prefixes, CPT slug (`mcp_ai_audit_event`), meta keys, hook names (`wp_mcp_ai_*`), and error codes are unchanged — data stability is sacred (extraction plan §3).
- Standalone mode: `Agents::register()` mirrors the base `agents-init.php` wiring (`AgentAuditTrail::init()`, `AgentCapabilityBoundaryHooks::register()`, `EvolvedPromptResolver::register()`). Monolith mode: the base plugin's own copy owns runtime wiring — never register twice.
- The discriminator is always `defined( 'WP_MCP_AI_PATH' )` — never bare `class_exists( 'WP_MCP_AI_X' )` as an absence test, because the monorepo root Composer autoloader classmaps base classes to disk even when the base plugin is inactive.
- Standalone degradations: executor task execution fails fast with `wp_mcp_ai_no_tool_registry` (no tool registry); harness bootstrap resolves roles from the ported role classes; harness evolver's artifact/provider/registry integrations skip; role logging falls back to `error_log`.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
# Monolith matrix (base plugin loaded)
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Agents

# Standalone matrix (base plugin skipped)
WP_MCP_AI_PLATFORM_STANDALONE=1 vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Agents
```

Covered: data-stability constants, role contracts (planner/executor/critic), task decomposition, validation error codes, standalone executor degradation, capability boundary contract, harness bootstrap save/load roundtrip, audit trail session lifecycle, approval gate risk tiers, evolved-prompt resolver opt-in, and CPT registration per matrix.

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Base sources: `includes/agents/`, `includes/interfaces/interface-wp-mcp-ai-agent-role.php`
