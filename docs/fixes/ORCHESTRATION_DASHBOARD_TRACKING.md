# Orchestration Dashboard Tracking - Visual Summary

## Yes, You're Correct! ✅

Teams and workflows **SHOULD** be tracked on the orchestration dashboard. Here's what we implemented:

## The Dashboard Purpose

The **Orchestration Dashboard** at `/wp-admin/admin.php?page=mcp-ai-orchestration-dashboard` is designed to show:

1. **Agent Role Distribution** - How many professions are assigned to each role (planner, executor, critic, etc.)
2. **Orchestration Statistics** - Total professions, seeded professions, etc.
3. **Recent Workflows** ⭐ - THIS is where teams should appear

## The Problem (Before)

```
User creates team via create_agent_team
         ↓
   Team stored as: wp_mcp_ai_team_xxxxx (transient)
         ↓
Dashboard looks for: wp_mcp_ai_workflow_xxxxx (transient)
         ↓
   ❌ MISMATCH - Team doesn't appear!
```

## The Solution (After)

```
User creates team via create_agent_team
         ↓
   Team stored as BOTH:
   - wp_mcp_ai_team_xxxxx (for team operations)
   - wp_mcp_ai_workflow_wf_xxxxx (for dashboard visibility)
         ↓
Dashboard finds workflow transient
         ↓
   ✅ Team appears immediately!
```

## What Shows on Dashboard

When a team is created, it now appears in "Recent Workflows" with:

### Workflow Data Structure
```json
{
  "workflow_id": "wf_team_abc123",
  "team_id": "team_abc123",
  "task_type": "content",
  "state": "initialized",
  "tasks": [
    {
      "task_id": "compose_team_abc123",
      "name": "Team Composition",
      "type": "composition",
      "status": "completed",      // ✅ Already done
      "completed_at": "2026-01-28 18:00:00"
    },
    {
      "task_id": "step_0_team_abc123",
      "name": "Planning",
      "type": "planning",
      "status": "pending"          // ⏳ Waiting
    },
    {
      "task_id": "step_1_team_abc123",
      "name": "Execution",
      "type": "execution",
      "status": "pending"          // ⏳ Waiting
    }
  ],
  "members": [...],
  "created_at": "2026-01-28 18:00:00",
  "updated_at": "2026-01-28 18:00:00",
  "started_at": null,             // Workflow not yet started
  "completed_at": null            // Workflow not yet completed
}
```

### Dashboard Display
The dashboard shows:
- **Workflow ID**: `wf_team_abc123`
- **State**: `initialized` (team created, workflow not yet executing)
- **Tasks Total**: 3 (composition + 2 workflow steps)
- **Tasks Done**: 1 (just the composition)
- **Created At**: When the team was created
- **Started At**: null (until workflow execution begins)
- **Completed At**: null (until workflow finishes)

## Workflow Lifecycle Tracking

### 1. Team Creation (what we just implemented)
```
create_agent_team called
  → state: "initialized"
  → tasks_done: 1 (composition)
  → tasks_total: varies (composition + workflow steps)
  → appears on dashboard ✅
```

### 2. Workflow Execution Starts
```
execute_team_workflow called
  → state: "running"
  → started_at: set to current time
  → tasks start completing
  → dashboard updates in real-time
```

### 3. Workflow Completes
```
All tasks done
  → state: "completed"
  → completed_at: set to current time
  → remains visible on dashboard (for 7 days)
```

### 4. Workflow Fails
```
Error occurs
  → state: "failed"
  → remains visible for troubleshooting
```

## Why This Matters

### Before Our Fix
```
User: "I created 5 teams today"
Dashboard: Shows 0 workflows
User: "Where are my teams??" ❌
```

### After Our Fix
```
User: "I created 5 teams today"
Dashboard: Shows 5 workflows (state: initialized)
User: "Great! I can see them all!" ✅
```

## What Should Appear on Dashboard

✅ **YES - These SHOULD appear:**
- Teams created via `create_agent_team` (state: initialized)
- Workflows started via `execute_team_workflow` (state: running)
- Completed workflows (state: completed)
- Failed workflows (state: failed)

❌ **NO - These should NOT appear:**
- Just profession metadata (roles, task patterns)
- Individual agent definitions
- Configuration settings

## Testing the Dashboard

### Step 1: Create a Team
```bash
# Via chat-client or REST API
POST /wp-json/mcp-ai/v1/chat-client
{
  "messages": [...],
  "tools": ["create_agent_team"],
  ...
}

# AI calls create_agent_team
```

### Step 2: Check Dashboard
```
Navigate to: /wp-admin/admin.php?page=mcp-ai-orchestration-dashboard
Look for "Recent Workflows" section
Should see: wf_team_xxxxx with state "initialized"
```

### Step 3: Verify Data
```sql
-- Check workflow transient in database
SELECT option_name, option_value 
FROM wp_options 
WHERE option_name LIKE '_transient_wp_mcp_ai_workflow_%';
```

## Summary

**Your understanding is CORRECT!** ✅

- Teams created via `create_agent_team` **SHOULD** appear on the orchestration dashboard
- They **WERE NOT** appearing before (bug)
- They **NOW APPEAR** after our fix (by saving them as workflow transients)
- The dashboard shows workflow lifecycle: initialized → running → completed/failed
- This provides visibility into multi-agent orchestration activity

The orchestration dashboard is the central monitoring point for all multi-agent workflows, whether they're just initialized, actively running, or completed.
