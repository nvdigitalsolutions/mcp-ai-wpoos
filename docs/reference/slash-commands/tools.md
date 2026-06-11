# /tools

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/tools [<search>] [--capability-flag=<flag>] [--list] [--page=<n>] [--show=<slug>] [--json]
```

Browse, filter, and inspect tools registered in `WP_MCP_AI_Tool_Registry`.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `<search>` | Search term (positional arg[0]) — filters by slug or description | — |
| `--capability-flag=<flag>` | Filter by capability flag (`read-only`, `write`, `state-changing`, etc.) | — |
| `--list` | List all tools (default action) | On |
| `--page=<n>` | Page number (20 tools per page) | 1 |
| `--show=<slug>` | Show full definition for one tool slug | — |
| `--json` | Return raw JSON output | Off |

## Examples

```
/tools
/tools post
/tools --capability-flag=write
/tools --page=2
/tools --show=manage_posts
/tools --json
```

## Required Capability

`edit_posts`

## Notes

- Requires `WP_MCP_AI_Tool_Registry`. Returns a graceful message if unavailable.
- `--show` with a nonexistent slug returns `WP_Error( 'tool_not_found' )`.
- Results are paginated at 20 per page; use `--page` to navigate.
