# Fix for create_agent_team Tool Silent Failures

## Problem Statement

The `create_agent_team` tool was failing silently with OpenAI as the provider, returning only a generic "tool_fatal_error" message without explaining why the failure occurred. This made debugging impossible for users.

Example error:
```json
{
  "error": true,
  "code": "tool_fatal_error",
  "message": "Tool create_agent_team failed with a fatal error."
}
```

## Root Causes Identified

### 1. Generic Error Messages in REST API
**Location:** `includes/class-wp-mcp-ai-rest.php:3124-3142`

When PHP `Error` objects were caught during tool execution, the actual error message was logged but not included in the response to the client. This resulted in users seeing only "Tool X failed with a fatal error" without any details.

**Before:**
```php
$tool_result = new WP_Error(
    'tool_fatal_error',
    sprintf(
        __( 'Tool %s failed with a fatal error.', 'mcp-ai-wpoos' ),
        $tool_name
    )
);
```

**After:**
```php
$tool_result = new WP_Error(
    'tool_fatal_error',
    sprintf(
        __( 'Tool %1$s failed with a fatal error: %2$s', 'mcp-ai-wpoos' ),
        $tool_name,
        $e->getMessage()
    )
);
```

### 2. No Fallback for Missing Assistants
**Location:** `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php`

The team orchestrator would return an empty array when no assistants were found, leading to a cryptic "No suitable agents available" error. The system had no fallback mechanism.

### 3. Insufficient Logging
There was no detailed logging to help diagnose why team composition was failing.

## Solutions Implemented

### 1. Enhanced Error Reporting
- REST API now includes the actual error message in `tool_fatal_error` responses
- Error codes provide specific failure reasons
- Error data includes actionable suggestions

### 2. Corrected Multi-Level Fallback System
Implemented a 4-tier fallback system in `find_agents_for_roles()` with the optimal priority order:

**Step 1:** Search for assistants with specific agent role metadata
```php
meta_query => array(
    'key' => '_wp_mcp_ai_agent_role',
    'value' => $role
)
```

**Step 2:** Try profession-based agents (have relevant expertise)
```php
$profession_agent = $this->find_profession_agent_for_role( $role, $task_requirements );
```

**Step 3:** Use any published assistant as a generalist (last resort before virtual)
```php
'post_type' => 'mcp_ai_assistant',
'post_status' => 'publish',
'orderby' => 'rand'
```

**Step 4:** Create virtual agents as absolute last resort
```php
$virtual_agent = $this->create_virtual_agent_for_role( $role );
```

This order prioritizes agents with relevant expertise (profession-based) over random generic assistants.

### 3. Virtual Agent System
New `create_virtual_agent_for_role()` method creates placeholder agents when no real agents are available:

```php
protected function create_virtual_agent_for_role( $role ) {
    $role_definitions = array(
        'planner' => array(
            'name' => __( 'Virtual Planner', 'mcp-ai-wpoos' ),
            'expertise' => array( 'task decomposition', 'strategic planning', 'workflow design' ),
        ),
        'executor' => array(
            'name' => __( 'Virtual Executor', 'mcp-ai-wpoos' ),
            'expertise' => array( 'task execution', 'content creation', 'problem solving' ),
        ),
        'critic' => array(
            'name' => __( 'Virtual Critic', 'mcp-ai-wpoos' ),
            'expertise' => array( 'quality assurance', 'validation', 'feedback' ),
        ),
    );
    
    return array(
        'id' => 'virtual_' . $role . '_' . wp_generate_uuid4(),
        'role' => $role,
        'type' => 'virtual',
        'expertise' => $definition['expertise'],
    );
}
```

### 4. Comprehensive Logging
Added logging at each fallback level:
- `team_composition_fallback_generic` - When using any assistant
- `team_composition_fallback_profession` - When using profession-based agent
- `team_composition_virtual_agent` - When creating virtual agent
- `team_composition_missing_agents` - When all fallbacks fail

### 5. Improved Error Messages
Error messages now provide context and actionable guidance:

```php
return new WP_Error(
    'wp_mcp_ai_no_agents_available',
    sprintf(
        __( 'No suitable agents available for %s team composition. Please ensure you have at least one published assistant, or the system will automatically create virtual agents. Check the plugin logs for more details.', 'mcp-ai-wpoos' ),
        $task_type
    ),
    array(
        'task_type' => $task_type,
        'required_roles' => $template['roles'],
        'suggestion' => __( 'Create at least one assistant in the WordPress admin, or the system will use virtual agents.', 'mcp-ai-wpoos' ),
    )
);
```

