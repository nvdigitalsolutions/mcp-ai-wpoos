# FlowHub Inventory Sync Integration

> FlowHub POS dispensary inventory → WooCommerce synchronization for NV oOS AI assistants.

## What is FlowHub?

FlowHub is the leading cannabis dispensary POS in the US, serving 1,000+ dispensaries across 15+ states. The FlowHub Inventory Sync Pro Toolkit connects your FlowHub dispensary inventory to WooCommerce via a JetEngine CCT-based cache layer, enabling AI assistants to query inventory, products, and locations instantly — without hitting the FlowHub API on every request.

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **WooCommerce** | Active and configured |
| **NV oOS Pro** | v1.1.36+ |
| **FlowHub Account** | API credentials (Client ID + API Key) from FlowHub |
| **JetEngine** | Required for CCT cache storage |
| **Action Scheduler** | Bundled with WooCommerce |

## Installation & Activation

### Step 1: Obtain FlowHub API Credentials

1. Log into your FlowHub dashboard
2. Navigate to **Settings → API**
3. Generate or copy your **Client ID** and **API Key**
4. Note the API base URL (default: `https://api.flowhub.co/v0/`)

### Step 2: Configure the Toolkit

1. Go to **FlowHub Toolkit** (NV oOS → Toolkits → FlowHub)
2. Under **Configuration**, select your FlowHub connection from the **Remote Sites connection selector**
3. Enter your Client ID and API Key (or configure via Remote Sites for centralized credential management)
4. Set the sync interval (1–60 minutes)
5. Configure field mapping:
   - Click **Generate Default Mapping** to auto-populate common FlowHub→WooCommerce field mappings
   - Adjust individual field mappings as needed
6. Choose sync direction:
   - **FlowHub → WooCommerce only** (default) — FlowHub is the source of truth
   - **Read-only** — pull data without pushing changes back
   - **Bidirectional** — Stock changes sync both ways
7. Optionally enable WooCommerce stock writeback
8. Set your low-stock threshold (items below this count will be flagged)
9. If behind a forward proxy, enable **proxy support** (uses `http_api_curl` hook — proxy settings resolved from the Remote Sites connection first, then toolkit config)
10. Save settings

### Step 3: Run Initial Sync

1. Go to the **Overview** tab
2. Use the **per-connection sync controls** to sync individual connections or all at once
3. Click **Sync Now** to pull inventory from FlowHub
4. Verify the CCT row count and freshness indicator
5. Check the **Sync Log** tab for per-item audit trail

### Step 4: Enable the Toolkit Toggle

1. Go to **NV oOS → Settings**
2. Under **Pro Toolkits**, check **Enable FlowHub Toolkit**
3. Save settings

## Understanding the Architecture

```
FlowHub API ──(Action Scheduler, 1–60 min)──▶ JetEngine CCT (flowhub_inventory)
                                                      │
                    ┌─────────────────────────────────┼──────────────────┐
                    ▼                                 ▼                  ▼
            flowhub_inventory                  flowhub_products    flowhub_locations
            (reads CCT, zero API cost)         (reads CCT)         (reads CCT)
```

**Key principle:** All AI tool reads hit the CCT cache — zero FlowHub API calls per query. The Action Scheduler background job pulls fresh data from FlowHub on your configured interval. This means AI assistants can run hundreds of inventory queries without hitting FlowHub's rate limits.

## Using the AI Tools

### Example Natural Language Prompts

**Inventory queries:**
- "Show me all Flower products below reorder threshold"
- "What's the total inventory value at the Main St. location?"
- "List all out-of-stock edibles across all locations"
- "Compare stock levels of SKU FH-001 across all dispensary locations"

**Product browsing:**
- "List all concentrate products with their THC percentages"
- "Show me products in the Flower category sorted by quantity"
- "Find product by SKU BLUE-001 and show all its inventory levels"

**Location queries:**
- "List all dispensary locations with item counts"
- "Show me the Denver location's inventory health"

**Sync management (admin only):**
- "When was the last FlowHub sync?"
- "Trigger a full inventory sync now"
- "What's the current sync status?"

## WP-CLI Commands

```bash
# Show sync status
wp flowhub status
wp flowhub status --format=json

# Trigger a full sync
wp flowhub trigger

# Clear the CCT cache (requires --force)
wp flowhub clear-cache --force

# Test API connection
wp flowhub test-connection

# Compliance audit report
wp flowhub compliance-report
wp flowhub compliance-report --days=30 --format=csv

# Low-stock items report
wp flowhub low-stock-report
wp flowhub low-stock-report --threshold=10
```

## Troubleshooting

### Sync not running

1. Check **FlowHub Toolkit → Overview** — is the status "Fresh"?
2. Verify credentials in the Configuration tab
3. Check Action Scheduler: **Tools → Scheduled Actions** for `wp_mcp_ai_flowhub_full_sync`
4. Run `wp flowhub status` to see the last error

### API connection failing

1. Verify Client ID and API Key are correct
2. Run `wp flowhub test-connection` for diagnostics
3. Check that your server can reach `api.flowhub.co` (firewall/DNS)
4. If behind a proxy, enable proxy support in Configuration and verify proxy settings in Remote Sites
5. FlowHub API rate limit is ~5 req/s — the toolkit enforces this automatically

### Products not matching WooCommerce

1. The toolkit matches by SKU: FlowHub SKU must match WooCommerce product SKU exactly
2. Check that SKUs are populated in both systems
3. Case-sensitive matching

### CCT not found / JetEngine missing

1. Install and activate JetEngine
2. The CCT is auto-created on first sync — no manual setup needed
3. If the CCT slug was changed, make sure it exists in JetEngine → Custom Content Types

### Stale data

1. Run `wp flowhub trigger` or click "Sync Now" in the admin
2. Reduce the sync interval in Configuration (minimum 1 minute)
3. Large dispensaries (5K+ SKUs) may need longer sync times — increase PHP timeout

## Compliance Notes

### Metrc / Biotrack Integration

The FlowHub Toolkit does **not** directly integrate with state track-and-trace systems (Metrc, Biotrack). FlowHub handles compliance reporting through its own platform. The toolkit's role is to synchronize inventory data for WooCommerce and AI query purposes.

**Compliance best practices:**
- Use `wp flowhub compliance-report` to generate audit-ready inventory snapshots
- The sync engine logs every inventory quantity change with timestamps
- Store the `item_data` JSON field in CCT for complete API response audit trails
- Never use AI tools to modify inventory quantities without human review (the tools are read-only except for `flowhub_sync` settings management)

### Purchase Limits

Purchase limit enforcement is handled by FlowHub's POS, not by the WooCommerce sync. The toolkit surfaces inventory for AI queries and WooCommerce product listings but does not enforce regulatory purchase limits.

## Sync Log Manager

Every sync operation is logged with per-item audit trail via the unified **Sync Log Manager** (`WP_MCP_AI_Sync_Log_Manager`):

- **Per-item tracking** — Each synced record logged individually with timestamps and status
- **Error details** — Specific error messages for failed items (API_TIMEOUT, API_AUTH, FIELD_MAPPING, etc.)
- **Status dashboards** — Visual sync history on the Sync Log tab
- **WP-CLI access** — `wp flowhub sync-log --days=7` or `--format=json`
- **Configurable retention** — Default 30 days, adjustable via `WP_MCP_AI_SYNC_LOG_RETENTION_DAYS`

See [Sync Log Manager](../features/sync-log-manager.md) for full documentation.

## See Also

- [FlowHub Integration Proposal](../project/proposals/FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md)
- [FlowHub Implementation Plan](../project/proposals/FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md)
- [FlowHub Enhancement Plan](../project/proposals/FLOWHUB-TOOLKIT-ENHANCEMENT-PLAN.md)
- [FlowHub Toolkit README](../../addons/pro/includes/tools/flowhub/README.md)
- [Sync Log Manager](../features/sync-log-manager.md)
- [FlowHub API Documentation](https://www.flowhub.com/)
- [JetEngine CCT Documentation](https://crocoblock.com/knowledge-base/features/custom-content-type/)
