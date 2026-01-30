# Fix for create_agent_team Bug with OpenAI Chat-Client

## Problems

### Problem 1: Delegation with Wrong IDs

When using `create_agent_team` in chat-client with OpenAI as the provider, the AI was attempting to delegate tasks using **profession names** (like "social_media_manager") instead of the actual **agent IDs** (like "virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3").

### Problem 2: Teams Not Showing on Dashboard

Teams created via `create_agent_team` were not appearing on the orchestration dashboard even though they were successfully created. This made it impossible to track team workflows.

## Root Causes

### Problem 1: Ambiguous Field Names

The `create_agent_team` tool response included both `agent_id` and `profession` fields for each team member:

```json
{
  "members": [
    {
      "agent_id": "virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3",
      "role": "executor",
      "profession": "Virtual Executor",
      "expertise": ["task execution", "content creation", "problem solving"]
    }
  ]
}
```

The AI model (OpenAI) was confused about which field to use for delegation, and the `delegate_to_agent` parameter description mentioned "profession" which suggested it could be used as an ID.

### Problem 2: Missing Workflow Transient

The orchestration dashboard queries for `wp_mcp_ai_workflow_*` transients to display workflows. However, the `create_agent_team` tool only created `wp_mcp_ai_team_*` transients. There was no workflow record created until the team actually executed a workflow via `execute_team_workflow()`, which meant teams created but not yet executing wouldn't show on the dashboard.

## Solutions

### Solution 1: Enhanced Delegation Guidance

#### 1. Added `delegation_examples` Array

Added a `delegation_examples` array that explicitly shows how to delegate to each team member:

```json
{
  "delegation_examples": [
    "Delegate to executor using agent_id: \"virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3\"",
    "Delegate to critic using agent_id: \"virtual_critic_0537d240-c5de-4610-b687-1c707e78743b\""
  ]
}
```

#### 2. Clarified Next Steps

Updated the `next_steps` array to include an explicit warning:

```json
{
  "next_steps": [
    "IMPORTANT: Use the agent_id field (not profession) when calling delegate_to_agent",
    "Example: delegate_to_agent with agent_id from the members array above",
    "When delegating, include team_id in the context parameter for virtual agents",
    "Use aggregate_agent_results to combine outputs from multiple agents"
  ]
}
```

#### 3. Updated `delegate_to_agent` Parameter Description

Changed the `agent_id` parameter description from:

❌ **Before:** "ID of the agent (profession, assistant, or virtual agent) to delegate to..."

✅ **After:** "The agent_id value from the create_agent_team response. This can be an integer assistant post ID or a virtual agent string ID (e.g., "virtual_executor_abc123"). Do NOT use profession names."

### Solution 2: Automatic Workflow Tracking

#### 1. Added `save_team_as_workflow()` Method

Created a new method in the orchestrator that converts team composition into a workflow record:

```php
protected function save_team_as_workflow( $team ) {
    // Create workflow tasks from team members and workflow steps
    $tasks = array();
    
    // Add team composition as initial completed task
    $tasks[] = array(
        'task_id'      => 'compose_' . $team['team_id'],
        'name'         => __( 'Team Composition', 'mcp-ai-wpoos' ),
        'type'         => 'composition',
        'status'       => 'completed',
        'completed_at' => $team['created_at'],
    );

    // Add workflow steps as pending tasks
    foreach ( $team['workflow'] as $index => $step ) {
        $tasks[] = array(
            'task_id' => 'step_' . $index . '_' . $team['team_id'],
            'name'    => $step['name'],
            'type'    => $step['type'],
            'status'  => 'pending',
        );
    }

    // Build workflow data and save
    $workflow_data = array(
        'workflow_id' => 'wf_' . $team['team_id'],
        'team_id'     => $team['team_id'],
        'state'       => 'initialized',
        'tasks'       => $tasks,
        // ... more fields
    );

    return $this->save_workflow_to_dashboard( $workflow_data['workflow_id'], $workflow_data );
}
```

