# File-Based Polling Implementation for Veo Video Generation

## Overview

This implementation adds file-based polling detection for Veo video generation, allowing the system to detect completion by checking for file creation in the WordPress uploads directory instead of only polling the Gemini API.

## Problem Statement

The original request was to change from polling for job completion to polling for file creation based on the filename format: `veo-video-692607824a3408.36654646.mp4`

## Solution Architecture

### 1. Predictable Filename Generation

**Location**: `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

- **Pre-generate filename**: In `queue_async_polling()`, generate expected filename using job_id
  - Format: `veo-video-{job_id}.mp4`
  - Example: `veo-video-veo_674472824a3408.36654646.mp4`
  - Stored in job metadata as `expected_filename`

- **Use job_id in filename**: Modified `save_video_to_media()` to use job_id when provided
  ```php
  if ( ! empty( $job_id ) ) {
      $filename = 'veo-video-' . sanitize_file_name( $job_id ) . '.mp4';
  } else {
      $filename = 'veo-video-' . uniqid( '', true ) . '.mp4';
  }
  ```

### 2. File-Based Detection Method

**Method**: `check_for_created_video_file()`

Strategy:
1. Query attachments by `_veo_job_id` metadata (exact match, not LIKE)
2. Verify filename matches expected filename for additional safety
3. Return attachment data if found, false if not found

Benefits:
- More efficient than LIKE queries on `_wp_attached_file`
- Exact metadata matching prevents false positives
- Double verification with filename check

### 3. Polling Priority

**Method**: `poll_video_async()`

Execution order:
1. **First**: Check for file creation using `check_for_created_video_file()`
2. **Then**: If file not found, poll Gemini API for operation status
3. **Result**: Faster completion detection when file is created

```php
// First, check if the video file has been created in the uploads directory.
if ( isset( $metadata['expected_filename'] ) && ! empty( $metadata['expected_filename'] ) ) {
    $attachment = $this->check_for_created_video_file( $metadata['expected_filename'], $job_id );

    if ( $attachment && ! is_wp_error( $attachment ) ) {
        // File was found - video generation is complete!
        // ... handle completion
        return;
    }
}

// Poll the Gemini API for status.
// ... existing API polling logic
```

### 4. Code Deduplication

**Method**: `fire_job_completion_hooks()`

Extracted common hook firing logic to eliminate duplication:
- Fires `wp_mcp_ai_job_completed` hook
- Fires `wp_mcp_ai_after_tool_execution` hook for token tracking
- Handles parent job completion with proper delays
- Used by both file-based detection and API polling paths

## Benefits

1. **Faster Completion Detection**
   - Detects completion as soon as file appears in uploads directory
   - No need to wait for next API poll cycle
   - Reduces overall completion time

2. **Reduced API Calls**
   - Fewer calls to Gemini API when file is created quickly
   - Lower quota usage
   - Better rate limit management

3. **Backwards Compatible**
   - Falls back to API polling if file not found
   - No breaking changes to existing functionality
   - Works with existing job metadata structure

4. **Better Debugging**
   - Predictable filenames make troubleshooting easier
   - Job ID visible in filename
   - Clear log messages for file-based detection

## Testing

### Test File: `tests/test-veo-file-based-polling.php`

**Test Coverage** (5 test methods):

1. `test_expected_filename_stored_in_metadata`
   - Verifies filename is generated and stored in job metadata
   - Validates format: `veo-video-{job_id}.mp4`

2. `test_save_video_uses_job_id_in_filename`
   - Confirms filename uses job_id when provided
   - Verifies job_id stored in attachment metadata

3. `test_check_for_created_video_file_detects_existing_video`
   - Tests file detection for existing attachments
   - Validates returned attachment data structure

4. `test_check_for_created_video_file_returns_false_when_not_found`
   - Ensures false returned when file doesn't exist
   - No false positives

5. `test_poll_video_async_uses_file_based_detection`
   - Integration test for full polling flow
   - Verifies hooks fire correctly
   - Validates completion status updated

### Verification Script: `verify-file-polling.sh`

Automated checks:
- ✅ Expected filename generation implemented
- ✅ Filename uses job_id format
- ✅ File detection method exists
- ✅ File polling prioritized before API polling
- ✅ Completion hooks method exists
- ✅ Test coverage adequate (5+ tests)
- ✅ No trailing whitespace
- ✅ PHP syntax valid

## Security Considerations

1. **Filename Sanitization**
   - All filenames sanitized with `sanitize_file_name()`
   - Job IDs sanitized with `sanitize_key()`

2. **Database Queries**
   - Uses WP_Query with proper meta_query
   - No direct SQL injection vulnerabilities
   - Exact metadata matching prevents unwanted matches

3. **File Verification**
   - Double-check filename after query
   - Ensures we have the correct file
   - Prevents accidental completion on wrong files

4. **Existing Security Maintained**
   - All existing capability checks remain
   - User permissions validated
   - No changes to authentication logic

## Code Quality Improvements

1. **Condition Order**
   - Fixed to check false first, then is_wp_error
   - Proper handling of all return cases

2. **Query Optimization**
   - Changed from LIKE on filename to exact match on job_id metadata
   - More efficient for large media libraries
   - No partial matches or false positives

3. **Test Cleanup**
   - Uses `delete_transient()` for known transients
   - Targeted DB cleanup with prepared statements
   - Prevents cleanup of unrelated transients

## Migration Path

No migration needed - changes are:
- Additive only (new functionality)
- Backwards compatible (existing code continues to work)
- Automatic (no manual intervention required)

## Performance Impact

**Positive**:
- Faster completion detection (filesystem check vs API call)
- Fewer API requests
- Lower latency for users

**Negligible**:
- One additional WP_Query per poll cycle
- Minimal overhead (indexed meta query)
- Query only runs if expected_filename is set

## Future Enhancements

Potential improvements:
1. Configurable polling strategy (file-first, API-first, or both)
2. Filesystem watching with inotify for real-time detection
3. Webhooks for external file creation notifications
4. Metrics tracking for file detection vs API detection

## Conclusion

This implementation successfully addresses the requirement to poll for file creation instead of only job completion. The solution is efficient, secure, well-tested, and maintains full backwards compatibility with existing functionality.
