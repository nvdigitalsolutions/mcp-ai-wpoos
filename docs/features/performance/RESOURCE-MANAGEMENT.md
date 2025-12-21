# Dynamic AI Resource Management

## Overview

The WP oOS plugin implements a computer-implemented method for managing resource budgets during real-time streaming events. The system automatically detects available server resources and adjusts AI operation parameters accordingly, providing intelligent orchestration for WordPress-based AI operations.

> **📖 Related:** For a comprehensive explanation of how these resource management features differentiate WP oOS from standard SSE and MCP implementations, see [ORCHESTRATION-LAYER-ARCHITECTURE.md](../../architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)

## Computer-Implemented Method Architecture

This document describes a system comprising a processor and memory storing instructions that, when executed, implement a comprehensive resource management workflow. The implementation is embodied in non-transitory computer-readable media (PHP source files) distributed as part of the WP oOS plugin.

### Core Method Claims

The system performs the following computer-implemented method for managing resources during real-time AI streaming events:

1. **Dynamically Allocating Resource Budgets**: Token and memory budgets are allocated to tool execution requests within an orchestration layer based on runtime system metrics
2. **Enforcing Capability-Based Access**: Access to execution endpoints is controlled through WordPress capability checks enforced at the REST API boundary

## Patent Claims Implementation Summary

This resource management system implements the following method and system claims:

### Independent Method Claim

**A computer-implemented method for managing resource budgets during real-time streaming events**, comprising:

1. **Dynamically allocating a token and memory budget** to tool execution requests within an orchestration layer
   - Implemented through `WP_MCP_AI_Resource_Manager::get_max_tokens()` and `get_request_timeout()`
   - Budget allocation occurs before each tool execution request is dispatched

2. **Enforcing capability-based access** to execution endpoints
   - Implemented through `WP_MCP_AI_REST` permission callbacks
   - WordPress capability checks at REST API boundaries (`/tools`, `/chat` endpoints)

3. **Scheduling tool execution** based on registry state and policy constraints
   - Implemented through `WP_MCP_AI_Tool_Registry` state management
   - Conditional tool loading based on dependency availability and assistant configuration

4. **Adjusting said budgets in response to system metrics** to reduce resource exhaustion and latency
   - Implemented through workload tier determination based on memory/execution time metrics
   - Dynamic token limit and timeout adjustments applied to all AI provider clients

### Independent System Claim

**A system** comprising:

- A processor (web server CPU) and memory (server RAM) storing instructions to perform the above method
- Embodied in the WP oOS plugin's PHP source files deployed on WordPress-compatible hosting

### Computer-Readable Medium Claim

**A non-transitory computer-readable medium** storing instructions that, when executed by a web server processor, cause the system to perform the method described above:

- PHP source files: `includes/class-resource-manager.php`, `includes/class-wp-mcp-ai-rest.php`, `includes/class-wp-mcp-ai-tool-registry.php`, `includes/services/class-wp-mcp-ai-token-budget-service.php`, `includes/class-wp-mcp-ai-cron-manager.php`
- Configuration stored in WordPress database tables
- Distributed as installable WordPress plugin package

### Additional Claims - Cron-Based Task Orchestration Extension

**The system of claim 1, further comprising:**

**A time-based scheduling subsystem** configured to execute deferred or recurring operations created by authorized agents, wherein:
- Said operations inherit the same resource budgets and capability constraints as real-time sessions
- Each scheduled operation is validated against predictive budget forecasts before dispatch to prevent resource contention
- The scheduling subsystem maintains an internal registry (`wp_mcp_ai_cron_jobs`) of active tasks with:
  - Unique job identifiers for tracking and management
  - User attribution (created_by) for compliance auditing
  - Creation timestamps and execution intervals
  - Inherited budget constraints from orchestration layer
- Scheduled operations are subject to the same capability-based access controls as real-time operations
- Automatic cleanup of completed or cancelled jobs from the registry

**The method of claim 1** wherein:
- Scheduled operations are validated against predictive budget forecasts before dispatch to prevent resource contention
- Each deferred operation inherits token and memory budgets from the orchestration layer's resource manager
- User attribution is maintained throughout the lifecycle of scheduled operations for compliance auditing
- Policy constraints are re-verified at execution time to ensure continued authorization

## Orchestration Layer Features

### Real-Time System Metrics Detection

The orchestration layer, implemented through the `WP_MCP_AI_Resource_Manager` singleton, performs continuous real-time detection of system metrics:

