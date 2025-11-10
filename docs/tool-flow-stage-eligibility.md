# Flow Stage Eligibility for Tools

**Version:** 1.0.0  
**Last Updated:** November 10, 2024

This document explains the flow stage eligibility feature for tools in WP Open Operator System (WP oOS), which allows tools to declare when they can be invoked during an agentic workflow.

---

## Overview

The flow stage eligibility system enables tools to specify which stages of an agentic workflow they can participate in. This prevents tools from being invoked at inappropriate times and helps orchestrate more controlled, predictable workflows.

### Available Stages

- **`anytime`** - Tool can be used at any stage (default behavior)
- **`start`** - Tool can only be used in the first iteration (iteration 0)
- **`middle`** - Tool can only be used in middle iterations (1 to n-1)
- **`end`** - Tool can only be used in the final iteration

Tools can declare multiple eligible stages, e.g., `array('start', 'middle')` to allow execution in the start and middle stages but not the end.

---

## Implementation

### 1. Declaring Flow Stage Eligibility

Tools implement the `WP_MCP_AI_Tool_Flow_Stage_Interface` to declare their eligible stages:

```php
<?php
class WP_MCP_AI_Tool_Example implements 
    WP_MCP_AI_Tool_Interface, 
    WP_MCP_AI_Tool_Flow_Stage_Interface {
    
    // ... standard tool methods ...
    
    /**
     * Declare this tool can only be used at the start of a workflow.
     */
    public function get_flow_stages() {
        return array( 'start' );
    }
}
```

### 2. Stage Detection

The system automatically determines the current flow stage based on:

1. **Explicit `flow_stage` in context** - Direct specification
2. **Iteration context** - Calculated from `iteration` and `max_iterations`:
   - Iteration 0 = `start`
   - Iteration n-1 (last) = `end`
   - All others = `middle`

### 3. Validation

Before executing a tool, the registry validates:

```php
$validation = $registry->validate_tool_flow_stage( $tool_slug, $context );
if ( is_wp_error( $validation ) ) {
    // Tool cannot execute in current stage
    return $validation;
}
```

---

## Use Cases

### Example 1: Initialization Tool (Start Only)

A tool that sets up context or retrieves initial data should only run at the start:

```php
public function get_flow_stages() {
    return array( 'start' );
}
```

**Example:** A tool that fetches user preferences or initializes a session.

### Example 2: Data Processing Tool (Middle Only)

A tool that processes intermediate results should only run in the middle:

```php
public function get_flow_stages() {
    return array( 'middle' );
}
```

**Example:** A tool that aggregates or transforms data collected by other tools.

### Example 3: Finalization Tool (End Only)

A tool that performs cleanup or generates final output should only run at the end:

```php
public function get_flow_stages() {
    return array( 'end' );
}
```

**Example:** A tool that sends notifications, saves results, or generates reports.

### Example 4: Multi-Stage Tool (Start and Middle)

A tool that can be used early but not at the end:

```php
public function get_flow_stages() {
    return array( 'start', 'middle' );
}
```

**Example:** A search tool that gathers information but shouldn't be called for final output.

---

## Context Requirements

To enable flow stage validation, execution contexts should include:

```php
$context = array(
    'iteration'      => 0,          // Current iteration number
    'max_iterations' => 5,          // Total allowed iterations
    // ... other context data
);
```

Or explicitly specify the stage:

```php
$context = array(
    'flow_stage' => 'start',  // Explicit stage declaration
    // ... other context data
);
```

---

## Error Handling

When a tool is invoked in an ineligible stage, a `WP_Error` is returned:

```php
WP_Error {
    code: 'tool_flow_stage_not_eligible',
    message: 'Tool "example_tool" cannot be used in the "middle" stage. Eligible stages: start',
    data: array(
        'tool' => 'example_tool',
        'current_stage' => 'middle',
        'eligible_stages' => array('start'),
    )
}
```

---

## API Reference

### Tool Interface

```php
interface WP_MCP_AI_Tool_Flow_Stage_Interface {
    /**
     * Retrieve the eligible flow stages for this tool.
     *
     * @return array<string> Array of eligible stage identifiers.
     */
    public function get_flow_stages();
}
```

### Registry Methods

```php
// Get eligible stages for a tool
$stages = $registry->get_tool_flow_stages( 'tool_slug' );

// Get all tools with stage restrictions
$all_stages = $registry->get_all_tool_flow_stages();

// Validate tool eligibility for current stage
$validation = $registry->validate_tool_flow_stage( 'tool_slug', $context );

// Determine current stage from context
$stage = $registry->determine_flow_stage( $context );
```

---

## Integration with Agentic Workflows

The chat service and REST API automatically pass iteration context to tools:

### In Chat Service

```php
$tool_results = $this->execute_tool_calls( 
    $tool_calls, 
    $assistant_id, 
    $assistant_config, 
    $iteration,        // Current iteration
    $max_iterations    // Max iterations
);
```

### In REST API

```php
$tool_result = $this->execute_tool_call_internal( 
    $tool_call, 
    $assistant_id, 
    $assistant_config, 
    $user_id, 
    $request, 
    $iteration,        // Current iteration
    $max_iterations    // Max iterations
);
```

---

## Backward Compatibility

Tools without flow stage restrictions (`anytime` default):
- Continue to work as before
- Can be invoked at any stage
- No changes required to existing tools

---

## Testing

Comprehensive test coverage in `tests/test-tool-flow-stages.php` includes:

- Stage detection from context
- Validation for restricted tools
- Validation for multi-stage tools
- Validation for unrestricted tools
- Error handling for ineligible stages
- Integration with tool execution

---

## Best Practices

1. **Be Specific**: Only restrict stages when necessary
2. **Document Reasons**: Comment why a tool has stage restrictions
3. **Test Thoroughly**: Verify tools work in all eligible stages
4. **Handle Errors**: Gracefully handle stage eligibility errors in UI
5. **Use Anytime Default**: Most tools should remain unrestricted

---

## Future Enhancements

Potential future additions:
- Dynamic stage determination based on workflow state
- Custom stage definitions per assistant
- Stage-based tool prioritization
- UI indicators for stage-restricted tools
- Stage transition hooks and events

---

## See Also

- [Tool Interface Documentation](./tool-reference.md)
- [Agentic Workflow Architecture](./agentic-workflow-architecture.md)
- [Tool Registry API](./TECHNICAL-REFERENCE.md)
- [Orchestration Layer](./ORCHESTRATION-LAYER-ARCHITECTURE.md)
