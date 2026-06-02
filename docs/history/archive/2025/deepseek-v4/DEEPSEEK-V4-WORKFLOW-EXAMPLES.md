# DeepSeek V4 Orchestration - End-to-End Workflow Examples

**Practical, tested examples of multi-agent workflows in action**

**Version:** 1.0.0  
**Date:** January 18, 2026  
**Status:** Production-ready examples

---

## Overview

This document provides complete, copy-paste ready examples of multi-agent workflows that have been validated. Each example includes:

- Complete PHP code ready to run
- Expected inputs and outputs
- Error handling
- Chat-client usage examples

---

## Example 1: Competitive Research Report

**Scenario:** Research competitors and create a comprehensive report with validation.

### Complete Workflow Code

```php
<?php
/**
 * Example 1: Competitive Research Report
 * 
 * Creates a research team, executes research, validates quality, saves report.
 */

// Initialize orchestrator
$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

// Step 1: Compose research team
$team = $orchestrator->compose_team( array( 'task_type' => 'research' ) );

if ( is_wp_error( $team ) ) {
    wp_die( 'Team composition failed: ' . $team->get_error_message() );
}

echo "✓ Team assembled with " . count( $team['members'] ) . " members\n\n";

// Step 2: Define research task
$task = array(
    'description' => 'Research top 5 WordPress security plugins',
    'type'        => 'research',
    'parameters'  => array(
        'query'        => 'WordPress security plugins market leaders 2026',
        'save_results' => true,
        'title'        => 'Competitive Analysis: WP Security Plugins',
    ),
);

// Step 3: Execute workflow
$result = $orchestrator->execute_team_workflow(
    $team,
    $task,
    array( 'assistant_id' => 1, 'user_id' => get_current_user_id() )
);

// Step 4: Process results
if ( 'completed' === $result['status'] ) {
    echo "✓ Workflow completed in {$result['execution_time']}s\n";
    
    // Check validation
    if ( isset( $result['results']['validate']['validation'] ) ) {
        $validation = $result['results']['validate']['validation'];
        echo "Validation: " . ( $validation['passes'] ? 'PASSED' : 'FAILED' );
        echo " (Score: {$validation['score']})\n";
    }
}
```

### Expected Output

```
✓ Team assembled with 3 members

✓ Workflow completed in 12.3s
Validation: PASSED (Score: 0.87)
```

---

## Example 2: Product Content with Validation

**Scenario:** Create product description with automatic quality validation.

### Complete Code

```php
<?php
/**
 * Example 2: Product Content Creation
 */

$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

// Compose content team (includes critic)
$team = $orchestrator->compose_team( array( 'task_type' => 'content' ) );

// Define content task
$task = array(
    'description' => 'Create product description',
    'type'        => 'creation',
    'parameters'  => array(
        'title'    => 'Premium WordPress Theme',
        'keywords' => array( 'WordPress theme', 'responsive' ),
        'length'   => 800,
        'publish'  => false, // Save as draft
    ),
);

$result = $orchestrator->execute_team_workflow(
    $team,
    $task,
    array( 'assistant_id' => 1 )
);

if ( 'completed' === $result['status'] ) {
    $validation = $result['results']['review']['validation'];
    
    echo "Quality Score: {$validation['score']}\n";
    
    if ( $validation['passes'] && $validation['score'] > 0.85 ) {
        echo "✓ High quality content!\n";
    }
}
```

---

## Example 3: Using Tools Directly

**Scenario:** Using agent coordination tools directly without orchestrator.

### Create Team Tool

```php
<?php
$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
$team_tool = $tool_registry->get_tool( 'create_agent_team' );

$team = $team_tool->execute(
    array(
        'task_type'    => 'analysis',
        'requirements' => array(
            'expertise_needed' => array( 'SEO', 'content optimization' ),
            'quality_level'    => 'validated',
        ),
    ),
    array( 'assistant_id' => 1 )
);

echo "Team created: {$team['team_id']}\n";
foreach ( $team['members'] as $member ) {
    echo "  - {$member['title']} ({$member['role']})\n";
}
```

### Delegate to Agent Tool

```php
<?php
$delegate_tool = $tool_registry->get_tool( 'delegate_to_agent' );

$result = $delegate_tool->execute(
    array(
        'agent_id'        => 123,
        'task'            => 'Research competitor pricing',
        'expected_output' => 'Detailed pricing analysis',
    ),
    array( 'assistant_id' => 1 )
);

echo "Delegation ID: {$result['delegation_id']}\n";
echo "Status: {$result['status']}\n";
```

### Aggregate Results Tool

```php
<?php
$aggregate_tool = $tool_registry->get_tool( 'aggregate_agent_results' );

$aggregated = $aggregate_tool->execute(
    array(
        'agent_results' => array(
            array( 'agent_id' => 123, 'result' => array( 'score' => 85 ) ),
            array( 'agent_id' => 456, 'result' => array( 'score' => 78 ) ),
        ),
        'strategy'      => 'weighted',
        'weights'       => array( 0.6, 0.4 ),
    ),
    array( 'assistant_id' => 1 )
);

echo "Aggregated score: {$aggregated['unified_result']['score']}\n";
echo "Confidence: {$aggregated['confidence']}\n";
```

---

## Example 4: Chat-Client Integration

**Scenario:** Using orchestration from the WordPress chat interface.

### Chat Conversation Example

```
User: "I need to research competitor pricing and create a report."

Assistant (using create_agent_team tool):
"I'll create a specialized research team for this task."

[Tool Call: create_agent_team]
{
  "task_type": "research",
  "requirements": {
    "expertise_needed": ["market research", "pricing analysis"],
    "quality_level": "validated"
  }
}

[Tool Response: Success]
✓ Research team assembled
Team ID: team_abc123...
Members: 3 (Planner, Executor, Critic)