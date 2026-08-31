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
| Skills | `includes/class-wp-mcp-ai-skill-registry.php`, `-skill-parser.php`, `-skill-pack-registry.php` | `src/Skills/` | 🟡 Bridged |
| Slash Commands | `includes/slash-commands/` (7 classes + commands/) | `src/SlashCommands/` | 🟡 Bridged |
| Harness | `includes/harness/` (16 classes) | `src/Harness/` | 🟡 Bridged |
| Measurement | `includes/measurement/` (30+ files) | `src/Measurement/` | 🟡 Bridged |
| Professions + Teams + KB | `includes/professions/`, `includes/teams/`, `includes/knowledge-base/` | `src/Professions/`, `src/Teams/`, `src/KnowledgeBase/` | 🟡 Bridged |
| ACP | `includes/acp/` (4 classes + transport/) | `src/ACP/` (6 classes ported) | 🟢 Extracted (Wave A) |
| Federation | `includes/class-wp-mcp-ai-federation*.php` + mesh classes | `src/Federation/` | 🟡 Bridged — well-known, peer CPT, directory REST, bootstrap, verifier, rate limiter extracted; mesh networking + settings admin UI remain |
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

### Federation 🟡 Bridged — server surface + directory extracted

- Ported classes in `src/Federation/`: `Federation` (bootstrap — well-known, peer CPT, directory REST, cron, A2A well-known, activation hooks on the platform plugin file), `Settings` (reads the same `wp_mcp_ai_settings` option; admin UI stays in the base plugin), `WellKnown` (registry-agnostic — accepts base or Content Graph core registries, null-safe), `PeerCpt` (same `ai_peer` post type + byte-identical meta keys; JetEngine CCT sync no-ops in standalone mode), `DirectoryRest` (`ai-dir/v1` — register/list/get/search/reverify/report with rate limiting), `PeerVerifier`, `RateLimiter` (fleet-management capability check falls back to `manage_options` in standalone mode).
- `FederationService` boots `Federation` in standalone mode only — monolith mode keeps the base plugin's own federation wiring (no double registration).
- **Remaining in base plugin (follow-up PRs):** mesh networking (`mesh-router` 1,379 lines, `mesh-peer-sync`, `mesh-peer-tester`, `mesh-peer-test-rest`) and `WP_MCP_AI_Federation_Settings` admin settings UI. Mesh stays unavailable in standalone mode until ported — `RuntimeMode` keeps reporting the Federation subsystem as bridged for that reason.

## Runtime degradation guard

`src/RuntimeMode.php` replaces the old silent `class_exists()`-skip failure mode:

- Monolith matrix: all bridged probes resolve; only `Blueprints` is reported (greenfield).
- Standalone matrix: every non-extracted subsystem + `Blueprints` is reported via a dismissible `manage_options` admin notice.

## Next extraction waves

1. **Wave B** — Skills, Slash Commands (plan Phase 2)
2. **Wave C** — Harness, Measurement, Agents role system (plan Phase 3)
3. **Greenfields** — Federation port to `src/Federation/`, Blueprints build (plan Phase 4)
4. **Cutover** — delete base copies, meta-plugin mode, v2.0.0 (plan Phase 5)
