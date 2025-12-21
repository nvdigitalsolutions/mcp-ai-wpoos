# Tool Update Guide: Adding file_id and URL Support

## Overview

This guide documents the pattern for updating tools to support three input formats:
1. **attachment_id** - WordPress attachment ID (integer)
2. **file_id** - OpenAI/Gemini file identifier (string like 'file-abc123')
3. **url** - Direct file URL (string)

## Core Infrastructure

### Trait: `WP_MCP_AI_Attachment_File_Resolver`

Location: `includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php`

**Key Methods:**
- `resolve_attachment_id($arguments, $param_name)` - Resolves attachment_id, file_id, or url to WordPress attachment ID
- `resolve_attachment_id_from_file_id($file_id)` - Converts OpenAI/Gemini file ID to attachment ID
- `resolve_attachment_id_from_url($url)` - Converts URL to attachment ID (or returns remote URL)
- `get_file_id_parameter_schema($description)` - Returns schema for file_id parameter
- `get_url_parameter_schema($media_type, $description)` - Returns schema for url parameter

### Base Class: `WP_MCP_AI_Tool_Image_Base`

Updated to use the trait and provide file_id/url support to all inheriting tools:
- convert-image-format
- crop-image
- resize-image
- rotate-image

## Update Pattern

### Step 1: Add Trait to Class

```php
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

class WP_MCP_AI_Tool_Your_Tool implements WP_MCP_AI_Tool_Interface {
    use WP_MCP_AI_Attachment_File_Resolver;
    
    // ... rest of class
}
```

### Step 2: Update Parameter Schema

Add `file_id` and `url` parameters alongside existing `attachment_id`:

```php
public function get_parameters_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            'attachment_id' => array(
                'type'        => 'integer',
                'description' => __( 'WordPress attachment ID.', 'wp-mcp-ai' ),
            ),
            'file_id'       => $this->get_file_id_parameter_schema(),
            'url'           => $this->get_url_parameter_schema( 'image' ), // or 'video', 'audio', 'file'
            // ... other parameters
        ),
        'required'   => array(), // Don't require any single one
    );
}
```

**For tools with legacy URL parameters** (like `image_url`, `video_url`):
Keep the legacy parameter for backward compatibility, but add the new `url` parameter as well.

### Step 3: Update Execute Method

Replace direct attachment_id extraction with trait method:

**BEFORE:**
```php
public function execute( array $arguments = array(), array $context = array() ) {
    $attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
    
    if ( ! $attachment_id ) {
        return new WP_Error( 'missing_attachment', 'Attachment ID required' );
    }
    
    // ... rest of execution
}
```

**AFTER:**
```php
public function execute( array $arguments = array(), array $context = array() ) {
    // Resolve attachment ID from attachment_id, file_id, or url.
    $resolved = $this->resolve_attachment_id( $arguments );
    
    // Handle remote URL case (for tools that support remote files).
    if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
        $url = $resolved['url'];
        // Use $url directly for remote file access
    } elseif ( is_wp_error( $resolved ) ) {
        return $resolved;
    } elseif ( $resolved > 0 ) {
        $attachment_id = $resolved;
    } else {
        return new WP_Error(
            'wp_mcp_ai_missing_source',
            __( 'You must provide attachment_id, file_id, or url.', 'wp-mcp-ai' ),
            array( 'status' => 400 )
        );
    }
    
    // ... rest of execution using $attachment_id or $url
}
```

**For tools that don't support remote URLs** (like transcribe-openai-audio):
```php
$resolved = $this->resolve_attachment_id( $arguments );

if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
    return new WP_Error(
        'wp_mcp_ai_remote_url_not_supported',
        __( 'Remote URLs are not supported. Please upload to Media Library first.', 'wp-mcp-ai' ),
        array( 'status' => 400 )
    );
}

if ( is_wp_error( $resolved ) ) {
    return $resolved;
}

$attachment_id = $resolved;

if ( ! $attachment_id ) {
    return new WP_Error(
        'wp_mcp_ai_missing_source',
        __( 'You must provide attachment_id, file_id, or url.', 'wp-mcp-ai' ),
        array( 'status' => 400 )
    );
}
```

