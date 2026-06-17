# Pro Scheduler Toolkit

The Pro Scheduler turns recurring responsibilities into managed Pro Schedules
that can fire a plain reminder hook or invoke an assistant. As of this PR the
toolkit ships with three admin surfaces:

| Page | Slug | Purpose |
|------|------|---------|
| Schedule Manager | `nvoos-pro-schedule-manager` | List, edit, run-now, and toggle individual schedules. |
| Research & Add Schedule | `research-pro-schedule` | Workflow-card UI to plan new schedules with AI, bulk-import them from a paste box, or review existing runs. |
| Schedule Settings | `wp-mcp-ai-pro-schedule-toolkit-settings` | Six-tab toolkit settings page (Overview · Configuration · Tools · Research · Help · MCP Server). |

## Research & Add Schedule — workflow cards

The Research & Add page uses the same `.workflow-option` card pattern as the
Task and Document Template research pages. Three cards toggle the visible
panel without a page reload:

| Card | `data-workflow` | What it does |
|------|-----------------|--------------|
| **AI Research** | `research` | Embeds the chat shortcode with the schedule-planning toolset (`plan_schedules_from_workflow`, `create_pro_schedule`, …). |
| **Bulk Import** | `import` | Paste a free-form list of responsibilities and preview/create each line as a managed Pro Schedule. |
| **Review & Run History** | `review` | Inspect schedules created from this workflow and their last-run state. Each row exposes three per-schedule action buttons: **Dry-run** invokes `dry_run_pro_schedule` over AJAX and renders the next 5 projected runs + warnings inline; **Pause / Resume** toggles `enabled` via `WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule()` and updates the Status cell in place; **Run now** confirms then calls `WP_MCP_AI_Pro_Schedule_Manager::trigger_now()` to dispatch the schedule synchronously. |
| **Calendar** | `calendar` | At-a-glance list of the next 30 upcoming runs across all enabled schedules, rendered in the site time-zone. |

### Legacy `?mode=` compatibility

Old bookmarks like `?page=research-pro-schedule&mode=paste` still land on the
correct card. The page localizes a small `wpMcpAiResearchPage.initialWorkflow`
value (`chat → research`, `paste → import`, `review → review`) that the shared
`assets/js/enhanced-research-page.js` consumes on page load.

### Per-page sessionStorage key

The shared workflow handler now stores the selected card under
`wp_mcp_ai_selected_workflow_<entityType>` (rather than a single global key),
so toggling on the Task research page does not bleed into the Schedule
research page.

## Schedule Settings — six-tab template

The Settings page extends `WP_MCP_AI_Toolkit_Settings_Base` and is registered
under **NV oOS Pro Dashboard → Pro Scheduler**. Tabs:

1. **Overview** — total / active / paused counts, next five upcoming runs,
   quick links to the Manager and Research & Add pages.
2. **Configuration** — site-wide defaults: cadence, time-of-day, max
   concurrent runs, retry policy (count + backoff), error-notification
   email, global kill-switch. Persisted under
   `wp_mcp_ai_pro_schedule_toolkit_settings`.
3. **Tools** — toggleable list of the seven core Pro Schedule tools
   (`create_pro_schedule`, `update_pro_schedule`, `delete_pro_schedule`,
   `list_pro_schedules`, `get_schedule_run_history`,
   `dry_run_pro_schedule`, `plan_schedules_from_workflow`).
4. **Research** — opens the standalone Research & Add Schedule page.
5. **Help** — cadence cheat-sheet, common patterns, troubleshooting.
6. **MCP Server** — inherited; lists tools that are exposed via the
   per-toolkit MCP server (when registered).

## Permissions

All Schedule admin pages and tools default to the `manage_options`
capability. To delegate scheduling to other roles, filter
`wp_mcp_ai_pro_schedule_capability` and return a custom capability slug —
the Schedule Settings page applies this filter when registering its submenu.

## Hooks

