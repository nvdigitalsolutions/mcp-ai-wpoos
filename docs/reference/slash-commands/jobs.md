# /jobs

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/jobs [--list] [--all] [--cancel=<job_id>] [--status=<status>] [--limit=<n>] [--json]
```

List and manage async background jobs. By default shows jobs for the current user.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--list` | List jobs (default action) | On |
| `--all` | List jobs for all users (`manage_options` required) | Off |
| `--cancel=<job_id>` | Cancel a specific job by ID | — |
| `--status=<status>` | Filter by status: `queued`, `running`, `completed`, `failed`, `paused` | All |
| `--limit=<n>` | Maximum number of rows to return | 10 |
| `--json` | Return raw JSON output | Off |

## Examples

```
/jobs
/jobs --status=failed
/jobs --all --limit=50
/jobs --cancel=abc-123
/jobs --json
```

## Required Capability

`edit_posts` (listing own jobs); `manage_options` (for `--all`)

## Notes

- Uses `WP_MCP_AI_Cron_Status_Service` when available; falls back to `WP_MCP_AI_Async_Job_Queue`.
- If neither service is loaded, returns an empty list gracefully.
