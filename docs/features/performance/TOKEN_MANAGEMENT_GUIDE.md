# Token Management User Guide

**Last Updated:** December 29, 2025  
**Plugin Version:** 1.1.0  
**Difficulty:** Beginner to Intermediate  
**Time Required:** 10-15 minutes

---

## Overview

The NV oOS Token Management system provides intelligent, tiered token limits with usage tracking, forecasting, and automated alerts. This guide shows you how to manage user token limits, monitor usage, and optimize costs.

### What You'll Learn

- Understanding token tiers (Free, Pro, Enterprise)
- Viewing user token usage and statistics
- Assigning users to different tiers
- Setting tool-specific limits
- Exporting usage reports
- Configuring alerts and forecasting

---

## Token Tiers Explained

NV oOS uses a three-tier system to manage token usage:

| Tier | Daily Limit | Typical Users | Use Case |
|------|-------------|---------------|----------|
| **Free** | 50,000 tokens | Subscribers, Contributors | Basic chat, occasional use |
| **Pro** | 200,000 tokens | Authors, Editors | Regular content creation |
| **Enterprise** | 1,000,000 tokens | Administrators | Heavy usage, batch operations |

### Auto-Assignment by Role

Users are automatically assigned tiers based on their WordPress role:

```
Subscriber    → Free Tier (50k tokens/day)
Contributor   → Free Tier (50k tokens/day)
Author        → Pro Tier (200k tokens/day)
Editor        → Pro Tier (200k tokens/day)
Administrator → Enterprise Tier (1M tokens/day)
```

You can override these defaults for individual users.

---

## Accessing Token Manager

### Via Settings Dashboard

1. Navigate to **WordPress Admin → Settings → NV oOS**
2. Click the **Token Manager** tab in the settings interface
3. You'll see four sub-tabs:
   - **Overview** - Dashboard with charts and statistics
   - **Per User** - Individual user token usage
   - **Per Site** - Site-wide usage aggregation (multisite)
   - **Tool Limits** - Tool-specific token multipliers

### Via Menu (if enabled)

Alternatively, look for **NV oOS → Token Manager** in the admin menu.

---

## Token Manager Overview Tab

The Overview tab provides a high-level dashboard of your site's token usage.

### Key Metrics

**Usage Trend Chart**
- 7-day rolling usage visualization
- Color-coded by tier (Free=blue, Pro=green, Enterprise=gold)
- Shows peak usage periods
- Interactive tooltips with exact token counts

**Tier Distribution**
- Pie chart showing user distribution across tiers
- Click segments to filter user list
- Shows percentage and user count per tier

**Top Consumers**
- Table of users with highest token usage
- Sortable by usage, tier, or role
- Quick action buttons to adjust tiers

**Quick Stats Panel**
```
┌─────────────────────────────────────┐
│ Total Tokens Used Today: 1,234,567 │
│ Active Users Today: 42              │
│ Forecasted Daily Avg: 1,500,000    │
│ Cost Estimate: $2.25                │
└─────────────────────────────────────┘
```

### Refreshing Data

- Click the **Refresh** button to update charts
- Data auto-refreshes every 5 minutes
- Manual refresh pulls latest data immediately

---

## Managing User Token Limits (Per User Tab)

### Viewing User Usage

The Per User tab shows a detailed table of all users:

| Column | Description |
|--------|-------------|
| **User** | Username with avatar |
| **Role** | WordPress role |
| **Tier** | Current token tier |
| **Usage Today** | Tokens used today / Daily limit |
| **Usage (7d)** | Weekly total usage |
| **Forecast** | Predicted usage at current rate |
| **Actions** | Quick action buttons |

### Changing User Tier

**Method 1: Individual User**

1. Find the user in the table
2. Click the **tier badge** (e.g., "Pro")
3. Select new tier from dropdown:
   - Free (50k/day)
   - Pro (200k/day)
   - Enterprise (1M/day)
   - Custom (specify amount)
