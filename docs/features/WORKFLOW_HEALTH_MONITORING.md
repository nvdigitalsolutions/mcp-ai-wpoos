# Workflow Health Monitoring and Dashboard Triggering

## Overview

Team workflows created via `create_agent_team` are now monitored for health and can be manually triggered from the Orchestration Dashboard if they get stuck in the "initialized" state.

## Problem Solved

Previously, workflows created by the `create_agent_team` tool would remain in "initialized" state indefinitely if not explicitly executed. This was especially problematic for WordPress plugins where cron jobs or async processing might delay or never trigger the workflow.

## Features Added

### 1. Health Check Mechanism

Workflows are now monitored for "stale" status:
- **Timeout**: 5 minutes (300 seconds)
- **Status**: Workflows initialized for >5 minutes are flagged as "stale"
- **Recommendations**: System provides guidance on how to start workflows

### 2. Check Workflow Health Tool

A new AI tool `check_workflow_health` allows assistants to diagnose workflow issues:

```json
{
  "tool": "check_workflow_health",
  "arguments": {
    "workflow_id": "wf_team_abc123" // optional, checks all if omitted
  }
}
```

**Response includes:**
- Workflow state (initialized, running, completed, failed)
- Age in seconds and minutes
- Health status (healthy, pending, warning, error)
- Warnings for stale workflows
- Recommendations for fixing issues

### 3. Dashboard Integration

The Orchestration Dashboard now includes:

#### Workflow Listing Table
- Shows all team workflows with their current state
- Displays workflow ID, team ID, task type, state, age, task progress
- Highlights stale workflows with yellow background and orange border
- Auto-refreshes every 5 seconds

#### Start Workflow Button
- Visible for workflows in "initialized" state
- More prominent for stale workflows (highlighted with animation)
- Confirms before starting
- Provides immediate feedback on success/failure

## Usage

### For AI Assistants

**Check workflow health:**
```javascript
// Check specific workflow
{
  "tool": "check_workflow_health",
  "arguments": {
    "workflow_id": "wf_team_9d701ac2-709b-4805-940f-c7b14c65605a"
  }
}

// Check all workflows
{
  "tool": "check_workflow_health",
  "arguments": {}
}
```

**Expected response for stale workflow:**
```json
{
  "success": true,
  "health": {
    "workflow_id": "wf_team_abc123",
    "state": "initialized",
    "age_minutes": 6.7,
    "status": "warning",
    "warnings": [
      "Workflow has been initialized for 6.7 minutes without starting."
    ],
    "recommendations": [
      "Call execute_workflow() to start the workflow, or use delegate_to_agent to assign tasks to team members.",
      "For WordPress plugins, ensure wp-cron is running: wp cron event run --due-now"
    ]
  }
}
```

### For Users (Dashboard)

1. Navigate to **NV oOS → Orchestration** in WordPress admin
2. Scroll to **Team Workflows** section
3. Stale workflows are highlighted in yellow with "STALE" badge
4. Click **"🚀 Start Workflow"** button to manually trigger execution
5. Confirm the action when prompted
6. Workflow will transition from "initialized" to "running" state

## Technical Implementation

### Health Check Constants

```php
// Enhanced Workflow Coordinator
const INITIALIZED_TIMEOUT = 300; // 5 minutes
```

### Key Methods

**WP_MCP_AI_Enhanced_Workflow_Coordinator:**
- `check_stale_initialized_workflows()` - Returns list of stale workflows
- `get_workflows_health()` - Comprehensive health status for all workflows

**WP_MCP_AI_Agent_Team_Orchestrator:**
- `check_workflow_health($workflow_id)` - Check specific workflow
- `get_workflow($workflow_id)` - Retrieve workflow data

**WP_MCP_AI_Orchestration_Dashboard:**
- `get_team_workflows()` - Fetch workflows for dashboard display
- `ajax_trigger_workflow()` - Handle workflow start requests from UI

### Storage

Workflows are stored as WordPress transients:
- **Key**: `wp_mcp_ai_workflow_{workflow_id}`
- **TTL**: 7 days
- **Structure**: Array with workflow_id, team_id, state, tasks, created_at, etc.

## WordPress Cron Integration

For automatic workflow execution (future enhancement), consider:

```php
// Schedule workflow health check
add_action('init', function() {
    if (!wp_next_scheduled('wp_mcp_ai_check_workflow_health')) {
        wp_schedule_event(time(), 'hourly', 'wp_mcp_ai_check_workflow_health');
    }
});

// Hook handler
add_action('wp_mcp_ai_check_workflow_health', function() {
    $coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();
    $health = $coordinator->get_workflows_health();
    
    // Auto-start stale workflows or send notifications
    if ($health['status'] === 'warning') {
        // Handle stale workflows
    }
});
```

## Best Practices

1. **Always check workflow health** after creating a team with `create_agent_team`
2. **Monitor the dashboard** for workflows stuck in initialized state
3. **Use delegate_to_agent** to start workflow execution if not using `execute_workflow` directly
4. **Ensure wp-cron is working** - test with: `wp cron event run --due-now`
5. **Set up proper cron** in production (don't rely on wp-cron alone)

## Troubleshooting

**Workflow stuck in initialized for >5 minutes:**
- ✅ Use dashboard "Start Workflow" button
- ✅ Call `execute_workflow` tool with workflow_id
- ✅ Use `delegate_to_agent` to assign tasks to team members
- ✅ Check WordPress cron is running: `wp cron event list`

**Workflow not appearing in dashboard:**
- Workflows are stored as transients (7-day expiration)
- Check database: `SELECT * FROM wp_options WHERE option_name LIKE '%workflow%'`
- Verify workflow was saved: check `save_team_as_workflow()` was called

**Workflow fails to start from dashboard:**
- Check browser console for JavaScript errors
- Verify user has `manage_options` capability
- Check PHP error logs for server-side issues
- Ensure Enhanced Workflow Coordinator class exists

## Related Files

- `includes/services/class-wp-mcp-ai-enhanced-workflow-coordinator.php`
- `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php`
- `includes/tools/class-wp-mcp-ai-tool-check-workflow-health.php`
- `includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`
- `addons/pro/assets/js/orchestration-dashboard.js`
- `addons/pro/assets/css/orchestration-dashboard.css`

## Testing

Run the workflow health check tests:

```bash
vendor/bin/phpunit tests/test-workflow-health-check.php
```

Tests cover:
- Stale workflow detection
- Recent workflow handling  
- Health status for all workflows
- Tool execution and capability flags
