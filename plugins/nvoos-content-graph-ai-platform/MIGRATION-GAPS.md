# NV oOS Content Graph Platform — Migration Tracker

**Last updated:** 2026-09-05
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
| Agents (role system) | `includes/agents/` (12 classes) | `src/Agents/` | 🟢 Extracted (Wave C) |
| Assistant CPT | `includes/assistants/` + `includes/class-assistant-cpt.php` | (stays in base — plan §10 decision 1) | ⏸️ Deferred |
| Skills | `includes/class-wp-mcp-ai-skill-registry.php`, `-skill-parser.php`, `-skill-pack-registry.php` | `src/Skills/` (registry, parser, pack registry; SkillBridge resolves ported-first) | 🟢 Extracted (Wave B) |
| Slash Commands | `includes/slash-commands/` (7 classes + commands/) | `src/SlashCommands/` + `src/SlashCommands/Commands/` | 🟢 Extracted (Wave B, S1–S2) |
| Harness | `includes/harness/` (30 classes) | `src/Harness/` | 🟢 Extracted (Wave C) |
| Measurement | `includes/measurement/` (30+ files) | `src/Measurement/` | 🟢 Extracted (Wave C) |
| Professions + Knowledge-base | `includes/professions/`, `includes/knowledge-base/` | `src/Professions/` + `src/Professions/Metaboxes/` | 🟢 Extracted (Wave A, P1–P3) |
| Teams | `includes/teams/` + team repository/loader | `src/Teams/` (CPT, repository, KB loader, seeder, service) | 🟢 Extracted (Wave A) |
| ACP | `includes/acp/` (4 classes + transport/) | `src/ACP/` (6 classes ported) | 🟢 Extracted (Wave A) |
| Federation | `includes/class-wp-mcp-ai-federation*.php` + mesh classes | `src/Federation/` + `src/Mesh/` | 🟢 Extracted (Wave A) — settings admin UI stays in base by design (FederationAdmin covers the platform dashboard) |
| Blueprints | — (Pro has tool-import/unified pages only) | `src/Blueprints/` | 🟢 Built (Phase 4 greenfield) |
| Queues (E2) | `includes/class-wp-mcp-ai-async-job-queue.php` | `src/Queues/` (`AsyncJobQueue` ported) | 🟡 In progress (Wave E2) — remaining: JobNotifier + REST, scheduler bridge |

## Wave E2 extraction notes (2026-09-05)

### AsyncJobQueue 🟡 In progress

