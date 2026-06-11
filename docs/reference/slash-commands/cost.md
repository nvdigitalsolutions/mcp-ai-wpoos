# /cost

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/cost [--days=<n>] [--user-id=<n>] [--json]
```

Show token usage and cost summaries from the cost-tracking service.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--days=<n>` | Look-back window in days (max 365) | 7 |
| `--user-id=<n>` | View costs for a specific user (`manage_options` required) | Current user |
| `--json` | Return raw JSON output | Off |

## Examples

```
/cost
/cost --days=30
/cost --days=7 --json
/cost --user-id=5 --days=14
```

## Required Capability

`edit_posts` (own costs); `manage_options` (for `--user-id` targeting another user)

## Notes

- Requires `WP_MCP_AI_Cost_Tracking_Service`. Returns a graceful message if unavailable.
- Costs are shown in USD with 4 decimal places.
- Breakdown includes provider, model, tokens, and cost per row.
