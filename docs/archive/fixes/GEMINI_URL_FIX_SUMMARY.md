# Fix: Gemini Image/Video URLs Point to WordPress Media Library (SoC-Compliant)

## Problem Statement

When Gemini Veo 3 generates videos or edits images, the files are correctly saved to WordPress media library. However, in the chat client, the video/image links were pointing to OneDrive URLs instead of the local WordPress media library URLs.

## Root Cause

WordPress installations with media offloading plugins (like WP Offload Media, WP-Stateless, etc.) configured to use external storage (OneDrive, S3, etc.) filter `wp_get_attachment_url()` to return the external storage URL instead of the local WordPress upload directory URL.

## Solution (SoC-Compliant)

Created a utility class `WP_MCP_AI_Media_URL_Utils` that provides centralized, reusable methods for retrieving local WordPress URLs. This eliminates code duplication while maintaining proper Separation of Concerns (SoC) between tools and services.

### Architecture Benefits

1. **Single Responsibility**: Utility class has one job - provide local WordPress URLs
2. **No Code Duplication**: URL selection logic exists in one place
3. **Reusability**: Any tool or service can use the utility class
4. **Testability**: Utility methods can be unit tested independently
5. **Maintainability**: Future changes only need to be made in one place
6. **Layer Independence**: Tools and services remain architecturally separate

## Implementation

### New Utility Class

**File:** `includes/class-wp-mcp-ai-media-url-utils.php` (NEW)

```php
class WP_MCP_AI_Media_URL_Utils {
    /**
     * Get local WordPress URL (not external CDN/offloaded URL).
     * Prefers wp_upload_bits() URL over wp_get_attachment_url().
     */
    public static function get_local_upload_url( $upload, $attachment_id = 0 )

    /**
     * Build standardized attachment result with local URL.
     */
    public static function build_attachment_result( $attachment_id, $upload )
}
```

### Updated Files

**Tools:**
- `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`
- `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`

**Services:**
- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

All now use: `WP_MCP_AI_Media_URL_Utils::build_attachment_result()` or `get_local_upload_url()`

## Why This Works

- **`wp_upload_bits()`** → Returns array with local `'url'` key
- **`$upload['url']`** → Always points to local WordPress uploads directory  
- **`wp_get_attachment_url()`** → Can be filtered to return external URLs
- **Utility Class** → Centralizes the decision logic following SoC

## Test Coverage

**File:** `tests/test-veo-local-url.php` (NEW)

Tests verify:
- Service returns local URLs (not OneDrive)
- Tool returns local URLs (not OneDrive)
- Async completion uses local URLs (not OneDrive)
- URLs contain `wp-content/uploads` and NOT `onedrive`

## Backwards Compatibility

✅ Fully backwards compatible:
- Falls back to `wp_get_attachment_url()` if upload URL unavailable
- No breaking changes for existing functionality
- Works with or without media offloading plugins

## Affected Features

- ✅ Gemini Veo 3 video generation (sync and async)
- ✅ Gemini image editing
- ℹ️ Gemini image generation (already correct - no changes needed)

## Future Usage

For any new tools that save files to WordPress media:

```php
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

$upload = wp_upload_bits( $filename, null, $data );
// ... create attachment ...

return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
```

This maintains SoC and ensures consistency across the codebase.
