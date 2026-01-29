# Orchestration Dashboard Workflow Actions

## Overview

The Orchestration Dashboard at `/wp-admin/admin.php?page=mcp-ai-orchestration-dashboard` now includes **Continue** and **Restart** actions for managing workflows created by the `create_agent_team` tool.

## Features Implemented

### 1. Workflow Tracking

All teams created via `create_agent_team` are automatically saved as workflows and appear on the dashboard with:

- **Workflow ID**: Unique identifier (format: `wf_team_xxxxx`)
- **Team ID**: Associated team identifier
- **Task Type**: Type of workflow (research, content, development, etc.)
- **State**: Current workflow state (initialized, running, completed, failed)
- **Progress**: Visual progress bar showing completed vs total tasks
- **Created/Updated timestamps**
- **Action buttons** based on workflow state

### 2. Workflow States

| State | Description | Available Actions |
|-------|-------------|-------------------|
| `initialized` | Team created, workflow not yet started | Continue |
| `running` | Workflow actively executing | (None - shows running indicator) |
| `completed` | All tasks completed successfully | Restart |
| `failed` | Workflow encountered errors | Continue, Restart |

### 3. Action Buttons

#### Continue Button
- **Appears when**: Workflow is in `initialized` or `failed` state
- **Purpose**: Starts or resumes workflow execution
- **Behavior**:
  - Calls `execute_workflow()` method via AJAX
  - Uses `WP_MCP_AI_Enhanced_Workflow_Coordinator`
  - Updates workflow state to `running`
  - Shows progress updates in real-time

#### Restart Button
- **Appears when**: Workflow is in `completed` or `failed` state
- **Purpose**: Resets workflow to initial state for re-execution
- **Behavior**:
  - Resets workflow state to `initialized`
  - Clears task completion status (except composition task)
  - Resets timestamps (started_at, completed_at)
  - Allows workflow to be continued again

## Usage Examples

### Example 1: Creating a Team and Viewing on Dashboard

```php
// 1. Create an agent team via AI assistant
$result = $tool->execute([
    'task_type' => 'content',
    'requirements' => [
        'expertise_needed' => ['writing', 'SEO'],
        'quality_level' => 'validated'
    ]
]);

// 2. Team is automatically saved as workflow
// - Creates transient: wp_mcp_ai_workflow_wf_team_abc123
// - State: initialized
// - Tasks: [composition (completed), planning (pending), execution (pending)]

// 3. Navigate to dashboard
// URL: /wp-admin/admin.php?page=mcp-ai-orchestration-dashboard

// 4. See workflow in "Recent Workflows" section with:
// - Workflow ID: wf_team_abc123
// - State: initialized (yellow badge)
// - Progress: 1/3 (33%)
// - Continue button available
```

### Example 2: Continuing a Workflow

```javascript
// User clicks "Continue" button on dashboard
// JavaScript sends AJAX request:
{
  action: 'wp_mcp_ai_execute_workflow',
  nonce: '...',
  workflow_id: 'wf_team_abc123'
}

// Server processes via ajax_execute_workflow():
// 1. Loads workflow from transient
// 2. Creates WP_MCP_AI_Enhanced_Workflow_Coordinator instance
// 3. Calls execute_workflow(workflow_id)
// 4. Workflow state changes to 'running'
// 5. Tasks execute sequentially or in parallel
// 6. Dashboard updates automatically show progress
```

### Example 3: Restarting a Failed Workflow

```php
// When workflow fails:
// - State: failed
// - Error message stored
// - Both Continue and Restart buttons appear

// User clicks "Restart":
// 1. Workflow state reset to 'initialized'
// 2. All non-composition tasks reset to 'pending'
// 3. Timestamps cleared (started_at, completed_at)
// 4. Error data cleared
// 5. User can now click "Continue" to retry
```

## Technical Implementation

### Backend (PHP)

#### New AJAX Handlers