- **PHP Memory Limit** - Detected via `ini_get('memory_limit')` and converted to bytes for consistent resource calculations
- **Max Execution Time** - Monitored via `ini_get('max_execution_time')` to ensure API timeouts don't exceed PHP limits, preventing incomplete operations

### Dynamic Budget Allocation Through Workload Tiers

Based on detected memory limit metrics, the orchestration layer dynamically determines a workload tier and allocates corresponding resource budgets:

| Tier | Memory Limit | Max Tokens (default) | Request Timeout (default) |
|------|--------------|---------------------|---------------------------|
| **Low** | < 128M | 1,000 | 30s |
| **Medium** | 128M - 512M | 4,000 | 60s |
| **High** | ≥ 512M | 16,000 | 120s |

### Policy-Driven Parameter Selection

The Resource Manager orchestrates policy-driven parameter selection, automatically adjusting operational parameters for AI API calls based on system state:

- **Max Tokens**: Dynamically limits response size based on available memory metrics to prevent resource exhaustion
- **Request Timeout**: Adjusts timeouts in response to PHP execution time constraints to reduce incomplete operations and latency

These adjustments occur within the orchestration layer before tool execution requests are dispatched to external AI providers.


## Capability-Based Access Control

The orchestration layer enforces capability-based access to execution endpoints through the REST API controller (`WP_MCP_AI_REST`):

### Access Enforcement Mechanism

1. **Endpoint Authentication**: All tool execution requests through `/wp-json/mcp-ai/v1/tools` and `/wp-json/mcp-ai/v1/chat` validate user authentication before processing
2. **Capability Validation**: Each request is checked against WordPress capability requirements, with the default capability filtered through `wp_mcp_ai_chat_capability`
3. **Tool-Specific Permissions**: Individual tools declare required capabilities (e.g., `manage_options` for administrative tools) that are enforced before execution
4. **Assistant-Scoped Access**: Tool execution is further constrained by assistant configuration, limiting available tools based on per-assistant policy

This capability-based system ensures that only authorized users can invoke specific tool execution endpoints within the orchestration layer.

## Registry-State-Based Scheduling

The `WP_MCP_AI_Tool_Registry` singleton maintains tool availability state and schedules execution based on registry state and policy constraints:

### Tool Registry State Management

1. **Dependency Detection**: On initialization, the registry detects plugin dependencies (WooCommerce, JetEngine, Elementor) and conditionally loads tools based on availability
2. **State Tracking**: The registry maintains a `$tools` array mapping tool slugs to instantiated tool objects, representing current system state
3. **Policy-Constrained Loading**: Tools are loaded based on policy constraints including:
   - Base version mode (controlled by `WP_MCP_AI_BASE_VERSION` constant)
   - Plugin dependency availability
   - Administrator configuration settings

### Execution Scheduling

When a tool execution request arrives:

1. **Registry Lookup**: The orchestration layer queries the registry for the requested tool slug
2. **State Validation**: Confirms the tool is registered and available in current system state
3. **Assistant Policy Check**: Validates the tool is enabled in the target assistant's configuration
4. **Execution Dispatch**: If all constraints pass, the tool's `execute()` method is invoked with allocated resource budgets

This registry-based approach ensures tool execution is scheduled according to current system state and policy constraints rather than unconditional processing.

## Cron-Based Task Orchestration Extension

The orchestration layer further integrates with a **time-based scheduling subsystem** ("Cron Manager") that allows AI agents to autonomously create, monitor, and delete scheduled background operations. Each scheduled operation inherits the same budget and capability constraints defined by the orchestration layer, ensuring policy compliance during deferred execution.

### Internal Registry Management

The Cron Manager maintains an internal registry (`wp_mcp_ai_cron_jobs`) of active tasks stored in the WordPress options table. Each registered job contains:

1. **Unique Job Identifier**: MD5 hash of hook name and arguments for consistent identification
2. **User Attribution**: `created_by` field tracking which user scheduled the operation for compliance auditing
3. **Creation Timestamp**: Unix timestamp of when the job was registered (`created_at`)
4. **Execution Schedule**: Recurrence pattern (`single`, `hourly`, `daily`, etc.) or one-time execution
5. **First Execution Time**: Initial timestamp when the job should first execute (`first_timestamp`)
6. **Hook and Arguments**: WordPress cron hook name and normalized argument array

### Budget Inheritance and Validation

