# Cron Job Visibility Flow

This document explains the complete flow of how test jobs show up in the WP oOS Cron Manager.

## Visual Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                     User Creates Test Job                            │
│  (via AI Assistant, REST API, or WP-CLI)                            │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│         create_cron_job Tool Execution                              │
│                                                                      │
│  1. Validates parameters (hook, timestamp, schedule)                │
│  2. Schedules job in WordPress cron (_set_cron_array)              │
│  3. Records job in wp_mcp_ai_cron_jobs option                      │
│     - job_id (md5 hash of hook + args)                             │
│     - hook, args, schedule                                          │
│     - first_timestamp (when job should run)                         │
│     - created_at, created_by                                        │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                Cron Manager Admin UI                                 │
│         /admin.php?page=wp-mcp-ai-cron-manager                      │
│                                                                      │
│  On page load:                                                       │
│  1. Calls WP_MCP_AI_Cron_Manager::maybe_prune_jobs()               │
│  2. Loads all jobs from wp_mcp_ai_cron_jobs                        │
│  3. Checks each job against WordPress cron array                    │
│  4. Determines status for each job                                  │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
                    ┌───────────────────────┐
                    │   Job Status Check    │
                    └───────────┬───────────┘
                                │
                    ┌───────────┴───────────┐
                    │                       │
                    ▼                       ▼
        ┌──────────────────┐    ┌──────────────────┐
        │  wp_get_scheduled│    │  Job Not in      │
        │  _event() returns│    │  WordPress Cron  │
        │  event object    │    │                  │
        └────────┬─────────┘    └────────┬─────────┘
                 │                       │
                 ▼                       ▼
     ┌──────────────────────┐ ┌──────────────────────────┐
     │   STATUS: ACTIVE     │ │  Check first_timestamp   │
     │   (Green Badge)      │ │                          │
     │                      │ │  If past time:           │
     │  Display:            │ │    STATUS: EXECUTED      │
     │  - Next run time     │ │    (Blue Badge)          │
     │  - "In X minutes"    │ │                          │
     │  - Full timestamp    │ │  Display:                │
     └──────────────────────┘ │  - "Ran X ago"           │
                              │  - Execution timestamp   │
                              │                          │
                              │  If future/no timestamp: │
                              │    STATUS: INACTIVE      │
                              │    (Gray Badge)          │
                              └──────────────────────────┘
```

## Job Lifecycle States

```
┌───────────────┐
│ Job Created   │
│ (recorded in  │
│  options DB)  │
└───────┬───────┘
        │
        │ Scheduled in WordPress cron
        ▼
┌────────────────────────────────┐
│  ACTIVE State                  │
│  ✓ In WordPress cron array     │
│  ✓ Shows "Active" badge        │
│  ✓ Displays next run time      │
│  ✓ Visible in Cron Manager     │
└───────┬────────────────────────┘
        │
        │ Job executes (WordPress processes cron)
        ▼
┌────────────────────────────────┐
│  EXECUTED State                │
│  ✗ Not in WordPress cron       │
│  ✓ first_timestamp < now       │
│  ✓ Shows "Executed" badge      │
│  ✓ Displays "Ran X ago"        │
│  ✓ STILL VISIBLE in Manager    │◄── THIS IS KEY!
└───────┬────────────────────────┘
        │
        │ Time passes...
        │ (Retention period elapses)
        ▼
┌────────────────────────────────┐
│  maybe_prune_jobs()            │
│                                │
│  Checks:                       │
│  if (time() - first_timestamp) │
│     > retention_period         │
│                                │
│  If true: Remove from DB       │
└───────┬────────────────────────┘
        │
        ▼
┌────────────────────────────────┐
│  Job Removed                   │
│  ✗ No longer visible           │
│  ✗ Deleted from options DB     │
└────────────────────────────────┘
```

## Retention Period Settings

```
Settings → Orchestration Layer → Cron Job History Retention

┌─────────────────────────────────────────────────────────┐
│  Retention Options:                                     │
│                                                          │
│  ⚪ 1 hour    - Quick tests only                        │
│  ⚪ 6 hours   - Short-term testing                      │
│  ⚫ 24 hours  - Standard (RECOMMENDED - DEFAULT)        │
│  ⚪ 72 hours  - 3 days - Extended review                │
│  ⚪ 168 hours - 1 week - Full audit trail               │
│  ⚪ 720 hours - 30 days - Maximum retention             │
│  ⚪ 0 hours   - Never - Remove immediately (not for testing)│
└─────────────────────────────────────────────────────────┘

Effect: Jobs with "Executed" status remain visible
        for this period after first_timestamp
```

## One-Time vs Recurring Jobs

### One-Time Job Flow
```
Create → Active → Execute → Executed → [Wait retention] → Removed
  ↓        ↓         ↓          ↓                             ↓
