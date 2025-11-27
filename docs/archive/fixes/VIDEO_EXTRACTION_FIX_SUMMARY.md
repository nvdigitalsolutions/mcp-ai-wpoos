# Video Extraction Fix Summary

## Problem Statement

Videos generated asynchronously by the `generate_veo_video` tool were not being properly extracted and displayed in the chat client. Users experienced timeout errors:

```
[WP oOS] Async tool polling failed: Error: timeout
Fetch failed loading: POST "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat-client"
```

The issue was specifically occurring when a tool with a different ID was trying to insert the video into the chat, suggesting a mismatch in how async tool results were being processed.

## Root Cause Analysis

### The Async Tool Flow

When an async tool (like `generate_veo_video`) executes in the agentic loop:

1. **Tool Execution**: The tool returns an async response with a `job_id`
2. **Parent Job Created**: The async executor creates a parent job (e.g., `async_abc123`)
3. **Nested Job Delegation**: The veo service creates a nested job (e.g., `veo_xyz789`)
4. **Completion**: When the video completes, `complete_parent_job()` updates the parent job
5. **Agentic Loop**: The chat service's `wait_for_async_tool_completion()` polls until completion
6. **Result Returned**: The result is returned directly to the agentic loop

### The Problem

In step 6, the result was being returned **WITHOUT** applying the tool's `sanitize_for_llm()` method. This caused two critical issues:

1. **Large Payloads**: Results contained unsanitized base64-encoded video data (several MB)
2. **Missing Structures**: The `video_url` structure wasn't properly formatted

This resulted in:
- HTTP timeouts when sending large payloads to the LLM
- Chat client unable to extract video URL from malformed result structure
- Agentic loop failures

### Code Location

**File**: `includes/services/class-wp-mcp-ai-chat-service.php`

**Method**: `wait_for_async_tool_completion()`

**Original Code** (lines 663-678):
```php
if ( 'completed' === $status ) {
    // Job completed successfully - extract result.
    $result = isset( $job_status['result'] ) ? $job_status['result'] : array();

    WP_MCP_AI_Logger::log_event(...);

    return $result;  // ⚠️ PROBLEM: No sanitization applied!
}
```

## Solution Implemented

### Phase 1: Add Sanitization to Async Results

Added sanitization call before returning async results:

```php
if ( 'completed' === $status ) {
    // Job completed successfully - extract result.
    $result = isset( $job_status['result'] ) ? $job_status['result'] : array();

    WP_MCP_AI_Logger::log_event(...);

    // Apply tool's sanitize_for_llm to ensure result is properly formatted for agentic loop.
    // This is critical for tools like generate_veo_video which add display structures (video_url)
    // and need to strip large base64 data before sending to the LLM.
    if ( is_array( $result ) && ! empty( $tool_name ) && $this->tool_registry->is_tool_registered( $tool_name ) ) {
        $tool_instance = $this->tool_registry->get_tool( $tool_name );
        if ( $tool_instance && $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) {
            $result = $tool_instance->sanitize_for_llm( $result );
        }
    }

    return $result;  // ✅ Now properly sanitized!
}
```

### Phase 2: Refactor to Reduce Code Duplication

Created a helper method to eliminate duplicate code:

```php
/**
 * Apply tool's sanitize_for_llm method if tool implements the interface.
 *
 * Helper method to reduce code duplication when sanitizing tool results.
 * Used by both wait_for_async_tool_completion and sanitize_tool_result_for_llm.
 *
 * @param mixed  $content   Content to sanitize (typically an array).
 * @param string $tool_name Tool name.
 * @return mixed Sanitized content, or original content if tool doesn't implement interface.
 */
private function apply_tool_sanitization( $content, $tool_name ) {
    // Return early if no tool name provided.
    if ( empty( $tool_name ) ) {
        return $content;
    }

    // Check if tool is registered.
    if ( ! $this->tool_registry->is_tool_registered( $tool_name ) ) {
        return $content;
    }

    // Get tool instance and check if it implements sanitization interface.
    $tool_instance = $this->tool_registry->get_tool( $tool_name );
    if ( ! $tool_instance || ! ( $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) ) {
        return $content;
    }

    // Apply tool's sanitization method.
    // The tool's implementation will handle content type validation.
    return $tool_instance->sanitize_for_llm( $content );
}
```

Then updated both methods to use the helper:

**In `wait_for_async_tool_completion()`:**
```php
$result = $this->apply_tool_sanitization( $result, $tool_name );
```

**In `sanitize_tool_result_for_llm()`:**
```php
// Apply tool-specific sanitization if available (delegated to helper method).
$content = $this->apply_tool_sanitization( $content, $tool_name );
```

## How It Works

### For `generate_veo_video` Tool

The tool's `sanitize_for_llm()` method (lines 581-640 of `class-wp-mcp-ai-tool-generate-veo-video.php`):