4. Click **Update**
5. Confirmation message appears

**Method 2: Bulk Tier Assignment**

1. Check boxes next to multiple users
2. Click **Bulk Actions** dropdown at top
3. Select **Assign to Tier**
4. Choose tier: Free, Pro, or Enterprise
5. Click **Apply**
6. Confirmation shows number of users updated

### Custom Tier Limits

For users with unique needs:

1. Click user's tier badge
2. Select **Custom** from dropdown
3. Enter daily token limit (e.g., 500000)
4. Optionally set expiration date
5. Click **Save Custom Limit**

Custom limits override role-based defaults.

### Resetting User Usage

To reset a user's token count:

1. Find user in table
2. Click **Actions → Reset Usage**
3. Confirm in popup dialog
4. User's daily counter resets to 0
5. Historical data remains for reports

**Bulk Reset:**
- Select multiple users via checkboxes
- **Bulk Actions → Reset All Selected**
- Useful at start of billing cycle

---

## Tool-Specific Limits (Tool Limits Tab)

Different tools consume tokens at different rates. Set multipliers to reflect resource intensity.

### Default Multipliers

| Tool | Multiplier | Reason |
|------|------------|--------|
| `run_crawl4ai_job` | 2.0× | Large web crawls |
| `search_content` | 1.5× | Database-heavy queries |
| `web_search` | 1.5× | External API calls |
| `submit_document_prompt` | 2.0× | Long document processing |
| `generate_openai_image` | 3.0× | Image generation costs |
| `generate_veo_video` | 5.0× | Video generation is expensive |
| `vision_product_search` | 2.0× | Vision API processing |

### Adjusting Tool Multipliers

1. Navigate to **Tool Limits** tab
2. Find tool in the list
3. Click **Edit** next to multiplier
4. Enter new multiplier (e.g., 1.5 for 50% more tokens)
5. Click **Save**
6. Changes apply immediately to future requests

### Disabling Tool for Free Tier

Restrict expensive tools to paid tiers:

1. Find tool in Tool Limits tab
2. Toggle **"Available to Free Tier"** switch to OFF
3. Free tier users see "Upgrade required" message
4. Pro and Enterprise tiers remain unaffected

---

## Usage Forecasting & Alerts

NV oOS predicts when users will hit limits and sends proactive alerts.

### Viewing Forecasts

In the **Per User** tab, the **Forecast** column shows:
- **Green:** Safe, <70% of limit predicted
- **Yellow:** Warning, 70-90% predicted
- **Red:** Critical, >90% predicted

Click any forecast to see detailed projection:
```
┌────────────────────────────────────┐
│ User: john@example.com             │
│ Current Usage: 120,000 / 200,000   │
│ Projected EOD: 185,000 (92%)       │
│ Confidence: 87%                    │
│ Recommendation: Upgrade to         │
│ Enterprise tier to avoid limits    │
└────────────────────────────────────┘
```

### Configuring Email Alerts

**Enable Alerts:**

1. Go to **Settings → NV oOS → Advanced**
2. Find **"Token Usage Alerts"** section
3. Toggle **"Enable Email Alerts"** ON
4. Configure thresholds:
   - **Warning at:** 70% (default)
   - **Critical at:** 90% (default)
5. **Save Changes**

**Alert Recipients:**

- User receives alert at their WordPress email
- Site admins can receive copies (toggle "CC Admins")
- Customize email template in Advanced settings

**Alert Timing:**

- Alerts check hourly via WP-Cron
- One warning email per user per day
- Critical alert resends every 4 hours until resolved

---

## Exporting Usage Reports

### CSV Export

Generate detailed usage reports for accounting or analysis:

1. Navigate to **Per User** or **Overview** tab
2. Click **Export** button (top right)
3. Choose format:
   - **CSV** - Spreadsheet compatible
   - **PDF** - Formatted report (Pro feature)
4. Select date range:
   - Today
   - Last 7 days
   - Last 30 days
   - Custom range