```php
// In class-wp-mcp-ai-admin-orchestration-dashboard.php

/**
 * Execute/continue a workflow
 */
public function ajax_execute_workflow() {
    check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );
    
    $workflow_id = sanitize_text_field( wp_unslash( $_POST['workflow_id'] ) );
    
    $coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();
    $result = $coordinator->execute_workflow( $workflow_id );
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( ... );
    }
    
    wp_send_json_success( ... );
}

/**
 * Reset a workflow to initial state
 */
public function ajax_restart_workflow() {
    check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );
    
    $workflow_id = sanitize_text_field( wp_unslash( $_POST['workflow_id'] ) );
    $workflow_data = get_transient( 'wp_mcp_ai_workflow_' . $workflow_id );
    
    // Reset state and tasks
    $workflow_data['state'] = 'initialized';
    $workflow_data['started_at'] = null;
    $workflow_data['completed_at'] = null;
    
    foreach ( $workflow_data['tasks'] as &$task ) {
        if ( 'composition' !== $task['type'] ) {
            $task['status'] = 'pending';
        }
    }
    
    set_transient( 'wp_mcp_ai_workflow_' . $workflow_id, $workflow_data, 7 * DAY_IN_SECONDS );
    
    wp_send_json_success( ... );
}
```

### Frontend (JavaScript)

#### Enhanced Workflow Table Rendering

```javascript
// Renders table with action buttons based on state
renderWorkflows: function(workflows) {
    workflows.forEach(function(workflow) {
        // Add appropriate buttons
        if (workflow.state === 'initialized' || workflow.state === 'failed') {
            html += '<button class="workflow-action-continue" ...>Continue</button>';
        }
        
        if (workflow.state === 'completed' || workflow.state === 'failed') {
            html += '<button class="workflow-action-restart" ...>Restart</button>';
        }
        
        if (workflow.state === 'running') {
            html += '<span>Running...</span>';
        }
    });
}
```

#### Action Handlers

```javascript
// Continue workflow
handleContinueWorkflow: function(e) {
    const workflowId = $(e.currentTarget).data('workflow-id');
    
    $.ajax({
        url: wpMcpAiOrchestration.ajaxUrl,
        type: 'POST',
        data: {
            action: 'wp_mcp_ai_execute_workflow',
            nonce: wpMcpAiOrchestration.nonce,
            workflow_id: workflowId
        },
        success: function(response) {
            alert('Workflow started successfully!');
            OrchestrationDashboard.loadWorkflows(); // Refresh
        }
    });
}

// Restart workflow
handleRestartWorkflow: function(e) {
    const workflowId = $(e.currentTarget).data('workflow-id');
    
    $.ajax({
        url: wpMcpAiOrchestration.ajaxUrl,
        type: 'POST',
        data: {
            action: 'wp_mcp_ai_restart_workflow',
            nonce: wpMcpAiOrchestration.nonce,
            workflow_id: workflowId
        },
        success: function(response) {
            alert('Workflow reset successfully!');
            OrchestrationDashboard.loadWorkflows(); // Refresh
        }
    });
}
```

### Styling (CSS)

```css
/* Action buttons */
.workflow-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.workflow-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
}

/* State badges */
.workflow-status-badge.status-warning {
    background: #fcf3cd;
    color: #996800;
}

.workflow-status-badge.status-info {
    background: #e5f3fa;
    color: #2271b1;
}
```

## Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. AI Assistant calls create_agent_team tool               │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. WP_MCP_AI_Agent_Team_Orchestrator::compose_team()       │
│    - Creates team structure                                 │
│    - Stores team: wp_mcp_ai_team_xxxxx                     │
│    - Calls save_team_as_workflow()                         │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. save_team_as_workflow()                                  │
│    - Creates workflow structure                             │
│    - Sets state: initialized                                │
│    - Creates task list with composition + workflow steps    │
│    - Stores: wp_mcp_ai_workflow_wf_xxxxx (7 day expiry)    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Dashboard loads via ajax_get_recent_workflows()          │
│    - Queries all workflow transients                        │
│    - Returns workflow data to JavaScript                    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 5. JavaScript renders table with action buttons             │
│    - Shows Continue button for initialized/failed           │
│    - Shows Restart button for completed/failed              │
│    - Shows running indicator for running                    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 6. User clicks Continue                                     │
│    - AJAX: wp_mcp_ai_execute_workflow                      │
│    - WP_MCP_AI_Enhanced_Workflow_Coordinator::execute()    │
│    - State changes to running                               │
│    - Tasks execute                                          │
│    - State changes to completed/failed                      │
└─────────────────────────────────────────────────────────────┘
```

## Security

All AJAX actions include:
- **Nonce verification**: `check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' )`
- **Capability check**: `current_user_can( 'manage_options' )`
- **Input sanitization**: `sanitize_text_field()`, `sanitize_key()`

