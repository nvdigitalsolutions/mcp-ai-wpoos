# Pro Schedule Manager

**Since:** 2.0.0 (Pro addon)  
**Files:** `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`,  
`addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php`,  
`addons/pro/assets/js/schedule-manager.js`

---

## Overview

The Pro Schedule Manager is a persistent, cron-backed task scheduler that goes beyond bare `wp_schedule_event()`.  
It supports five first-class schedule types, execution history with a success/failure chart, retry logic, rich failure notifications (MJML email + chat channel alerts), JetEngine CCT persistence, and a full admin UI — all backed by Symfony Cache, Symfony Validator, and the existing Pro service layer.

---

## Schedule Types

| Type | Description |
|------|-------------|
| `task` | Fires a WP action hook with optional arguments |
| `workflow` | Executes an ordered chain of AI tool calls via the Tool Registry; each step receives previous results in context |
| `assistant_run` | Sends a message to an NV oOS assistant via the internal `/mcp-ai/v1/chat` REST endpoint and stores the AI response in the action log |
| `channel_broadcast` | Delivers a recurring message to Telegram, Slack, Discord, Teams, Messenger, or WhatsApp via `unified_channel_broadcast` |
| `workflow_builder` | References a saved **Pro Workflow Builder** workflow by ID and runs its full DAG server-side |

---

## Creating a Schedule

### PHP API

```php
$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( [
    'schedule_type' => 'workflow',
    'name'          => 'Daily SEO Audit',
    'schedule'      => 'wp_mcp_ai_daily',
    'timestamp'     => strtotime( 'tomorrow 03:00' ),
    'workflow_steps' => [
        [ 'tool_slug' => 'web_search',  'arguments' => [ 'query' => 'brand mentions' ], 'label' => 'Search' ],
        [ 'tool_slug' => 'create_post', 'arguments' => [],                              'label' => 'Publish' ],
    ],
    'notify_on_failure' => true,
    'notify_email'      => 'admin@example.com',   // validated via Symfony Email constraint
], get_current_user_id() );
```

### Workflow Builder type

```php
$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( [
    'schedule_type'      => 'workflow_builder',
    'name'               => 'Nightly Content Pipeline',
    'schedule'           => 'wp_mcp_ai_daily',
    'timestamp'          => strtotime( 'tomorrow midnight' ),
    'workflow_builder_id' => 'abc123def456',   // key from wp_mcp_ai_pro_workflows option
], get_current_user_id() );
```

### Channel Broadcast type

```php
$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( [
    'schedule_type'   => 'channel_broadcast',
    'name'            => 'Weekly Digest',
    'schedule'        => 'weekly',
    'timestamp'       => strtotime( 'next Monday 09:00' ),
    'broadcast_config' => [
        'message'     => 'Weekly digest ready — check your dashboard.',
        'channels'    => [ 'telegram', 'slack' ],
        'credentials' => [
            'telegram' => [ 'bot_token' => '...', 'chat_id' => '...' ],
            'slack'    => [ 'webhook_url' => '...' ],
        ],
    ],
], get_current_user_id() );
```

---

## CRUD API

| Method | Signature | Description |
|--------|-----------|-------------|
| `create_schedule` | `( array $data, int $user_id = 0 ) : string\|WP_Error` | Creates and optionally schedules the WP-cron event |
| `update_schedule` | `( string $id, array $data, int $user_id = 0 ) : true\|WP_Error` | Partial field update; re-schedules cron if `schedule`/`timestamp`/`enabled` change |
| `delete_schedule` | `( string $id ) : bool` | Removes record + history + WP-cron event |
| `get_schedule` | `( string $id ) : array\|null` | Fetch one schedule record |
| `get_schedules` | `( array $filters = [] ) : array` | Filtered listing (`type`, `tag`, `enabled`) |
| `get_run_history` | `( string $id, int $limit = 20 ) : array` | Last *N* run records (newest first) |
| `get_schedules_ical` | `( array $filters = [] ) : string` | Export enabled schedules as RFC 5545 ICS |
| `get_history_csv` | `( string $id, int $limit = 50 ) : string` | Export run history as CSV |
| `clear_run_history` | `( string $id ) : void` | Wipe history for a schedule |
| `get_next_run_time` | `( string $id ) : int\|false` | Next WP-cron timestamp |