5. Click **Generate Report**
6. Download starts automatically

### Report Contents

**User-Level CSV includes:**
- User ID, Username, Email
- Role, Tier, Custom Limit (if set)
- Tokens used (hourly breakdown)
- Tool usage breakdown
- Costs per provider/model
- Forecast data
- Alert history

**Site-Level CSV includes:**
- Aggregate tokens by day
- Tier distribution percentages
- Top tools by usage
- Cost breakdown by provider
- Peak usage times

### Automated Reports

Schedule weekly or monthly reports:

1. **Advanced → Token Manager → Scheduled Reports**
2. Toggle **"Enable Scheduled Reports"** ON
3. Set frequency: Weekly (Monday) or Monthly (1st)
4. Enter email recipient(s)
5. Choose report format (CSV or PDF)
6. **Save Settings**

Reports email automatically via WP-Cron.

---

## Per-Site Usage (Multisite)

For WordPress Multisite networks:

### Viewing Network-Wide Usage

1. **Network Admin → Settings → NV oOS → Token Manager**
2. **Per Site** tab shows all subsites
3. Table columns:
   - Site Name & URL
   - Total Users
   - Tokens Used Today
   - Tier Distribution
   - Cost Estimate
4. Sort by usage to identify heavy consumers

### Site-Level Limits

Set limits per subsite:

1. Click **Site Actions → Set Limit**
2. Enter daily token limit for entire site
3. When exceeded, all users on site are blocked
4. Admin receives notification email

### Network-Level Reporting

- Aggregate usage across all sites
- Export combined CSV with per-site breakdown
- Identify sites needing tier upgrades
- Budget forecasting for network

---

## Common Scenarios

### Scenario 1: User Hitting Limits Daily

**Problem:** User consistently exceeds daily limit.

**Solution:**
1. Go to **Per User** tab
2. Find user in table
3. Check **Usage (7d)** column
4. If averaging >180k/day on Pro tier:
   - Upgrade to Enterprise tier
5. If usage is legitimate (not abuse):
   - Click tier badge → Select **Enterprise**
   - Or set custom limit: 500,000/day

### Scenario 2: Reducing Costs

**Problem:** Token costs are too high.

**Solution:**
1. Go to **Overview** tab
2. Check **Top Consumers** widget
3. Identify users with low activity but high tier
4. Downgrade underutilizing users:
   - Select users with <30k/day on Enterprise
   - Bulk assign to Pro tier
5. Check **Tool Limits** tab:
   - Increase multipliers for expensive tools
   - Disable image/video tools for Free tier

### Scenario 3: Setting Up Team Budgets

**Problem:** Need to limit token usage for a department.

**Solution:**
1. Create custom tier: "Marketing Team"
2. Set daily limit: 500,000 tokens
3. Assign all marketing team members
4. Enable alerts at 80% threshold
5. Monitor via exported weekly reports

### Scenario 4: Forecasting Budget

**Problem:** Need to predict monthly token costs.

**Solution:**
1. **Overview → Usage Trend** chart
2. Note average daily usage (e.g., 1.2M tokens)
3. Multiply by 30 days: 36M tokens/month
4. Check **Cost Estimate** in Quick Stats
5. Use formula: `(tokens ÷ 1000) × $0.015` for GPT-4o
6. Example: 36M tokens × $0.015/1k = ~$540/month

---

## Troubleshooting

### User Can't Access Chat ("Token Limit Exceeded")

**Check:**
1. **Per User** tab → Find user
2. Look at **Usage Today** column
3. If at limit:
   - Reset usage (Actions → Reset)
   - Or upgrade tier temporarily
4. Check custom limits:
   - User may have low custom limit set
   - Increase or remove custom limit

### Forecasts Inaccurate

**Causes:**
- Not enough data (need 3+ days of usage)
- Usage pattern recently changed
- User had extended downtime

**Fix:**
- Wait 3-7 days for algorithm to adjust
- Forecasts improve with more data
- Confidence score shows reliability

