# Pro Dashboard Monitoring Page Enhancement

## Overview

The monitoring page at `/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=monitoring` has been significantly enhanced with real-time monitoring capabilities, comprehensive event logging, and interactive features.

## New Features

### 1. Real-time Status Metrics

The monitoring page now displays four key metrics at the top:

- **Security Status**: Overall system security status (Operational/Warning/Error)
- **Critical Events**: Count of critical events in the last 24 hours
- **Total Events**: Total number of monitoring events in the last 24 hours
- **System Uptime**: Server/WordPress installation uptime

Each metric card includes:
- Icon for visual identification
- Large numeric value
- Descriptive label
- Color-coded status indicators

### 2. System Health Monitoring

A comprehensive system health card displays:

- **Database Connection**: Real-time database connectivity status
- **PHP Version**: Current PHP version with compatibility check
- **WordPress Version**: Current WordPress version with update check
- **Memory Usage**: Current memory usage vs. limit

Each indicator shows:
- Status icon (green for healthy, orange for warning, red for error)
- Indicator name
- Current value
- Health status

### 3. Enhanced Event Log

The event log table provides:

- **Filterable columns**: Severity, Type, Message, Timestamp, Actions
- **Color-coded severity badges**: Critical (red), High (orange), Medium (yellow), Low (blue), Info (gray)
- **Event type icons**: Visual categorization of events
- **Timestamp**: Human-readable time difference
- **Actions**: Dismiss button for each event

### 4. Interactive Controls

#### Auto-refresh Toggle
- Automatically refreshes monitoring data every 30 seconds
- Can be toggled on/off
- Shows last update timestamp

#### Manual Refresh
- Button to manually trigger data refresh
- Visual feedback during refresh

#### Export Events
- Download visible events as CSV file
- Includes all filtered events
- Filename includes timestamp

#### Clear Dismissed Events
- Removes all dismissed events from view
- Confirmation dialog for safety

### 5. Event Filtering

Comprehensive filtering options:

- **Event Type**: Authentication, File Integrity, Configuration, Plugin Updates, Security Alerts
- **Severity**: Critical, High, Medium, Low, Info
- **Timeframe**: Last 24 Hours, 7 Days, 30 Days, 90 Days
- **Search**: Real-time text search across all event data

### 6. Event Timeline Chart

- 24-hour event history visualization using Chart.js
- Displays events per hour as a line chart
- Responsive design adapts to container size
- Smooth transitions and hover effects

### 7. Monitored Resources

Displays active monitoring categories with event counts:

- File Integrity Monitoring
- Authentication Events
- Plugin & Theme Updates
- Configuration Changes
- Security Alerts

### 8. Event Details Modal

Click "View Details" on any event to see:

- Full event message
- Severity level
- Event type
- Complete timestamp
- Additional context (if available)

## Technical Implementation

### PHP Methods Added

#### `get_monitoring_event_stats()`
Returns aggregated statistics for monitoring events in the last 24 hours.

```php
private function get_monitoring_event_stats()
```

Returns:
```php
array(
    'total_events'          => int,
    'critical_count'        => int,
    'file_integrity_events' => int,
    'auth_events'           => int,
    'update_events'         => int,
    'config_events'         => int,
    'security_events'       => int,
)
```

#### `get_system_health_status()`
Returns current system health indicators and overall status.

```php
private function get_system_health_status()
```

Returns:
```php
array(
    'overall_status' => 'operational'|'warning'|'error',
    'uptime_display' => string,
    'indicators'     => array(
        array(
            'name'   => string,
            'value'  => string,
            'status' => 'healthy'|'warning'|'error',
            'icon'   => string,
        ),
    ),
)
```

#### `get_system_uptime()`
Returns a human-readable system uptime string.

```php
private function get_system_uptime()
```

#### `render_monitoring_event_table()`
Renders a comprehensive, filterable table of monitoring events.

```php
private function render_monitoring_event_table( $events )
```