---

## Built-in Cron Intervals

| Slug | Interval |
|------|----------|
| `wp_mcp_ai_every_5_minutes` | Every 5 minutes |
| `wp_mcp_ai_every_10_minutes` | Every 10 minutes |
| `wp_mcp_ai_every_15_minutes` | Every 15 minutes |
| `wp_mcp_ai_every_30_minutes` | Every 30 minutes |
| `wp_mcp_ai_twice_daily` | Twice daily |
| `wp_mcp_ai_daily` | Daily |
| `wp_mcp_ai_weekly` | Weekly |
| `wp_mcp_ai_biweekly` | Every two weeks |
| `wp_mcp_ai_monthly` | Monthly (~30 days) |

Standard WordPress intervals (`hourly`, `twicedaily`, `daily`, `weekly`) are also accepted.

---

## Validation (Symfony Validator)

`create_schedule()` validates the `notify_email` field with `Symfony\Component\Validator\Constraints\Email` when the Validator component is available. An invalid address returns `WP_Error( 'invalid_notify_email', ... )` before any data is written.

```php
$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( [
    'schedule_type'     => 'task',
    'hook'              => 'my_hook',
    'notify_on_failure' => true,
    'notify_email'      => 'not-an-email',  // ← validation error
    ...
] );
// $result is WP_Error with code 'invalid_notify_email'
```

---

## Caching (Symfony Cache / WP_MCP_AI_Cache_Helper)

`load_schedules()` and `load_history()` both attempt to read from the Symfony-backed cache pool (`WP_MCP_AI_Cache_Helper`) before hitting the WordPress options table:

| Cache key | TTL | Invalidated by |
|-----------|-----|----------------|
| `pro_schedules` | 300 s | `save_schedules()` |
| `pro_schedule_history` | 60 s | `save_history()` |

When `WP_MCP_AI_Cache_Helper` is not available or caching is disabled, reads go directly to `get_option()`.

---

## Failure Notifications

### Email

On failure the manager calls `send_failure_notification()`, which:

1. **MJML** — compiles a responsive MJML template via `WP_MCP_AI_MJML_Service` if available (red header, site/type/error detail table, "Manage Schedules" button).
2. **Nodemailer** — delivers the compiled HTML + plain-text via `WP_MCP_AI_Nodemailer_Service` when available.
3. **`wp_mail`** — final fallback with `Content-Type: text/html` header.

### Chat Channel Alerts

Set `notify_channels` and `notify_channel_credentials` to receive failure notifications on Telegram, Slack, Discord, Teams, Messenger, or WhatsApp via the `unified_channel_broadcast` tool.

```php
$id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( [
    ...
    'notify_on_failure'          => true,
    'notify_email'               => 'ops@example.com',
    'notify_channels'            => [ 'slack', 'telegram' ],
    'notify_channel_credentials' => [
        'slack'    => [ 'webhook_url' => 'https://hooks.slack.com/...' ],
        'telegram' => [ 'bot_token' => '...', 'chat_id' => '...' ],
    ],
], get_current_user_id() );
```

---

## Retry Logic

| Field | Default | Notes |
|-------|---------|-------|
| `max_retries` | `0` | 0–5 |
| `retry_delay` | `300` s | Minimum 60 s |

On failure the schedule re-queues itself via `wp_schedule_single_event()` after `retry_delay` seconds. After all retries are exhausted the failure notification is sent.

---

## Timeout

| Field | Default | Notes |
|-------|---------|-------|
| `timeout` | `0` | Seconds; 0 = no limit |