- Ported as `src/Queues/AsyncJobQueue.php` under `NvoosContentGraphAiPlatform\Queues`: byte-identical table schema (`mcp_ai_job_queue`), priority/status/type constants, cron hook names (`wp_mcp_ai_process_job_queue`, `wp_mcp_ai_cleanup_job_queue`), and error codes (`missing_job_type`, `missing_job_data`, `insert_failed`, `invalid_job_data`).
- Standalone-only via `Plugin::registerQueues()` (`! defined('WP_MCP_AI_PATH')`) — the base owns the same table/hooks in monolith installs.
- Deviations (documented in the class docblock): the `minute` cron interval is registered by the class (the base relies on an external registration, so its polling cron never fires standalone); the Action Scheduler bridge / DLQ / notifier / logger seams are dormant with `method_exists` guards (the base's own calls target methods its DLQ/notifier classes do not expose); a new executor filter (`nvoos_content_graph_ai_platform/async_job_executors`) lets E1 register job-type executors.
- Drive-by fix: `AgentCapabilityBoundaryHooks` extracted from `AgentCapabilityBoundary.php` into its own PSR-4 file — the multi-class file broke standalone autoloading and fatalled `Plugin::register()`.
- 19 characterization tests in `tests/test-async-job-queue.php` green in both matrices.

### Queue managers, DLQ, rate limiter, SLA 🟡 In progress (2026-09-05)

- `QueueManager`, `JobQueueManager`, `DeadLetterQueue`, `RateLimitManager`, and `SlaManager` ported under `src/Queues/` (PRs #6319–#6326 + SlaManager): byte-identical constants/schema/envelopes/error codes with per-install-mode seams, each with characterization tests green in both matrices. `SlaManager` keeps the base option keys (`wp_mcp_ai_settings`, `wp_mcp_ai_sla_compliance_log`) and resolves queue statistics per install mode; `JobQueueManager` resolves its SLA seam per install mode through the new `sla_class()` seam.
- Remaining E2 pieces: JobNotifier + REST, scheduler bridge.

### OutboundWebhook 🟡 In progress (2026-09-05)

- Ported as `src/Queues/OutboundWebhook.php` under `NvoosContentGraphAiPlatform\Queues`: byte-identical option key, subscription lifecycle, signed dispatch, signature verification, and event listeners. Standalone-only listener registration via `Plugin::registerOutboundWebhook()`. 11 characterization tests in `tests/test-outbound-webhook.php` green in both matrices.

### CronManager 🟡 In progress (2026-09-05)

- Ported as `src/Queues/CronManager.php` under `NvoosContentGraphAiPlatform\Queues`: byte-identical `wp_mcp_ai_cron_jobs` option, argument normalisation, record/remove lifecycle, retention pruning, and stable job-ID generation. Standalone-only `init` prune hook via `Plugin::registerCronManager()`; the retention setting resolves per install mode (base `WP_MCP_AI_Settings_Registry` monolith / direct option read standalone). 22 characterization tests in `tests/test-cron-manager.php` green in both matrices.

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

1. **Phase 5 (release-gated)** — delete base copies (plan §10b, decision #4), meta-plugin mode (decision #3 — needs a shared ownership discriminator replacing `defined('WP_MCP_AI_PATH')` checks across services + a third CI matrix), base 2.0.0, docs sweep.

## Phase 5 status (2026-09-01)

- Platform addon version bumped to **2.0.0** — extraction Waves A–C + Phase 4 complete; the addon runs standalone (base absent) with every planned subsystem, or side-by-side with the base plugin (monolith) where the base copies own runtime wiring.
- Base-copy deletion and meta-plugin mode remain release-gated on plan open decisions #3/#4 — see `docs/project/plans/content-graph-platform-extraction-plan.md` §10b.

## Phase 4 — Blueprints greenfield (2026-09-01)

- `BlueprintRegistry` — CRUD over the existing `TemplateCpt` (`ai_platform_template`) — plan open decision 2 resolved: REUSE (no new slug). Meta keys: `_nvoos_platform_blueprint_definition` (JSON), `_nvoos_platform_blueprint_version`, `_nvoos_platform_blueprint_kind`; schema version 1.0.
- `BlueprintValidator` — required envelope keys (`blueprint_version`, `kind`), kind whitelist (agent, workflow, prompt_pack), version guard (newer schema rejected with `blueprint_version_too_new`), kind-specific payload checks, and an irreversible/credential-field guard (`api_key`, `secret`, `token`, … never exported/imported).
- `BlueprintExporter` — agent config array → versioned definition (works on config arrays; the assistant CPT stays in base by plan decision). `BlueprintImporter` — validated definition → normalized agent config.
- `BlueprintRestController` — `nvoos-content-graph/v1/platform/blueprints` CRUD: reads `edit_posts`, writes `manage_options`, schema-validated args, 400/404 error mapping.
- `BlueprintService::register()` wires the REST surface in every runtime mode (greenfield — no base counterpart to double-register).
- Exit-gate tests: full lifecycle (export → validate → store → import → update → delete), validator safety guards, REST surface + permission callbacks (fail-closed).
- `RuntimeMode` now reports NO missing subsystems in either matrix (the notice is effectively dormant until a future subsystem ships).

## Wave C extraction notes (2026-09-01)

### Harness 🟢 Extracted

- All 30 harness classes ported to `src/Harness/` (artifact pipeline, guardrails, citation verifier, evolution governor, eval scheduler, prompt injector, trace capture, necessity gate, PII filter, retrieval harness, self-refine loop, tool router, …) plus a platform copy of `InlineAsyncTickTrait` (class-declaration-level `use`; hook names preserved).
- `HarnessService::register()` wires the ported subscribers (evolution settings bridge, prompt injector, guardrails, necessity gate, output guardrail, citation verifier, eval scheduler, trace capture) in standalone mode only.
- **Port fix (behaviour-preserving):** 25 sibling `class_exists('X')` probes rewritten to `class_exists( __NAMESPACE__ . '\X' )` — PHP does not namespace-resolve string probes, and the unqualified probes would have silently degraded every layer in standalone mode.
- Standalone degradations: eval-suite runs return `wp_mcp_ai_harness_eval_unavailable`; necessity gate passes through without the base tool pipeline; failure-replay falls back to the literal verifier slug; drift detection short-circuits to a non-actionable report.

### Measurement 🟢 Extracted

- All measurement classes ported to `src/Measurement/` (registries, collector, event store, persister, retention, chat-turn metrics/observer, SSE metrics, session-log observer, reward functions, verifiers, budgets).
- `MeasurementService::register()` requires the standalone `shim-functions.php` — a faithful port of `wp_mcp_ai_measurement_bootstrap()` and its companions (plugins_loaded priority 50, admin_init capability seeding, reference verifier/reward registration hooks).
- Standalone degradations: base-only eval suites, tool-execution/SSE/stock-metrics observers, reference verifiers/rewards, and the base admin dashboard are gated off.

### Agents (role system) 🟢 Extracted

- Role-system classes ported to `src/Agents/` (approval gate, audit trail, capability boundary + hooks, code sandbox, harness bootstrap, harness evolver + role adapter, role base/planner/executor/critic, evolved prompt resolver; `AgentRoleInterface` ported from `includes/interfaces/` as a namespaced dependency).
- `Agents::register()` extended with a standalone branch; `AgentHarnessBootstrap` resolves the ported role classes when the base `wp_mcp_ai_get_agent_role()` accessor is absent.
- Standalone degradations: executor tool registry gated (fails fast with `wp_mcp_ai_no_tool_registry`); evolver enhancement paths (artifacts, governor, provider client) skip; provider fallback returns `wp_mcp_ai_evolver_provider_class_missing`.
- Assistant CPT remains in base (⏸️ Deferred, plan §10 decision 1) — the platform admin UI degrades via `CptBridge` in standalone.

## Runtime degradation guard (Wave C update)

`BRIDGED_SUBSYSTEMS` is now EMPTY in both matrices — only the greenfield `Blueprints` subsystem is reported (Phase 4). The assistant-CPT deferral is tracked in the matrix above rather than in the runtime notice.

## Wave B extraction notes (2026-08-31)

### Skills 🟢 Extracted

- Ported classes in `src/Skills/`: `SkillRegistry` (same `wp_mcp_ai_skill_index` option, uploads-dir storage, preserved `wp_mcp_ai_skill_registry_include_evolved` filter), `SkillParser`, `SkillPackRegistry` (preserved `wp_mcp_ai_skill_packs` filter + `wp_mcp_ai_skill_pack_installed` action).
- Bundled skills (1.2 MB) bundled at `data/bundled-skills/`; `SkillRegistry` prefers the base plugin's `includes/bundled-skills` in monolith mode and the bundled copy in standalone mode.
- `SkillBridge` now resolves the ported registries first (same storage, so monolith behaviour is unchanged), falling back to the base copies for packaging BC.
- `SkillService::register()` owns the deferred bundled-skills install (transient `wp_mcp_ai_install_bundled_skills`, init priority 100) in standalone mode; the platform activation hook sets the transient standalone-only.
- The evolved-skills probe (`WP_MCP_AI_Agent_Harness_Evolver`) is gated behind `defined('WP_MCP_AI_PATH')` — the Harness port (Wave C) will add the ported-class probe.
- Remains in base by design: `tool-load-skill`, admin AJAX handlers, and the assistant CPT's skill metaboxes (assistant CPT stays in base per plan §10 decision 1).

### Slash Commands (S1–S2) 🟢 Extracted

- Ported engine classes in `src/SlashCommands/`: `SlashCommandParser`, `SlashCommandValidator`, `SlashCommandHandler`, `SlashCommandAudit`, plus all 20 command classes in `src/SlashCommands/Commands/`.
- `src/SlashCommands/shim-functions.php` is a faithful port of `slash-commands-init.php`: the full `wp_mcp_ai_*` global function surface (`wp_mcp_ai_init_slash_commands`, `wp_mcp_ai_execute_slash_command`, `wp_mcp_ai_register_slash_command`, `wp_mcp_ai_slash_command_exists`, `wp_mcp_ai_get_slash_commands`, `wp_mcp_ai_register_slash_command_scripts`, `wp_mcp_ai_get_workflow_orchestrator`, `wp_mcp_ai_get_performance_optimizer`, `wp_mcp_ai_execute_workflow`, audit-table helpers, `wp_mcp_ai_log`) plus the `wp_mcp_ai_slash_command_handler` global and the init wiring (priority 20). Loaded standalone-only by `SlashCommandService::register()`.
- All base-class probes in the port are gated behind `defined('WP_MCP_AI_PATH')` (the monorepo-autoloader trap). `wp_mcp_ai_register_slash_command_scripts()` degrades in standalone (base JS assets + REST classes absent). The cache adapter is intentionally NOT shimmed — the optimizer's `function_exists` probe degrades cleanly.
- **Port deviations (behaviour-identical):** `SlashCommandContext`'s known-limits map carried duplicate array keys in the base ('gpt-4.1' ×2, 'gemini-2.5-flash' ×3); deduped to the last-wins effective values.
- **S2:** `SlashCommandToolkitManager` (10.4K lines — toolkit + WooCommerce + media commands, ~86 contract-signature `phpcs:ignore`s for unused execute() params), `SlashCommandWorkflowOrchestrator`, `SlashCommandPerformanceOptimizer` ported; `wp_mcp_ai_load_toolkit_slash_commands()` initializes the ported manager standalone. `RuntimeMode` no longer lists Slash Commands as bridged.
