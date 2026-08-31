# NV oOS Content Graph Platform — Migration Tracker

**Last updated:** 2026-08-31
**Plan:** [`docs/project/plans/content-graph-platform-extraction-plan.md`](../../../docs/project/plans/content-graph-platform-extraction-plan.md)
**Replaces:** the 2026-08-03 gap analysis, which was stale: Federation business logic now exists in the base plugin (`includes/class-wp-mcp-ai-federation*.php` + mesh classes), `includes/agents/`, `includes/teams/`, and `includes/knowledge-base/` were built after it was written.

---

## How to read this tracker

| Status | Meaning |
|---|---|
| 🟡 Bridged | Admin UI + bridge here; business logic still in base plugin `includes/` |
| 🟢 Extracted | Business logic ported to this addon's `src/`; base copy is legacy |
| 🔴 Gap | Planned but no implementation anywhere |
| ⏸️ Deferred | Decision: stays in base plugin (see plan §10 open decisions) |

## Subsystem matrix

| Subsystem | Base source | Platform target | Status |
|---|---|---|---|
| A2A | `includes/a2a/` (7 classes) | `src/A2A/` (7 classes ported) | 🟢 Extracted (Wave A) |
| Agents (role system) | `includes/agents/` (12 classes) | `src/Agents/` | 🟡 Bridged |
| Assistant CPT | `includes/assistants/` + `includes/class-assistant-cpt.php` | (stays in base — plan §10 decision 1) | ⏸️ Deferred |
| Skills | `includes/class-wp-mcp-ai-skill-registry.php`, `-skill-parser.php`, `-skill-pack-registry.php` | `src/Skills/` (registry, parser, pack registry; SkillBridge resolves ported-first) | 🟢 Extracted (Wave B) |
| Slash Commands | `includes/slash-commands/` (7 classes + commands/) | `src/SlashCommands/` + `src/SlashCommands/Commands/` | 🟡 Bridged — engine + commands + shims ported (S1); toolkit manager, workflow orchestrator, performance optimizer pending (S2) |
| Harness | `includes/harness/` (16 classes) | `src/Harness/` | 🟡 Bridged |
| Measurement | `includes/measurement/` (30+ files) | `src/Measurement/` | 🟡 Bridged |
| Professions + Knowledge-base | `includes/professions/`, `includes/knowledge-base/` | `src/Professions/` + `src/Professions/Metaboxes/` | 🟢 Extracted (Wave A, P1–P3) |
| Teams | `includes/teams/` + team repository/loader | `src/Teams/` (CPT, repository, KB loader, seeder, service) | 🟢 Extracted (Wave A) |
| ACP | `includes/acp/` (4 classes + transport/) | `src/ACP/` (6 classes ported) | 🟢 Extracted (Wave A) |
| Federation | `includes/class-wp-mcp-ai-federation*.php` + mesh classes | `src/Federation/` + `src/Mesh/` | 🟢 Extracted (Wave A) — settings admin UI stays in base by design (FederationAdmin covers the platform dashboard) |
| Blueprints | — (Pro has tool-import/unified pages only) | `src/Blueprints/` | 🔴 Greenfield |

## Wave A extraction notes (2026-08-31)

### A2A 🟢 Extracted

- Ported classes live in `src/A2A/` under `NvoosContentGraphAiPlatform\A2A`: `AgentCard`, `TaskManager`, `Client`, `MessageTranslator`, `PushNotifications`, `WebhookHandler`, `WellKnown`.
- Behaviour is 1:1 — same option keys (`wp_mcp_ai_a2a_tasks`, `wp_mcp_ai_a2a_push_configs`), same hooks (`wp_mcp_ai_a2a_*`), same A2A protocol version.
- `AgentCard::resolve_tool_registry()` prefers `WP_MCP_AI_Tool_Registry` (monolith) and falls back to the Content Graph core registry (standalone).
- `A2AService` wires `WellKnown` only when the base plugin has not booted (`! defined('WP_MCP_AI_PATH')`) — no double hook registration in monolith mode.
- **Deferred (standalone parity gap):** the A2A REST receive routes (`includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`) remain in the base plugin. Standalone mode serves the well-known agent card but cannot yet receive A2A messages. Tracked for a follow-up PR — port or re-implement the controller against platform auth.