#### 2. Automatic Invocation

The method is automatically called when a team is composed:

```php
public function compose_team( $task_requirements ) {
    // ... team composition logic ...
    
    // Store team configuration
    $this->store_team( $team );
    
    // Also store as workflow for orchestration dashboard tracking
    $this->save_team_as_workflow( $team );
    
    return $team;
}
```

#### 3. Workflow Structure

Teams are now saved with this structure:
- **workflow_id**: `wf_{team_id}` format for easy identification
- **state**: `initialized` - indicates team is created but not yet executing
- **tasks**: Array with composition marked as completed, workflow steps as pending
- **members**: Full team member details
- **timestamps**: created_at, updated_at, started_at (null initially), completed_at (null initially)

## Example Usage

### Step 1: Create a Team

```javascript
// AI calls create_agent_team
{
  "tool": "create_agent_team",
  "arguments": {
    "task_type": "content",
    "requirements": {
      "expertise_needed": ["content writing"],
      "quality_level": "validated"
    }
  }
}
```

### Step 2: Response with Clear Guidance

```json
{
  "success": true,
  "team": {
    "team_id": "team_abc123",
    "members": [
      {
        "agent_id": "virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3",
        "role": "executor",
        "profession": "Virtual Executor",
        "expertise": ["task execution", "content creation", "problem solving"]
      },
      {
        "agent_id": "virtual_critic_0537d240-c5de-4610-b687-1c707e78743b",
        "role": "critic",
        "profession": "Virtual Critic",
        "expertise": ["quality assurance", "validation", "feedback"]
      }
    ]
  },
  "next_steps": [
    "IMPORTANT: Use the agent_id field (not profession) when calling delegate_to_agent",
    "Example: delegate_to_agent with agent_id from the members array above",
    "When delegating, include team_id in the context parameter for virtual agents",
    "Use aggregate_agent_results to combine outputs from multiple agents"
  ],
  "delegation_examples": [
    "Delegate to executor using agent_id: \"virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3\"",
    "Delegate to critic using agent_id: \"virtual_critic_0537d240-c5de-4610-b687-1c707e78743b\""
  ]
}
```

### Step 3: Correct Delegation

```javascript
// AI now correctly delegates using agent_id
{
  "tool": "delegate_to_agent",
  "arguments": {
    "agent_id": "virtual_executor_9ecad675-6335-49ea-9b2a-786946ac5eb3", // ✅ Using agent_id
    "task": "Write a blog post about AI",
    "context": {
      "team_id": "team_abc123"
    }
  }
}
```

## Testing

A comprehensive test suite was added in `tests/test-create-agent-team-delegation-guidance.php` that verifies:

1. ✅ `delegation_examples` array is present in the response
2. ✅ Each delegation example contains the correct `agent_id`
3. ✅ `next_steps` includes warnings about using `agent_id` (not profession)
4. ✅ `delegate_to_agent` parameter description warns against profession names
5. ✅ One delegation example per team member

## Files Changed

1. **includes/tools/class-wp-mcp-ai-tool-create-agent-team.php**
   - Added `delegation_examples` array to response
   - Enhanced `next_steps` with explicit warnings

2. **includes/tools/class-wp-mcp-ai-tool-delegate-to-agent.php**
   - Updated `agent_id` parameter description
   - Explicitly warns: "Do NOT use profession names"

3. **tests/test-create-agent-team-delegation-guidance.php** (new)
   - Comprehensive test suite validating the fix

## Impact

- ✅ AI models will now receive clear guidance on which field to use
- ✅ Explicit examples prevent confusion between `agent_id` and `profession`
- ✅ Delegation failures with virtual agents should be eliminated
- ✅ Better user experience in chat-client with OpenAI provider

## Next Steps for Testing

To fully validate this fix in a live environment:

1. Create a team using `create_agent_team` in chat-client with OpenAI
2. Observe that the response includes `delegation_examples` array
3. Attempt to delegate a task using the provided `agent_id`
4. Verify that delegation succeeds without errors