DB: ✓     ✓         ✓          ✓                             ✗
WP: ✗     ✓         ✓          ✗                             ✗
UI: ✓     ✓         ✓          ✓ (shows "Executed")          ✗

Legend:
DB = Recorded in wp_mcp_ai_cron_jobs option
WP = Scheduled in WordPress cron array
UI = Visible in Cron Manager
```

### Recurring Job Flow
```
Create → Active → Execute → Active again → Execute → ...
  ↓        ↓         ↓          ↓             ↓
DB: ✓     ✓         ✓          ✓             ✓
WP: ✗     ✓         ✓          ✓             ✓
UI: ✓     ✓         ✓          ✓             ✓

(Always shows "Active" status as long as scheduled)
```

## Database Storage

```
Option: wp_mcp_ai_cron_jobs
Type: Array of job objects

Structure:
{
  "job_id_hash_1": {
    "job_id": "abc123...",          // MD5 of hook + args
    "hook": "wp_mcp_ai_test_job",
    "args": ["test_arg"],
    "schedule": "single",            // or "hourly", "daily", etc.
    "first_timestamp": 1699999999,   // When job first runs
    "created_at": 1699999900,        // When job was created
    "created_by": 1                  // User ID who created it
  },
  "job_id_hash_2": { ... }
}
```

## Admin UI Components

```
┌─────────────────────────────────────────────────────────────────┐
│  WP oOS Cron Manager                                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  About Cron Manager                                             │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Test jobs and completed one-time events remain visible    │ │
│  │ for 24 hours after execution, then are automatically      │ │
│  │ removed. You can adjust this retention period in          │ │
│  │ Settings → Orchestration Layer.                           │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌─────────────┬─────────────┬─────────────┬─────────────┐     │
│  │ Total: 3    │ Active: 2   │ Recurring: 1│ One-off: 2  │     │
│  └─────────────┴─────────────┴─────────────┴─────────────┘     │
│                                                                  │
│  Hook                      Status     Next Run      Actions     │
│  ─────────────────────────────────────────────────────────────  │
│  wp_mcp_ai_test_job       [Active]    In 5 min     [Delete]    │
│  wp_mcp_ai_old_test      [Executed]   Ran 2h ago   [Delete]    │
│  wp_mcp_ai_hourly        [Active]     In 30 min    [Delete]    │
└─────────────────────────────────────────────────────────────────┘
```

## Key Implementation Points

### 1. Job Recording
```php
// In create_cron_job tool
WP_MCP_AI_Cron_Manager::record_job(
    $hook,
    $args,
    $schedule,
    $timestamp,
    $user_id
);
```

### 2. Status Determination
```php
// In admin UI render
$event = wp_get_scheduled_event( $hook, $args );
$is_active = (bool) $event;
$was_executed = ! $is_active && 
                $first_timestamp > 0 && 
                $first_timestamp < time();
```

### 3. Retention Check
```php
// In maybe_prune_jobs()
if ( ! $event ) {
    if ( $retention_period === 0 ) {
        // Remove immediately
        unset( $jobs[ $job_id ] );
    } else {
        // Check if retention period has passed
        if ( ( time() - $first_timestamp ) > $retention_period ) {
            unset( $jobs[ $job_id ] );
        }
    }
}
```

## Test Verification Checklist

When you create a test job, verify:

- [ ] Job appears immediately in Cron Manager
- [ ] Status shows "Active" (green badge)
- [ ] Next Run shows correct future time
- [ ] Creator shows your username
- [ ] After execution: Status changes to "Executed" (blue badge)
- [ ] After execution: Next Run shows "Ran X ago"
- [ ] Job remains visible for configured retention period
- [ ] After retention: Job is automatically removed

## Common Questions

**Q: Why don't I see my test job?**
A: Check that:
1. You created it using `create_cron_job` tool (not native WP functions)
2. You have admin permissions (manage_options capability)
3. The job was created successfully (check tool response)
4. You're on the correct site (if multisite)

**Q: My job disappeared right after running!**
A: Check retention setting:
- Go to Settings → Orchestration Layer
- Ensure "Cron Job History Retention" is NOT set to "Never - Remove immediately"
- Recommended: Use default "24 hours"

**Q: How do I delete an executed job?**
A: Click the "Delete" button in the Actions column. Executed jobs can be manually deleted before the retention period expires.

**Q: Do recurring jobs show as "Executed"?**
A: No, recurring jobs always show "Active" because they remain scheduled. Only one-time jobs show "Executed" status after running.

## Related Files

- Admin UI: `includes/admin/class-wp-mcp-ai-admin-cron-manager.php`
- Cron Manager: `includes/class-wp-mcp-ai-cron-manager.php`
- Create Tool: `includes/tools/class-wp-mcp-ai-tool-create-cron-job.php`
- Settings: `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php`
