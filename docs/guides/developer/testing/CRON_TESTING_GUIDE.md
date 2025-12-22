# Cron Job Testing Guide

This guide explains how to test cron job creation and verify that test jobs appear in the Cron Manager admin interface.

## Overview

The WP oOS Cron Manager allows you to create scheduled tasks through AI Assistant tools and view their status, including executed test jobs. This guide shows you how to:

1. Create test cron jobs
2. Verify they appear in the Cron Manager
3. Confirm executed jobs remain visible
4. Adjust retention settings

## Accessing the Cron Manager

Navigate to: **WP Admin → WP oOS → Cron Manager**

Or directly: `/wp-admin/admin.php?page=wp-mcp-ai-cron-manager`

## Test Job Visibility Features

### Job Statuses

The Cron Manager displays three status badges:

- **Active** (Green) - Job is scheduled and waiting to run
- **Executed** (Blue) - Job has completed successfully
- **Inactive** (Gray) - Job is not currently scheduled

### Retention Period

Executed jobs remain visible for a configurable period (default: 24 hours) after execution. This allows you to:

- Verify test jobs ran successfully
- Review execution history
- Audit completed tasks
- Debug issues

## Creating Test Cron Jobs

### Method 1: Using AI Assistant Tools

You can create test jobs through the AI Assistant using the `create_cron_job` tool:

**Example: Create a one-time test job**
```json
{
  "hook": "wp_mcp_ai_test_job",
  "timestamp": "1 minute from now",
  "schedule": "single",
  "args": {
    "test_param": "test_value"
  }
}
```

**Example: Create a recurring test job**
```json
{
  "hook": "wp_mcp_ai_recurring_test",
  "timestamp": "5 minutes from now",
  "schedule": "hourly"
}
```

### Method 2: Using REST API

You can also create jobs via the REST API endpoint:

**Endpoint:** `POST /wp-json/mcp-ai/v1/tools/execute`

**Request Body:**
```json
{
  "tool": "create_cron_job",
  "arguments": {
    "hook": "wp_mcp_ai_api_test",
    "timestamp": 1699999999,
    "schedule": "single"
  }
}
```

**Authentication:** Use WordPress nonce, Assistant Credentials, or Auth0 token.

### Method 3: Using WP-CLI

If WP-CLI is available, you can execute tools directly:

```bash
wp mcp-ai tool execute create_cron_job --hook=wp_mcp_ai_cli_test --schedule=single
```

## Verification Steps

### Step 1: Create a Test Job

1. Create a cron job using one of the methods above
2. Set it to run 1-2 minutes in the future
3. Note the job details (hook name, timestamp)

### Step 2: Verify in Cron Manager (Before Execution)

1. Navigate to **WP Admin → WP oOS → Cron Manager**
2. You should see your job listed with:
   - Status: **Active** (green badge)
   - Next Run: "In X minutes"
   - Schedule Type: **One-off** or **Recurring**
   - Creator: Your username
   - Created At: Current timestamp

### Step 3: Wait for Execution

Wait for the scheduled time to pass (1-2 minutes).

### Step 4: Verify After Execution

1. Refresh the Cron Manager page
2. For one-time jobs, you should see:
   - Status: **Executed** (blue badge)
   - Next Run: "Ran X minutes ago" (with timestamp)
   - The job remains visible in the list

3. For recurring jobs, you should see:
   - Status: **Active** (green badge)
   - Next Run: Shows next scheduled execution time
   - The job continues to appear in the active list

## Adjusting Retention Period

### Via Settings UI

1. Navigate to **WP Admin → Settings → WP oOS → Orchestration Layer**
2. Find **Cron Job History Retention** setting
3. Choose from available options:
   - **1 hour** - Quick tests only
   - **6 hours** - Short-term testing
   - **24 hours** - Standard (Recommended)
   - **3 days** - Extended review
   - **1 week** - Full audit trail
   - **30 days** - Maximum retention
   - **Never** - Remove immediately (not recommended for testing)
4. Save changes

### Via Code

Add to `wp-config.php` or your theme's `functions.php`:

```php
// Set retention to 6 hours
add_filter( 'wp_mcp_ai_settings', function( $settings ) {
    $settings['cron_job_retention_period'] = '6';
    return $settings;
} );
```