### ACP 🟢 Extracted

- Ported classes live in `src/ACP/` under `NvoosContentGraphAiPlatform\ACP`: `Server`, `JsonRpcDispatcher`, `SessionManager`, `SessionBridge`, `TransportHttp`, plus a minimal local `AbstractRestController` (the base plugin's `WP_MCP_AI_REST_Controller_Base` is intentionally not ported — it depends on the monolith container/auth/security stack).
- `ACPService` mounts the transport on `rest_api_init` only in standalone mode when `ai_platform_settings.acp_enabled` is set. Monolith mode: base REST layer owns ACP routing.
- **Intentional deviation:** the base copy's transport permission callback is `return true` (TODO). The platform port enforces `is_user_logged_in() && current_user_can( 'edit_posts' )` — it only mounts in standalone mode where the base auth stack is absent.
- Transient prefixes (`acp_sess_*`, `acp_updates_*`), user meta (`_acp_sessions`), JSON-RPC wire format, and route paths (`mcp-ai/v1/acp`, `/acp/sse`) are unchanged.

### Transition mechanism

Base plugin `includes/` copies remain for the transition; deletion happens at cutover (plan Phase 5). No `class_alias` shims during the transition — platform owns wiring only in standalone mode, which avoids double registration entirely.

### Teams 🟢 Extracted

- Ported classes live in `src/Teams/` under `NvoosContentGraphAiPlatform\Teams`: `TeamCpt` (same `mcp_ai_team` post type + byte-identical `_wp_mcp_ai_team_*` meta keys), `TeamRepository`, `TeamKnowledgeBaseLoader`, `TeamSeeder`, plus `TeamsService` (composition root).
- Knowledge base bundled at `data/knowledge-base/teams/` (26 JSON files, mirror of `includes/knowledge-base/teams/`); the loader prefers the base plugin's directory in monolith mode.
- `TeamsService` wires the ported CPT + seeder on `init` (priority 5, mirroring the base `teams-init.php`) in standalone mode only — monolith mode keeps the base plugin's own wiring (no double registration). Shared option key `wp_mcp_ai_teams_seeded` keeps seeding idempotent across modes.
- `src/Schema/Sanitize.php` mirrors `wp_mcp_ai_sanitize_recursive()` byte-for-byte (delegates to the base function when present) — used by `TeamCpt::save_post()` for workflow-template JSON; also needed by the pending Professions port.
- **Graceful degradation (standalone):** the defaults metabox's provider/model selects probe `WP_MCP_AI_Admin_Settings` / `WP_MCP_AI_Model_Service` via `class_exists()` and render empty when absent (no model service in standalone mode).

### Professions core (P1) — in progress

- Ported classes in `src/Professions/`: `ProfessionCpt` (same `mcp_ai_profession` post type + byte-identical `_wp_mcp_ai_profession_*` meta keys), `ProfessionRepository`, `ProfessionKnowledgeBaseLoader`, `ProfessionPlaybookLoader`, `ProfessionToolRecommender`, `DatasetMappings` (function pair → static class), and `ProfessionService` (composition root + ported domain logic of `WP_MCP_AI_Profession_Service`).
- Knowledge base `professions/` (18 JSON files) bundled at `data/knowledge-base/professions/`; loaders prefer the base plugin's directory in monolith mode.
- `ProfessionService::register()` wires the ported CPT + cache-invalidation hooks in standalone mode only; monolith mode keeps the base `professions-init.php` wiring. `src/Professions/shim-functions.php` (standalone-only) restores the global function surface: `wp_mcp_ai_get_profession_service`, `wp_mcp_ai_get_profession_dataset_recommendations`, `wp_mcp_ai_get_all_profession_dataset_mappings`.
- Tool registry resolution follows the `defined( 'WP_MCP_AI_PATH' )` boot discriminator — the monorepo root autoloader classmaps base registry files to disk even when the base plugin is inactive, and those files reference `WP_MCP_AI_PATH` (fatal on bare `class_exists` probes; fixed in `ProfessionPlaybookLoader::resolve_tool_registry()` and `ProfessionCpt`'s tools render).
- **Custom metaboxes degrade in standalone mode** until P3 ports the 8 metabox classes (`ProfessionCpt::init_metaboxes()` probes the ported names). `ProfessionOrchestrationSeeder` + `ProfessionOrchestrationCli` follow in P3; Professions stays 🟡 Bridged in RuntimeMode until then.
- **P2:** the three init-wired seeders are ported — `ProfessionSeeder` (311 bundled professions seeded), `ProfessionBaseKnowledgeSeeder` (191 bundled documents), `ProfessionPlaybookSeeder` (277 bundled playbook files) — with byte-identical seeding option keys and the same `admin_init` priorities. Seeder wiring added to `ProfessionService::register()` (standalone-only). Data bundled at `data/knowledge-base/profession-documents/` + `data/knowledge-base/profession-playbooks/`. Note: the base copies' `throw new \RuntimeException( sprintf( ... ) )` sites are flagged by WPCS `EscapeOutput.ExceptionNotEscaped`; the port restructured those messages to concatenation with `ExceptionNotEscaped` ignores (the base file itself has the same latent violations — 6 errors under the root ruleset).
- **P3 (Professions complete):** `ProfessionOrchestrationSeeder` (on-demand; byte-identical `wp_mcp_ai_profession_orchestration_version` option), `ProfessionOrchestrationCli` (WP-CLI commands registered by `ProfessionService` in standalone mode), and all 8 metabox classes in `src/Professions/Metaboxes/` (`ProfessionMetaboxBase` + 7 concrete). `ProfessionCpt::init_metaboxes()` now wires the ported metaboxes in standalone mode. Standalone degradations: expertise picker assets (`WP_MCP_AI_URL`) skip enqueue; datasets integration reads base settings only when present; provider/model selects render empty without the base model service. `RuntimeMode` no longer lists Professions as bridged.
- **Hardening TODO (pre-existing):** `A2A\AgentCard::resolve_tool_registry()` uses a bare `class_exists( 'WP_MCP_AI_Tool_Registry' )` probe with the same latent standalone fatal — follow-up one-line fix.

### Federation 🟢 Extracted (server surface, directory, mesh)

- Ported classes in `src/Federation/`: `Federation` (bootstrap — well-known, peer CPT, directory REST, mesh sync, cron, A2A well-known, activation hooks on the platform plugin file), `Settings` (reads the same `wp_mcp_ai_settings` option), `WellKnown` (registry-agnostic — accepts base or Content Graph core registries, null-safe), `PeerCpt` (same `ai_peer` post type + byte-identical meta keys; JetEngine CCT sync no-ops in standalone mode), `DirectoryRest` (`ai-dir/v1` — register/list/get/search/reverify/report with rate limiting), `PeerVerifier`, `RateLimiter` (fleet-management capability check falls back to `manage_options` in standalone mode).
- Ported classes in `src/Mesh/`: `MeshRouter` (AI-optimized peer selection, circuit breakers, retry — logs via the base logger when present, error_log fallback), `MeshPeerSync` (settings → `ai_peer` CPT sync), `MeshPeerTester`, `MeshPeerTestRest` (`mcp-ai/v1/mesh/test-peer`).
- `FederationService` boots `Federation` in standalone mode only — monolith mode keeps the base plugin's own federation wiring (no double registration).
- **Remains in base by design:** `WP_MCP_AI_Federation_Settings` admin settings UI (registers into the base settings page); the Platform addon's `FederationAdmin` provides its own dashboard surface.

## Runtime degradation guard

`src/RuntimeMode.php` replaces the old silent `class_exists()`-skip failure mode:

- Monolith matrix: all bridged probes resolve; only `Blueprints` is reported (greenfield).
- Standalone matrix: every non-extracted subsystem + `Blueprints` is reported via a dismissible `manage_options` admin notice.

## Next extraction waves

1. **Wave B remainder** — Slash Commands S2 (toolkit manager, workflow orchestrator, performance optimizer)
2. **Wave C** — Harness, Measurement, Agents role system (plan Phase 3)
3. **Greenfields** — Blueprints build (plan Phase 4)
4. **Cutover** — delete base copies, meta-plugin mode, v2.0.0 (plan Phase 5)

## Wave B extraction notes (2026-08-31)

### Skills 🟢 Extracted

- Ported classes in `src/Skills/`: `SkillRegistry` (same `wp_mcp_ai_skill_index` option, uploads-dir storage, preserved `wp_mcp_ai_skill_registry_include_evolved` filter), `SkillParser`, `SkillPackRegistry` (preserved `wp_mcp_ai_skill_packs` filter + `wp_mcp_ai_skill_pack_installed` action).
- Bundled skills (1.2 MB) bundled at `data/bundled-skills/`; `SkillRegistry` prefers the base plugin's `includes/bundled-skills` in monolith mode and the bundled copy in standalone mode.
- `SkillBridge` now resolves the ported registries first (same storage, so monolith behaviour is unchanged), falling back to the base copies for packaging BC.
- `SkillService::register()` owns the deferred bundled-skills install (transient `wp_mcp_ai_install_bundled_skills`, init priority 100) in standalone mode; the platform activation hook sets the transient standalone-only.
- The evolved-skills probe (`WP_MCP_AI_Agent_Harness_Evolver`) is gated behind `defined('WP_MCP_AI_PATH')` — the Harness port (Wave C) will add the ported-class probe.
- Remains in base by design: `tool-load-skill`, admin AJAX handlers, and the assistant CPT's skill metaboxes (assistant CPT stays in base per plan §10 decision 1).

### Slash Commands (S1) — in progress

- Ported engine classes in `src/SlashCommands/`: `SlashCommandParser`, `SlashCommandValidator`, `SlashCommandHandler`, `SlashCommandAudit`, plus all 20 command classes in `src/SlashCommands/Commands/`.
- `src/SlashCommands/shim-functions.php` is a faithful port of `slash-commands-init.php`: the full `wp_mcp_ai_*` global function surface (`wp_mcp_ai_init_slash_commands`, `wp_mcp_ai_execute_slash_command`, `wp_mcp_ai_register_slash_command`, `wp_mcp_ai_slash_command_exists`, `wp_mcp_ai_get_slash_commands`, `wp_mcp_ai_register_slash_command_scripts`, `wp_mcp_ai_get_workflow_orchestrator`, `wp_mcp_ai_get_performance_optimizer`, `wp_mcp_ai_execute_workflow`, audit-table helpers) plus the `wp_mcp_ai_slash_command_handler` global and the init wiring (priority 20). Loaded standalone-only by `SlashCommandService::register()`.
- All base-class probes in the port are gated behind `defined('WP_MCP_AI_PATH')` (the monorepo-autoloader trap). `wp_mcp_ai_register_slash_command_scripts()` degrades in standalone (base JS assets + REST classes absent).
- **Port deviations (behaviour-identical):** `SlashCommandContext`'s known-limits map carried duplicate array keys in the base ('gpt-4.1' ×2, 'gemini-2.5-flash' ×3); deduped to the last-wins effective values.
- **S2 pending:** `SlashCommandToolkitManager` (10.4K lines), `SlashCommandWorkflowOrchestrator`, `SlashCommandPerformanceOptimizer` — `wp_mcp_ai_load_toolkit_slash_commands()` degrades via `class_exists` until they land. Slash Commands stays 🟡 Bridged in RuntimeMode until then.
