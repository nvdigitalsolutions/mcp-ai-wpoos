# Orchestration Dashboard Troubleshooting Guide

## Issue: Dashboard Shows "-" Placeholders Instead of Data

The orchestration dashboard at `/wp-admin/admin.php?page=mcp-ai-orchestration-pro` displays "-" for all metrics instead of loading real-time data.

---

## Quick Diagnosis Steps

### 1. Enable Debug Mode

Add these lines to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false); // Keep false to avoid breaking AJAX
```

### 2. Check Browser Console

1. Navigate to the orchestration dashboard page
2. Open browser developer tools (F12)
3. Go to the **Console** tab
4. Look for these messages:

#### ✅ Expected Success Messages:
```
OrchestrationDashboard: Initializing... {ajaxUrl: "...", nonce: "..."}
OrchestrationDashboard: Configuration loaded successfully
OrchestrationDashboard: Loading dashboard data...
OrchestrationDashboard: AJAX response received {success: true, data: {...}}
OrchestrationDashboard: Updating dashboard with data {...}
```

#### ❌ Possible Error Messages:

**Configuration Not Loaded:**
```
OrchestrationDashboard: Configuration not loaded properly {}
```
**Cause:** `wpMcpAiOrchestration` is not defined
**Fix:** Scripts not enqueued properly (see step 3)

**AJAX Error:**
```
Failed to load dashboard data: {status: "error", error: "...", response: "..."}
```
**Cause:** AJAX endpoint failing
**Fix:** Check PHP error log (see step 4)

**Data Load Failed:**
```
Dashboard data load failed: {success: false, ...}
```
**Cause:** PHP function returning error
**Fix:** Check PHP error log for backend errors

### 3. Verify Assets Are Loading

In browser developer tools:

1. Go to the **Network** tab
2. Refresh the page
3. Check for these files:

#### Required Assets:
- ✅ `orchestration-bundle.min.js` - Should return 200 OK
- ✅ `orchestration-dashboard.css` - Should return 200 OK  
- ✅ `orchestration-dashboard.js` - Should return 200 OK

#### If Files Return 404:
The assets are not being enqueued. Check PHP error log for:
```
Orchestration Dashboard: Hook=nvoos-pro-dashboard_page_mcp-ai-orchestration-pro, Is orchestration page=YES
```

If you see `Is orchestration page=NO`, the hook check is failing.

### 4. Check PHP Error Log

Location: Usually `/wp-content/debug.log` when `WP_DEBUG_LOG` is enabled

#### Look for these messages:

**Asset Enqueuing:**
```
Orchestration Dashboard: Hook=nvoos-pro-dashboard_page_mcp-ai-orchestration-pro, GET page=mcp-ai-orchestration-pro, Is orchestration page=YES
```

**AJAX Errors:**
```
PHP Fatal error: Uncaught Error: Call to undefined function...
```

### 5. Test AJAX Endpoint Directly

Use browser console or a tool like Postman:

```javascript
jQuery.ajax({
    url: wpMcpAiOrchestration.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_get_dashboard_data',
        nonce: wpMcpAiOrchestration.nonce
    },
    success: function(response) {
        console.log('AJAX Test Response:', response);
    },
    error: function(xhr, status, error) {
        console.error('AJAX Test Error:', error);
    }
});
```

Expected response:
```json
{
  "success": true,
  "data": {
    "overview": {
      "active_sessions": 0,
      "total_plans": 0,
      "total_executions": 0,
      "system_health": "Healthy"
    },
    "capacity": {
      "utilization": "0%",
      "queue_length": 1,
      "load_status": "IDLE"
    },
    "sessions": [],
    "workflows": [],
    "activity": [...]
  }
}
```

---

## Common Issues & Solutions

### Issue 1: "OrchestrationDashboard is not defined"

**Symptoms:** Console shows `ReferenceError: OrchestrationDashboard is not defined`

**Cause:** JavaScript file not loading

**Solution:**
1. Verify file exists at: `/wp-content/plugins/mcp-ai-wpoos/addons/pro/assets/js/orchestration-dashboard.js`
2. Check file permissions (should be readable)
3. Clear any caching plugins
4. Check if parent menu `nvoos-pro-dashboard` exists in WordPress admin

### Issue 2: "wpMcpAiOrchestration is not defined"

**Symptoms:** Console shows configuration error or empty config object

**Cause:** `wp_localize_script()` not being called

**Solution:**
1. Verify PHP hook is firing: Check for "Is orchestration page=YES" in error log
2. Ensure `WP_MCP_AI_Orchestration_Dashboard` class is instantiated
3. Check if Pro addon is active: `class_exists('WP_MCP_AI_Orchestration_Dashboard')`

### Issue 3: AJAX Returns 403 Forbidden

**Symptoms:** Network tab shows 403 error for admin-ajax.php request

**Cause:** Nonce verification failing or permission check failing

**Solution:**
1. Check if user has `manage_options` capability
2. Verify nonce is being passed: Look in Network tab > Request payload
3. Try clearing WordPress cache
4. Check if WordPress security plugins are blocking the request

### Issue 4: Empty Data Arrays

**Symptoms:** AJAX succeeds but all arrays are empty (sessions: [], workflows: [])

**Cause:** No data in database/transients (This is normal for a fresh install!)

**Expected Behavior:** Dashboard should show "No active sessions" and "No workflows found" instead of "-"

**Solution:** This is actually normal! The "-" placeholders just need to be replaced with the actual empty state messages.

The JavaScript already has this logic:
- Sessions table shows: "No active sessions"
- Workflows table shows: "No workflows found"

If you're still seeing "-", the JavaScript isn't running the update functions.

### Issue 5: Assets Loading But Data Not Updating

**Symptoms:** 
- Console shows: "OrchestrationDashboard: Initializing..." 
- But no further messages
- Assets all load successfully (200 OK)

**Cause:** JavaScript execution stopped after initialization

**Solution:**
1. Check for JavaScript errors earlier in the console
2. Verify jQuery is loaded before orchestration script
3. Check if `.wp-mcp-ai-orchestration-dashboard` element exists on page
4. Verify dependencies: `wp-mcp-ai-orchestration-bundle` must load before dashboard script

---

## Verification Checklist

Use this checklist to verify the dashboard is working:

- [ ] WordPress debug mode enabled (`WP_DEBUG=true`)
- [ ] Browser console is open (F12 → Console tab)
- [ ] Navigated to: `/wp-admin/admin.php?page=mcp-ai-orchestration-pro`
- [ ] Console shows: "OrchestrationDashboard: Initializing..."
- [ ] Console shows: "Configuration loaded successfully"
- [ ] Console shows: "AJAX response received"
- [ ] Network tab shows all 3 assets loaded (200 OK)
- [ ] Network tab shows AJAX request to admin-ajax.php succeeded
- [ ] PHP error log shows: "Is orchestration page=YES"
- [ ] Dashboard displays actual data or proper "No data" messages

---

## Still Not Working?

If you've completed all steps above and the dashboard still shows "-" placeholders:

1. **Export Debug Information:**
   - Save browser console output
   - Save Network tab HAR file
   - Copy PHP error log entries related to orchestration
   - Note WordPress version, PHP version, and any active security plugins

2. **Check GitHub Issues:**
   - Search for similar issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
   - Include debug information in your issue report

3. **Temporary Workaround:**
   - Use the base orchestration dashboard at: `/wp-admin/admin.php?page=mcp-ai-orchestration`
   - The Pro dashboard has additional features but base dashboard provides core functionality

---

## After Fixing the Issue

Once the dashboard is working:

1. **Disable debug logging** (for production):
   - Remove/comment out `WP_DEBUG` in wp-config.php
   - OR set `WP_DEBUG_DISPLAY` to false

2. **Remove console.log statements** (optional):
   - The debug logging in JavaScript can be left in place
   - It only outputs to console, which users typically don't see
   - OR remove it for cleaner production code

3. **Test with sample data:**
   - Create a test workflow to verify the dashboard updates
   - Monitor a test session to see real-time updates
   - Verify auto-refresh works (every 5 seconds)

---

## Technical Details

### Asset Loading Order

1. jQuery (WordPress core)
2. `orchestration-bundle.min.js` (Contains: opossum, p-queue, autonomous orchestrator)
3. `orchestration-dashboard.js` (Dashboard UI logic)

Dependencies are correctly specified in `wp_enqueue_script()` calls.

### AJAX Flow

```
Page Load
↓
JavaScript Init → Check Config → Start Auto-Refresh
↓
AJAX Request (wp_mcp_ai_get_dashboard_data)
↓
PHP: WP_MCP_AI_Orchestration_Dashboard::ajax_get_dashboard_data()
↓
PHP: get_dashboard_data() → Queries DB/transients
↓
JSON Response
↓
JavaScript: updateDashboard(data)
↓
Updates DOM (overview cards, tables, activity feed)
↓
Repeat every 5 seconds
```

### Data Sources

- **Active Sessions:** Transients with prefix `mcp_ai_session_*`
- **Task Plans:** CPT `mcp_task_plan`
- **Workflows:** Transients with prefix `wp_mcp_ai_workflow_*`
- **Activity:** Static placeholder (future: real activity log)

### Hook Names

- **Admin menu hook:** `'admin_menu'` (priority 25)
- **Asset enqueue hook:** `'admin_enqueue_scripts'`
- **AJAX hooks:** 
  - `'wp_ajax_wp_mcp_ai_get_dashboard_data'`
  - `'wp_ajax_wp_mcp_ai_control_session'`
  - `'wp_ajax_wp_mcp_ai_trigger_workflow'`

---

## Additional Resources

- [WordPress AJAX Documentation](https://developer.wordpress.org/plugins/javascript/ajax/)
- [Browser Developer Tools Guide](https://developer.chrome.com/docs/devtools/)
- [WordPress Debug Log Guide](https://wordpress.org/support/article/debugging-in-wordpress/)
