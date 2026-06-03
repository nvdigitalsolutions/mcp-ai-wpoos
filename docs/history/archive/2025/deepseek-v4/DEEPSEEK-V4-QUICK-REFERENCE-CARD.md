# DeepSeek V4 Orchestration - Quick Reference

**Quick reference for developers working with multi-agent orchestration**

---

## Core Concepts

| Concept | Description |
|---------|-------------|
| **Agent Role** | Primary function: Planner, Executor, Critic, Specialist, Generalist |
| **Team** | Group of agents with defined workflow |
| **Workflow** | Series of steps executed by team members |
| **Orchestrator** | Manages team composition and execution |
| **Seeder** | Assigns roles to professions automatically |

---

## Quick Commands

```bash
# Seed orchestration data
wp profession seed-orchestration

# Check statistics
wp profession orchestration-stats

# Force re-seed
wp profession seed-orchestration --force

# Run tests
vendor/bin/phpunit tests/test-deepseek-v4-orchestration-validation.php
```

---

## 5 Core Tools

### 1. Create Agent Team
```php
$tool = $tool_registry->get_tool( 'create_agent_team' );
$team = $tool->execute(
    array(
        'task_type' => 'research',
        'requirements' => array( 'quality_level' => 'validated' ),
    ),
    array( 'assistant_id' => 1 )
);
```

### 2. Delegate to Agent
```php
$tool = $tool_registry->get_tool( 'delegate_to_agent' );
$result = $tool->execute(
    array(
        'agent_id' => 123,
        'task' => 'Research competitor pricing',
        'expected_output' => 'Detailed report',
    ),
    array( 'assistant_id' => 1 )
);
```

### 3. Aggregate Agent Results
```php
$tool = $tool_registry->get_tool( 'aggregate_agent_results' );
$result = $tool->execute(
    array(
        'agent_results' => array( $result1, $result2 ),
        'strategy' => 'consensus',
    ),
    array( 'assistant_id' => 1 )
);
```

### 4. Store Agent Context (NEW - Phase 4/5)
```php
$tool = $tool_registry->get_tool( 'store_agent_context' );
$result = $tool->execute(
    array(
        'agent_id' => 123,
        'context_type' => 'learning',
        'context_data' => array(
            'title' => 'Customer Preference',
            'content' => 'Prefers email over phone',
            'importance' => 'high',
            'tags' => array( 'customer', 'communication' ),
        ),
        'ttl' => 2592000,  // 30 days
    ),
    array( 'assistant_id' => 1 )
);
```

### 5. Retrieve Agent Memory (NEW - Phase 4/5)
```php
$tool = $tool_registry->get_tool( 'retrieve_agent_memory' );
$result = $tool->execute(
    array(
        'agent_id' => 123,
        'query' => 'customer communication preferences',
        'filters' => array(
            'importance' => array( 'high', 'critical' ),
            'tags' => array( 'customer' ),
        ),
        'limit' => 10,
    ),
    array( 'assistant_id' => 1 )
);
```

---

## Quick Workflow

```php
// 1. Compose team
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
$team = $orchestrator->compose_team( array( 'task_type' => 'research' ) );

// 2. Execute workflow
$result = $orchestrator->execute_team_workflow(
    $team,
    array( 'description' => 'Task description', 'type' => 'research' ),
    array( 'assistant_id' => 1 )
);

// 3. Check results
if ( ! is_wp_error( $result ) && 'completed' === $result['status'] ) {
    // Process results
}
```

---

## Agent Roles

| Role | When to Use | Examples |
|------|-------------|----------|
| **Planner** | Strategy, coordination | Project Manager, Architect |
| **Executor** | Implementation | Developer, Designer |
| **Critic** | Validation, QA | Editor, QA Engineer |
| **Specialist** | Domain expertise | Lawyer, Doctor |
| **Generalist** | Flexible tasks | General Assistant |

---

## Aggregation Strategies

| Strategy | Use Case |
|----------|----------|
| **consensus** | Quality validation |
| **weighted** | Expert panels |
| **hierarchical** | Management approval |
| **first** | Speed-critical |
| **best** | Highest confidence |

