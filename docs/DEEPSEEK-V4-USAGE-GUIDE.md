# DeepSeek V4 Multi-Agent Orchestration - Usage Guide

**Version:** 1.0.0  
**Date:** January 18, 2026  
**Target Audience:** WordPress administrators and developers

> **📖 Technical Reference:** For complete architectural details, see [ORCHESTRATION-LAYER-ARCHITECTURE.md - Section 6](architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md#-6-multi-agent-orchestration-deepseek-v4-inspired-enhancement)

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Seeding Orchestration Data](#seeding-orchestration-data)
3. [Using Agent Coordination Tools](#using-agent-coordination-tools)
4. [Creating Multi-Agent Workflows](#creating-multi-agent-workflows)
5. [Monitoring and Troubleshooting](#monitoring-and-troubleshooting)
6. [Best Practices](#best-practices)

---

## Getting Started

### Prerequisites

- WordPress 6.0 or higher
- PHP 7.4 or higher
- NV oOS plugin version 1.9.0 or higher
- WP-CLI (for command-line operations)

### Quick Start

1. **Verify Installation**
   ```bash
   wp plugin list | grep mcp-ai-wpoos
   ```

2. **Check Orchestration Status**
   ```bash
   wp profession orchestration-stats
   ```

3. **Seed Orchestration Data** (first-time setup)
   ```bash
   wp profession seed-orchestration
   ```

---

## Seeding Orchestration Data

### What is Seeding?

Seeding assigns agent roles and task patterns to your profession posts based on intelligent heuristics. This enables the multi-agent orchestration system to select appropriate agents for different tasks.

### Running the Seeder

#### Basic Seeding

```bash
# Seed all professions with agent roles and task patterns
wp profession seed-orchestration
```

**Expected Output:**
```
Starting orchestration configuration seeding...
Success: Seeded 203 agent roles and 6 task patterns.
```

#### Force Re-seeding

If you need to update role assignments (e.g., after adding new professions):

```bash
wp profession seed-orchestration --force
```

### Understanding Agent Roles

The seeder assigns one of five primary roles to each profession:

| Role | Description | Example Professions |
|------|-------------|---------------------|
| **Planner** | Strategic planning, coordination, workflow design | Project Manager, Architect, Event Planner |
| **Executor** | Implementation, technical execution, hands-on work | Software Developer, Designer, Engineer |
| **Critic** | Validation, review, quality assurance, testing | QA Engineer, Editor, Auditor |
| **Specialist** | Domain expertise requiring deep knowledge | Lawyer, Doctor, Financial Advisor |
| **Generalist** | Multi-domain tasks, broad capabilities | General Assistant, Consultant |

### Viewing Seeding Results

```bash
# View orchestration statistics
wp profession orchestration-stats
```

**Example Output:**
```
Orchestration Statistics:

Agent Roles:
  Planner     : 45
  Executor    : 89
  Critic      : 34
  Specialist  : 28
  Generalist  : 7

Professions with task patterns: 6
Seeder version: 1.0.0
```

### Multi-Role Professions

Some professions are assigned multiple roles:

**Example:** A QA Engineer might have:
- **Primary Role:** Critic (testing and validation)
- **Secondary Role:** Planner (test planning and strategy)

---

## Using Agent Coordination Tools

The system provides three MCP-compliant tools for agent coordination:

### 1. Create Agent Team

Compose a team of agents optimized for a specific task type.

#### Usage via PHP

```php
$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
$tool = $tool_registry->get_tool( 'create_agent_team' );

$result = $tool->execute(
    array(
        'task_type'    => 'research',  // research, content, ecommerce, development
        'requirements' => array(
            'expertise_needed' => array( 'data analysis', 'visualization' ),
            'quality_level'    => 'validated',  // standard, validated, expert
        ),
    ),
    array( 'assistant_id' => 1, 'user_id' => get_current_user_id() )
);

// Result structure
if ( ! is_wp_error( $result ) ) {
    echo "Team ID: " . $result['team_id'];
    echo "Members: " . count( $result['members'] );
    echo "Workflow: " . count( $result['workflow'] ) . " steps";
}
```

#### Result Structure

```php
array(
    'team_id'      => 'team_abc123...',
    'task_type'    => 'research',
    'template'     => 'Research Team',
    'members'      => array(
        array(
            'id'    => 123,
            'title' => 'Data Scientist',
            'role'  => 'executor',
        ),
        array(
            'id'    => 456,
            'title' => 'Technical Editor',
            'role'  => 'critic',
        ),
    ),
    'workflow'     => array(
        array(
            'name'     => 'plan',
            'type'     => 'delegate',
            'role'     => 'planner',
            'critical' => true,
        ),
        // ... more steps
    ),
    'created_at'   => '2026-01-18 09:00:00',
    'status'       => 'assembled',
)
```

### 2. Delegate to Agent

Delegate a specific task to an agent with optional dependencies.

#### Usage

```php
$tool = $tool_registry->get_tool( 'delegate_to_agent' );

$result = $tool->execute(
    array(
        'agent_id'        => 123,  // Assistant post ID
        'task'            => 'Research competitor pricing models',
        'context'         => array(
            'project'  => 'Q1 Strategy',
            'deadline' => '2026-01-31',
        ),
        'expected_output' => 'Detailed report with pricing analysis',
        'dependencies'    => array( 'task_xyz' ),  // Optional
    ),
    array( 'assistant_id' => 1 )
);
```

### 3. Aggregate Agent Results

Combine results from multiple agents using different strategies.

#### Usage

```php
$tool = $tool_registry->get_tool( 'aggregate_agent_results' );

$result = $tool->execute(
    array(
        'agent_results' => array(
            array(
                'agent_id' => 123,
                'result'   => array( 'score' => 85, 'recommendation' => 'Approve' ),
                'metadata' => array( 'confidence' => 0.9 ),
            ),
            array(
                'agent_id' => 456,
                'result'   => array( 'score' => 78, 'recommendation' => 'Review' ),
                'metadata' => array( 'confidence' => 0.85 ),
            ),
        ),
        'strategy'      => 'weighted',  // consensus, weighted, hierarchical, first, best
        'weights'       => array( 0.6, 0.4 ),  // For weighted strategy
    ),
    array( 'assistant_id' => 1 )
);
```

#### Aggregation Strategies

| Strategy | Description | Use Case |
|----------|-------------|----------|
| **consensus** | Requires majority agreement | Quality validation, approval workflows |
| **weighted** | Weighted average by confidence | Expert panels, multi-source analysis |
| **hierarchical** | Higher priority agents override | Management approval chains |
| **first** | First result wins | Speed-critical tasks |
| **best** | Highest confidence wins | Competitive analysis |

---

## Creating Multi-Agent Workflows

### Example 1: Research Workflow

Complete workflow for researching a topic and creating a report.

```php
// Step 1: Compose team
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

$team = $orchestrator->compose_team(
    array(
        'task_type' => 'research',
    )
);

// Step 2: Define task
$task = array(
    'description' => 'Research AI orchestration patterns and create comprehensive report',
    'type'        => 'research',
    'parameters'  => array(
        'query'        => 'multi-agent orchestration best practices 2026',
        'depth'        => 'comprehensive',
        'save_results' => true,
        'title'        => 'AI Orchestration Patterns Report',
    ),
);

// Step 3: Execute workflow
$context = array(
    'assistant_id' => 1,
    'user_id'      => get_current_user_id(),
    'priority'     => 'normal',
);

$result = $orchestrator->execute_team_workflow( $team, $task, $context );

// Step 4: Process results
if ( ! is_wp_error( $result ) && 'completed' === $result['status'] ) {
    echo "Workflow completed in " . $result['execution_time'] . " seconds\n";
    
    foreach ( $result['results'] as $step_name => $step_result ) {
        echo "Step: {$step_name} - Status: {$step_result['status']}\n";
    }
}
```

### Example 2: Content Creation with Validation

Create content with automatic quality validation.

```php
// Step 1: Create content team (executor + critic)
$team = $orchestrator->compose_team(
    array(
        'task_type' => 'content',
    )
);

// Step 2: Content creation task
$task = array(
    'description' => 'Create SEO-optimized blog post about WordPress security',
    'type'        => 'creation',
    'parameters'  => array(
        'title'    => '10 Essential WordPress Security Tips for 2026',
        'keywords' => array( 'WordPress security', 'website protection', 'best practices' ),
        'length'   => 1500,
        'research' => true,  // Do background research first
        'publish'  => false,  // Save as draft for review
    ),
);

// Step 3: Execute with validation
$result = $orchestrator->execute_team_workflow( $team, $task, $context );

// Step 4: Check validation results
if ( isset( $result['results']['validate'] ) ) {
    $validation = $result['results']['validate']['validation'];
    
    if ( $validation['passes'] && $validation['score'] > 0.8 ) {
        echo "Content approved! Score: " . $validation['score'];
    } else {
        echo "Content needs revision. Score: " . $validation['score'];
    }
}
```

### Example 3: E-commerce Product Analysis

Analyze product performance with multi-agent team.

```php
// Create specialized e-commerce team
$team = $orchestrator->compose_team(
    array(
        'task_type' => 'ecommerce',
    )
);

$task = array(
    'description' => 'Analyze product performance and recommend optimizations',
    'type'        => 'analysis',
    'parameters'  => array(
        'product_ids' => array( 101, 102, 103 ),
        'metrics'     => array( 'sales', 'conversion_rate', 'reviews' ),
        'time_period' => '30_days',
        'create_report' => true,
    ),
);

$result = $orchestrator->execute_team_workflow( $team, $task, $context );
```

---

## Monitoring and Troubleshooting

### Checking Team Status

```php
// Get team capacity metrics
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
$capacity = $orchestrator->get_team_capacity_metrics();

if ( $capacity ) {
    echo "System Status: " . $capacity['health_status'];
    echo "Available Capacity: " . $capacity['available_capacity'] . "%";
    echo "Utilization: " . $capacity['overall_utilization'] . "%";
}
```

### Common Issues and Solutions

#### Issue 1: "No suitable agents available"

**Cause:** No professions have been seeded with agent roles.

**Solution:**
```bash
wp profession seed-orchestration
```

#### Issue 2: "System capacity is critical"

**Cause:** Too many concurrent workflows or resource constraints.

**Solution:**
- Wait for current workflows to complete
- For critical tasks, set `'priority' => 'critical'` in task requirements

#### Issue 3: Team composition fails

**Cause:** Missing profession data or invalid task type.

**Solution:**
- Verify professions exist: `wp post list --post_type=mcp_ai_profession`
- Check task type is valid: `research`, `content`, `ecommerce`, `development`, or `generic`

### Logging

Enable detailed logging for troubleshooting:

```php
// In wp-config.php
define( 'WP_MCP_AI_DEBUG', true );

// View logs
$logs = get_option( 'wp_mcp_ai_recent_activity', array() );
```

---

## Best Practices

### 1. Task Design

**DO:**
- ✅ Provide clear, specific task descriptions
- ✅ Include relevant context and constraints
- ✅ Set appropriate quality levels
- ✅ Use task types that match your needs

**DON'T:**
- ❌ Create overly broad or vague tasks
- ❌ Skip task parameters that help agents
- ❌ Set unrealistic deadlines
- ❌ Ignore validation feedback

### 2. Team Composition

**DO:**
- ✅ Use predefined templates for common task types
- ✅ Specify expertise requirements when needed
- ✅ Include validation steps for quality-critical work
- ✅ Monitor team capacity before large workflows

**DON'T:**
- ❌ Create unnecessarily large teams
- ❌ Skip the critic role for important content
- ❌ Ignore system capacity warnings
- ❌ Hardcode specific agent IDs (use profession preferences instead)

### 3. Workflow Execution

**DO:**
- ✅ Handle errors gracefully with `is_wp_error()` checks
- ✅ Process results incrementally for long workflows
- ✅ Track execution time and performance
- ✅ Save important results for audit trails

**DON'T:**
- ❌ Run critical workflows without testing
- ❌ Ignore step failures in non-critical steps
- ❌ Block UI while workflows execute
- ❌ Run multiple high-priority workflows simultaneously

### 4. Result Aggregation

**DO:**
- ✅ Choose aggregation strategy based on use case
- ✅ Weight results by agent confidence when available
- ✅ Validate aggregated results before taking action
- ✅ Keep audit trail of individual agent results

**DON'T:**
- ❌ Always use the same aggregation strategy
- ❌ Ignore low-confidence results entirely
- ❌ Over-weight a single agent's opinion
- ❌ Discard minority viewpoints without review

---

## Advanced Usage

### Custom Team Templates

Register custom team templates for specific use cases:

```php
add_filter( 'wp_mcp_ai_team_templates', function( $templates ) {
    $templates['seo_audit'] = array(
        'name'     => __( 'SEO Audit Team', 'your-plugin' ),
        'roles'    => array( 'specialist', 'executor', 'critic' ),
        'workflow' => array(
            array(
                'name' => 'audit',
                'type' => 'delegate',
                'role' => 'specialist',
            ),
            array(
                'name' => 'implement',
                'type' => 'delegate',
                'role' => 'executor',
            ),
            array(
                'name' => 'validate',
                'type' => 'validate',
                'role' => 'critic',
            ),
        ),
    );
    
    return $templates;
});
```

### Manual Role Assignment

Programmatically assign roles to specific professions:

```php
$profession_id = 123;

// Set primary role
update_post_meta(
    $profession_id,
    WP_MCP_AI_Profession_CPT::META_AGENT_ROLE,
    'executor'
);

// Set secondary roles
update_post_meta(
    $profession_id,
    WP_MCP_AI_Profession_CPT::META_AGENT_SECONDARY_ROLES,
    wp_json_encode( array( 'planner' ) )
);

// Set task patterns
$patterns = array(
    'custom_workflow' => array(
        'steps'        => array( 'analyze', 'design', 'implement', 'test' ),
        'dependencies' => array(
            'design'     => 'analyze',
            'implement'  => 'design',
            'test'       => 'implement',
        ),
        'tools'        => array( 'web_search', 'create_post', 'save_post' ),
    ),
);

update_post_meta(
    $profession_id,
    WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS,
    wp_json_encode( $patterns )
);
```

### Performance Optimization

For high-volume workflows:

```php
// 1. Use team caching
$team_id = 'research_team_cache_key';
$cached_team = get_transient( $team_id );

if ( false === $cached_team ) {
    $cached_team = $orchestrator->compose_team( array( 'task_type' => 'research' ) );
    set_transient( $team_id, $cached_team, HOUR_IN_SECONDS );
}

// 2. Parallel task execution (when supported)
$tasks = array( $task1, $task2, $task3 );
$results = array();

foreach ( $tasks as $task ) {
    // Execute tasks in parallel if infrastructure supports it
    $results[] = $orchestrator->execute_team_workflow( $team, $task, $context );
}

// 3. Monitor performance
$execution_time = $result['execution_time'];
if ( $execution_time > 60 ) {
    // Log slow workflows for optimization
    error_log( "Slow workflow detected: {$task['description']} took {$execution_time}s" );
}
```

---

## Resources

### Further Reading

- **Implementation Summary:** `/docs/DEEPSEEK-V4-IMPLEMENTATION-SUMMARY.md`
- **Validation Results:** `/docs/DEEPSEEK-V4-VALIDATION-RESULTS.md`
- **Test Suite:** `/tests/test-deepseek-v4-orchestration-validation.php`

### CLI Commands Reference

```bash
# Orchestration commands
wp profession seed-orchestration [--force]
wp profession orchestration-stats

# Plugin commands
wp mcp-ai status
wp mcp-ai plugins list
```

### Support

- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation:** See `/docs/` directory
- **Contributing:** See `CONTRIBUTING.md`

---

**Last Updated:** January 18, 2026  
**Version:** 1.0.0  
**Maintained By:** NV Digital Solutions