#### `enrich_monitoring_events()`
Adds type labels, severity, icons, and formatted timestamps to events.

```php
private function enrich_monitoring_events( $events )
```

### JavaScript Methods Added

#### `initMonitoringEnhancements()`
Initializes all enhanced monitoring features including auto-refresh, export, dismiss, etc.

#### `refreshMonitoringData()`
Refreshes monitoring data via AJAX (placeholder for future implementation).

#### `exportMonitoringEvents()`
Exports visible events to CSV file for download.

#### `dismissEvent(eventId, $button)`
Dismisses an event with fade-out animation.

#### `showEventDetails(eventId)`
Displays event details in a modal popup.

#### `initEventTimelineChart()`
Initializes the Chart.js event timeline visualization.

### CSS Classes Added

- `.wp-mcp-ai-monitoring-metrics`: Metrics grid container
- `.wp-mcp-ai-monitoring-options`: Options bar container
- `.wp-mcp-ai-health-indicators`: Health indicators grid
- `.wp-mcp-ai-severity-badge`: Severity badge styles
- `.wp-mcp-ai-event-table`: Event table styles
- `.wp-mcp-ai-modal`: Modal dialog styles
- `.wp-mcp-ai-empty-state`: Empty state display

## Data Sources

### Current Implementation

Currently, the monitoring page uses:

- `wp_mcp_ai_recent_activity` option: Recent activity events
- `wp_mcp_ai_recent_errors` option: Recent error events
- WordPress Site Health API: For system health indicators
- WordPress database: For connection status
- PHP/WordPress globals: For version and memory information

### Future Enhancements

Planned data source integrations:

- WordPress activity logs (user logins, logouts, profile changes)
- Plugin logger events (WP_MCP_AI_Logger integration)
- Failed login tracking (authentication monitoring)
- File change monitoring (file integrity checks)
- Performance metrics (page load times, API response times)

## Filters and Hooks

### Filters

#### `wp_mcp_ai_monitoring_event_stats`
Filter monitoring event statistics.

```php
apply_filters( 'wp_mcp_ai_monitoring_event_stats', array $stats )
```

#### `wp_mcp_ai_system_health_status`
Filter system health status data.

```php
apply_filters( 'wp_mcp_ai_system_health_status', array $health )
```

## Responsive Design

The monitoring page is fully responsive:

- **Desktop (>1200px)**: Full 4-column metrics grid
- **Tablet (768-1200px)**: 2-column metrics grid
- **Mobile (<768px)**: Single column layout, simplified table

Mobile optimizations:
- Hides less critical table columns
- Stacks filters vertically
- Adjusts font sizes for readability
- Full-width modal dialogs

## Browser Compatibility

Tested and working on:

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## Performance Considerations

- Event table pagination prevents large DOM trees
- Auto-refresh uses efficient timestamp updates
- Chart data is limited to 24 hours
- CSS animations use GPU-accelerated transforms
- JavaScript uses event delegation for dynamic content

## Security

All user input is properly sanitized:

- Event data is escaped with `esc_html()`, `esc_attr()`
- URLs are validated with `esc_url()`
- AJAX requests use WordPress nonces
- Capability checks ensure admin-only access

## Testing

Run the test suite:

```bash
composer test -- --filter=test_pro_dashboard_monitoring
```

Or run all pro dashboard tests:

```bash
composer test -- --filter=Pro_Dashboard
```

## Future Roadmap

1. **REST API Integration**
   - Add endpoints for real-time data fetching
   - Implement WebSocket support for live updates

2. **Advanced Filtering**
   - Date range picker
   - Custom severity thresholds
   - Event grouping and aggregation

3. **Notifications**
   - Email alerts for critical events
   - Browser push notifications
   - Slack/Discord webhooks

4. **Analytics**
   - Event trends over time
   - Anomaly detection
   - Comparative analysis

5. **Export Options**
   - JSON export format
   - PDF report generation
   - Scheduled exports

## Support

For issues or questions about the monitoring page:

1. Check the [documentation](docs/)
2. Review [troubleshooting guide](docs/deployment-troubleshooting.md)
3. Open an [issue](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)

## Changelog

### Version 1.5.4 - 2024-01-07

- Added real-time status metrics
- Added system health monitoring
- Added comprehensive event log table
- Added event severity badges and categorization
- Added export functionality (CSV)
- Added event dismiss and detail view modal
- Added auto-refresh toggle
- Added event timeline chart
- Added responsive design for mobile/tablet
- Added helper methods for event stats and system health

---

## GSD × BMAD Development Session Monitoring

Configure the Pro Dashboard monitoring tab to track active GSD × BMAD development sessions.
This satisfies Phase 6 of the GSD × BMAD methodology (Automation & Metrics).

**URL:** `/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=monitoring`

### Recommended Configuration for Dev Sessions

During active GSD × BMAD development (Phases 5–8), configure the monitoring tab as follows:

#### 1. Set Timeframe to "Last 24 Hours"

Keep the **Timeframe** filter set to **Last 24 Hours** during active development. This surfaces
tool execution errors and workflow health issues that were introduced by the current story.

#### 2. Enable Auto-Refresh

Turn on the **Auto-refresh Toggle** (30-second interval) during Phase 5 (Implementation) and
Phase 6 (Validation) sub-sessions. This gives the QA Engineer near-real-time visibility into
any errors introduced by each atomic story commit.

#### 3. Filter by Severity — Critical and High Only

During Phase 8 (Monitoring, 48–72 hours post-release), set the **Severity** filter to show
only **Critical** and **High** events. This reduces noise and focuses on actionable issues
that require escalation to the Orchestrator.

#### 4. Monitor These Event Types

| Event Type | Relevant Phase | What to Watch For |
|-----------|---------------|-------------------|
| Security Alerts | 5, 6, 8 | Unexpected capability violations, nonce failures |
| Configuration Changes | 5, 6 | Unintended option updates during implementation |
| Authentication Events | 8 | Post-release auth failures from new tools/endpoints |
| File Integrity | 7, 8 | Unexpected file changes after release deployment |
| Plugin & Theme Updates | 7 | Activation/deactivation events during release |

#### 5. Export Events After Phase 8

At the end of the 72-hour Phase 8 monitoring window, use **Export Events (CSV)** to produce a
post-release monitoring report. Attach it to the feature's `.context/archive/` entry when
archiving the feature context in Phase 9 (Retrospective).

### Metrics to Record in `docs/GSD-BMAD-METRICS-BASELINE.md`

After each feature release, capture these values from the monitoring dashboard:

| Metric | Dashboard Location | Record In |
|--------|-------------------|----------|
| Critical events (48h post-deploy) | Status Metrics → Critical Events | Metrics Baseline — Cycle N |
| Tool execution errors | Event Log → Type: Security Alerts | Metrics Baseline — Defect Rate |
| Memory usage at deploy time | System Health → Memory Usage | Metrics Baseline — Token Budget |

### Phase 8 Automation

The post-deploy health check workflow (`.github/workflows/post-deploy-health.yml`) runs
automatically after each versioned release tag (`v*.*.*`) and:

1. Validates the release artifact integrity
2. Calls the `check_workflow_health` REST endpoint (if `WP_HEALTH_CHECK_URL` is configured)
3. Emits the Phase 8 monitoring checklist to the GitHub Actions workflow summary

Review the workflow summary in GitHub Actions → **Phase 8 — Post-Deploy Health Check** after
each release to verify all automated checks passed.

### Quick Reference: Phase 8 Tool Calls

Run these tool calls in the Orchestrator (Scrum Master) AI session during Phase 8:

```
check_workflow_health()                    ← overall workflow health
get_session_status()                       ← confirm active sessions completed
analyze_data_patterns(timeframe: "72h")    ← detect usage spikes or anomalies
```

If `check_workflow_health` returns `status: error`, escalate immediately — do not wait for
the 72-hour window to close.

