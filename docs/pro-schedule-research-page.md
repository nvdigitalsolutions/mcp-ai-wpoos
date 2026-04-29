# Research & Add Schedule (Pro)

The **Research & Add Schedule** page is a chat-driven admin UI under **NV oOS Pro Dashboard → Research & Add Schedule**. It lets a user paste in a free-form list of recurring responsibilities and turn each line into a managed [Pro Schedule](./pro-schedule-manager.md) (with retry, failure notification, run history, and an optional assistant runner).

It mirrors the existing _Research & Add Task_ pattern and is powered by the new [`plan_schedules_from_workflow`](#tool-plan_schedules_from_workflow) Pro tool.

---

## When to use it

You have a list of recurring responsibilities — one per line — and you want each to become a scheduled, automated reminder or assistant run. Examples:

### Admin / executive routine

```
Respond to emails
Check WhatsApp messages on internal groups
Follow up with the team on pending tasks from the previous day
Delegate new tasks to the team
Liaise with Niki as needed
Follow up with Niki on approvals and feedback
Review daily sales updates in the group
Coordinate with HR regarding new candidates for recruitment
Follow up on operational matters with supervisors and store managers
```

### Marketing / social media

```
## Daily
Respond to queries and requests (Ask Sophie, IG customers)
Review social media posts and submit for approval
Stay updated on new social media trends in beauty & personal care

## Weekly
Plan and schedule social media campaigns
Plan special product promotions
Coordinate with mall management for activations and events
```

### Mass-market sales

```
## Daily
Invoicing
Mass market customer handling
Follow up payments

## Weekly
Make sales reports (COD, Beauty by Rosh)
Weekly sales updates
Get approvals from Miss Niki

## Monthly
Budget expense for marketing & sales
```

Headings (`## ...`) are optional — they tag the items beneath them so you can filter the resulting schedules by category.

---

## Workflow modes

The page exposes three modes:

### 1. AI Plan (default)

A chat interface scoped to scheduling tools. The assistant can call:

- `plan_schedules_from_workflow` (the new wrapper)
- `create_pro_schedule`
- `list_pro_schedules`
- `update_pro_schedule`
- `delete_pro_schedule`
- `get_schedule_run_history`
- `list_assistants`
- `web_search`

Use this when you want the assistant to ask clarifying questions before committing.

### 2. Bulk Paste

A textarea + form fields:

- **Workflow Items** — one responsibility per line; `## Heading` lines tag the items that follow.
- **Default Category** — extra tag applied to every created schedule.
- **Default Cadence** — used when an item gives no hint (`single`, `hourly`, `twicedaily`, `daily`, `weekly`, ...).
- **Default Time** — first run time-of-day in 24h format. Defaults to `09:00` site time.
- **Default Assistant** — when set, every schedule fires this assistant with the line as its instruction (`schedule_type = 'assistant_run'`); when blank, schedules fall back to a generic `wp_mcp_ai_workflow_reminder` task hook.

The **Preview Plan** button calls the tool with `dry_run=true` and renders the parsed plan in a table. **Create Schedules** then commits the same plan via `dry_run=false`.

### 3. Review

Lists every schedule that carries the `planned-from-workflow` tag — i.e. schedules created via this page. Use it to confirm what was created and check first run times.

---

## Tool: `plan_schedules_from_workflow`

A Pro tool that wraps `create_pro_schedule` and accepts a list of natural-language workflow items.

| Property            | Type                                    | Description                                                                                                                              |
| ------------------- | --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `workflow_items`    | array of objects                        | Each item: `{ title, description, suggested_cadence, suggested_time, priority, tags }`. Strings inside `title` are sanitized.            |
| `workflow_text`     | string                                  | Alternative input — multi-line string. Each non-empty line becomes one item. Lines starting with `## ` set the active category tag.       |
| `category`          | string                                  | Default category — added to every item's `tags`.                                                                                         |
| `default_assistant_id` | integer                              | NV oOS assistant post ID. When set, items become `assistant_run` schedules; when omitted, they become `task` schedules firing `wp_mcp_ai_workflow_reminder`. |
| `default_cadence`   | string                                  | One of `single` plus the registered WP cron schedules (`daily`, `weekly`, ...). Defaults to `daily`.                                     |
| `default_time`      | string                                  | First-run time-of-day, `HH:MM` (24h, site timezone). Defaults to `09:00`.                                                                |
| `notify_on_failure` | boolean                                 | Defaults to `true`. Forwarded to every created schedule.                                                                                 |
| `notify_email`      | string                                  | Defaults to the WordPress admin email.                                                                                                   |
| `dry_run`           | boolean                                 | When `true`, returns the parsed plan without persisting any schedules.                                                                   |

### Cadence inference

When an item carries no `suggested_cadence`, the tool inspects the item text and matches keywords:

| Keyword(s)                                 | Cadence       |
| ------------------------------------------ | ------------- |
| `hourly`, `every hour`                     | `hourly`      |
| `twice a day`, `twice daily`               | `twicedaily`  |
| `daily`, `each day`, `every day`, `morning routine` | `daily` |
| `weekly`, `each week`, `every week`, `monday`, `friday` | `weekly` |
| `monthly`, `each month`, `every month`     | `monthly` (when registered) |
| `follow-up`, `review`, `check`, `respond`, `monitor` | `daily` (heuristic fallback) |

A cadence is only used if it is registered with WordPress (`wp_get_schedules()`). Anything else falls back to `default_cadence`.

### Priority inference

If the item carries no explicit `priority`, the tool infers one from keywords:

| Keyword(s)                                  | Priority |
| ------------------------------------------- | -------- |
| `urgent`, `asap`, `critical`, `emergency`   | 1        |
| `approval`, `sign-off`, `escalat...`        | 2        |
| `follow-up`, `payment`, `invoice`, `deadline` | 3      |
| `review`, `coordinate`, `plan`, `schedule`  | 5        |
| _(none)_                                    | 5        |

### Return shape

```json
{
  "plan":    [ { /* sanitized create_schedule payload */ } ],
  "created": [ { "schedule_id": "...", "name": "...", "cadence": "daily", "next_run": "...", "tags": [ ... ] } ],
  "skipped": [],
  "errors":  [ { "message": "...", "code": "..." } ],
  "summary": { "total": 5, "planned": 5, "created": 5, "errors": 0, "dry_run": false, "category": "marketing" },
  "message": "Created 5 of 5 planned schedules."
}
```

In `dry_run = true` mode, `created` is empty and `plan` contains the would-be payloads.

### Capabilities

- Caller must have `manage_options` (matches the rest of the scheduler tools).
- Capability flags: `write`, `requires-capability`, `state-changing`, `bulk`, `async-capable`.

### Error aggregation

If individual items are invalid (e.g. empty title) the tool collects them in `errors[]` and continues processing the remaining items, so a single bad line does not abort the whole batch.

---

## How items become schedules

For each parsed item the tool builds the following payload and hands it to `WP_MCP_AI_Pro_Schedule_Manager::create_schedule()`:

| Field               | Source                                                                                              |
| ------------------- | --------------------------------------------------------------------------------------------------- |
| `schedule_type`     | `assistant_run` if `default_assistant_id` resolves to a published assistant, else `task`.            |
| `hook` (task)       | `wp_mcp_ai_workflow_reminder` — a generic hook other plugins can listen on.                          |
| `args` (task)       | One JSON-encoded element with `{ title, description, tags }`.                                       |
| `assistant_config`  | `{ assistant_id, message }` where `message` is the original line wrapped in a "It's time for…" prompt. |
| `name`              | Title truncated to 80 chars.                                                                        |
| `description`       | Original full line.                                                                                 |
| `schedule`          | Inferred or default cadence.                                                                        |
| `timestamp`         | Next occurrence of the requested HH:MM (today if still in the future, else tomorrow), spread by 60s per item to keep schedule IDs unique. |
| `enabled`           | `true`                                                                                               |
| `priority`          | Inferred or explicit (1-10).                                                                        |
| `tags`              | `[ 'planned-from-workflow', category, item-tags... ]` (deduped).                                    |
| `notify_on_failure` | Forwarded from the request (default `true`).                                                        |
| `notify_email`      | Forwarded or admin email.                                                                           |

Because the actual write goes through `WP_MCP_AI_Pro_Schedule_Manager`, every retry / notification / history feature on the Schedule Manager page applies to schedules created from this page automatically.

---

## Reacting to `wp_mcp_ai_workflow_reminder`

When `default_assistant_id` is omitted, scheduled events fire the generic action hook:

```php
add_action( 'wp_mcp_ai_workflow_reminder', function ( $payload_json ) {
    $data = json_decode( $payload_json, true );
    // $data = [ 'title' => ..., 'description' => ..., 'tags' => [...] ]
    // Send a Slack ping, create a task, fire an email, etc.
} );
```

This lets developers integrate the reminder stream with any custom workflow without modifying the core plugin.

---

## See also

- [Pro Schedule Manager](./pro-schedule-manager.md) — the existing UI for managing every Pro Schedule.
- `WP_MCP_AI_Pro_Schedule_Manager::create_schedule()` — the underlying API.
- The existing `Research & Add Task` page, which this page is modeled on.
