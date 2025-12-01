# Quick Reference: Test Cron Jobs Visibility

## TL;DR - The Answer

**Yes, test jobs DO show up in the Cron Manager!**

Navigate to: **WP Admin → WP oOS → Cron Manager**  
Or: `/wp-admin/admin.php?page=wp-mcp-ai-cron-manager`

## How It Works

1. **Create a test job** using the `create_cron_job` tool
2. **Job appears immediately** in the Cron Manager with "Active" status
3. **After execution**, status changes to "Executed" showing "Ran X ago"
4. **Job remains visible** for the configured retention period (default: 24 hours)
5. **Automatic cleanup** removes the job after retention period expires

## Quick Test (2 minutes)

Create a test job that runs in 1 minute:

```json
{
  "hook": "wp_mcp_ai_quick_test",
  "timestamp": "<current_time + 60>",
  "schedule": "single",
  "args": {"test": "verification"}
}
```

**What you'll see:**

| Time | Status | Next Run | Visible? |
|------|--------|----------|----------|
| Immediately | Active (green) | "In 1 minute" | ✅ Yes |
| After 1 min | Executed (blue) | "Ran 0 minutes ago" | ✅ Yes |
| After 24 hours | — | — | ❌ Removed |

## Configuring Retention

**Settings → Orchestration Layer → Cron Job History Retention**

Options:
- 1 hour - Quick tests only
- 6 hours - Short-term testing  
- **24 hours** - Standard (Recommended) ← DEFAULT
- 3 days - Extended review
- 1 week - Full audit trail
- 30 days - Maximum retention

## Documentation

For complete details, see:

- **Testing Guide**: `docs/CRON_TESTING_GUIDE.md` - Complete testing instructions
- **Flow Diagrams**: `docs/CRON_JOB_VISIBILITY_FLOW.md` - Visual system overview
- **Summary**: `TEST_JOBS_VISIBILITY_SUMMARY.md` - Implementation details

## Common Questions

**Q: I don't see my test job!**  
A: Ensure you created it via `create_cron_job` tool (not native WP functions) and have admin permissions.

**Q: Job disappeared after running!**  
A: Check Settings → Orchestration Layer → ensure retention is NOT "Never - Remove immediately"

**Q: How long are jobs visible?**  
A: Default 24 hours after execution. Configurable in settings.

## Key Feature

**The Cron Manager now clearly displays:**
> "Test jobs and completed one-time events remain visible for [X hours/days] after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer."

This makes it obvious that test jobs WILL show up and persist!
