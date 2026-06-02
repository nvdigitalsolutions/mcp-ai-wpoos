# `/schedule` — Schedule Manager

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability (read ops):** `edit_posts`  
> **Capability (create/modify/delete):** `manage_options`

## Synopsis

```
/schedule [list|show|create|pause|resume|delete|run|history] [<id>] [flags]
```

Manage Pro automation schedules: list, inspect, create, pause, resume, delete, and trigger them immediately.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--all` | List all schedules (requires `manage_options`) | Off |
| `--name=<name>` | Schedule name (for `create`) | — |
| `--type=<type>` | Schedule type: `task\|workflow\|assistant_run\|channel_broadcast\|workflow_builder` | — |
| `--cron=<interval>` | WP cron interval (for `create`) | — |
| `--notify` | Enable failure notifications (for `create`) | Off |
| `--limit=<n>` | Max results | `20` |
| `--json` | Return JSON envelope instead of Markdown | Off |

## Sub-actions

| Sub-action | Description | Extra Capability |
|-----------|-------------|-----------------|
| `list` (default) | List schedules for current user | — |
| `show <id>` | Show details + last 5 run history entries | — |
| `create` | Create a new schedule | `manage_options` |
| `pause <id>` | Pause (disable) a schedule | `manage_options` |
| `resume <id>` | Resume (enable) a schedule | `manage_options` |
| `delete <id>` | Delete a schedule | `manage_options` |
| `run <id>` | Trigger schedule immediately | — |
| `history <id>` | Show run history | — |

## Examples

```bash
# List your schedules
/schedule

# List all schedules (admin only)
/schedule list --all

# Show details for schedule 42
/schedule show 42

# Create a new daily task schedule
/schedule create --name="Daily Digest" --type=task --cron=daily --notify

# Pause schedule 7
/schedule pause 7

# Run schedule 12 immediately
/schedule run 12

# Get run history for schedule 5 (last 20 entries)
/schedule history 5 --limit=20

# Output as JSON
/schedule list --json
```

## Notes

- The `WP_MCP_AI_Pro_Schedule_Manager` class must be loaded for any operation.
- Guest requests are always blocked.
- Requires Pro addon (`addons/pro/`).
