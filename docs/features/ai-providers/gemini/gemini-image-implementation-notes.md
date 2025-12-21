# Gemini Image Generation and Editing Implementation

## Overview

WP oOS provides two tools for working with Gemini's image capabilities:
- `generate_gemini_image` - Text-to-image generation
- `edit_gemini_image` - Image-to-image editing (Nano Banana)

## Shared Pattern Implementation

Both tools use **identical API configuration patterns** to ensure consistency and maintainability.

### Common Configuration

| Setting | Value | Notes |
|---------|-------|-------|
| **Default Model** | `gemini-2.5-flash-image` | Optimized for image tasks |
| **Response Modalities** | `['IMAGE']` | Required for image output |
| **Image Config** | `aspectRatio` support | 1:1, 3:4, 4:3, 9:16, 16:9 |
| **Temperature** | Optional | Configurable per request |
| **MIME Types** | PNG, JPEG | WebP for generation only |
| **Timeout** | 5-300 seconds | Adjustable per request |
| **Usage Tracking** | Enabled | Token counts for billing |

### Implementation Details

#### Generation (`generate_image`)
```php
// Payload structure
$payload = array(
    'contents' => array(
        array(
            'role'  => 'user',
            'parts' => array(
                array( 'text' => $prompt ),
            ),
        ),
    ),
    'generationConfig' => array(
        'responseModalities' => array( 'IMAGE' ),
        'imageConfig' => array(
            'aspectRatio' => '1:1',  // configurable
        ),
        'temperature' => 0.8,  // optional
    ),
);
```

#### Editing (`edit_image`)
```php
// Payload structure (identical except for source image)
$payload = array(
    'contents' => array(
        array(
            'role'  => 'user',
            'parts' => array(
                array( 'text' => $prompt ),
                array(
                    'inline_data' => array(
                        'mime_type' => 'image/png',
                        'data'      => $base64_image,
                    ),
                ),
            ),
        ),
    ),
    'generationConfig' => array(
        'responseModalities' => array( 'IMAGE' ),
        'imageConfig' => array(
            'aspectRatio' => '1:1',  // configurable
        ),
        'temperature' => 0.8,  // optional
    ),
);
```

### Key Differences

The **only** difference between the two methods is the presence of the source image:

- **Generation**: `parts` contains only text prompt
- **Editing**: `parts` contains text prompt + source image data

All other aspects (API endpoint, model, configuration, response handling, error handling, logging) are identical.

## Why This Pattern?

### Design Rationale

1. **Consistency**: Same configuration ensures predictable behavior
2. **Maintainability**: Changes to one can be easily mirrored in the other
3. **Model Optimization**: `gemini-2.5-flash-image` is optimized for both generation and editing
4. **API Compatibility**: Gemini uses the same endpoint for both operations
5. **Feature Parity**: Both support the same aspect ratios, formats, and quality settings

### Historical Context

Prior to consolidation, different models or configurations could lead to inconsistent results. By standardizing on the image model (`gemini-2.5-flash-image`) for both operations, we ensure:

- Consistent quality and performance
- Unified rate limiting and quota management
- Simplified debugging and testing
- Better user experience with predictable results

## Testing Pattern Consistency

The test suite includes `Test_Gemini_Image_Pattern_Consistency` which verifies:

- ✅ Both tools use the same default model
- ✅ Both tools use the same default MIME type
- ✅ Both tools use the same default aspect ratio
- ✅ Both tools have consistent parameter schemas
- ✅ Both tools use consistent capability flags
- ✅ Both tools have compatible model requirements
- ✅ Both tools have identical rate limits
- ✅ Both tools require the same Gemini provider/models
- ✅ Both tools implement LLM sanitization consistently

These tests serve as regression guards to prevent accidental divergence.

## Usage Examples

### Text-to-Image Generation
```php
$client = new WP_MCP_AI_Gemini_Client();
$image = $client->generate_image( 'A sunset over mountains', array(
    'model'        => 'gemini-2.5-flash-image',
    'aspect_ratio' => '16:9',
    'mime_type'    => 'image/png',
) );
```

### Image-to-Image Editing
```php
$client = new WP_MCP_AI_Gemini_Client();
$image = $client->edit_image( 'Make the sky purple', array(
    'model'        => 'gemini-2.5-flash-image',
    'aspect_ratio' => '16:9',
    'mime_type'    => 'image/png',
    'source_image' => array(
        'mime_type' => 'image/png',
        'data'      => $base64_source_image,
    ),
) );
```

## Configuration Settings

Both tools respect the same WordPress settings:

- `gemini_image_model` - Default model (fallback: `gemini-2.5-flash-image`)
- `gemini_image_mime_type` - Default MIME type (fallback: `image/png`)
- `gemini_image_aspect_ratio` - Default aspect ratio (fallback: `1:1`)

These can be configured at:
**WordPress Admin → Settings → WP oOS → Gemini Settings**

## Future Considerations

### Maintaining Consistency

When modifying either method, ensure changes are mirrored in the other:

1. **Before**: Check current implementation in both methods
2. **Change**: Apply identical changes to configuration/handling
3. **Test**: Run `Test_Gemini_Image_Pattern_Consistency`
4. **Verify**: Confirm both methods produce expected results
5. **Document**: Update this file with any pattern changes

### Extension Points

Both methods provide filters for customization:

- `wp_mcp_ai_gemini_image_payload` - Modify generation request
- `wp_mcp_ai_gemini_image_edit_payload` - Modify edit request
- `wp_mcp_ai_gemini_image_result` - Modify generation response
- `wp_mcp_ai_gemini_image_edit_result` - Modify edit response
- `wp_mcp_ai_gemini_image_defaults` - Modify default settings
- `wp_mcp_ai_gemini_image_edit_defaults` - Modify edit defaults

## Related Documentation

- [Tool Reference](../../../reference/tools/tool-reference.md) - Complete tool documentation
- [REST API Reference](../../../reference/api/rest-api.md) - API endpoint details
- [Gemini Client](../includes/class-wp-mcp-ai-gemini-client.php) - Implementation source
- [Generate Tool](../includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php)
- [Edit Tool](../includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php)

## Troubleshooting

### Issue: Inconsistent Results Between Generation and Editing

**Cause**: This should not occur as both use the same model and configuration.

**Solution**: 
1. Verify both tools are using `gemini-2.5-flash-image` model
2. Check settings at **Settings → WP oOS → Gemini Settings**
3. Run consistency tests: `composer test -- tests/test-gemini-image-pattern-consistency.php`
4. Enable logging to compare request payloads

### Issue: Different Quality or Style

**Cause**: Different prompts or aspect ratios can affect results.

**Solution**:
1. Use identical prompts for comparison
2. Use same aspect ratio and MIME type
3. Ensure source image quality is high for editing
4. Check model version in response metadata

## References

- [Gemini API Documentation](https://ai.google.dev/docs)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [WP oOS Architecture](ARCHITECTURE_DIAGRAM.txt)