### Tier Changes Not Taking Effect

**Check:**
1. Hard refresh browser (Ctrl+Shift+R)
2. Clear WP object cache (if using caching plugin)
3. Verify change saved (should see green checkmark)
4. Check user meta in database:
   ```sql
   SELECT * FROM wp_usermeta 
   WHERE meta_key = '_wp_mcp_ai_token_tier' 
   AND user_id = 123;
   ```

### Export Button Not Working

**Requirements:**
- Administrator capability required
- Must have data in selected date range
- Check JavaScript console for errors

**Fix:**
- Verify user role has `manage_options` capability
- Try different date range
- Disable conflicting plugins temporarily

---

## Best Practices

### 1. Monitor Weekly
- Review Overview tab every Monday
- Check for unusual spikes
- Adjust tiers proactively before alerts

### 2. Set Realistic Limits
- Don't over-restrict creative users
- Free tier for testing, Pro for production
- Enterprise for content teams and admins

### 3. Enable Alerts
- 70% warning gives time to react
- CC admins for high-value users
- Customize threshold per user if needed

### 4. Use Tool Multipliers
- Reflect actual API costs
- Update when provider pricing changes
- Disable luxury features for Free tier

### 5. Export Monthly Reports
- Keep records for accounting
- Track growth trends
- Identify optimization opportunities

### 6. Educate Users
- Explain tiers during onboarding
- Show users how to check their usage
- Provide upgrade path when needed

### 7. Cache Strategically
- Enable object caching for large sites
- Reduces database queries
- Speeds up Token Manager UI

---

## API Reference

For developers building custom integrations:

### Get User Token Tier
```php
$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
// Returns: 'free', 'pro', 'enterprise', or 'custom'
```

### Get User Daily Limit
```php
$limit = WP_MCP_AI_Tool_Token_Limits::get_user_daily_limit( $user_id );
// Returns: integer (e.g., 200000)
```

### Check if User Can Use Tool
```php
$can_use = WP_MCP_AI_Tool_Token_Limits::can_user_use_tool( $user_id, 'tool_slug' );
// Returns: boolean
```

### Get Usage Forecast
```php
$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( 
    $user_id, 
    'tool_slug' 
);
// Returns: array with 'projected_usage', 'confidence', 'hours_until_limit'
```

### Bulk Assign Tiers
```php
$result = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers( 
    array( 123, 456, 789 ),  // User IDs
    'pro'                     // Tier
);
// Returns: array with 'success' count and 'errors'
```

### Export Usage Report
```php
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( array(
    'start_date' => '2025-12-01',
    'end_date'   => '2025-12-31',
    'format'     => 'csv',
    'users'      => array( 123, 456 ), // Optional: specific users
) );
// Returns: CSV string
```

---

## Related Documentation

- [Per-Call and Session Limits](PER-CALL-AND-SESSION-LIMITS.md) - Request-level token limits
- [Token Manager Enhancement Plan](../analytics/TOKEN-MANAGER-ENHANCEMENT-PLAN.md) - Technical implementation details
- [Analytics Dashboard](../analytics/PHASE-7-ANALYTICS-PLAN.md) - Related usage analytics
- [Settings Dashboard Guide](../../guides/admin/SETTINGS_DASHBOARD_GUIDE.md) - Navigating the settings interface
- [Architecture: Tool Token Limits](../../architecture/core/COPILOT_ARCHITECTURE_GUIDE.md#tool-token-limits) - System design

---

## Support

**Need Help?**
- Check the [Troubleshooting](#troubleshooting) section above
- Review [Common Scenarios](#common-scenarios)
- See [Quick Reference Guide](../../QUICK_REFERENCE.md)
- Open an issue: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

**Feature Requests:**
- Suggest improvements via GitHub Issues
- Tag with `enhancement` and `token-management`

---

**Last Updated:** December 29, 2025  
**Version:** 1.1.0  
**Maintainer:** NV Digital Solutions
