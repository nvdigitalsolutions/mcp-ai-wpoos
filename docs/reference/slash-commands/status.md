# /status

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/status [--json]
```

Aggregated system health report combining cron health, job counts, and tool-registry status.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--json` | Return raw JSON output | Off |

## Examples

```
/status
/status --json
```

## Required Capability

`edit_posts`

## Data Sources

| Data Point | Service |
|------------|---------|
| Async / cron health | `WP_MCP_AI_Async_Health_Monitor::check_async_health()` |
| Job counts | `WP_MCP_AI_Cron_Status_Service->get_status_counts()` |
| Tool registry | `WP_MCP_AI_Tool_Registry::get_instance()->get_tools()` |

## Notes

- Each data source is checked with `class_exists()` before use.
- A missing service shows ⚠️ rather than causing an error.
