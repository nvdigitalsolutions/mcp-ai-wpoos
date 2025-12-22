# Double-Async Execution Prevention Pattern

## Problem

When a tool has BOTH:
1. **Orchestrator-level async** (via `long-running` capability flag)
2. **Tool-level async** (its own internal async mechanism)

This creates a "double-async" execution issue where:
- Orchestrator queues the tool for async execution (job_id: `async_xxx`)
- Cron executes the tool
- Tool queues ANOTHER async job (job_id: `veo_xxx`)  
- Tool returns nested async response: `{async: true, job_id: 'veo_xxx', status: 'pending'}`
- Async executor stores this nested response as the "result"
- Client polls `async_xxx` and receives the nested async object
- **Client doesn't know it needs to poll `veo_xxx` too!** ❌

## Solution

### 1. Async Executor Sets Context Flag

The async executor (`class-wp-mcp-ai-tool-async-executor.php`) adds a flag to the execution context:

```php
// Add flag to context indicating this tool is running in async executor.
// This prevents double-async execution.
$context['in_async_executor'] = true;

// Execute tool with enhanced context
$result = $tool->execute( $arguments, $context );
```

### 2. Tools Check Context Flag

Tools that have their own async mechanisms must check this flag:

```php
protected function should_use_async( $arguments, $context = array() ) {
    // CRITICAL: If already running in async executor context, do NOT use tool-level async.
    // This prevents double-async execution.
    if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
        return false;
    }
    
    // Normal async logic...
    return true;
}
```

## Affected Tools

Currently implemented in:
1. ✅ `class-wp-mcp-ai-tool-generate-veo-video.php` - Video generation tool
2. ✅ `class-wp-mcp-ai-tool-create-assistant.php` - Assistant creation tool

## When to Apply This Pattern

Apply this pattern when creating a NEW tool that has BOTH:

1. **Capability flag indicating long-running execution:**
   ```php
   public function get_capability_flags() {
       return array(
           'long-running',  // ← Triggers orchestrator async
           'may-timeout',
           'async',
       );
   }
   ```

2. **Internal async mechanism:**
   - Calls a service with `async` parameter
   - Queues its own cron job
   - Returns `{async: true, job_id: 'xxx', status: 'pending'}`

## Testing

Tests are provided to verify the pattern:
- `tests/test-veo-double-async-fix.php` - Video tool
- `tests/test-create-assistant-double-async-fix.php` - Assistant tool  
- `tests/test-async-executor-context-flag.php` - Async executor

## Example Implementation

```php
class WP_MCP_AI_Tool_My_Long_Running_Tool implements WP_MCP_AI_Tool_Interface {
    
    public function execute( array $arguments = array(), array $context = array() ) {
        // Check if we should use internal async mechanism
        $use_async = $this->should_use_async( $arguments, $context );
        
        // Call service with async flag
        $result = $service->do_something( array(
            'prompt' => $prompt,
            'async'  => $use_async,  // ← Will be false when in executor context
        ));
        
        return $result;
    }
    
    protected function should_use_async( $arguments, $context = array() ) {
        // CRITICAL: Prevent double-async
        if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
            return false;
        }
        
        // Check explicit parameter
        if ( isset( $arguments['async'] ) ) {
            return (bool) $arguments['async'];
        }
        
        // Default behavior
        return true; // or false, depending on your tool
    }
    
    public function get_capability_flags() {
        return array(
            'long-running',  // ← This triggers orchestrator async!
            'async',
        );
    }
}
```

## Why This Works

When the tool is executed via orchestrator:
1. Orchestrator sees `long-running` flag → queues for cron (job_id: `async_xxx`)
2. Client polls `async_xxx`
3. Cron runs with `in_async_executor: true` in context
4. Tool detects flag and runs synchronously (no nested async job)
5. Tool completes and returns full result (e.g., video URL)
6. Async executor stores complete result
7. Client polls `async_xxx` and gets complete result ✅

When the tool is called directly (not via orchestrator):
1. No orchestrator involvement
2. `in_async_executor` is not set
3. Tool uses its own async mechanism (if configured)
4. Returns `{async: true, job_id: 'tool_xxx', status: 'pending'}`
5. Client polls `tool_xxx` directly ✅

## Best Practices

1. **Always check `context['in_async_executor']` in async decision logic**
2. **Document why the check exists** (prevents double-async)
3. **Make context parameter optional** for backward compatibility
4. **Test both paths** (with and without the flag)
5. **Use descriptive comments** with "CRITICAL" or "IMPORTANT" markers

## Related Files

- `includes/services/class-wp-mcp-ai-tool-async-executor.php` - Sets the flag
- `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php` - Routes to async executor
- `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php` - Example implementation

## Issue Reference

This pattern fixes the issue where video generation tool timed out because:
- User requested video from chat client
- Tool was queued async by orchestrator
- Tool queued another async job for video service
- Client received nested async response and didn't know how to handle it
- Result: "Tool timed out before completing" error

With the fix:
- Tool runs synchronously when in async executor
- Complete video result returns to client
- No timeout error ✅
