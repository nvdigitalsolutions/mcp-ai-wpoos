# Veo Video Generation Async Job Completion Fix

## Problem Statement

When a user creates a video from the chat client using OpenAI as the provider, the video generation may timeout during synchronous polling and fall back to async mode. This creates a nested async structure where:

1. The async executor creates a job with ID `async_xxx`
2. The veo service creates its own job with ID `veo_yyy`
3. When `veo_yyy` completes, the parent `async_xxx` job was never updated with the final video URL
4. The completion hooks didn't include `assistant_id` and `user_id` for proper client routing

## Solution

The fix ensures that when a veo video generation job completes:

1. ✅ Both `veo_` and `async_` jobs are marked complete with final video URL
2. ✅ URL and response are returned to chat client  
3. ✅ `assistant_id` and `user_id` are included in completion hooks

## Technical Implementation

### 1. Context Propagation

**Async Executor** (`class-wp-mcp-ai-tool-async-executor.php`):
```php
// Add parent job ID to context
$context['parent_job_id'] = $job_id;
```

**Veo Tool** (`class-wp-mcp-ai-tool-generate-veo-video.php`):
```php
// Pass context through to service
if ( isset( $context['assistant_id'] ) ) {
    $generation_args['assistant_id'] = absint( $context['assistant_id'] );
}
if ( isset( $context['parent_job_id'] ) ) {
    $generation_args['parent_job_id'] = sanitize_key( $context['parent_job_id'] );
}
```

### 2. Parent Job Completion

**Veo Service** (`class-wp-mcp-ai-gemini-video-generation-service.php`):

Stores parent context:
```php
// Store parent_job_id if provided
if ( isset( $args['parent_job_id'] ) ) {
    $metadata['parent_job_id'] = sanitize_key( $args['parent_job_id'] );
}
```

Completes parent job:
```php
// When veo job completes, also complete parent
if ( isset( $metadata['parent_job_id'] ) && ! empty( $metadata['parent_job_id'] ) ) {
    $this->complete_parent_job( $metadata['parent_job_id'], $metadata['result'] );
}
```

The `complete_parent_job()` method:
- Retrieves parent async job metadata
- Updates it with the final video result
- Fires completion hook for the parent job
- Handles missing parents gracefully

### 3. Hook Metadata Enhancement

Completion hooks now include:
```php
array(
    'tool'         => 'generate_veo_video',
    'user_id'      => $user_id,
    'assistant_id' => $assistant_id,
    'prompt'       => $prompt,
    'duration'     => $duration,
)
```

## Flow Diagrams

### Before Fix (Broken Flow)

```
Chat Client Request
    ↓
Async Executor creates async_123
    ↓
Execute Tool (with in_async_executor=true)
    ↓
Try Sync Polling → TIMEOUT
    ↓
Fall back to Async → Create veo_456
    ↓
Tool Returns {async: true, job_id: 'veo_456'}
    ↓
Async Executor marks async_123 "completed" ✓
(but result is just nested async response)
    ↓
Veo Service polls veo_456
    ↓
Video Ready → Update veo_456 metadata ✓
    ↓
Fire hook for veo_456 ✓
    ↓
❌ async_123 never updated with video URL
❌ Chat client polling async_123 gets nested async response
```

### After Fix (Working Flow)

```
Chat Client Request
    ↓
Async Executor creates async_123
    ↓
Execute Tool (with parent_job_id='async_123')
    ↓
Try Sync Polling → TIMEOUT
    ↓
Fall back to Async → Create veo_456
Store parent_job_id='async_123' in veo_456 metadata
    ↓
Tool Returns {async: true, job_id: 'veo_456'}
    ↓
Async Executor marks async_123 "completed" ✓
(result is nested async response)
    ↓
Veo Service polls veo_456
    ↓
Video Ready → Update veo_456 metadata ✓
    ↓
✅ Update async_123 with final video result
✅ Fire hook for veo_456 (with assistant_id/user_id)
✅ Fire hook for async_123 (with final result)
    ↓
✅ Chat client can poll async_123 and get video URL
```

## Testing

See `tests/test-veo-parent-job-completion.php` for comprehensive tests:

- ✅ Parent job is updated when veo job completes
- ✅ Completion hooks include assistant_id and user_id
- ✅ Missing parent jobs are handled gracefully

## Backward Compatibility

- No breaking changes
- Existing code continues to work
- New context fields are optional
- Graceful degradation if parent job is missing

## Future Considerations

This pattern can be applied to other long-running tools that may create nested async operations:
- Crawl4AI operations
- Image generation with multiple models
- Batch processing tools

The key is to:
1. Pass `parent_job_id` in context through the tool chain
2. Complete parent job when child job finishes
3. Include routing context (assistant_id, user_id) in hooks