When `timeout > 0`, the dispatcher calls `set_time_limit()` before execution and checks
the actual duration afterwards. Runs exceeding the limit are marked as failed with a
descriptive error message.

> **Note:** `set_time_limit()` may be disabled on shared hosting environments. As a
> fallback, the post-execution duration check will still mark long-running tasks as
> failed (best-effort enforcement).

---

## Webhook Callback

| Field | Default | Notes |
|-------|---------|-------|
| `callback_url` | `''` | Empty = disabled |

When a valid HTTPS URL is configured, the dispatcher POSTs a JSON payload to it after
every run (success **and** failure). The request is non-blocking (`blocking => false`)
so it does not delay the cron cycle.

**Payload shape:**

```json
{
  "event": "schedule.run.success",
  "schedule_id": "abc123",
  "schedule_name": "Daily Report",
  "schedule_type": "assistant_run",
  "status": "success",
  "duration": 1.234,
  "error": "",
  "action_log": { ... },
  "timestamp": "2026-03-27T12:00:00+00:00",
  "site_url": "https://example.com"
}
```

---

## JetEngine CCT Persistence

When JetEngine is active, every run is written to `WP_MCP_AI_Execution_History_CCT`, making runs visible alongside orchestration history in JetEngine admin listings:

| CCT field | Value |
|-----------|-------|
| `schedule_id` | MD5 schedule ID |
| `schedule_name` | Schedule name |
| `schedule_type` | e.g. `workflow` |
| `success` | `'1'` / `'0'` |
| `error_message` | Error string (empty on success) |
| `duration_ms` | Duration in milliseconds |
| `executed_at` | `Y-m-d H:i:s` (local time) |

---

## Execution History Ring-Buffer

The last **50** run records per schedule are stored in the `wp_mcp_ai_pro_schedule_history` option. A `wp_mcp_ai_pro_schedule_history_prune` WP-cron event runs hourly to keep memory bounded.

Each record contains:

```php
[
    'status'     => 'success' | 'failed',
    'duration'   => 0.456,      // seconds (float)
    'error'      => '',         // error message or empty string
    'start_time' => 1745000000, // Unix timestamp
    'action_log' => [           // type-specific execution summary (array)
        'type'       => 'workflow',  // schedule type
        // workflow:        'steps'            => [ [ 'tool_slug', 'label', 'result', 'duration' ], … ]
        // assistant_run:   'assistant_id'     => 42, 'message' => '…'
        // channel_broadcast: 'channels'       => ['telegram', …], 'message' => '…'
        // task:            'hook'             => 'my_hook', 'args' => [ … ]
    ],
]
```

The `action_log` field captures a trimmed summary of what the schedule actually did during each run. It is stored alongside the other fields and exposed in the admin UI as an expandable **View Log** panel in the run-history modal. It is also included as a JSON column in the CSV export.

Retrieve history:

```php
$runs = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, 20 );
```

---

## iCalendar Export

Export all enabled schedules as a standards-compliant `.ics` file:

```php
$ics = WP_MCP_AI_Pro_Schedule_Manager::get_schedules_ical();
// Returns RFC 5545 VCALENDAR string
```

The method fires the `wp_mcp_ai_ics_generate_calendar` filter so the Pro ical-generator Node.js service can produce the file when available; falls back to a pure-PHP RFC 5545 implementation.

In the admin UI click **Export to Calendar (.ics)** in the schedule list toolbar to trigger an in-browser download.

---

## CSV History Export

Export run history as CSV:

```php
$csv = WP_MCP_AI_Pro_Schedule_Manager::get_history_csv( $schedule_id, 50 );
```

Uses `WP_MCP_AI_Contact_Importer_Service` (csv-stringify NPM package) when available; falls back to pure-PHP `fputcsv`. The export includes an `action_log` column containing a JSON-encoded summary of what the schedule did. In the admin UI click **Export CSV** in the run-history modal footer.

---

## AI Tools

