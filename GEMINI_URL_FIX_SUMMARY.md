# Fix: Gemini Image/Video URLs Point to WordPress Media Library

## Problem Statement

When Gemini Veo 3 generates videos or edits images, the files are correctly saved to WordPress media library. However, in the chat client, the video/image links were pointing to OneDrive URLs instead of the local WordPress media library URLs.

## Root Cause

The issue occurred because WordPress installations with media offloading plugins (like WP Offload Media, WP-Stateless, etc.) configured to use external storage (OneDrive, S3, etc.) will filter `wp_get_attachment_url()` to return the external storage URL.

The affected code was calling `wp_get_attachment_url($attachment_id)` which, when filtered by offloading plugins, returns the external URL instead of the local WordPress upload directory URL.

## Solution

The fix ensures that we always use the **local WordPress upload URL** by preferring `$upload['url']` from `wp_upload_bits()` over `wp_get_attachment_url()`.

### Why This Works

1. **`wp_upload_bits($filename, null, $data)`** - Returns an array containing:
   - `'file'` - Absolute path to the uploaded file
   - `'url'` - Direct URL to the file in WordPress uploads directory
   - `'error'` - Error message if upload failed

2. **`$upload['url']`** - Always points to the local WordPress uploads directory (e.g., `https://example.com/wp-content/uploads/2024/11/video.mp4`)

3. **`wp_get_attachment_url($attachment_id)`** - Can be filtered by plugins to return external URLs (e.g., `https://onedrive.live.com/...`)

## Code Changes

### 1. Gemini Veo Video Generation Tool

**File:** `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

**Before:**
```php
protected function save_video_to_media( $result, $user_id ) {
    // ... upload and create attachment ...
    return $attachment_id;
}

// In execute method:
'url' => wp_get_attachment_url( $attachment_id ),
```

**After:**
```php
protected function save_video_to_media( $result, $user_id ) {
    // ... upload and create attachment ...
    return array(
        'attachment_id' => $attachment_id,
        'url'           => isset( $upload['url'] ) ? $upload['url'] : wp_get_attachment_url( $attachment_id ),
    );
}

// In execute method:
$save_result = $this->save_video_to_media( $result, $user_id );
'url' => $save_result['url'],
```

### 2. Gemini Video Generation Service

**File:** `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

Same pattern applied to the service's `save_video_to_media()` method and async polling code.

### 3. Gemini Image Editing Tool

**File:** `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`

**Before:**
```php
return array(
    'url'          => isset( $upload['url'] ) ? $upload['url'] : '',
    'download_url' => wp_get_attachment_url( $attachment_id ),
    // ...
);
```

**After:**
```php
$local_url = isset( $upload['url'] ) ? $upload['url'] : '';
$download_url = $local_url ? $local_url : wp_get_attachment_url( $attachment_id );

return array(
    'url'          => $local_url,
    'download_url' => $download_url,
    // ...
);
```

## Test Coverage

Created comprehensive test file: `tests/test-veo-local-url.php`

The tests:
1. Mock `wp_get_attachment_url()` to return OneDrive URLs
2. Verify that `save_video_to_media()` returns local WordPress URLs
3. Test both tool and service implementations
4. Test async completion flow
5. Assert URLs contain `wp-content/uploads` and NOT `onedrive`

## Affected Features

- ✅ Gemini Veo 3 video generation (sync mode)
- ✅ Gemini Veo 3 video generation (async mode)
- ✅ Gemini image editing
- ℹ️ Gemini image generation (already correct - no changes needed)

## Backwards Compatibility

This change is **fully backwards compatible**:

1. If no offloading plugin is active, `wp_get_attachment_url()` returns the same URL as `$upload['url']`
2. We still fall back to `wp_get_attachment_url()` if `$upload['url']` is not available
3. Existing code that relies on attachment IDs continues to work

## How to Verify

1. **Without Offloading:**
   - Generate a Veo video
   - Check that the URL in the chat points to `/wp-content/uploads/`

2. **With Offloading (OneDrive, S3, etc.):**
   - Configure media offloading plugin
   - Generate a Veo video
   - Verify the URL in chat still points to local WordPress uploads
   - The offloading plugin may still serve the file from OneDrive, but the URL shown to users is local

## Future Considerations

This same pattern should be applied to any new tools that:
1. Use `wp_upload_bits()` to save files
2. Return URLs to the chat client
3. Want to ensure local WordPress URLs are used

## Related Issues

- Gemini image generation already uses this pattern (no changes needed)
- Image base class (`class-wp-mcp-ai-tool-image-base.php`) already uses this pattern
- Other tools that fetch existing attachments should continue using `wp_get_attachment_url()`
