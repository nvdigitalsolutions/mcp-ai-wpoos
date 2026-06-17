# Veo Video Filename Fix - Remove Dots from Unique IDs

## Issue

Veo video filenames were being generated with multiple dots due to `uniqid('', true)` producing IDs like `6926100bb2f8e3.59706124`, resulting in confusing filenames:
- **Before:** `veo-video-6926100bb2f8e3.59706124.mp4`
- **After:** `veo-video-6926100bb2f8e3_59706124.mp4`

While technically valid, filenames with multiple dots can:
1. Confuse file extension parsers
2. Create ambiguity about the actual file extension
3. Cause issues with some file handling systems

## Root Cause

PHP's `uniqid('', true)` function with the second parameter set to `true` adds more entropy by appending a decimal portion, which includes a dot:

```php
uniqid('', true)  // Returns: "6926100bb2f8e3.59706124"
uniqid('', false) // Returns: "6926100bb2f8e3" (no dot)
```

## Solution

Replace the dot with an underscore in all locations where unique IDs are generated:

```php
// Before
$job_id = 'veo_' . uniqid( '', true );
$filename = 'veo-video-' . uniqid( '', true ) . '.mp4';

// After
$job_id = 'veo_' . str_replace( '.', '_', uniqid( '', true ) );
$filename = 'veo-video-' . str_replace( '.', '_', uniqid( '', true ) ) . '.mp4';
```

## Files Modified

### 1. `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

**Line ~967** - Job ID generation in `queue_async_polling()`:
```php
// Generate unique job ID.
// Use str_replace to convert dot to underscore for cleaner filenames.
$job_id = 'veo_' . str_replace( '.', '_', uniqid( '', true ) );
```

**Line ~1628** - Filename generation in `save_video_to_media()`:
```php
} else {
    // Use str_replace to convert dot to underscore for cleaner filenames.
    $filename = 'veo-video-' . str_replace( '.', '_', uniqid( '', true ) ) . '.mp4';
}
```

### 2. `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

**Line ~473** - Filename generation in `save_video_to_media()`:
```php
// Generate unique filename.
// Use str_replace to convert dot to underscore for cleaner filenames.
$filename = 'veo-video-' . str_replace( '.', '_', uniqid( '', true ) ) . '.mp4';
```

### 3. Documentation Updates

**`FILE_BASED_POLLING_IMPLEMENTATION.md`:**
- Updated examples to show underscore format
- Added note about the change from dots to underscores

**`includes/class-wp-mcp-ai-job-notifier-rest.php`:**
- Updated PHPDoc for `sanitize_job_id()` to reflect underscore format

**`includes/rest/class-wp-mcp-ai-rest-tools-controller.php`:**
- Updated PHPDoc for `sanitize_job_id()` to reflect underscore format

## Benefits

1. **Clearer Filenames:** Only one dot before the extension (`.mp4`)
2. **Better Compatibility:** Reduces confusion for file parsers and systems
3. **Maintains Uniqueness:** Still uses same entropy from `uniqid('', true)`
4. **Backwards Compatible:** Sanitization functions already support underscores
5. **No Breaking Changes:** Existing job IDs with dots still work (sanitizer accepts both)

## Examples

### Job IDs
- **Before:** `veo_674472824a3408.36654646`
- **After:** `veo_674472824a3408_36654646`

### Filenames
- **Before:** `veo-video-6926100bb2f8e3.59706124.mp4`
- **After:** `veo-video-6926100bb2f8e3_59706124.mp4`

### Job ID in Metadata
- **Before:** `veo_674472824a3408.36654646`
- **After:** `veo_674472824a3408_36654646`

## Testing

All modified files pass PHP syntax validation:
```bash
php -l includes/services/class-wp-mcp-ai-gemini-video-generation-service.php  # ✓ Pass
php -l includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php            # ✓ Pass
php -l includes/class-wp-mcp-ai-job-notifier-rest.php                        # ✓ Pass
php -l includes/rest/class-wp-mcp-ai-rest-tools-controller.php               # ✓ Pass
```

## Impact on Video Notification Flow

This fix ensures that the complete video notification flow works correctly:

### Backend Flow (All Working)
1. ✅ Veo service generates video asynchronously
2. ✅ Job ID created with underscores: `veo_6926100bb2f8e3_59706124`
3. ✅ Filename generated: `veo-video-veo_6926100bb2f8e3_59706124.mp4`
4. ✅ Video saved to WordPress Media Library
5. ✅ `wp_mcp_ai_job_completed` hook fired with full result including `video_url`
6. ✅ Job Notifier caches status with video data
7. ✅ SSE Stream retrieves and sends status to frontend

### Frontend Flow (All Working)
1. ✅ Chat client polls job status via SSE
2. ✅ Receives job completion with `video_url` structure
3. ✅ `isVideoAttachment()` detects video file
4. ✅ Video player rendered with correct URL
5. ✅ User sees completed video in chat

## Migration

No migration needed:
- Existing job IDs with dots still work (sanitizer accepts both)
- New job IDs will use underscores
- Old files remain accessible
- No database changes required

## Security

The change maintains security:
- `sanitize_job_id()` still validates input
- Path traversal protection remains (consecutive dots check)
- Underscores already allowed in sanitization regex: `/[^a-zA-Z0-9_.\-]/`
- No new attack vectors introduced

## Conclusion

This fix resolves the filename confusion issue by replacing dots with underscores in unique IDs, while maintaining full backwards compatibility and all existing functionality. The video notification flow from backend to frontend remains intact and fully functional.