1. **Strips base64 data**: Removes data URLs to prevent large payloads
2. **Preserves metadata**: Keeps only essential fields (attachment_id, url, duration, etc.)
3. **Adds video_url structure**: Creates the structure the chat client expects:
   ```php
   'video_url' => array(
       'url' => 'https://site.com/wp-content/uploads/video.mp4'
   )
   ```

### Chat Client Video Extraction

The chat client (`assets/js/chat.js`, lines 6600-6623) looks for the `video_url` structure:

```javascript
// Check for video_url structure (similar to image_url for generate_veo_video)
const nestedVideo = result && result.video_url && typeof result.video_url === 'object' 
    ? result.video_url 
    : null;

if (nestedVideo) {
    // Handle video_url structure from generate_veo_video
    if (typeof nestedVideo.url === 'string' && nestedVideo.url.trim()) {
        url = nestedVideo.url.trim();
    }
}
```

Then renders the video player (lines 10855-10886):

```javascript
if (isVideo) {
    // Render video player
    const videoContainer = document.createElement('div');
    videoContainer.className = 'wp-mcp-ai-chat__video-container';
    
    const video = document.createElement('video');
    video.controls = true;
    video.preload = 'metadata';
    video.className = 'wp-mcp-ai-chat__video-player';
    
    const source = document.createElement('source');
    source.src = attachment.url;
    source.type = getVideoMimeType(attachment.url);
    
    video.appendChild(source);
    videoContainer.appendChild(video);
    item.appendChild(videoContainer);
}
```

## Benefits

1. **Fixes Video Display**: Videos now properly extract and display in chat client
2. **Prevents Timeouts**: Sanitized results are much smaller (metadata only, no base64 data)
3. **Consistent Behavior**: Async tools now behave identically to sync tools
4. **Better Code Quality**: Follows DRY principle with reusable helper method
5. **Maintains Backward Compatibility**: No breaking changes to existing functionality
6. **Flexible Design**: Helper method delegates content type validation to tools

## Affected Tools

All async tools implementing `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` now benefit from this fix:

- ✅ `generate_veo_video` (primary use case)
- ✅ `generate_openai_image`
- ✅ `generate_gemini_image`
- ✅ `edit_gemini_image`
- ✅ `extract_video_frames`
- ✅ `run_crawl4ai_job`

## Testing Recommendations

### Functional Tests

1. **Video Generation**:
   - Generate a video asynchronously in chat
   - Verify video displays in chat client after completion
   - Confirm no timeout errors occur

2. **Image Generation**:
   - Generate an image asynchronously
   - Verify image displays correctly
   - Ensure no regressions

3. **Agentic Loop**:
   - Test multi-turn conversations with async tools
   - Verify LLM receives sanitized results
   - Confirm conversation flow continues smoothly

### Technical Verification

1. **Check Result Size**:
   - Before fix: Result could be several MB (base64 video data)
   - After fix: Result should be < 1KB (metadata only)

2. **Verify Structure**:
   - Check that `video_url` structure is present
   - Confirm `url` field contains the WordPress media URL
   - Validate `text` field has descriptive message

3. **Monitor Logs**:
   ```php
   // Log should show:
   'async_tool_wait_complete' => 'Async tool completed: generate_veo_video (job_id: veo_xxx, polls: X)'
   ```

## Migration Notes

**No migration required** - This is a bug fix with no breaking changes:
- ✅ Existing functionality preserved
- ✅ No database changes
- ✅ No API changes
- ✅ No configuration changes
- ✅ Automatic benefit for all async tools

## Code Quality Improvements

### DRY Principle

Before: Duplicate sanitization logic in two methods
After: Single `apply_tool_sanitization()` helper used by both

### Separation of Concerns

- `wait_for_async_tool_completion()`: Handles polling logic
- `apply_tool_sanitization()`: Handles sanitization logic
- Tool's `sanitize_for_llm()`: Handles tool-specific formatting

### Defensive Programming

- Checks if tool is registered before attempting sanitization
- Checks if tool implements interface before calling method
- Returns original content if sanitization not applicable
- Delegates content type validation to individual tools

## Related Documentation

- **Veo Notification Flow**: `VEO_NOTIFICATION_FLOW.md`
- **File-Based Polling**: `FILE_BASED_POLLING_IMPLEMENTATION.md`
- **Tool Reference**: `docs/tool-reference.md`
- **Chat API**: `docs/rest-api.md`

## Commits

1. **fee1d36**: Initial fix - Apply sanitize_for_llm to async results
2. **8891741**: Refactor - Extract sanitization logic to helper method
3. **30087ab**: Improve - Make helper more flexible for different content types

## Conclusion

This fix ensures that async tool results are properly sanitized before being used in the agentic loop, preventing timeout errors and ensuring videos (and other media) are properly extracted and displayed in the chat client. The implementation follows best practices with code reusability, defensive programming, and comprehensive documentation.
