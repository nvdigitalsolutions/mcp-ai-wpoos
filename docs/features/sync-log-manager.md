# Sync Log Manager

> Unified per-item audit trail across EZuite, FlowHub, and Shopify sync toolkits.

## Overview

The Sync Log Manager (`WP_MCP_AI_Sync_Log_Manager`) provides a centralized audit trail for all Pro toolkit sync operations. Every item synchronized — whether from EZuite ERP, FlowHub POS, or Shopify — is logged with timestamps, status codes, and detailed error tracking.

## Features

- **Per-item audit trail** — Each synced record logged individually
- **Cross-toolkit support** — EZuite, FlowHub, and Shopify all use the same log infrastructure
- **Status dashboards** — Visual sync history on each toolkit's admin page
- **Error tracking** — Detailed error messages for failed items
- **WP-CLI integration** — Query and export logs from the command line
- **Configurable retention** — Control how long logs are kept

## Architecture

```
EZuite Sync Engine ─────┐
FlowHub Sync Engine ────┼──▶ Sync Log Manager ──▶ JetEngine CCT (sync_log)
Shopify Sync Engine ────┘              │
                                       ▼
                              Admin UI Dashboards
                              WP-CLI Commands
                              AI Tools (read-only)
```

## Using the Sync Log

### Admin UI

Each toolkit's settings page includes a **Sync Log** tab showing:
- Recent sync operations with status badges
- Per-sync record counts (total, created, updated, deleted, failed)
- Expandable error details for failed items
- Date range filtering

### WP-CLI Commands

```bash
# EZuite sync log
wp ezuite sync-log --days=7
wp ezuite sync-log --days=30 --format=csv

# FlowHub sync log
wp flowhub sync-log --days=7
wp flowhub sync-log --days=30 --format=json

# Shopify sync log
wp shopify sync-log --days=7
wp shopify sync-log --status=failed

# Global sync log (all toolkits)
wp mcp-ai sync-log --toolkit=all --days=7
wp mcp-ai sync-log --toolkit=ezuite --limit=50
```

### AI Tools

AI assistants can query sync status via each toolkit's settings tool:
- "Show me the last 3 FlowHub syncs"
- "How many items failed in yesterday's EZuite sync?"
- "Give me a sync health report for all toolkits"

## Log Entry Structure

Each sync log entry contains:

| Field | Description |
|---|---|
| `sync_id` | Unique identifier for the sync operation |
| `toolkit` | Source toolkit (`ezuite`, `flowhub`, `shopify`) |
| `connection_id` | Remote Sites connection used |
| `started_at` | Sync start timestamp |
| `completed_at` | Sync completion timestamp |
| `status` | `success`, `partial`, or `failed` |
| `total_items` | Total items processed |
| `created` | New items created |
| `updated` | Existing items updated |
| `deleted` | Items removed |
| `failed` | Items that failed to sync |
| `errors` | JSON array of per-item error details |
| `trigger` | How sync was started (`manual`, `scheduled`, `webhook`) |

## Configuration

### Retention Policy

Configure how long sync logs are kept:
- Default: 30 days
- Minimum: 7 days
- Maximum: 365 days

```php
// wp-config.php or custom code
define( 'WP_MCP_AI_SYNC_LOG_RETENTION_DAYS', 60 );
```

### Cleanup Cron

Old logs are automatically purged via WordPress cron:
- Hook: `wp_mcp_ai_sync_log_cleanup`
- Frequency: Daily

## Error Codes

| Code | Description |
|---|---|
| `API_TIMEOUT` | Source API request timed out |
| `API_AUTH` | Authentication failed |
| `API_RATE_LIMIT` | Rate limit exceeded |
| `API_NOT_FOUND` | Resource not found at source |
| `CCT_WRITE_FAILED` | Failed to write to JetEngine CCT |
| `FIELD_MAPPING` | Field mapping mismatch |
| `VALIDATION` | Data validation failed |
| `DUPLICATE` | Duplicate item detected |

## See Also

- [EZuite Inventory Sync Integration](../toolkits/ezuite-inventory-sync.md)
- [FlowHub Integration](../toolkits/flowhub-integration.md)
- [Shopify Sync Integration](../toolkits/shopify-sync-integration.md)
- [JetEngine CCT Documentation](https://crocoblock.com/knowledge-base/features/custom-content-type/)