| Hook | Type | Fires |
|------|------|-------|
| `wp_mcp_ai_pro_schedule_run_completed` | action | After every schedule run, regardless of success. Receives `( $schedule_id, $result )` where `$result` is `[ 'success', 'duration', 'error', 'action_log', 'schedule' ]`. Use this to forward run summaries to observability layers (OTel, dashboards) or to trigger notifications. **Built-in subscriber:** `WP_MCP_AI_Pro_Schedule_Otel_Subscriber` records `schedule.run.duration_ms` and (on failure) `schedule.run.failure.count` via the base metric collector, then dispatches the OTel exporter. Disable with `add_filter('wp_mcp_ai_pro_schedule_otel_enabled','__return_false')`. |
| `wp_mcp_ai_pro_schedule_capability` | filter | Capability slug required to access the Pro Scheduler settings page. Default `manage_options`. |

## Tools

| Slug | Risk | Purpose |
|------|------|---------|
| `create_pro_schedule` | write | Create a new Pro Schedule. |
| `update_pro_schedule` | write | Update an existing schedule's cadence, args, or metadata. |
| `delete_pro_schedule` | write | Permanently remove a schedule. |
| `list_pro_schedules` | info | List schedules with their next-run and run-count metadata. |
| `get_schedule_run_history` | info | Return the ring-buffer of recent runs for one schedule. |
| `dry_run_pro_schedule` | info | Side-effect-free preview of what a schedule would do at its next N runs. Validates configuration, surfaces warnings (paused, unknown cadence, no upcoming runs), and returns a type-specific action preview without writing to the run-history ring buffer or firing the hook. |
| `plan_schedules_from_workflow` | write | Create one or more schedules from a workflow plan. |

## Metrics

Two metrics are registered by `WP_MCP_AI_Pro_Schedule_Metrics` (priority 30 on
`wp_mcp_ai_register_metrics`) and emitted by
`WP_MCP_AI_Pro_Schedule_Otel_Subscriber` on every
`wp_mcp_ai_pro_schedule_run_completed` event.

| Metric id | Type | Unit | Tagged by |
|-----------|------|------|-----------|
| `schedule.run.duration_ms` | histogram | ms | `schedule_id`, `schedule_type`, `success` |
| `schedule.run.failure.count` | counter | runs | `schedule_id`, `schedule_type`, `success=false` |

Both follow the **Goodhart pairing** convention: `duration_ms` is paired with
`failure.count` so that "fast runs" that always fail do not appear healthy.

**Opt-out filters:**

| Filter | Default | Effect |
|--------|---------|--------|
| `wp_mcp_ai_pro_schedule_otel_enabled` | `true` | Return `false` to disable the entire subscriber. |
| `wp_mcp_ai_pro_schedule_otel_jit_dispatch` | `true` | Return `false` to suppress the just-in-time `WP_MCP_AI_OTel_Exporter::dispatch()` call (useful when batching). |
| `wp_mcp_ai_pro_schedule_metrics_definitions` | full set | Return a subset or empty array to limit which metrics are registered. |

## Tests

The PR adds PHPUnit tests under `addons/pro/tests/`:

- `test-pro-schedule-research-page-uses-workflow-cards.php` — asserts the
  rendered HTML contains `.workflow-option[data-workflow="research"]`, three
  `#workflow-*` content panels, the Settings + Manager quick-action links,
  and **no** legacy `.mode-tab` markup.
- `test-pro-schedule-toolkit-settings-registration.php` — asserts the
  settings page registers under `nvoos-pro-dashboard`, exposes the six
  canonical tab links, lists the six core tools, and sanitizes settings
  input to safe defaults.
- `test-pro-schedule-research-page-action-ajax.php` — 5-case suite for the
  Pause/Resume and Run-now AJAX endpoints: subscriber-denied, missing id,
  and admin happy paths.
- `test-pro-schedule-otel-subscriber.php` — 4-case suite for the OTel
  subscriber: boot registration, successful run records only duration_ms,
  failed run records both metrics, zero-duration skips duration_ms, and
  schedule_type attribute flows through to context.
- `test-pro-schedule-research-run-history-ajax.php` — 4-case suite for the
  run-history AJAX endpoint: subscriber-denied (403), missing `schedule_id`
  (400 + message contains "schedule_id"), tool-unavailable graceful error,
  and happy-path returns a parseable response with a `success` key.
- `test-pro-schedule-webhook-hmac.php` — 3-case suite for HMAC-SHA256 webhook
  signing: no secret → no `X-WP-MCP-AI-Signature` header; with secret →
  header present and `hash_hmac('sha256', timestamp.body, secret)` verifies;
  tampered body → hash mismatch (integrity protection demonstration).