## Persistence

- **Storage**: WordPress transients in `wp_options` table
- **Duration**: 7 days (configurable via `set_transient()` expiry parameter)
- **Cleanup**: WordPress automatically removes expired transients
- **Keys**: Prefixed with `wp_mcp_ai_workflow_` for easy identification

## Testing

### Manual Testing Steps

1. **Create a team**:
   ```php
   // Via chat or REST API
   create_agent_team with task_type: 'content'
   ```

2. **Verify dashboard display**:
   - Navigate to: `/wp-admin/admin.php?page=mcp-ai-orchestration-dashboard`
   - Verify workflow appears in "Recent Workflows"
   - Check state is "initialized"
   - Verify Continue button is visible

3. **Test Continue**:
   - Click Continue button
   - Verify confirmation dialog
   - Verify AJAX call succeeds
   - Check workflow state changes to "running"
   - Verify button disappears or changes

4. **Test Restart**:
   - Wait for workflow to complete
   - Click Restart button
   - Verify confirmation dialog
   - Verify workflow resets to "initialized"
   - Verify Continue button reappears

### Database Verification

```sql
-- View all workflow transients
SELECT option_name, option_value 
FROM wp_options 
WHERE option_name LIKE '_transient_wp_mcp_ai_workflow_%'
ORDER BY option_id DESC;

-- Check specific workflow
SELECT option_value 
FROM wp_options 
WHERE option_name = '_transient_wp_mcp_ai_workflow_wf_team_abc123';
```

## Troubleshooting

### Workflow Not Appearing

1. Check if team was created successfully
2. Verify transient exists in database
3. Check browser console for JavaScript errors
4. Verify AJAX endpoint is accessible
5. Check WordPress error logs

### Continue Button Not Working

1. Open browser developer tools
2. Check Network tab for AJAX response
3. Verify nonce is valid
4. Check user has `manage_options` capability
5. Verify `WP_MCP_AI_Enhanced_Workflow_Coordinator` class exists

### Workflow Stuck in Running State

1. Check if workflow execution encountered an error
2. Look for PHP errors in WordPress debug log
3. Manually reset workflow state via database:
   ```php
   $workflow = get_transient( 'wp_mcp_ai_workflow_wf_xxx' );
   $workflow['state'] = 'initialized';
   set_transient( 'wp_mcp_ai_workflow_wf_xxx', $workflow, 7 * DAY_IN_SECONDS );
   ```

## Future Enhancements

Potential improvements for future versions:

1. **Workflow Details Modal**: Show detailed task breakdown
2. **Live Progress Updates**: WebSocket or Server-Sent Events for real-time updates
3. **Workflow Logs**: Display execution logs and errors
4. **Bulk Actions**: Continue/restart multiple workflows at once
5. **Filtering**: Filter workflows by state, type, date
6. **Pagination**: Handle large numbers of workflows
7. **Export**: Export workflow data as JSON or CSV
8. **Notifications**: Email/push notifications on workflow completion
9. **Workflow Templates**: Save and reuse workflow configurations
10. **Metrics**: Track workflow performance and success rates

## Conclusion

The Orchestration Dashboard now provides full lifecycle management for multi-agent workflows:

✅ **Automatic Tracking**: All created teams appear as workflows
✅ **Visual Feedback**: Color-coded states and progress bars
✅ **Action Controls**: Continue and Restart buttons for workflow management
✅ **Real-time Updates**: AJAX-powered dynamic updates
✅ **Secure**: Nonce and capability checks on all actions
✅ **Persistent**: 7-day retention for review and debugging

Users can now easily monitor, continue, and restart their agent team workflows from a centralized dashboard interface.
