# Dashboards

## Purpose

Wave E-UI-1 port surface. Holds the four base operator dashboards as
they land — multi-agent, orchestration, slash-commands, run timeline —
each an aligned port of the matching `WP_MCP_AI_Admin_*_Dashboard`
class in the base plugin's `includes/admin/`. Sub-cluster 1
(`MultiAgentDashboard`) is the aligned port of
`WP_MCP_AI_Admin_Multi_Agent_Dashboard`: byte-identical page slug
(`mcp-ai-multi-agent`), nonce action (`wp_mcp_ai_multi_agent`), AJAX
action names (`wp_ajax_wp_mcp_ai_get_multi_agent_stats`,
`wp_ajax_wp_mcp_ai_reinstall_agents`), menu/enqueue/render
registration, the `manage_options` render gate, the nonce+capability
AJAX gates, the statistics shape, the workflow-pattern classification
and the default-assistant reinstall flow. Sub-cluster 2
(`OrchestrationDashboard`) is the aligned port of
`WP_MCP_AI_Admin_Orchestration_Dashboard`: byte-identical page slug
(`mcp-ai-orchestration`), nonce action (`wp_mcp_ai_orchestration`),
the six AJAX actions (seeder/stats/recent-workflows/execute/restart/
refresh-memory), the orchestration statistics shape, the status
banner thresholds, the statistics cards, the system-status monitor
(cron/async/health/SSE), the role distribution chart, the quick
actions, the documentation links, the recent-workflows transient
list, the agent memory stats widget (type/wing/importance
breakdowns, wings/rooms, mined count, persistent CCT vs cache split,
retrieval-path chart, RAG features, context health metrics) and the
workflow execute/restart AJAX flows. Sub-cluster 3
(`SlashCommandsDashboard`) is the aligned port of
`WP_MCP_AI_Admin_Slash_Commands_Dashboard`: byte-identical page slug
(`mcp-ai-slash-commands`) with the `edit_posts` (Contributor+) menu
capability, the five AJAX actions (execute-command / get-history /
get-entry / clear-history / execute-workflow), the four in-page tabs
(commands/workflows/history/test with the `$_GET['tab']` whitelist),
the command statistics + global/toolkit grouping + help display, the
workflows table + details + execution boxes, the history table, the
command tester, the handler-driven command list, the
orchestrator-driven workflow list (built-in + uploads YAML), the
execution history option (100-entry cap, 500-char truncation), and
the execute/history AJAX flows. Its handler/orchestrator resolve
through the `wp_mcp_ai_get_slash_command_handler()` /
`wp_mcp_ai_get_workflow_orchestrator()` globals (base monolith /
platform shim standalone — no seam needed); the toolkit grouping uses
the `toolkit_manager()`/`toolkit_name()` seams; the base's `private`
helpers become `protected` for test exposure (documented).

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerDashboards()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | None (assistant CPT meta + `wp_mcp_ai_*` options) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Admin\Dashboards\MultiAgentDashboard` | `MultiAgentDashboard.php` | `Plugin::registerDashboards()` — standalone menu/enqueue/AJAX wiring |
| `NvoosContentGraphAiPlatform\Admin\Dashboards\OrchestrationDashboard` | `OrchestrationDashboard.php` | `Plugin::registerDashboards()` — standalone menu/enqueue/AJAX wiring |
| `NvoosContentGraphAiPlatform\Admin\Dashboards\SlashCommandsDashboard` | `SlashCommandsDashboard.php` | `Plugin::registerDashboards()` — standalone menu/enqueue/AJAX wiring |

## Inputs / Outputs / Neighbors

- **Reads from:** the default-assistant installation seam, assistant
  CPT meta (`_wp_mcp_ai_provider`/`_wp_mcp_ai_model`/
  `_wp_mcp_ai_temperature`/`_wp_mcp_ai_tools`/
  `_wp_mcp_ai_primary_roles`), the JetEngine availability probe
- **Writes to:** AJAX JSON envelopes (stats, reinstall), the rendered
  dashboard HTML
- **Upstream callers:** `Plugin::registerDashboards()` (standalone
  menu mounting under `PlatformDashboard::PAGE_SLUG`), admin
  `wp_ajax_*` requests
- **Downstream consumers:** the base admin loader owns the same page
  monolith (the ported class stays unwired there)

## Conventions

- **Operational dashboards are single-page section-composed surfaces
  with AJAX auto-refresh.** The base's tab/sub-tab/view routing lives
  in the settings dashboard (`?tab=` + `subtab_<section_id>`) and Pro
  orchestration settings — these dashboards do NOT replicate
  settings-level routing; they mount as submenu pages under the
  platform's NV Platform menu. The orchestration dashboard's own
  section view-routing lands with its sub-cluster.
- Per-mode discriminator is always `defined( 'WP_MCP_AI_PATH' )` —
  never bare `class_exists()`. Collaborators resolve through
  `protected static` seams (default-assistant seeder, meta-key map,
  JetEngine probe) so monolith installs resolve the base classes
  unchanged.
- Own assets live in the platform `assets/` tree (byte-identical
  copies of the base files); chat-bundle assets are monolith-only.

## Tests

- `tests/test-multi-agent-dashboard.php` — characterization suite
  covering the byte-identical slug/nonce/action names, per-mode menu
  registration, register idempotence, the per-mode collaborator
  seams, statistics shape, workflow-pattern classification, render
  output (incl. the monolith-only test-chat modal), the AJAX
  nonce/capability gates, and per-mode asset enqueues. Runs in both
  matrices; uses an exposer fixture + an AJAX capture harness
  (`wp_send_json` echoes then dies; `check_ajax_referer` failures die
  through the throwing test handler).
- `tests/test-orchestration-dashboard.php` — characterization suite
  covering the byte-identical slug/nonce/action names, per-mode menu
  registration, register idempotence, the per-mode collaborator
  seams (profession meta keys, seeder, tool inventory, context
  manager, SSE probe, settings cross-links), the orchestration
  statistics shape (real profession posts), the status banner
  thresholds, the system status shape, the recent-workflows
  transient list (with namespace isolation), the agent memory stats
  aggregation (type/wing/importance/rooms/mined/persistent/
  retrieval-path), the render output (incl. the per-mode settings
  links), the AJAX nonce/capability gates, the workflow restart
  flow, and the per-mode asset enqueues. Runs in both matrices.
- `tests/test-slash-commands-dashboard.php` — characterization suite
  covering the byte-identical constants/slug/nonce/action names,
  per-mode menu registration (edit_posts capability), register
  idempotence, the toolkit manager/name seams + grouping, the
  handler-driven command list shape, the orchestrator-driven
  workflow list shape, the execution history sort/limit/entry/
  truncation/cap, the four-tab routing with the `$_GET['tab']`
  whitelist, the render capability gate, the AJAX nonce/capability/
  empty-input gates, the execute-command success + error envelopes,
  the history get/entry/clear flows, the execute-workflow gates, and
  the per-mode asset enqueues. Standalone loads the slash-command
  global-function shims in `setUp` (the production boot path fires
  before the test bootstrap requires the ecosystem files). Runs in
  both matrices.

## Also Load

- [`../PlatformDashboard.php`](../PlatformDashboard.php) — the NV Platform menu (`PAGE_SLUG`) these dashboards mount under
- [`../../Plugin.php`](../Plugin.php) — `registerDashboards()` wiring
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- Base originals: `includes/admin/class-wp-mcp-ai-admin-multi-agent-dashboard.php` (sub-cluster 1), `class-wp-mcp-ai-admin-orchestration-dashboard.php` (sub-cluster 2), `class-wp-mcp-ai-admin-slash-commands-dashboard.php` (sub-cluster 3), `class-wp-mcp-ai-admin-run-timeline-dashboard.php`
- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E-UI-1 row status
