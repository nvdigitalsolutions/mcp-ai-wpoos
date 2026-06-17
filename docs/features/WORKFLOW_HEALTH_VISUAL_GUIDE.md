# Workflow Health Monitoring - Visual Guide

## Dashboard View

The Orchestration Dashboard now includes a "Team Workflows" section that displays:

### Normal Workflow (Recently Initialized)
```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Workflow ID          │ Team ID    │ Type     │ State        │ Age   │ Tasks │
├─────────────────────────────────────────────────────────────────────────────┤
│ wf_team_abc...       │ team_123   │ research │ initialized  │ 2 min │ 0 / 3 │
│                                                                  [▶ Start]    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Stale Workflow (>5 Minutes in Initialized State)
```
┌─────────────────────────────────────────────────────────────────────────────┐
│                   ⚠️ YELLOW BACKGROUND - STALE WORKFLOW ⚠️                   │
├─────────────────────────────────────────────────────────────────────────────┤
│ Workflow ID          │ Team ID    │ Type     │ State              │ Age     │
├─────────────────────────────────────────────────────────────────────────────┤
│ wf_team_9d701a...    │ team_456   │ content  │ initialized (STALE)│ 6.7 min │
│                                                   [🚀 Start Workflow] ✨      │
└─────────────────────────────────────────────────────────────────────────────┘
                                                        ↑
                                                   Pulsing button
```

## State Color Coding

| State       | Badge Color | Background    | Meaning                           |
|-------------|-------------|---------------|-----------------------------------|
| initialized | Blue        | White/Yellow* | Waiting to start (*yellow if >5min)|
| running     | Cyan        | White         | Currently executing               |
| completed   | Green       | White         | Successfully finished             |
| failed      | Red         | White         | Execution failed                  |

## User Actions

### Starting a Workflow

1. **Locate stale workflow** (highlighted in yellow)
2. **Click "🚀 Start Workflow"** button
3. **Confirm** in popup dialog
4. **Watch state change** from "initialized" → "running"

### Monitoring Progress

- **Auto-refresh**: Dashboard updates every 5 seconds
- **Task Progress**: Shows completed/total tasks (e.g., "2 / 5")
- **Age Display**: Human-readable format (seconds, minutes, hours)

## AI Assistant View

When an AI assistant calls `check_workflow_health`:

```
Human: Why is my workflow stuck?

AI: Let me check the workflow health...

[Calls check_workflow_health tool]

AI: I found the issue! Your workflow (wf_team_9d701ac2) has been 
    initialized for 6.7 minutes but never started. This is common 
    in WordPress plugins where workflows wait for cron execution.
    
    You can:
    1. Start it manually from the Orchestration Dashboard
    2. Let me trigger it by calling execute_workflow
    3. Use delegate_to_agent to assign tasks to team members
    
    Would you like me to start it now?
```

## Workflow States Explained

### initialized
- **What it means**: Team created, workflow saved, but not started
- **Why it happens**: Waiting for explicit trigger (execute_workflow or delegate_to_agent)
- **Action needed**: Start via dashboard or tool call
- **Timeout**: Flagged as "stale" after 5 minutes

### running
- **What it means**: Workflow is actively executing
- **Why it happens**: execute_workflow() called successfully
- **Action needed**: Monitor progress
- **Timeout**: No specific timeout (depends on workflow complexity)

### completed
- **What it means**: All tasks finished successfully
- **Why it happens**: Normal completion
- **Action needed**: Review results
- **Timeout**: N/A (terminal state)

### failed
- **What it means**: Execution encountered an error
- **Why it happens**: Exception, tool failure, or validation error
- **Action needed**: Check error logs, fix issue, retry
- **Timeout**: N/A (terminal state)

## CSS Animation

Stale workflow "Start Workflow" buttons pulse to draw attention:

```
🚀 Start Workflow ◉ ← Pulsing shadow effect
                  ↓
              (expands)
                  ↓
🚀 Start Workflow   ○ ← Fades out
                  ↓
              (retracts)
                  ↓
🚀 Start Workflow ◉ ← Cycle repeats
```

## Technical Flow

```
create_agent_team
        ↓
   compose_team()
        ↓
save_team_as_workflow()
        ↓
[state: initialized]
        ↓
   Wait 5 minutes
        ↓
check_stale_initialized_workflows()
        ↓
[flagged as STALE]
        ↓
   Dashboard displays
   with yellow highlight
        ↓
   User clicks button
        ↓
ajax_trigger_workflow()
        ↓
execute_workflow()
        ↓
[state: running] → [state: completed]
```

## Best Practices

✅ **DO**:
- Check workflow health after creating teams
- Monitor dashboard for stale workflows
- Use delegate_to_agent to start execution naturally
- Set up proper cron in production

❌ **DON'T**:
- Leave workflows in initialized state indefinitely
- Rely solely on wp-cron for critical workflows
- Ignore warnings in workflow health checks
- Delete workflow transients manually

## Error Messages

| Message | Cause | Solution |
|---------|-------|----------|
| "Workflow not found" | Transient expired or invalid ID | Recreate team |
| "Workflow already running" | Duplicate start attempt | Wait for completion |
| "Coordinator not available" | Missing class | Check plugin activation |
| "Unauthorized" | Permission denied | Verify user capabilities |

## Integration with Other Tools

The workflow health system integrates with:

- ✅ `create_agent_team` - Auto-creates workflow record
- ✅ `execute_workflow` - Transitions state to running
- ✅ `delegate_to_agent` - Indirect workflow execution
- ✅ Dashboard UI - Visual monitoring and manual trigger
- ⏳ `wp-cron` - Future auto-start capability (not yet implemented)
