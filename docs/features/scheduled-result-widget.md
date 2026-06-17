# Scheduled Result Widget & Block

The **Scheduled Result Display** ships as both a Gutenberg block
(`mcp-ai-wpoos/scheduled-result`) and an Elementor widget
(`WP_MCP_AI_Elementor_Scheduled_Result_Widget`). Both bind to a Pro Schedule
and render the **latest run's output** as a live dashboard tile, with the
same server-side renderer powering both editors and the front end.

## Use cases

- **Priority email digest** — daily run produces a structured list of the top
  five emails; the widget renders it as a numbered list with a "last run"
  timestamp.
- **Daily sales summary** — schedule writes a `metric` envelope with
  `value`, `label` and `delta`; the widget surfaces it as a KPI tile.
- **Overnight crawler results** — schedule emits a `table` envelope with
  `rows` and `columns`; the widget renders an HTML table.
- **Run health strip** — `timeline` mode shows the pass/fail status of the
  last 20 runs as a coloured pip strip.

## Architecture

1. **Schedule Manager** (`addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`)
   - On each run, the dispatcher's structured action log is wrapped into a
     typed envelope `{ summary, data, render, status, error, generated_at }`
     by `build_result_envelope()`.
   - The envelope is stored in a **separate** WordPress option
     (`wp_mcp_ai_pro_schedule_results`) so it never bloats the lightweight
     run-history ring buffer.
   - Each schedule has its own `result_retention` count (default 10,
     clamped 1–100) — independent of `MAX_HISTORY_PER_SCHEDULE`.
2. **REST controller**
   (`addons/pro/includes/rest/class-wp-mcp-ai-pro-schedule-result-controller.php`)
   exposes four routes under `mcp-ai-pro/v1/`:
   - `GET  /schedules?selectable=1` — lightweight picker.
   - `GET  /schedules/{id}/latest-result` — full or redacted envelope; ETag
     header on every 200 response.
   - `GET  /schedules/{id}/results?limit=N` — authenticated-only history.
   - `POST /schedules/{id}/preview` — nonce-protected one-shot run that
     writes ONLY the result store (never the history ring).
3. **Tools** — three new Pro tools, registered via `wp_mcp_ai_register_tools`:
   - `get_schedule_latest_result` (read-only)
   - `render_schedule_result` (read-only, returns sanitized HTML)
   - `configure_schedule_widget_defaults` (write, manage_options)
4. **Shared renderer**
   (`includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php`) —
   single source of truth for the markup; called by:
   - the block's `render_callback` (`WP_MCP_AI_Scheduled_Result_Block::render`)
   - the Elementor widget's `render()`
   - the `render_schedule_result` tool

## Security model

| Surface | Auth required | What it sees |
|---|---|---|
| `GET /schedules/{id}/latest-result` (unauth) | No — but only when `public_render` is `true` on the schedule | A redacted envelope: summary + only the `data` paths listed in `public_fields` |
| `GET /schedules/{id}/latest-result` (auth) | Yes — `read_private_posts` cap | Full envelope |
| `GET /schedules/{id}/results` | Yes — `read_private_posts` | Full history of envelopes |
| `POST /schedules/{id}/preview` | Yes — `manage_options` + nonce + rate-limit | Triggers a one-shot run; never writes the history ring |
| `configure_schedule_widget_defaults` tool | `manage_options` | — |

- `public_render` defaults to **false**. Opt-in only.
- `public_fields` is an **allow-list** of dotted JSON paths — never a
  deny-list. Anything not listed is dropped on the public path.
- The `html-safe` raw render mode goes through `wp_kses_post()` and is only
  honoured for authenticated viewers.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_pro_schedule_result_envelope` | Filter | Last chance to shape the envelope per schedule type before it is stored. |
| `wp_mcp_ai_pro_schedule_public_result` | Filter | Last chance to redact the envelope exposed on the public REST/render path. |
| `wp_mcp_ai_pro_schedule_result_retention` | Filter | Override the per-schedule retention count. |
| `wp_mcp_ai_pro_schedule_result_capability` | Filter | Override the capability required for the authenticated REST routes. |
| `wp_mcp_ai_pro_schedule_result_recorded` | Action | Fires after a result envelope is persisted: `do_action( ..., $schedule_id, $envelope )`. Useful for OTel/observability subscribers. |

## Envelope shape

```json
{
  "summary": "5 priority emails today",
  "data": {
    "items": [
      { "title": "Re: contract" },
      { "title": "Invoice #4421" }
    ]
  },
  "render": "list",
  "status": "success",
  "error": "",
  "generated_at": 1715438400
}
```

## Render modes

| Mode | What it expects in `data` | Use case |
|---|---|---|
| `summary-card` | — (uses `summary`) | Default — one-line headline + status badge. |
| `list` | `items[]` or `steps[]` | Priority email digest, top-N anything. |
| `table` | `rows[]` (+ optional `columns[]`) | Crawler results, sales by region. |
| `metric` | `value` + optional `label` + optional `delta` | KPI tile. |
| `timeline` | — (reads `results` history) | Run health strip. |
| `raw` | `response` or `summary` | Free-form text or HTML-safe content. |

## Worked example — "Priority email digest"

1. Create a Pro `assistant_run` schedule named "Daily Priority Email Digest"
   that calls an assistant tuned to list the top 5 priority emails.
2. Use `configure_schedule_widget_defaults` (or the schedule edit screen) to
   set:
   - `result_capture` = `full`
   - `public_render` = `false`
   - `widget_defaults.render_mode` = `list`
3. Drop the **NV oOS Scheduled Result** block onto the admin dashboard page
   and pick the schedule from the inspector. Press **Trigger preview** to
   populate the tile without waiting for the next cron tick.
4. The page renders a numbered list of the five priority emails with the
   schedule's last-run timestamp.
5. An assistant can call `get_schedule_latest_result({ schedule_id })` to
   discuss the digest inline in chat.
