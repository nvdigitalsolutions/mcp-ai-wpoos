# System Status Implementation - Technical Documentation

## Overview

The Orchestration Dashboard includes real-time system status monitoring for:
- **Cron Jobs**: Active, pending, and failed job counts
- **Async Operations**: Health status, stuck jobs, long-running tasks
- **System Health**: Overall orchestration health metrics
- **SSE Streaming**: Server-Sent Events connectivity status

## Architecture

### Data Flow

```
Services Layer (PHP)
├── WP_MCP_AI_Cron_Status_Service
├── WP_MCP_AI_Async_Health_Monitor
├── WP_MCP_AI_Orchestration_Health_Service
└── WP_MCP_AI_SSE_Stream
        ↓
Dashboard Controller (PHP)
└── WP_MCP_AI_Orchestration_Dashboard::get_system_status()
        ↓
AJAX Endpoint (PHP)
└── wp_ajax_wp_mcp_ai_get_dashboard_data
        ↓
JavaScript (jQuery)
└── OrchestrationDashboard.updateSystemStatus()
        ↓
DOM Elements (HTML)
└── [data-system-status="..."]
```

### Service Loading

Services are loaded in `/includes/services-init.php`:

```php
// Load async health monitor
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-async-health-monitor.php';

// Load cron status service (for admin dashboard monitoring)
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-cron-status-service.php';

// Load orchestration health service
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-health-service.php';
```

## PHP Implementation

### get_system_status() Method

Location: `/addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`

```php
private function get_system_status() {
    $status = array(
        'cron'   => array(),
        'async'  => array(),
        'sse'    => array(),
        'health' => array(),
    );

    // Get cron job status if service is available
    if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
        try {
            $cron_service = new WP_MCP_AI_Cron_Status_Service();
            $cron_summary = $cron_service->get_status_summary( 0, 5 );
            
            $status['cron'] = array(
                'active'    => 0,
                'pending'   => 0,
                'failed'    => 0,
                // ... count jobs by status
            );
        } catch ( Exception $e ) {
            $status['cron']['error'] = $e->getMessage();
        }
    }

    // Similar patterns for async, health, and sse...
    
    return $status;
}
```

### Graceful Degradation

The implementation uses defensive programming:

1. **Class existence checks**: `if ( class_exists( '...' ) )`
2. **Try-catch blocks**: Catch and log exceptions without breaking
3. **Default values**: Always return structured arrays even if empty
4. **Error tracking**: Store error messages in `['error']` key

## JavaScript Implementation

### updateSystemStatus() Method

Location: `/addons/pro/assets/js/orchestration-dashboard.js`

```javascript
updateSystemStatus: function(systemStatus) {
    // Update cron status
    if (systemStatus.cron) {
        $('[data-system-status="cron_active"]').text(systemStatus.cron.active || 0);
        $('[data-system-status="cron_pending"]').text(systemStatus.cron.pending || 0);
        $('[data-system-status="cron_failed"]').text(systemStatus.cron.failed || 0);
    }

    // Update async status
    if (systemStatus.async) {
        const asyncStatus = systemStatus.async.status || 'unknown';
        $('[data-system-status="async_status"]')
            .text(asyncStatus)
            .removeClass('status-healthy status-warning status-error')
            .addClass('status-' + asyncStatus);
        $('[data-system-status="async_stuck_jobs"]').text(systemStatus.async.stuck_jobs || 0);
        $('[data-system-status="async_long_running"]').text(systemStatus.async.long_running || 0);
    }

    // Similar patterns for health and sse...
}
```

### Auto-Refresh

Dashboard data refreshes every 5 seconds:

```javascript
startAutoRefresh: function() {
    const interval = this.config.refreshInterval || 5000;
    this.refreshInterval = setInterval(() => {
        this.loadDashboardData();
    }, interval);
}
```

## HTML Structure

Location: `/addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php` (lines 188-256)

```html
<div class="system-status-grid">
    <!-- Cron Jobs Status -->
    <div class="status-card">
        <h3><span class="dashicons dashicons-clock"></span> Cron Jobs</h3>
        <div class="status-metrics">
            <div class="metric">
                <span class="label">Active:</span>
                <span class="value" data-system-status="cron_active">-</span>
            </div>
            <div class="metric">
                <span class="label">Pending:</span>
                <span class="value" data-system-status="cron_pending">-</span>
            </div>
            <div class="metric">
                <span class="label">Failed:</span>
                <span class="value error" data-system-status="cron_failed">-</span>
            </div>
        </div>
    </div>
    
    <!-- Similar structure for Async Operations, System Health, SSE -->
</div>
```

### Data Attribute Pattern

All status values use the `data-system-status` attribute:

- `data-system-status="cron_active"` - Number of active cron jobs
- `data-system-status="cron_pending"` - Number of pending jobs
- `data-system-status="cron_failed"` - Number of failed jobs
- `data-system-status="async_status"` - Async health status (healthy/warning/error)
- `data-system-status="async_stuck_jobs"` - Number of stuck jobs
- `data-system-status="async_long_running"` - Number of long-running jobs
- `data-system-status="health_status"` - Overall health status
- `data-system-status="health_label"` - Health status label
- `data-system-status="sse_available"` - SSE availability (Yes/No)
- `data-system-status="sse_endpoint"` - SSE endpoint URL

## Service Classes

### WP_MCP_AI_Cron_Status_Service

**Location**: `/includes/services/class-wp-mcp-ai-cron-status-service.php`

**Purpose**: Provides lightweight cron job status for UI

**Key Method**: `get_status_summary( $user_id, $limit, $assistant_id )`

