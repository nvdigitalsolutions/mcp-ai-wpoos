# FlowHub Pro Toolkit — Enhancement Plan (Phase 2)

**Date:** June 28, 2026  
**Status:** 📋 PROPOSAL  
**Target Release:** NV oOS Pro v1.4.0  
**Reference:** Shopify Sync Pro Toolkit (v1.3.0) — feature parity target  
**Estimated Duration:** 3 weeks (14 stories)

---

## Executive Summary

The FlowHub Inventory Sync Pro Toolkit (PR #5501, v1.2.0) is a solid v1 implementation — it syncs FlowHub dispensary inventory to a JetEngine CCT cache, exposes 5 AI-callable tools, and handles WooCommerce stock writeback. However, the recently completed **Shopify Sync Pro Toolkit** (v1.3.0) introduced several architectural improvements that should be back-ported to FlowHub for consistency, observability, and operational maturity. This plan identifies 14 enhancement stories across 3 weeks, bringing FlowHub to full feature parity with Shopify while adding cannabis-industry-specific compliance features.

---

## 1. Gap Analysis — FlowHub vs. Shopify Sync

| Capability | FlowHub (v1.2.0) | Shopify Sync (v1.3.0) | Priority |
|---|---|---|---|
| **WP-CLI Commands** | ❌ None | ✅ 7 commands (`status`, `trigger`, `clear-cache`, etc.) | **P0** |
| **PHPUnit Test Suite** | ❌ None | ✅ 4 test files, >80% coverage target | **P0** |
| **User-Facing Docs** | ❌ README only | ✅ Full `docs/toolkits/` guide | **P0** |
| **Sync Log Admin Tab** | ❌ Overview only | ✅ Sync Log tab with filter/export | **P1** |
| **Rate Limit Tracking** | ❌ Client-side only | ✅ GraphQL cost budget dashboard | **P1** |
| **Analytics Tool** | ❌ None | ✅ `shopify_sync_analytics` (4 actions) | **P1** |
| **Low-Stock Alert System** | ❌ Passive threshold | ✅ Proactive alerts + notification hooks | **P1** |
| **Compliance Audit Logging** | ⚠️ Basic log only | N/A (e-commerce) | **P1** |
| **Error Notification** | ✅ Basic email | ✅ Per-connection context, admin notice | **P2** |
| **Sync Engine Enhancements** | ⚠️ Single hook | ✅ Per-connection dispatch, cost tracking | **P2** |
| **Admin Page Tabs** | 3 tabs | 4 tabs (adds Sync Log) | **P2** |
| **Tool Reference Docs** | ❌ Not in `tool-reference.md` | ❌ Not yet (both pending) | **P2** |

---

## 2. Industry Research — Cannabis Dispensary Compliance

### 2.1 Compliance Landscape (2025–2026)

FlowHub serves 1,000+ dispensaries across 15+ US states. Each state has different track-and-trace requirements:

| State | System | Key Requirements |
|---|---|---|
| California | Metrc | Daily inventory reconciliation, waste tracking, UID tagging |
| Colorado | Metrc | Real-time inventory updates, manifest tracking |
| Oregon | Metrc | Strain-level tracking, lab test integration |
| Michigan | Metrc | Patient purchase limits, caregiver tracking |
| Washington | Biotrack | Plant-to-sale tracking, destruction logging |
| New York | Biotrack | Seed-to-sale, delivery manifest verification |
| Illinois | Biotrack | Cultivation center → dispensary transfers |

**Key compliance patterns identified from industry research:**

1. **Audit trail immutability** — Every inventory quantity change must be logged with timestamp, user, location, and reason. WooCommerce stock changes from FlowHub sync must be traceable back to the API call that triggered them.

2. **Reconciliation windows** — Most states require daily inventory reconciliation. Discrepancies > 2% must be investigated. The toolkit should surface reconciliation-ready reports.

3. **Purchase limit enforcement** — Patient/customer purchase limits vary by state, product category (flower vs. concentrate vs. edible), and time period (daily, 30-day rolling). CCT cache could store and enforce these.

4. **Strain/variety tracking** — Unlike generic e-commerce, cannabis products have strain names, THC/CBD percentages, and lab test IDs. The CCT schema should accommodate these fields.

5. **Non-zero inventory only** — FlowHub's API already filters to `inventoryNonZero`. This matches the industry pattern of showing only "sellable" inventory to consumers, while keeping full inventory (including zero-quantity items) for compliance reporting.

### 2.2 FlowHub API Capabilities (Post-Research)

The FlowHub v0 REST API provides:

| Endpoint | Method | Pagination | Rate Limit |
|---|---|---|---|
| `/v0/inventoryNonZero` | GET | offset/limit | ~5 req/s (client-enforced) |
| `/v0/products` | GET | offset/limit | Same |
| `/v0/locations` | GET | N/A | Same |

**Key findings for enhancement:**

- **No webhook support** — FlowHub v0 has no webhook infrastructure. Compare to Shopify which has full webhook support. FlowHub must rely on polling-only sync with configurable intervals.
- **Rate limit is client-enforced** — Unlike Shopify's server-side bucket model, FlowHub's rate limit is documented at ~5 requests/second. The client already implements `throttle()` with 200ms delay. We can add rate-limit telemetry similar to Shopify's cost tracking.
- **No bulk export endpoint** — FlowHub has no equivalent to Shopify's Bulk Operations (10 pts flat). Full syncs must paginate through all inventory pages. The existing `get_all_inventory()` method handles this but could benefit from progress tracking improvements.
- **Single-account model** — Unlike Shopify which supports multi-connection, FlowHub is a single-account integration. But future multi-store support could be added.

---

## 3. Proposed Enhancements

### Enhancement 1: WP-CLI Commands (P0 — Story 1)

Mirror the Shopify `wp shopify-sync` pattern for FlowHub:

```bash
wp flowhub status                        # Show sync status
wp flowhub trigger                       # Force full sync
wp flowhub clear-cache [--force]         # Clear CCT cache
wp flowhub test-connection               # Test API connectivity
wp flowhub compliance-report [--days=7]  # Inventory change audit log
wp flowhub low-stock-report              # Items below threshold
```

**Implementation:** New file `addons/pro/includes/class-wp-mcp-ai-flowhub-cli.php`, loaded via `init.php` when `WP_CLI` is defined.

### Enhancement 2: flowhub_sync_analytics Tool (P1 — Story 2)

A new 6th AI tool matching `shopify_sync_analytics`:

| Action | Description | CCT-First? |
|---|---|---|
| `inventory_summary` | Total items, value, per location/vendor breakdown | ✅ CCT |
| `stock_velocity` | Fast/slow movers, sold-out items | ✅ CCT |
| `category_breakdown` | By purchase category (Flower, Edible, Concentrate, etc.) | ✅ CCT |
| `compliance_summary` | Items with zero qty but still tracked, reconciliation stats | ✅ CCT |
| `location_comparison` | Compare inventory across dispensary locations | ✅ CCT |

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-analytics.php`

### Enhancement 3: Low-Stock Alert Automation (P1 — Story 3)

Proactive alert system for dispensary operators:

```php
class WP_MCP_AI_FlowHub_Alert_Manager {
    // Check after each sync, fire hooks for items below threshold.
    public static function check_low_stock( $cct_manager, $threshold );
    
    // Email notification with list of low-stock items.
    public static function send_low_stock_alert( $items, $threshold );
    
    // Admin bar indicator showing low-stock count.
    public static function add_admin_bar_indicator();
}
```

**Hooks:**
- `wp_mcp_ai_flowhub_low_stock_detected` — fires with array of items below threshold
- `wp_mcp_ai_flowhub_out_of_stock_detected` — fires for zero-quantity items
- `wp_mcp_ai_flowhub_stock_recovered` — fires when an item returns above threshold

### Enhancement 4: Compliance Audit Logging (P1 — Story 4)

Cannabis-industry-specific compliance features:

**CCT Schema Addition:**
| New Column | Type | Purpose |
|---|---|---|
| `strain_name` | TEXT | Cannabis strain/variety name |
| `thc_percentage` | DECIMAL(5,2) | THC content percentage |
| `cbd_percentage` | DECIMAL(5,2) | CBD content percentage |
| `lab_test_id` | TEXT | Lab test batch identifier |
| `compliance_status` | TEXT | `compliant` / `quarantine` / `recalled` |
| `metrc_uid` | TEXT | State track-and-trace UID |
| `previous_quantity` | INT | Quantity before last change (audit trail) |
| `quantity_change_reason` | TEXT | Reason for quantity delta |

**Compliance Report Tool Action:**
```
flowhub_sync → compliance_report action
  Returns: reconciliation-ready inventory log with before/after quantities,
  timestamps, and location breakdown.
  Format: compatible with Metrc/Biotrack CSV import.
```

**Compliance Hooks:**
```php
do_action( 'wp_mcp_ai_flowhub_inventory_change', array(
    'product_id'      => $product_id,
    'sku'             => $sku,
    'strain_name'     => $strain_name,
    'old_quantity'    => $old_qty,
    'new_quantity'    => $new_qty,
    'location_id'     => $location_id,
    'location_name'   => $location_name,
    'timestamp'       => current_time( 'mysql' ),
    'sync_operation'  => 'full_sync', // or 'delta' or 'manual'
) );
```

### Enhancement 5: Rate Limit Telemetry & Dashboard (P1 — Story 5)

Track FlowHub API usage similar to Shopify's GraphQL cost tracking:

**New option keys:**
```
wp_mcp_ai_flowhub_api_requests_today       — counter
wp_mcp_ai_flowhub_api_requests_last_hour   — sliding window
wp_mcp_ai_flowhub_api_rate_limit_hits      — 429 count
wp_mcp_ai_flowhub_last_sync_duration       — seconds
wp_mcp_ai_flowhub_sync_history             — array of last 50 sync events
```

**Admin dashboard widget:** Shows requests today, average sync duration, rate limit hits, sync history sparkline.

### Enhancement 6: Admin Page Sync Log Tab (P2 — Story 6)

Add a 4th tab to the FlowHub admin page:

| Tab | Content |
|---|---|
| Overview | (existing) Connection status, sync stats, quick sync |
| Configuration | (existing) Credentials, interval, direction, mapping |
| **Sync Log** | (new) Last 50 sync events, filterable, CSV export |
| Tools Management | (existing) Enable/disable tools |

### Enhancement 7: Sync Engine Enhancements (P2 — Story 7)

Back-port engine improvements from Shopify:
- Use `$items_count = count($items)` before loop condition (PHPCS compliance)
- Add `wp_mcp_ai_flowhub_after_wc_sync` hook (currently missing)
- Handle `sync_direction: woo_to_flowhub` placeholder with clear `WP_Error`
- Add progress tracking to sync log

### Enhancement 8: User-Facing Documentation (P0 — Story 8)

Create `docs/toolkits/flowhub-integration.md` matching the Shopify guide format:
- What is FlowHub? (dispensary context)
- Prerequisites (WooCommerce, JetEngine, FlowHub API credentials)
- Installation & activation
- Configuration walkthrough
- Tool usage examples with natural language prompts
- WP-CLI command reference
- Compliance notes (Metrc/Biotrack awareness)
- Troubleshooting common issues

### Enhancement 9: PHPUnit Test Suite (P0 — Story 9)

Create 4 test files matching Shopify's coverage:

| Test File | Coverage |
|---|---|
| `tests/test-flowhub-client.php` | API call mocking, pagination, error handling, throttling |
| `tests/test-flowhub-cct-manager.php` | Column creation, upsert, queries, freshness |
| `tests/test-flowhub-sync-engine.php` | Scheduling, full sync, WC sync, error notification |
| `tests/test-flowhub-tools.php` | Capability gates, canonical envelopes, sanitization, CCT read paths |

### Enhancement 10: Tool Reference Documentation (P2 — Story 10)

Add FlowHub section to `docs/reference/tools/tool-reference.md`:
- All 6 tool entries (5 existing + 1 new analytics)
- Parameter tables, capability requirements, example prompts
- Return shapes with compliance-specific fields

### Enhancement 11: CCT Column Auto-Discovery for New Fields (P2 — Story 11)

The Shopify CCT manager introduced `ensure_columns()` auto-creation. Enhance FlowHub's to:
- Detect new columns in the schema and auto-create them on update
- Provide a schema version tracker (`wp_mcp_ai_flowhub_sync_db_version`)
- Handle column type changes gracefully (log warning, don't break)

### Enhancement 12: Multi-Location Analytics Dashboard Widget (P2 — Story 12)

Add a WordPress dashboard widget showing:
- Inventory health gauge (in-stock / low / out-of-stock)
- Top 5 selling locations by item count
- Compliance status summary
- Last sync time + next scheduled sync

### Enhancement 13: Improved Sync Strategy (P2 — Story 13)

- **Smart sync**: Only re-fetch items updated since last sync (if API supports `?updated_since=` param)
- **Chunked sync**: Break large syncs into Action Scheduler batches to avoid timeouts
- **Sync priority**: Allow admin to prioritize certain locations for more frequent syncs
- **Conflict resolution for bidirectional**: When WC stock changes, optionally push back to FlowHub (requires FlowHub API write support)

### Enhancement 14: WooCommerce→FlowHub Writeback (P3 — Future)

- Placeholder in the sync engine for `woo_to_flowhub` direction
- requires FlowHub API to support inventory write operations
- requires conflict-resolution rules (last-write-wins, FlowHub-as-truth, etc.)

---

## 4. Effort Estimation

| Phase | Stories | Duration |
|---|---|---|
| **Phase A: CLI + Docs + Tests (P0)** | Stories 1, 8, 9 | 1 week |
| **Phase B: Analytics + Alerts + Compliance (P1)** | Stories 2, 3, 4, 5 | 1 week |
| **Phase C: Admin + Engine + Polish (P2)** | Stories 6, 7, 10, 11, 12, 13 | 1 week |
| **Total** | **14 stories** | **3 weeks** |

---

## 5. File Manifest

```
New files (10):
├── addons/pro/includes/
│   └── class-wp-mcp-ai-flowhub-cli.php              # WP-CLI commands
├── addons/pro/includes/tools/flowhub/
│   ├── class-wp-mcp-ai-pro-tool-flowhub-analytics.php  # Analytics tool
│   └── class-wp-mcp-ai-flowhub-alert-manager.php       # Low-stock alerts
├── tests/
│   ├── test-flowhub-client.php
│   ├── test-flowhub-cct-manager.php
│   ├── test-flowhub-sync-engine.php
│   └── test-flowhub-tools.php
├── docs/toolkits/
│   └── flowhub-integration.md                        # User-facing docs

Modified files (5):
├── addons/pro/includes/tools/flowhub/init.php         # CLI load + alert init
├── addons/pro/includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php  # Sync log tab
├── addons/pro/includes/class-wp-mcp-ai-flowhub-sync-engine.php  # Engine enhancements
├── addons/pro/includes/class-wp-mcp-ai-flowhub-cct-manager.php  # New columns
├── addons/pro/mcp-ai-wpoos-pro.php                    # Analytics tool registration
```

## 6. Appendix: Feature Parity Matrix (Post-Enhancement)

| Capability | FlowHub | Shopify Sync |
|---|---|---|
| AI Tools count | 6 (+analytics) | 5 |
| WP-CLI commands | 6 | 7 |
| PHPUnit tests | 4 files | 4 files |
| User-facing docs | ✅ | ✅ |
| Admin page tabs | 4 (+sync log) | 4 |
| Sync log / history | ✅ | ✅ |
| Analytics tool | ✅ | ✅ |
| Low-stock alerts | ✅ | ✅ |
| Rate-limit tracking | ✅ | ✅ |
| Compliance audit | ✅ | N/A |
| Webhook support | N/A (API limitation) | ✅ |
| Multi-connection | N/A (single account) | ✅ |
| GraphQL cost tracking | N/A (REST API) | ✅ |