### Step 4: Update Error Messages

Update all error messages that mention parameter requirements:

**BEFORE:**
```php
__( 'Either attachment_id or image_url must be provided.', 'wp-mcp-ai' )
```

**AFTER:**
```php
__( 'You must provide attachment_id, file_id, url, or image_url.', 'wp-mcp-ai' )
```

## Tools Completed (10/22)

1. ✅ transcribe-openai-audio
2. ✅ generate-image-alt-text
3. ✅ generate-image-caption
4. ✅ generate-video-caption
5. ✅ edit-gemini-image
6. ✅ convert-image-format (via Image_Base)
7. ✅ crop-image (via Image_Base)
8. ✅ resize-image (via Image_Base)
9. ✅ rotate-image (via Image_Base)
10. ✅ submit-document-prompt (already had support)

## Tools Remaining (12/22)

### High Priority (Media Analysis/Generation)
- analyze-video
- extract-video-frames
- generate-gemini-image
- generate-music
- generate-openai-image
- generate-openai-speech
- generate-veo-video
- get-video-metadata

### Medium Priority
- check-video-status
- create-chart

### Low Priority
- import-elementor-template-kit
- send-group-email

## Special Cases

### Tools Extending Image_Base

Tools that extend `WP_MCP_AI_Tool_Image_Base` automatically inherit file_id and url support through the base class. No code changes needed, but verify:

1. The tool uses `$this->load_source_image()` method
2. The tool uses `$this->get_source_parameters_schema()` method
3. Test with file_id and url parameters

### Tools with Custom URL Parameters

Some tools have legacy URL parameters (`image_url`, `video_url`, etc.). For these:

1. Keep the legacy parameter for backward compatibility
2. Add the new `url` parameter as an alternative
3. In execute method, prioritize new `url` parameter but fallback to legacy

Example from `generate-video-caption`:
```php
// Try to resolve from attachment_id, file_id, or url first.
if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) || ! empty( $arguments['url'] ) ) {
    $resolved = $this->resolve_attachment_id( $arguments );
    // ... handle resolved value
}

// Fallback to legacy video_url parameter.
if ( '' === $video_url && ! empty( $arguments['video_url'] ) ) {
    $video_url = esc_url_raw( $arguments['video_url'] );
}
```

### Tools That Don't Accept Files

Some tools in the original list may not actually accept file attachments (like `send-group-email`). For these:
- Review if they actually need this update
- If they use attachments as email attachments, apply the pattern
- If not, remove from the update list

## Testing Checklist

For each updated tool, test:

- [ ] Works with `attachment_id` (existing functionality)
- [ ] Works with `file_id` from OpenAI/Gemini
- [ ] Works with `url` pointing to WordPress media
- [ ] Works with `url` pointing to remote file (if supported)
- [ ] Returns appropriate error for invalid file_id
- [ ] Returns appropriate error for inaccessible url
- [ ] Backward compatible with legacy parameters

## Example: Complete Update

See `class-wp-mcp-ai-tool-generate-image-alt-text.php` for a complete example of:
- Trait usage
- Parameter schema with all three types
- Execute method with proper handling
- Support for legacy `image_url` parameter

## Commit Pattern

Use descriptive commit messages:
```
Add file_id and url support to [tool-name] tool

- Add WP_MCP_AI_Attachment_File_Resolver trait
- Update parameter schema with file_id and url
- Update execute method to handle all input types
- Maintain backward compatibility with legacy parameters
```

## Notes

- Always maintain backward compatibility
- Test with both local and remote URLs
- Verify error messages are helpful
- Consider whether remote URLs are appropriate for the tool's function
- Update related documentation after tool changes