## Additional Fixes

### get_profession_stats Tool Enhancement
**Location:** `includes/tools/class-wp-mcp-ai-tool-profession-stats.php`

Added comprehensive error handling with try-catch blocks:

```php
try {
    $profession_service = wp_mcp_ai_get_profession_service();
    
    if ( ! $profession_service ) {
        return array(
            'success' => false,
            'message' => __( 'Profession service could not be initialized.', 'mcp-ai-wpoos' ),
            'code' => 'profession_service_initialization_failed',
        );
    }
    
    $all_professions = $profession_service->get_all_professions();
    $category_counts = $profession_service->get_category_counts();
    
} catch ( Exception $e ) {
    WP_MCP_AI_Logger::log_error( /* ... */ );
    return array( /* detailed error */ );
} catch ( Error $e ) {
    WP_MCP_AI_Logger::log_error( /* ... */ );
    return array( /* detailed error */ );
}
```

## Testing

### Test Coverage
Created `tests/test-create-agent-team-error-handling.php` with tests for:
- Team creation with no assistants (virtual agents)
- Invalid arguments validation
- Descriptive error messages
- Fatal error handling

### Manual Testing Checklist
- [ ] Test with OpenAI provider in chat client
- [ ] Verify error messages are displayed in chat UI
- [ ] Confirm virtual agents work properly
- [ ] Test all fallback levels
- [ ] Verify logging output

## Usage Examples

### Before Fix
```json
{
    "response": {
        "preview": "{\"error\":true,\"code\":\"tool_fatal_error\",\"message\":\"Tool create_agent_team failed with a fatal error.\"}"
    }
}
```

### After Fix - With Virtual Agents
```json
{
    "success": true,
    "team": {
        "team_id": "team_a1b2c3d4-...",
        "task_type": "content",
        "member_count": 2,
        "members": [
            {
                "agent_id": "virtual_executor_...",
                "role": "executor",
                "profession": "Virtual Executor",
                "expertise": ["task execution", "content creation", "problem solving"]
            },
            {
                "agent_id": "virtual_critic_...",
                "role": "critic",
                "profession": "Virtual Critic",
                "expertise": ["quality assurance", "validation", "feedback"]
            }
        ]
    }
}
```

### After Fix - With Detailed Error
```json
{
    "success": false,
    "message": "No suitable agents available for research team composition. Please ensure you have at least one published assistant, or the system will automatically create virtual agents. Check the plugin logs for more details.",
    "code": "wp_mcp_ai_no_agents_available",
    "data": {
        "task_type": "research",
        "required_roles": ["planner", "executor", "critic"],
        "suggestion": "Create at least one assistant in the WordPress admin, or the system will use virtual agents."
    }
}
```

## Files Changed

1. **includes/class-wp-mcp-ai-rest.php**
   - Enhanced fatal error handling to include actual error message

2. **includes/services/class-wp-mcp-ai-agent-team-orchestrator.php**
   - Refactored `find_agents_for_roles()` with 4-tier fallback
   - Added `create_virtual_agent_for_role()` method
   - Added `get_profession_data()` helper method
   - Enhanced error messages and logging

3. **includes/tools/class-wp-mcp-ai-tool-profession-stats.php**
   - Added comprehensive exception handling
   - Improved error messages with specific codes

4. **tests/test-create-agent-team-error-handling.php** (new)
   - Test suite for error handling scenarios

## Deployment Notes

- No database schema changes
- No breaking API changes
- Backward compatible with existing assistants
- Virtual agents are created on-demand and don't persist

## Future Improvements

1. Persist virtual agents as draft assistants for user review
2. Add UI indicator when virtual agents are being used
3. Allow configuration of virtual agent templates
4. Add metrics tracking for virtual agent usage
5. Implement virtual agent caching for performance

## Related Issues

- Fixes generic "tool_fatal_error" messages across all tools
- Improves multi-agent orchestration reliability
- Enables team composition even without pre-configured assistants

## Support

For questions or issues with this fix, refer to:
- Plugin logs: `wp option get wp_mcp_ai_recent_errors`
- Debug mode: `define( 'WP_MCP_AI_DEBUG', true );`
- Documentation: `docs/tool-reference.md`