Scheduled operations inherit resource budgets from the orchestration layer:

1. **Pre-Scheduling Validation**: Before creating a scheduled operation, the system validates against current resource budgets through `WP_MCP_AI_Resource_Manager::get_max_tokens()`
2. **Predictive Budget Forecasts**: Scheduled events are evaluated against system resource budgets prior to dispatch, allowing predictive load distribution
3. **Execution-Time Budget Application**: When a scheduled operation executes, it receives the same dynamically-allocated token and memory budgets as real-time requests
4. **Capability Re-Verification**: At execution time, capability constraints are re-checked to ensure continued authorization

### Compliance Auditing Across Asynchronous Workloads

The Cron Manager enables comprehensive compliance auditing for deferred operations:

- **User Accountability**: Every scheduled operation is attributed to the user who created it (`created_by` field)
- **Temporal Tracking**: Creation timestamps and execution intervals are logged for forensic analysis
- **Policy Enforcement**: Scheduled operations require `manage_options` capability, limiting scheduling to administrators
- **Audit Trail**: All scheduled operations are visible through cron management tools (`list_cron_jobs`, `get_cron_job`)
- **Automatic Cleanup**: The registry automatically prunes entries for jobs that are no longer scheduled, maintaining data hygiene

### Implementation Architecture

```php
// Cron Manager implementation
class WP_MCP_AI_Cron_Manager {
    const OPTION_NAME = 'wp_mcp_ai_cron_jobs';
    
    // Record job with user attribution
    public static function record_job($hook, $args, $schedule, $timestamp, $user_id) {
        $job_id = self::generate_job_id($hook, $args);
        
        $jobs[$job_id] = array(
            'job_id'          => $job_id,
            'hook'            => $hook,
            'args'            => self::normalise_args($args),
            'schedule'        => $schedule,
            'first_timestamp' => $timestamp,
            'created_at'      => time(),
            'created_by'      => $user_id,  // User attribution
        );
        
        self::save_jobs($jobs);
        return $job_id;
    }
    
    // Automatic cleanup of completed jobs
    public static function maybe_prune_jobs() {
        $jobs = self::load_jobs();
        $changed = false;
        
        foreach ($jobs as $job_id => $job) {
            $event = wp_get_scheduled_event($job['hook'], $job['args']);
            if (!$event) {
                unset($jobs[$job_id]);  // Remove if no longer scheduled
                $changed = true;
            }
        }
        
        if ($changed) {
            self::save_jobs($jobs);
        }
    }
}
```

### Integration with Tool Registry

Cron management tools are integrated into the orchestration layer through the tool registry:

- **create_cron_job**: Creates scheduled operations with capability checks and budget validation
- **list_cron_jobs**: Lists all scheduled operations with user attribution and execution details
- **get_cron_job**: Retrieves detailed information about a specific scheduled operation
- **delete_cron_job**: Removes scheduled operations from both WordPress scheduler and internal registry

All cron tools require `manage_options` capability, ensuring only administrators can manage scheduled operations.

### Predictive Load Distribution

The Cron Manager enables predictive load distribution across asynchronous workloads:

1. **Temporal Spreading**: Operations can be scheduled during off-peak hours to reduce real-time resource contention
2. **Budget Forecasting**: Before scheduling, the system can predict resource requirements based on operation type
3. **Resource Reservation**: Scheduled operations can be counted against future resource budgets for capacity planning
4. **Execution Throttling**: Multiple scheduled operations executing simultaneously respect the same resource budgets as real-time operations

This predictive approach transforms the orchestration layer from reactive resource management to proactive capacity planning.

## System Metrics Monitoring and Budget Adjustment

The orchestration layer continuously monitors system metrics and adjusts resource budgets in response to reduce resource exhaustion and latency:

### Monitored Metrics

1. **Memory Usage**: PHP memory limit and available memory tracked through `get_memory_limit()`
2. **Execution Time**: Maximum execution time monitored via `get_max_execution_time()`
3. **Token Consumption**: Estimated token usage calculated through `WP_MCP_AI_Token_Budget_Manager::estimate_tokens()`
4. **API Response Patterns**: Tracked through usage tracker (`WP_MCP_AI_Usage_Tracker`) for per-user, per-provider metrics

### Dynamic Budget Adjustments

Based on detected metrics, the system performs real-time adjustments:

1. **Token Budget Scaling**: The `WP_MCP_AI_Token_Budget_Manager` adjusts max token allocations based on model limits and available resources, implementing safety margins to prevent API limit overruns
2. **Timeout Adjustments**: Request timeouts are dynamically set to `min(tier_timeout, max_execution_time - 5)` to ensure completion before PHP timeout
3. **Workload Tier Transitions**: Memory tier determination affects all downstream operations through filterable `wp_mcp_ai_workload_tier` hook
4. **Provider-Specific Limits**: Individual AI provider clients receive adjusted limits via filters (`wp_mcp_ai_openai_max_tokens`, `wp_mcp_ai_gemini_max_output_tokens`, etc.)

### Preventing Resource Exhaustion

The orchestration layer implements multiple safeguards:

- **Memory Ceiling Enforcement**: Operations requiring tokens exceeding tier limits return `WP_Error` with code `wp_mcp_ai_insufficient_resources`
- **Chunking for Large Documents**: Documents exceeding token budgets are automatically split into manageable chunks with overlap
- **Graceful Degradation**: When resources are constrained, the system reduces operation scope rather than failing completely
- **Audit Logging**: Resource allocation decisions are logged through `WP_MCP_AI_Logger` for post-operation analysis

This metrics-driven approach ensures the system operates within available resource constraints while maintaining stability during real-time streaming events.

## Integration with AI Clients

The Resource Manager is integrated with all supported AI providers:

### OpenAI Client
- Uses `max_completion_tokens` parameter
- Falls back to `max_tokens` if explicitly provided

### Gemini Client
- Uses `maxOutputTokens` in `generationConfig`
- Supports both `max_tokens` and `max_output_tokens` options

### Ollama Client
- Uses `num_predict` in options
- Supports both `max_tokens` and `num_predict` options

### LM Studio Client
- Uses `max_tokens` parameter
- Compatible with OpenAI API format

## Usage

### Automatic Application

Resource limits are automatically applied when making AI requests. No code changes are required.

```php
// The Resource Manager automatically provides appropriate limits
$response = $router->create_chat_completion( $messages, $options );
```

### Explicit Override

You can override resource limits by specifying values in the options array:

```php
$options = [
    'max_tokens' => 8000,  // Override automatic limit
    'timeout'    => 90,    // Override automatic timeout
];

$response = $router->create_chat_completion( $messages, $options );
```

### Accessing the Resource Manager

```php
// Get the singleton instance
$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

// Check current tier
$tier = $resource_mgr->get_workload_tier(); // 'low', 'medium', or 'high'

// Get recommended values
$max_tokens = $resource_mgr->get_max_tokens();
$timeout    = $resource_mgr->get_request_timeout();

// Check if operation is feasible
$result = $resource_mgr->can_handle_operation([
    'max_tokens' => 8000
]);

if ( is_wp_error( $result ) ) {
    // Handle insufficient resources
    $error_message = $result->get_error_message();
}
```

## Customization via Filters

### Workload Tier

Override the automatically detected workload tier:

```php
add_filter( 'wp_mcp_ai_workload_tier', function( $tier, $memory_limit ) {
    // Force high tier for powerful servers
    if ( $memory_limit > 1024 * 1024 * 1024 ) { // > 1GB
        return 'high';
    }
    return $tier;
}, 10, 2 );
```

### Global Max Tokens

Adjust the default max tokens for all providers:

```php
add_filter( 'wp_mcp_ai_resource_max_tokens', function( $max_tokens, $tier ) {
    // Increase limits for all tiers
    $limits = [
        'low'    => 2000,
        'medium' => 8000,
        'high'   => 32000,
    ];
    return isset( $limits[ $tier ] ) ? $limits[ $tier ] : $max_tokens;
}, 10, 2 );
```

### Global Request Timeout

Adjust the default request timeout:

```php
add_filter( 'wp_mcp_ai_resource_request_timeout', function( $timeout, $tier, $max_execution_time ) {
    // Use custom timeout values
    $timeouts = [
        'low'    => 20,
        'medium' => 45,
        'high'   => 90,
    ];
    return isset( $timeouts[ $tier ] ) ? $timeouts[ $tier ] : $timeout;
}, 10, 3 );
```

### Provider-Specific Filters

Customize limits for specific AI providers:

```php
// OpenAI
add_filter( 'wp_mcp_ai_openai_max_tokens', function( $max_tokens, $options ) {
    return 10000; // Custom limit for OpenAI
}, 10, 2 );

// Gemini
add_filter( 'wp_mcp_ai_gemini_max_output_tokens', function( $max_output_tokens, $options ) {
    return 12000; // Custom limit for Gemini
}, 10, 2 );

// Ollama
add_filter( 'wp_mcp_ai_ollama_num_predict', function( $num_predict, $options ) {
    return 8000; // Custom limit for Ollama
}, 10, 2 );

// LM Studio
add_filter( 'wp_mcp_ai_lm_studio_max_tokens', function( $max_tokens, $options ) {
    return 15000; // Custom limit for LM Studio
}, 10, 2 );
```

## Backward Compatibility

The Resource Manager maintains full backward compatibility:

1. **Existing Settings Honored**: If `request_timeout` is configured in plugin settings, it takes precedence
2. **Explicit Options Respected**: Parameters passed in the `$options` array always override automatic values
3. **No Breaking Changes**: The system only provides defaults when values are not explicitly set

## Error Handling

When server resources are insufficient for a requested operation, the system returns a descriptive error:

```php
$result = $resource_mgr->can_handle_operation([
    'max_tokens' => 50000
]);

if ( is_wp_error( $result ) ) {
    // Error code: 'wp_mcp_ai_insufficient_resources'
    // Error data includes: tier, max_tokens, requested_tokens
    $data = $result->get_error_data();
    
    error_log( sprintf(
        'Operation requires %d tokens but server (tier: %s) supports max %d tokens',
        $data['requested_tokens'],
        $data['tier'],
        $data['max_tokens']
    ));
}
```

## Best Practices

1. **Let Automatic Limits Work**: In most cases, the automatic limits are appropriate. Only override when you have a specific need.

2. **Monitor Resource Usage**: If you frequently encounter resource limit errors, consider upgrading your hosting or adjusting limits via filters.

3. **Test Custom Limits**: When using filters to adjust limits, test thoroughly to ensure your server can handle the increased load.

4. **Use can_handle_operation**: Before initiating complex operations, check if resources are sufficient:
   ```php
   if ( is_wp_error( $resource_mgr->can_handle_operation( $requirements ) ) ) {
       // Show user-friendly error or queue operation
   }
   ```

5. **Consider Context**: Different operations may need different limits. Use provider-specific filters to fine-tune behavior.

## Technical Details

### Memory Limit Detection

The system uses `ini_get('memory_limit')` to detect the PHP memory limit and converts it to bytes:

- Supports K, M, and G suffixes
- Handles unlimited (-1) by defaulting to 512MB
- Caches the result for performance

### Execution Time Considerations

Request timeouts are automatically adjusted to be less than `max_execution_time`:

```php
$timeout = min( $base_timeout, max_execution_time - 5 );
```

This ensures API requests complete before PHP times out, preventing incomplete operations.

### Singleton Pattern

The Resource Manager uses a singleton pattern to ensure consistent resource detection across the plugin:

```php
$manager = WP_MCP_AI_Resource_Manager::instance();
```

## Testing

Unit tests are provided in `tests/test-resource-manager.php`:

```bash
vendor/bin/phpunit tests/test-resource-manager.php
```

Tests cover:
- Memory limit detection
- Execution time detection  
- Workload tier calculation
- Max tokens recommendations
- Timeout recommendations
- Operation feasibility checks
- Filter functionality

## Troubleshooting

### Issue: Responses are truncated
**Solution**: Your server may be on a low tier. Check with:
```php
$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
error_log( 'Tier: ' . $resource_mgr->get_workload_tier() );
error_log( 'Max tokens: ' . $resource_mgr->get_max_tokens() );
```

### Issue: Requests timeout frequently
**Solution**: Check your execution time limit:
```php
error_log( 'Max execution time: ' . ini_get('max_execution_time') );
error_log( 'Recommended timeout: ' . $resource_mgr->get_request_timeout() );
```

### Issue: Want to disable automatic limits
**Solution**: Use filters to return high values or always pass explicit options:
```php
add_filter( 'wp_mcp_ai_resource_max_tokens', function() {
    return 999999; // Effectively unlimited
}, 10 );
```

## Future Enhancements

Potential future improvements to the resource management system:

1. **Dynamic Adjustment**: Monitor actual resource usage and adjust limits in real-time
2. **Operation Queuing**: Automatically queue operations that exceed current capacity
3. **Multi-tier Caching**: Cache responses based on resource tier
4. **Resource Pooling**: Share resources across multiple concurrent requests
5. **Admin UI**: Provide settings page for manual tier override and monitoring
