# Workflow 403 Error Fix - Implementation Details

## Problem Description

The Site Health Check workflow was failing with HTTP 403 errors when executed from the Slash Commands Dashboard by users with `edit_posts` capability (e.g., Editors).

### Root Cause

The workflow execution had a permission mismatch:
- The workflow command itself only required `edit_posts` capability
- But the workflow steps (like `/optimize-perf`) required `manage_options` capability
- The capability check happened **during execution**, not **before execution**
- This caused the workflow to fail mid-execution with a cryptic 403 error

### Before the Fix

```
User (Editor) → Execute site-health workflow
              → Workflow validates: user has edit_posts ✓
              → Start executing steps:
                  Step 1: optimize-perf
                         → Check capability: manage_options ✗
                         → ERROR 403: Forbidden
              → Workflow fails mid-execution
```

## Solution Implemented

### Key Changes

Added pre-execution capability validation in `class-wp-mcp-ai-slash-command-workflow.php`:

1. **`validate_workflow_capabilities()` method**: Checks if user has ALL required capabilities before executing ANY steps

2. **`get_task_required_capability()` method**: Maps task names to their required WordPress capabilities

3. **Pre-execution validation**: Called before `execute_workflow()` to prevent partial execution

### After the Fix

```
User (Editor) → Execute site-health workflow
              → Workflow validates: user has edit_posts ✓
              → Pre-validate ALL step capabilities:
                  - optimize-perf requires manage_options ✗
              → ERROR: Clear message listing missing capabilities
              → No steps executed (fail fast)
```

## Code Changes

### File: `includes/slash-commands/commands/class-wp-mcp-ai-slash-command-workflow.php`

#### 1. Added Pre-execution Validation (Line 82-86)

```php
// Validate user has required capabilities for all workflow steps.
$capability_check = $this->validate_workflow_capabilities( $workflow, $user_id );
if ( is_wp_error( $capability_check ) ) {
    return $capability_check;
}
```

#### 2. Added Capability Validation Method (Lines 328-370)

```php
private function validate_workflow_capabilities( $workflow, $user_id ) {
    if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
        return true;
    }

    $missing_capabilities = array();

    foreach ( $workflow['steps'] as $step ) {
        if ( empty( $step['task'] ) ) {
            continue;
        }

        $required_capability = $this->get_task_required_capability( $step['task'] );

        if ( $required_capability && ! user_can( $user_id, $required_capability ) ) {
            $missing_capabilities[ $step['task'] ] = $required_capability;
        }
    }

    if ( ! empty( $missing_capabilities ) ) {
        $task_list = array();
        foreach ( $missing_capabilities as $task => $capability ) {
            $task_list[] = sprintf( '%s (requires %s)', $task, $capability );
        }

        return new WP_Error(
            'insufficient_workflow_permissions',
            sprintf(
                __( 'You do not have sufficient permissions to execute this workflow. The following tasks require higher privileges: %s', 'mcp-ai-wpoos' ),
                implode( ', ', $task_list )
            )
        );
    }

    return true;
}
```

#### 3. Added Capability Mapping Method (Lines 372-399)

```php
private function get_task_required_capability( $task ) {
    $task_capabilities = array(
        'next-task'         => 'edit_posts',
        'check_drafts'      => 'edit_posts',
        'audit_drafts'      => 'edit_posts',
        'ship'              => 'publish_posts',
        'publish_post'      => 'publish_posts',
        'clean-content'     => 'edit_posts',
        'check_content'     => 'edit_posts',
        'optimize-perf'     => 'manage_options',
        'check_performance' => 'manage_options',
        'sync-docs'         => 'edit_posts',
        'check_docs'        => 'edit_posts',
        'notify_admin'      => 'edit_posts',
        'send_email'        => 'edit_posts',
        'wait'              => null,
        'sleep'             => null,
    );

    return isset( $task_capabilities[ $task ] ) ? $task_capabilities[ $task ] : null;
}
```

## Capability Requirements by Workflow

### site-health
- **Required**: `manage_options` (Administrator only)
- **Reason**: Uses `optimize-perf` task
- **Tasks**:
  - optimize-perf (manage_options)
  - clean-content (edit_posts)
  - sync-docs (edit_posts)

### daily-review
- **Required**: `edit_posts` (Contributor, Editor, Administrator)
- **Tasks**:
  - next-task (edit_posts)
  - clean-content (edit_posts)

### publish-ready
- **Required**: `publish_posts` (Editor, Administrator)
- **Reason**: Uses `ship` task
- **Tasks**:
  - next-task (edit_posts)
  - ship (publish_posts)

## Error Messages

### Before Fix
```
Error: HTTP 403 error
```
(No context about which task or what capability was missing)

### After Fix
```
Error: You do not have sufficient permissions to execute this workflow. 
The following tasks require higher privileges: optimize-perf (requires manage_options)
```
(Clear indication of the task and required capability)

## Testing

### Unit Tests Added

1. **`tests/test-workflow-capability-validation.php`**
   - Tests site-health workflow requires manage_options
   - Tests daily-review workflow allows editors
   - Tests publish-ready workflow requires publish_posts
   - Tests capability validation method directly
   - Tests error messages are helpful
   - 7 test methods

2. **`tests/test-slash-commands-dashboard-ajax.php`**
   - Tests all 5 AJAX endpoints
   - Tests nonce verification
   - Tests capability requirements
   - Tests workflow capability enforcement
   - Tests parameter validation
   - Tests history logging and limits
   - 17 test methods

### Manual Testing

Run `php tests/manual/demo-workflow-fix.php` to see the validation in action.

## Security Considerations

1. **Fail Fast**: No workflow steps are executed if user lacks required capabilities
2. **Clear Errors**: Users understand exactly what permissions they need
3. **No Information Leakage**: Error messages only show task names and WordPress standard capabilities
4. **Consistent with WordPress**: Uses WordPress's built-in `user_can()` function
5. **Backwards Compatible**: Existing workflows continue to work

## Maintenance Notes

### Adding New Tasks

When adding new workflow tasks, update the `get_task_required_capability()` method:

```php
private function get_task_required_capability( $task ) {
    $task_capabilities = array(
        // ... existing tasks ...
        'new-task' => 'required_capability', // Add new task here
    );
    
    return isset( $task_capabilities[ $task ] ) ? $task_capabilities[ $task ] : null;
}
```

### Adding New Workflows

No changes needed - the validation automatically checks all steps in any workflow.

## Performance Impact

- **Minimal**: Validation runs once before execution, not per step
- **No Database Queries**: Uses WordPress's cached user capabilities
- **Fast Failure**: Invalid workflows fail immediately without executing any steps

## Related Files

- `includes/slash-commands/commands/class-wp-mcp-ai-slash-command-workflow.php` - Main implementation
- `includes/slash-commands/commands/class-wp-mcp-ai-slash-command-optimize-perf.php` - Example task requiring manage_options
- `includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php` - AJAX handler
- `tests/test-workflow-capability-validation.php` - Unit tests for fix
- `tests/test-slash-commands-dashboard-ajax.php` - AJAX endpoint tests
- `tests/manual/demo-workflow-fix.php` - Demo script

## References

- WordPress Capability System: https://wordpress.org/documentation/article/roles-and-capabilities/
- Issue: Site Health Check workflow 403 error
- PR: Fix HTTP 403 error in workflow execution
