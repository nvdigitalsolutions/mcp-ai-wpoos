# Async Tool Execution Fix

## Problem

Async tool execution cron jobs were being scheduled but never executed. When users queued tools for async execution:

1. ✅ Job was queued successfully  
2. ✅ Cron job was scheduled in WP-Cron
3. ✅ Job was tracked in Cron Manager
4. ❌ **Tool never executed** when cron fired
5. ❌ No result was stored
6. ❌ Job showed as "Executed" but with no actual output

### Root Cause

The `WP_MCP_AI_Tool_Async_Executor::init()` method was never called during plugin initialization, so the cron hook handler was never registered:

```php
// This line in the executor's init() method was never executed:
add_action( 'wp_mcp_ai_async_tool_execution', array( $this, 'execute_async_tool' ), 10, 1 );
```

When WP-Cron fired the `wp_mcp_ai_async_tool_execution` action, WordPress looked for registered callbacks but found none, so nothing happened.

## Solution

Initialize the async executor during plugin bootstrap by hooking to the `wp_mcp_ai_bootstrapped` action:

```php
// In wp-mcp-ai.php
add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_async_executor', 5 );

function wp_mcp_ai_init_async_executor() {
    wp_mcp_ai_get_async_tool_executor();
}
```

This ensures:
1. WordPress core is fully loaded
2. Plugin services are initialized  
3. Executor registers its cron hook handler
4. When WP-Cron fires, the handler exists and tools execute

## Separation of Concerns

The fix maintains proper SOC:

- **Executor class** (`WP_MCP_AI_Tool_Async_Executor`): Responsible for tool execution logic
- **Main plugin file** (`wp-mcp-ai.php`): Responsible for initialization timing via WordPress hooks
- **Services init** (`services-init.php`): Provides getter function for executor instance

No mixing of concerns between scheduling, execution, and initialization.

## Flow After Fix

1. User/LLM calls tool with async execution
2. Orchestrator determines tool should run async
3. Executor queues job and schedules cron
4. **Cron Manager tracks the job**
5. WP-Cron fires `wp_mcp_ai_async_tool_execution` hook
6. **Executor's registered handler receives the hook**
7. **Tool executes and stores result**
8. Result is retrievable via `get_result($job_id)`
9. Cron status bar shows completed job

## Testing

See `tests/test-async-executor-initialization.php`:

- `test_executor_init_registers_cron_hook()` - Verifies hook is registered
- `test_cron_job_executes_tool()` - Verifies tools actually execute
- `test_executor_initialized_during_bootstrap()` - Verifies bootstrap hook works

## Related Files

- `wp-mcp-ai.php` - Hook registration
- `includes/services-init.php` - Executor getter function
- `includes/services/class-wp-mcp-ai-tool-async-executor.php` - Executor class
- `includes/services/class-wp-mcp-ai-async-tool-orchestrator.php` - Routing logic
- `tests/test-async-executor-initialization.php` - Tests