**Returns**:
```php
array(
    array(
        'job_id' => 'abc123',
        'title'  => 'Job Title',
        'status' => 'active|pending|completed|failed',
        // ...
    )
)
```

### WP_MCP_AI_Async_Health_Monitor

**Location**: `/includes/services/class-wp-mcp-ai-async-health-monitor.php`

**Purpose**: Monitors async process health

**Key Method**: `check_async_health()`

**Returns**:
```php
array(
    'status'         => 'healthy|warning|error',
    'stuck_jobs'     => 0,
    'long_running'   => 0,
    'pending_jobs'   => 0,
    'failed_jobs'    => 0,
    'cron_scheduled' => true|false,
    'issues'         => array(),
)
```

### WP_MCP_AI_Orchestration_Health_Service

**Location**: `/includes/services/class-wp-mcp-ai-orchestration-health-service.php`

**Purpose**: Calculates overall system health

**Key Method**: `get_health_status( $force_refresh )`

**Returns**:
```php
array(
    'status'  => 'healthy|good|fair|poor',
    'label'   => 'Healthy|Good|Fair|Poor',
    'icon'    => '💚|💛|🧡|❤️',
    'metrics' => array(),
)
```

## Testing

### Unit Tests

**Location**: `/tests/test-orchestration-dashboard-system-status.php`

Tests verify:
- Dashboard data includes `system_status` key
- All sections present (cron, async, health, sse)
- Proper data structure for each section
- Graceful degradation when services unavailable
- AJAX response includes system status

### Manual Testing

1. **Enable Debug Mode** in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

2. **Navigate to Dashboard**:
   - Go to WordPress admin
   - Open "Orchestration Monitor" page
   - Press F12 to open browser console

3. **Verify Console Output**:
   ```
   OrchestrationDashboard: Initializing...
   OrchestrationDashboard: Configuration loaded successfully
   OrchestrationDashboard: Loading dashboard data...
   OrchestrationDashboard: AJAX response received
   OrchestrationDashboard: Updating dashboard with data
   ```

4. **Check DOM Elements**:
   - System status values should show numbers (not "-")
   - Status badges should have color classes
   - Values should update every 5 seconds

### Verification Script

**Location**: `/bin/verify-system-status-implementation.sh`

Run from terminal:
```bash
cd /path/to/plugin
./bin/verify-system-status-implementation.sh
```

Checks:
- ✓ Service classes loaded
- ✓ Dashboard class available
- ✓ `get_system_status()` returns proper structure
- ✓ All required keys present
- ✓ Services populate data correctly

## Troubleshooting

### Issue: Values Show "-"

**Cause**: Services not loaded or AJAX not firing

**Solution**:
1. Check browser console for errors
2. Verify `wp_localize_script()` called correctly
3. Check Network tab for AJAX request/response
4. Enable `WP_DEBUG` and check error log

### Issue: "Configuration not loaded properly"

**Cause**: Scripts not enqueued correctly

**Solution**:
1. Verify Pro addon is active
2. Check parent menu is registered
3. Verify hook name matches: `nvoos-pro-dashboard_page_mcp-ai-orchestration-pro`

### Issue: Empty Data (All Zeros)

**Status**: This is expected!

**Explanation**: 
- Fresh installs have no active sessions/jobs
- System correctly shows "0" for all metrics
- Create test workflows to see populated data

### Issue: Service Class Not Found

**Cause**: Service not loaded in `services-init.php`

**Solution**:
1. Verify `require_once` statement exists
2. Check file path is correct
3. Ensure no PHP errors in service file

## Performance Considerations

### Caching

- Health status cached for 1 minute via `WP_MCP_AI_Cache_Helper`
- Reduces database queries on dashboard
- Force refresh available via parameter

### Database Queries

- Cron status queries limited to 50 jobs
- Workflows limited to 50 results
- Only queries necessary data

### Auto-Refresh

- 5-second intervals balance freshness vs load
- Can be adjusted via `refreshInterval` config
- AJAX uses nonce for security

## Security

### Nonce Verification

```php
check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );
```

### Capability Check

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array( 'message' => 'Unauthorized' ) );
}
```

### Input Sanitization

All `$_GET` and `$_POST` values sanitized:
```php
sanitize_text_field( wp_unslash( $_POST['session_id'] ) )
```

## Future Enhancements

Potential improvements:

1. **WebSockets**: Replace AJAX polling with WebSockets for real-time updates
2. **Detailed Metrics**: Expand health status to include memory, CPU, disk
3. **Historical Data**: Track metrics over time with graphs
4. **Alerts**: Email/SMS notifications for critical issues
5. **Filtering**: Filter by date range, user, assistant
6. **Export**: Download reports as CSV/PDF

## Related Documentation

- [Orchestration Dashboard Debug Guide](../../docs/troubleshooting/ORCHESTRATION-DASHBOARD-DEBUG.md)
- [Orchestration Dashboard Fix Summary](../../docs/troubleshooting/ORCHESTRATION-DASHBOARD-FIX-SUMMARY.md)
- [Services Architecture](../../docs/architecture/services.md)
- [AJAX Best Practices](../../docs/development/ajax.md)

## Change Log

### 2026-01-29
- ✅ Added `WP_MCP_AI_Cron_Status_Service` to services-init.php
- ✅ Created comprehensive test suite
- ✅ Created verification script
- ✅ Documented complete implementation

### Prior Work
- Implementation of `get_system_status()` method
- JavaScript `updateSystemStatus()` function
- HTML structure with `data-system-status` attributes
- Service classes (Cron, Async, Health, SSE)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs
