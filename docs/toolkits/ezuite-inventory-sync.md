# EZuite Inventory Sync Integration

> EZuite ERP inventory → WooCommerce/WordPress synchronization for NV oOS AI assistants.

## What is EZuite?

EZuite is an enterprise resource planning (ERP) system used for inventory management, item tracking, order processing, and customer management. The EZuite Inventory Sync Pro Toolkit bridges your EZuite ERP data with WordPress/WooCommerce via a JetEngine CCT-based cache layer, enabling AI assistants to query inventory, products, and orders instantly — without hitting the EZuite API on every request.

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **WooCommerce** | Active and configured |
| **NV oOS Pro** | v1.1.36+ |
| **EZuite Account** | API credentials and connection details from your EZuite instance |
| **JetEngine** | Required for CCT cache storage |
| **Action Scheduler** | Bundled with WooCommerce |

## Installation & Activation

### Step 1: Obtain EZuite API Credentials

1. Log into your EZuite ERP dashboard
2. Navigate to **Settings → API / Integrations**
3. Generate or copy your API credentials
4. Note the API base URL for your EZuite instance

### Step 2: Configure the Toolkit

1. Go to **EZuite Toolkit** (NV oOS → Toolkits → EZuite)
2. Under **Configuration**, enter your API credentials and connection details
3. Set the sync interval (1–60 minutes)
4. Configure field mapping between EZuite and WooCommerce/WordPress:
   - Click **Generate Default Mapping** to auto-populate common field mappings
   - Adjust individual field mappings as needed
5. Choose sync direction:
   - **EZuite → WordPress only** (default) — EZuite is the source of truth
   - **Read-only** — pull data without pushing changes back
   - **Bidirectional** — changes sync both ways
6. Set your low-stock threshold
7. Save settings

### Step 3: Run Initial Sync

1. Go to the **Overview** tab
2. Select your EZuite connection from the Remote Sites selector
3. Click **Sync Now** to pull data from EZuite
4. Verify the CCT row count and freshness indicator

### Step 4: Enable the Toolkit Toggle

1. Go to **NV oOS → Settings**
2. Under **Pro Toolkits**, check **Enable EZuite Toolkit**
3. Save settings

## Understanding the Architecture

```
EZuite ERP ──(Action Scheduler, 1–60 min)──▶ JetEngine CCT (ezuite_inventory)
                                                      │
                    ┌─────────────────────────────────┼──────────────────┐
                    ▼                                 ▼                  ▼
            ezuite_inventory                   ezuite_erp_get_products  ezuite_erp
            (reads CCT, zero API cost)         (reads CCT)              (invoke ERP API)
```

**Key principle:** All AI tool reads hit the CCT cache — zero EZuite API calls per query. The Action Scheduler background job pulls fresh data from EZuite on your configured interval. This means AI assistants can run hundreds of inventory queries without hitting EZuite's rate limits.

## Using the AI Tools

### Available Tools

| Tool | Slug | Description |
|---|---|---|
| EZuite Inventory | `ezuite_inventory` | Search and filter cached inventory from local CCT cache (zero API cost) |
| EZuite ERP Get Products | `ezuite_erp_get_products` | Pull products from EZuite with location filtering |
| EZuite ERP | `ezuite_erp` | Full ERP API access: list connections, test connection, invoke API actions |
| EZuite Settings | `ezuite_settings` | Manage ERP credentials, sync intervals, and connection config |

### Example Natural Language Prompts

**Inventory queries:**
- "Show me all items below reorder threshold in the MAIN warehouse"
- "What's the total inventory value across all locations?"
- "List all out-of-stock items at WAREHOUSE1"
- "Compare stock levels of SKU C316/L16/ITM-10 across all locations"

**Product browsing:**
- "Pull all products from the MAIN location"
- "Show me item details for SKU C316/L16/ITM-10"
- "List products by supplier X"

**ERP operations (admin only):**
- "Create a new item in EZuite"
- "Update stock levels for SKU C316/L16/ITM-10"
- "Query orders for customer ABC-123"

**Sync management (admin only):**
- "When was the last EZuite sync?"
- "Trigger a full inventory sync now"
- "Show me the sync log for the past 7 days"

## WP-CLI Commands

```bash
# Show sync status
wp ezuite status
wp ezuite status --format=json

# Trigger a full sync
wp ezuite trigger

# Clear the CCT cache (requires --force)
wp ezuite clear-cache --force

# Test API connection
wp ezuite test-connection

# List available connections
wp ezuite list-connections

# Pull specific item
wp ezuite pull-item --sku="C316/L16/ITM-10"

# Query sync log
wp ezuite sync-log --days=7
wp ezuite sync-log --days=30 --format=csv
```

## Field Mapping

The toolkit uses a flexible field mapping system to translate between EZuite ERP fields and WordPress/WooCommerce fields:

1. **Generate Default Mapping** — Auto-populates common field mappings for your ERP schema
2. **Custom mappings** — Map any EZuite field to any WordPress/WooCommerce field
3. **Read-only sync** — Pull EZuite data into WordPress without pushing changes back

### Default mappings include:
- SKU → WooCommerce SKU
- Item_Code → Product reference
- Item_Name → Product title
- Description → Product description
- Location_Code → Warehouse/location meta
- Quantity → Stock quantity
- Price → Regular price

## Sync Log Manager

Every sync operation is logged with per-item audit trail:

- **Timestamp** — When each item was synced
- **Status** — Success, failed, or skipped
- **Error details** — Specific error messages for failed items
- **Record counts** — Items processed, created, updated, deleted

Access via:
- **Admin UI:** EZuite Toolkit → Sync Log tab
- **WP-CLI:** `wp ezuite sync-log`
- **AI tools:** Query sync status and history via `ezuite_settings`

## Troubleshooting

### Sync not running

1. Check **EZuite Toolkit → Overview** — is the status "Fresh"?
2. Verify credentials in the Configuration tab
3. Check Action Scheduler: **Tools → Scheduled Actions** for `wp_mcp_ai_ezuite_full_sync`
4. Run `wp ezuite status` to see the last error

### API connection failing

1. Verify API credentials and connection URL are correct
2. Run `wp ezuite test-connection` for diagnostics
3. Check that your server can reach the EZuite API endpoint (firewall/DNS)
4. Ensure the EZuite connection appears in Remote Sites

### Connections not showing

1. Ensure Remote Sites connections are configured
2. Check the connection selector on the EZuite Toolkit settings page
3. Verify the connection has the correct API endpoint URL
4. Run `wp ezuite list-connections` for available connections

### CCT not found / JetEngine missing

1. Install and activate JetEngine
2. The CCT is auto-created on first sync — no manual setup needed
3. If the CCT slug was changed, verify it exists in JetEngine → Custom Content Types
4. Check that CCT columns match expected field mappings

### Field mapping issues

1. Click **Generate Default Mapping** to reset to auto-detected mappings
2. Verify ERP field names match the actual API response keys
3. Check the sync log for field-level errors

## See Also

- [EZuite Toolkit README](../../addons/pro/includes/tools/erp-ezuite/README.md)
- [Sync Log Manager](../features/sync-log-manager.md)
- [JetEngine CCT Documentation](https://crocoblock.com/knowledge-base/features/custom-content-type/)
- [Remote Sites Documentation](remote-sites-setup.md)
