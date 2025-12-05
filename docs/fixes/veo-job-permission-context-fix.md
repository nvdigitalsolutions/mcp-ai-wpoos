# Fix: Veo Video Job Permission Check Issue

## Problem Description

When the `generate_veo_video` tool runs in async mode and reuses the parent async executor job ID (jobs starting with `async_`), users encountered a "You do not have permission to view this job" error when polling for job status.

### Root Cause

The permission check in `WP_MCP_AI_Cron_Status_Service::get_job_details()` only checked for `$result['args']['user_id']` but did not account for `$result['context']['user_id']`.

When a veo job reuses the parent job ID from the async executor:
1. The job metadata is merged between veo-specific and async executor metadata
2. The `args` field contains veo-specific arguments (prompt, duration, etc.) with `user_id`
3. The `context` field (from async executor) also contains `user_id`, `assistant_id`, etc.
4. The `get_async_status()` method returned `args` but NOT `context`
5. The permission check failed when `args.user_id` was not present but `context.user_id` was

## Solution

### 1. Include `context` in `get_async_status()` Response

**File**: `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

**Location**: Lines 1787-1796

**Change**: Added code to include the `context` field in the response when present:

```php
// Include context for permission checking when job reuses parent ID.
// When use_parent_job is true, the metadata includes the async executor's context
// field which contains user_id, assistant_id, etc. This is needed for permission
// checks in the cron-status service.
if ( isset( $metadata['context'] ) ) {
    $response['context'] = $metadata['context'];
}
```

### 2. Update Permission Check to Use Both Fields

**File**: `includes/services/class-wp-mcp-ai-cron-status-service.php`

**Location**: Lines 826-840

**Change**: Modified permission check to try `args.user_id` first, then fall back to `context.user_id`:

```php
// Check permissions - video jobs store user_id in args or context.
// When a job reuses parent ID (async_xxx), the context field from async executor
// contains the user_id. Otherwise, the args field contains the user_id.
$job_user_id = 0;
if ( isset( $result['args']['user_id'] ) ) {
    $job_user_id = absint( $result['args']['user_id'] );
} elseif ( isset( $result['context']['user_id'] ) ) {
    $job_user_id = absint( $result['context']['user_id'] );
}
```

## When Does This Happen?

This issue occurs when:
1. The `generate_veo_video` tool is executed through the async executor
2. The video generation service detects it's already in an async executor context (`in_async_executor` flag is true)
3. The service reuses the parent job ID (async_xxx) instead of creating a new veo_xxx job
4. The job metadata is merged, preserving both `args` (from veo) and `context` (from async executor)
5. The client polls for job status using the async_xxx job ID

## Flow Diagram

```
Tool Execution (generate_veo_video)
         ↓
Async Executor Context Detected
         ↓
Reuse Parent Job ID (async_xxx)
         ↓
Merge Metadata:
  - args: {user_id, prompt, ...}     (from veo)
  - context: {user_id, assistant_id} (from async executor)
         ↓
Client Polls Job Status
         ↓
get_job_details() → get_async_status()
         ↓
BEFORE FIX: Returns {args} only → Permission check fails
AFTER FIX:  Returns {args, context} → Permission check succeeds
```

## Test Coverage

Created `tests/test-veo-job-permission-context.php` with comprehensive test cases:

1. **test_job_permission_with_args_user_id**: Verifies traditional structure with `args.user_id`
2. **test_job_permission_with_context_user_id**: Verifies async executor structure with `context.user_id`
3. **test_job_permission_with_both_args_and_context**: Verifies `args.user_id` takes precedence
4. **test_get_async_status_includes_context**: Verifies `context` is returned by `get_async_status()`
5. **test_job_permission_with_no_user_id**: Verifies edge case handling
6. All tests verify admin override works correctly

## Backward Compatibility

This fix is backward compatible:
- Jobs with only `args.user_id` continue to work (checked first)
- Jobs with only `context.user_id` now work (fallback)
- Jobs with both fields use `args.user_id` (precedence)
- Jobs with neither field deny access to non-admin users (secure default)

## Related Code

- **Veo Service**: `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`
  - `queue_async_polling()`: Line 1007-1189 (metadata merge logic)
  - `get_async_status()`: Line 1656-1793 (status retrieval)

- **Cron Status Service**: `includes/services/class-wp-mcp-ai-cron-status-service.php`
  - `get_job_details()`: Line 796-936 (permission checks)

- **Async Executor**: `includes/services/class-wp-mcp-ai-tool-async-executor.php`
  - Sets `context.user_id` when queueing async jobs

## Future Considerations

1. Consider standardizing on either `args.user_id` or `context.user_id` across all job types
2. Document the metadata structure difference between veo jobs and async executor jobs
3. Add logging when permission checks use the fallback path for debugging