---

## Task Types

| Type | Team Template | Roles |
|------|---------------|-------|
| **research** | Research Team | Planner, Executor, Critic |
| **content** | Content Creation | Executor, Critic |
| **ecommerce** | E-commerce Team | Planner, Executor, Critic |
| **development** | Development Team | Planner, Executor, Critic |
| **generic** | Generic Team | Executor |

---

## Error Handling

```php
// Always check for errors
if ( is_wp_error( $result ) ) {
    error_log( 'Orchestration error: ' . $result->get_error_message() );
    return;
}

// Check status
if ( 'completed' !== $result['status'] ) {
    error_log( 'Workflow incomplete: ' . $result['status'] );
}

// Check step failures
foreach ( $result['results'] as $step => $step_result ) {
    if ( 'failed' === $step_result['status'] ) {
        error_log( "Step {$step} failed" );
    }
}
```

---

## Monitoring

```php
// Check system capacity
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
$capacity = $orchestrator->get_team_capacity_metrics();

if ( 'critical' === $capacity['health_status'] ) {
    // System under load - defer non-critical tasks
}

// Track performance
$execution_time = $result['execution_time'];
$step_count = count( $result['results'] );
```

---

## Common Patterns

### Pattern 1: Research + Report
```php
$task = array(
    'type' => 'research',
    'parameters' => array(
        'query' => 'search term',
        'save_results' => true,
        'title' => 'Report Title',
    ),
);
```

### Pattern 2: Create + Validate
```php
$team = $orchestrator->compose_team( array( 'task_type' => 'content' ) );
// Automatically includes executor + critic for validation
```

### Pattern 3: Analyze + Recommend
```php
$task = array(
    'type' => 'analysis',
    'parameters' => array(
        'data_source' => 'get_recent_posts',
        'create_report' => true,
    ),
);
```

---

## Files & Classes

### Key Classes
- `WP_MCP_AI_Agent_Team_Orchestrator` - Team management
- `WP_MCP_AI_Agent_Role_Executor` - Task execution
- `WP_MCP_AI_Profession_Orchestration_Seeder` - Role assignment
- `WP_MCP_AI_Agent_Communication_Service` - Result aggregation

### Key Files
- `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php`
- `includes/agents/class-wp-mcp-ai-agent-role-executor.php`
- `includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php`
- `includes/tools/class-wp-mcp-ai-tool-create-agent-team.php`

### Meta Fields
- `_wp_mcp_ai_profession_agent_role` - Primary role
- `_wp_mcp_ai_profession_secondary_roles` - Additional roles
- `_wp_mcp_ai_profession_task_patterns` - Workflow templates
- `_wp_mcp_ai_profession_orchestration_rules` - Coordination rules

---

## Testing

```bash
# Run all orchestration tests
vendor/bin/phpunit tests/test-deepseek-v4-orchestration-validation.php

# Run with verbose output
vendor/bin/phpunit --testdox tests/test-deepseek-v4-orchestration-validation.php

# Run specific test
vendor/bin/phpunit --filter test_executor_tool_execution_integration
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "No suitable agents available" | Run `wp profession seed-orchestration` |
| "System capacity is critical" | Wait or set `'priority' => 'critical'` |
| Team composition fails | Check task_type is valid |
| Tool not found | Verify tool registry initialized |

---

## Performance Tips

1. **Cache teams** for repeated use
2. **Monitor execution time** for slow workflows
3. **Use appropriate task types** for optimal routing
4. **Check capacity** before large workflows
5. **Enable logging** for debugging: `WP_MCP_AI_DEBUG`

---

## Resources

- **Usage Guide:** `/docs/DEEPSEEK-V4-USAGE-GUIDE.md`
- **Implementation Summary:** `/docs/DEEPSEEK-V4-IMPLEMENTATION-SUMMARY.md`
- **Validation Results:** `/docs/DEEPSEEK-V4-VALIDATION-RESULTS.md`
- **Tests:** `/tests/test-deepseek-v4-orchestration-validation.php`

---

**Version:** 1.0.0 | **Updated:** January 18, 2026