| Tool slug | Description |
|-----------|-------------|
| `create_pro_schedule` | Full create with type-specific config |
| `update_pro_schedule` | Partial field update |
| `delete_pro_schedule` | Remove schedule + history |
| `list_pro_schedules` | Filtered listing |
| `get_schedule_run_history` | Last N run records |
| `schedule_channel_broadcast` | Convenience tool for channel broadcasts |

### Example — create via AI tool

```json
{
  "tool": "create_pro_schedule",
  "arguments": {
    "schedule_type": "workflow_builder",
    "name": "Nightly Content Pipeline",
    "schedule": "wp_mcp_ai_daily",
    "timestamp": 1745100000,
    "workflow_builder_id": "abc123def456",
    "notify_on_failure": true,
    "notify_email": "admin@example.com"
  }
}
```

---

## Admin UI

Navigate to **NV oOS → Settings → Orchestration** tab.

### Schedule List

- Filterable by type and enabled/disabled status.
- Per-row enable/disable toggle (immediately schedules / unschedules the WP-cron event).
- **Trigger Now** button — runs the schedule immediately for testing.
- **Run History** button — opens the history modal with a chart.js stacked bar chart (green = success, red = failure) and an **Export CSV** button.
- **Edit** button — opens the edit modal.
- **Export to Calendar (.ics)** button — downloads all enabled schedules as an `.ics` file.

### Create Form

The form switches panels based on the selected type:

| Type | Panel contents |
|------|----------------|
| `task` | Hook name + JSON args |
| `workflow` | Step builder (tool slug + JSON arguments + label per step) |
| `assistant_run` | Assistant picker + message textarea |
| `channel_broadcast` | Message textarea + channel checkboxes + credentials JSON |
| `workflow_builder` | Workflow picker (loaded from saved Pro Workflow Builder workflows) |

The **Notify on failure** checkbox reveals:
- Email address field (validated on submit)
- Channel checkboxes (Telegram, Slack, Discord, Teams, Messenger, WhatsApp)
- Credentials JSON field for each selected channel

Inline JSON validation highlights invalid fields before the form can be submitted.

### Run History Modal

- **chart.js stacked bar chart** — one bar per run (up to 20 shown), stacked green/red for success/failure.
- **History table** — status, timestamp, duration, error message, and a **View Log** toggle button that expands an inline JSON panel showing the `action_log` summary for that run.
- **Export CSV** button — triggers in-browser download.
- **Clear History** button — wipes all run records for the schedule.

---

## Action Hooks & Filters

| Hook | Type | When |
|------|------|------|
| `wp_mcp_ai_pro_workflow_completed` | Action | After all workflow steps succeed |
| `wp_mcp_ai_pro_scheduled_assistant_run` | Action | After an `assistant_run` schedule completes (includes `response` key in context) |
| `wp_mcp_ai_pro_channel_broadcast` | Action | Fallback when `unified_channel_broadcast` tool is unavailable |
| `wp_mcp_ai_ics_generate_calendar` | Filter | Override ICS generation (e.g. ical-generator Node service) |

---

## Workflow Logging

Each step in a `workflow`-type schedule calls:

```php
WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $arguments, $result, $context );
```

where `$context` includes `step_label` and `step_duration` (seconds). Duration is also stored in `previous_results` so downstream steps can access it.

---

## Registration

The section is autoloaded via `settings-dashboard-init.php` using the container singleton key `section.schedule_manager`. All AI tools are registered in `wp_mcp_ai_pro_register_tools()` under the `wordpress-core` group — no toolkit gate, always available when Pro is active.

---

## Related Documentation

- [Pro Workflow Builder](../pro-workflow-builder.md) — visual DAG builder that creates workflows the `workflow_builder` schedule type can reference
- [Orchestration Dashboard](../ORCHESTRATION_DASHBOARD_IMPLEMENTATION_SUMMARY.md)
- [Chat Channels Toolkit](../integrations/) — provides the `unified_channel_broadcast` tool
- [JetEngine Integration](../jetengine-integration-guide.md)
