# Video Generation Async Execution Fix

## Issue
Video generation tool (`generate_veo_video`) was experiencing a double-async execution problem where:
1. Orchestrator detected async capability flags → wrapped tool in `async_xxx` job
2. Async executor called tool with `in_async_executor=true` context
3. Tool disabled its own async mechanism to avoid double-async
4. Tool tried to run synchronously inside async executor
5. Synchronous polling timed out before video generation completed
6. Result: CRON jobs created but `veo_` polling never triggered

## Root Cause
The tool has its own robust async mechanism (`veo_xxx` polling via `wp_mcp_ai_poll_veo_video` cron hook), but when the orchestrator wrapped it with async capability flags (`background-only`, `long-running`, `async`, `may-timeout`), the tool couldn't use its internal mechanism properly.

## Solution
Removed async-related capability flags from `generate_veo_video` tool to prevent orchestrator wrapping:
- **Removed flags**: `async`, `long-running`, `may-timeout`, `background-only`
- **Removed check**: `in_async_executor` context check in `should_use_async()`

The tool now uses ONLY its own async mechanism:
1. Tool always defaults to async mode (returns `veo_xxx` job_id)
2. Service schedules `wp_mcp_ai_poll_veo_video` cron hook
3. Cron job polls Gemini API for completion
4. Result stored in transient for client retrieval

## Execution Flow

### Before Fix (Double-Async)
```
Client → Orchestrator → Async Executor (async_xxx) → Tool (sync) → Service (sync polling) → TIMEOUT
                                                                          ↓
                                                                   Never schedules veo_xxx cron
```

### After Fix (Single-Async)
```
Client → Orchestrator (no wrapping) → Tool (async) → Service (async) → Schedules veo_xxx cron
                                           ↓                                      ↓
                                     Returns veo_xxx job_id                Polls Gemini API
                                                                                  ↓
                                                                         Stores result in transient
```

## Testing
1. Call `generate_veo_video` tool with a video prompt
2. Tool should return `veo_xxx` job_id (not `async_xxx`)
3. Check WordPress cron queue: `wp cron event list`
4. Should see `wp_mcp_ai_poll_veo_video` scheduled with `veo_xxx` argument
5. Use `check_video_status` tool with `veo_xxx` job_id to poll for completion
6. On completion, video should be saved to Media Library

## Code Changes

### File: `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

**Capability Flags (Line 563)**
```php
// Before
return array(
    'async',
    'long-running',
    'background-only',
    'may-timeout',
    // ... other flags
);

// After  
return array(
    // async/long-running/may-timeout/background-only removed
    // Tool uses its own async mechanism instead of orchestrator wrapping
    'requires-credentials',
    'requires-capability',
    'write',
    // ... other flags
);
```

**should_use_async() Method (Line 417)**
```php
// Before
protected function should_use_async( $arguments, $context = array() ) {
    // Prevents double-async when wrapped by orchestrator
    if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
        return false;
    }
    // ...
}

// After
protected function should_use_async( $arguments, $context = array() ) {
    // No in_async_executor check - tool always uses its own async
    if ( isset( $arguments['async'] ) ) {
        return (bool) $arguments['async'];
    }
    return true; // Default to async
}
```

## Related Files
- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php` - Video generation service with async polling
- `includes/services/class-wp-mcp-ai-tool-async-executor.php` - Async executor (no longer wraps this tool)
- `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php` - Orchestrator (no longer detects this as async)
- `includes/tools/class-wp-mcp-ai-tool-check-video-status.php` - Status checking tool for polling veo_xxx jobs

## Verification Commands

### Check Cron Hook Registration
```bash
wp eval 'var_dump(has_action("wp_mcp_ai_poll_veo_video"));'
# Should return: int(10) - priority of registered hook
```

### List Scheduled Cron Jobs
```bash
wp cron event list --hook=wp_mcp_ai_poll_veo_video
# Shows all scheduled video polling jobs
```

### Manually Trigger Cron (Testing)
```bash
wp cron event run wp_mcp_ai_poll_veo_video veo_xxxxx
# Replace veo_xxxxx with actual job ID
```

### Check Job Status
```bash
wp eval '
$job_id = "veo_xxxxx";
$status = get_transient("wp_mcp_ai_veo_async_" . $job_id);
var_dump($status);
'
```

## Impact
- ✅ Video generation now completes successfully via cron polling
- ✅ No more timeout issues from synchronous polling in async executor
- ✅ Simpler execution flow (single async, not double)
- ✅ Direct `veo_xxx` job_id returned to client (no `async_xxx` wrapping)
- ⚠️ Tool is no longer automatically queued by orchestrator (uses its own mechanism)
- ⚠️ Tests updated to expect `veo_xxx` job_id instead of `async_xxx`

## Future Considerations
Other tools with internal async mechanisms should follow this pattern:
1. Don't use orchestrator async flags if tool has its own async
2. Return tool-specific job_id directly (e.g., `veo_xxx`, `task_xxx`)
3. Implement robust cron polling with error handling
4. Store results in transients for client retrieval
5. Provide status checking tool for polling