## Troubleshooting

### Test Jobs Don't Appear

**Problem:** Created a job but don't see it in Cron Manager

**Solutions:**
1. Verify you have `manage_options` capability (admin role)
2. Check that you're creating jobs through WP oOS tools (not native WordPress `wp_schedule_event`)
3. Ensure the job was created successfully (check tool execution response)
4. Try refreshing the Cron Manager page

### Executed Jobs Disappear Immediately

**Problem:** Jobs vanish right after execution

**Solutions:**
1. Check retention setting in **Settings → Orchestration Layer**
2. Ensure retention is not set to "Never - Remove immediately"
3. Verify the job's `first_timestamp` was recorded correctly
4. Check system time is accurate

### Job Shows "Inactive" Status

**Problem:** Job appears but shows as inactive

**Possible Causes:**
1. Job was manually unscheduled outside of WP oOS
2. WordPress cron array was cleared
3. Job failed to schedule due to an error

**Solutions:**
1. Delete the inactive job
2. Create a new test job
3. Check error logs for scheduling failures

## Expected Behavior Summary

### One-Time Jobs

1. **Before execution:**
   - Status: Active
   - Next Run: Future timestamp
   - Visible in Cron Manager

2. **After execution:**
   - Status: Executed
   - Next Run: "Ran X ago" with timestamp
   - Remains visible for retention period
   - Automatically removed after retention expires

### Recurring Jobs

1. **Always:**
   - Status: Active (unless manually stopped)
   - Next Run: Next scheduled execution
   - Continuously visible in Cron Manager
   - Only removed if manually deleted or unscheduled

## Test Scenarios

### Scenario 1: Quick Test Job (1 minute)

```json
{
  "hook": "wp_mcp_ai_quick_test",
  "timestamp": <current_time + 60>,
  "schedule": "single",
  "args": {"test": "quick"}
}
```

**Expected:** Job appears immediately, executes in 1 minute, shows "Executed" status, remains visible for 24 hours.

### Scenario 2: Recurring Hourly Test

```json
{
  "hook": "wp_mcp_ai_hourly_test",
  "timestamp": <current_time + 300>,
  "schedule": "hourly"
}
```

**Expected:** Job appears immediately, first execution in 5 minutes, continues to run hourly, always shows "Active" status.

### Scenario 3: Multiple Test Jobs

Create 3 different test jobs with different execution times. Verify:
- All appear in the list
- Each shows correct status
- Executed jobs show different "Ran X ago" times
- All remain visible within retention period

## Advanced Testing

### Testing with Arguments

Verify that job arguments are preserved and displayed:

```json
{
  "hook": "wp_mcp_ai_args_test",
  "timestamp": <current_time + 120>,
  "args": {
    "user_id": 1,
    "action": "test_notification",
    "data": {
      "message": "Test message",
      "priority": "high"
    }
  }
}
```

Check in Cron Manager that the Arguments column shows the full JSON structure.

### Testing Deletion

1. Create a test job
2. Before it executes, click "Delete" in Cron Manager
3. Verify job is removed from both Cron Manager and WordPress cron
4. Check that deletion message appears

### Testing Retention Expiry

1. Set retention to "1 hour"
2. Create and execute a test job
3. Wait 1 hour
4. Refresh Cron Manager
5. Verify the executed job has been automatically removed

## Integration with PHPUnit Tests

Note that PHPUnit tests use `setUp()` and `tearDown()` methods that clean up test data. This is **separate** from the user-facing Cron Manager:

- **PHPUnit tests:** Jobs are automatically cleaned up after each test
- **User-created jobs:** Jobs persist according to retention settings
- **No conflict:** Test cleanup doesn't affect user-created jobs

## Support

If test jobs aren't showing up as expected:

1. Check WordPress error logs
2. Verify WP oOS plugin is active
3. Ensure you have admin permissions
4. Review retention settings
5. Check that WordPress cron is functioning (`wp cron event list`)

For issues, please file a GitHub issue with:
- Steps to reproduce
- Expected vs actual behavior
- Screenshots from Cron Manager
- Relevant error logs
