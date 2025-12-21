# Graphic Editor Suite - Image Manipulation Tools

## Overview

The WP oOS plugin now includes a comprehensive suite of image manipulation tools that work entirely locally using WordPress's native image processing capabilities. These tools enable AI assistants to perform common image editing tasks without requiring external APIs.

## Architecture

### Separation of Concerns

Each tool has a single, well-defined responsibility:

- **resize_image** - Resize images to specific dimensions
- **crop_image** - Crop images to regions or aspect ratios
- **rotate_image** - Rotate and flip images
- **convert_image_format** - Convert between image formats

### Base Class

All image manipulation tools extend `WP_MCP_AI_Tool_Image_Base`, which provides:

- **Image Loading**: Load from WordPress attachment ID, URL, or base64 data
- **WordPress Integration**: Uses `wp_get_image_editor()` API
- **Attachment Saving**: Consistent saving logic with metadata generation
- **LLM Sanitization**: Removes base64 data to prevent context bloat
- **Error Handling**: Comprehensive WP_Error responses
- **Temp File Management**: Automatic cleanup of temporary files

## Tools

### 1. Resize Image (`resize_image`)

Resize images to specific dimensions or scale proportionally.

**Parameters:**
- `attachment_id` (int, optional): WordPress attachment ID
- `image_url` (string, optional): URL to image file
- `image_data` (string, optional): Base64-encoded image data
- `width` (int, optional): Target width in pixels (1-10000)
- `height` (int, optional): Target height in pixels (1-10000)
- `maintain_ratio` (bool, default: true): Maintain aspect ratio
- `crop` (bool, default: false): Crop to exact dimensions
- `file_name` (string, optional): Output filename

**Examples:**
```json
{
  "tool": "resize_image",
  "arguments": {
    "attachment_id": 123,
    "width": 800,
    "maintain_ratio": true
  }
}
```

```json
{
  "tool": "resize_image",
  "arguments": {
    "image_url": "https://example.com/image.jpg",
    "width": 1200,
    "height": 630,
    "crop": true
  }
}
```

### 2. Crop Image (`crop_image`)

Crop images to specific regions or aspect ratios.

**Parameters:**
- `attachment_id` (int, optional): WordPress attachment ID
- `image_url` (string, optional): URL to image file  
- `image_data` (string, optional): Base64-encoded image data
- **Manual Crop:**
  - `x` (int): X coordinate of top-left corner
  - `y` (int): Y coordinate of top-left corner
  - `width` (int): Crop width in pixels
  - `height` (int): Crop height in pixels
- **Aspect Ratio Crop:**
  - `aspect_ratio` (string): Target ratio (1:1, 16:9, 4:3, 3:2, 2:3, 9:16, 3:4)
  - `position` (string, default: center): Crop position (center, top, bottom, left, right, top-left, top-right, bottom-left, bottom-right)
- `file_name` (string, optional): Output filename

**Examples:**
```json
{
  "tool": "crop_image",
  "arguments": {
    "attachment_id": 123,
    "aspect_ratio": "16:9",
    "position": "center"
  }
}
```

```json
{
  "tool": "crop_image",
  "arguments": {
    "attachment_id": 123,
    "x": 100,
    "y": 50,
    "width": 400,
    "height": 300
  }
}
```

### 3. Rotate Image (`rotate_image`)

Rotate images by degrees or flip horizontally/vertically.

**Parameters:**
- `attachment_id` (int, optional): WordPress attachment ID
- `image_url` (string, optional): URL to image file
- `image_data` (string, optional): Base64-encoded image data
- `angle` (number, -360 to 360): Rotation angle in degrees (clockwise)
- `flip_horizontal` (bool, default: false): Flip horizontally (mirror)
- `flip_vertical` (bool, default: false): Flip vertically
- `file_name` (string, optional): Output filename

**Examples:**
```json
{
  "tool": "rotate_image",
  "arguments": {
    "attachment_id": 123,
    "angle": 90
  }
}
```

```json
{
  "tool": "rotate_image",
  "arguments": {
    "attachment_id": 123,
    "flip_horizontal": true
  }
}
```

### 4. Convert Image Format (`convert_image_format`)

Convert images between PNG, JPEG, WebP, and GIF formats.

**Parameters:**
- `attachment_id` (int, optional): WordPress attachment ID
- `image_url` (string, optional): URL to image file
- `image_data` (string, optional): Base64-encoded image data
- `format` (string, required): Target format (png, jpeg, jpg, webp, gif)
- `quality` (int, 1-100, default: 90): Output quality for JPEG/WebP
- `file_name` (string, optional): Output filename

**Examples:**
```json
{
  "tool": "convert_image_format",
  "arguments": {
    "attachment_id": 123,
    "format": "webp",
    "quality": 85
  }
}
```

```json
{
  "tool": "convert_image_format",
  "arguments": {
    "image_data": "iVBORw0KGgoAAAANS...",
    "format": "jpeg",
    "quality": 90
  }
}
```

## Image Source Options

All tools support three ways to specify the source image:

1. **WordPress Attachment** (`attachment_id`): Reference existing media library images
2. **URL** (`image_url`): Download and process images from any URL
3. **Base64 Data** (`image_data`): Process images generated in chat or uploaded directly

## Return Format

All tools return a consistent response format:

```json
{
  "attachment_id": 456,
  "url": "https://example.com/wp-content/uploads/2025/11/image-resized-20251121-035045.jpg",
  "file_name": "image-resized-20251121-035045.jpg",
  "mime_type": "image/jpeg",
  "bytes": 125432,
  "title": "Resized: Original Image Title",
  "operation": "resize",
  "text": "Successfully resized image from 2000x1500 to 800x600.",
  "content": {
    "encoding": "base64",
    "data": "iVBORw0KGgoAAAANS...",
    "mime_type": "image/jpeg",
    "data_url": "data:image/jpeg;base64,iVBORw0KGgoAAAANS...",
    "file_name": "image-resized-20251121-035045.jpg",
    "bytes": 125432
  }
}
```

**Note**: The `content.data` and `content.data_url` fields are stripped when the result is passed to the LLM to prevent context bloat. The full response with image data is preserved for the frontend.

## Chaining Tools

Tools can be chained together to create complex editing workflows:

**Example 1**: Resize, then convert to WebP
```
1. resize_image: attachment_id=123, width=1200 → attachment_id=456
2. convert_image_format: attachment_id=456, format=webp → final image
```

**Example 2**: Crop to square, rotate, then resize
```
1. crop_image: attachment_id=123, aspect_ratio=1:1 → attachment_id=456
2. rotate_image: attachment_id=456, angle=90 → attachment_id=789
3. resize_image: attachment_id=789, width=800 → final image
```

## Capability Flags

All tools declare the following capability flags:

- `requires-capability`: Requires user with `upload_files` capability
- `write`: Creates new media files
- `local-only`: Works entirely locally without external APIs

## Requirements

- **PHP**: 7.4+
- **WordPress**: 6.0+
- **PHP Extensions**: GD or ImageMagick (automatically detected)
- **Permissions**: User must have `upload_files` capability

## Security

- **Input Validation**: All parameters are sanitized and validated
- **Permission Checks**: Requires authentication and `upload_files` capability
- **MIME Type Validation**: Only allows safe image formats
- **File Size Limits**: Respects WordPress upload limits
- **Attachment Permissions**: Checks read permissions for source attachments

## Performance

- **Local Processing**: No external API calls
- **WordPress Native**: Uses optimized `WP_Image_Editor` class
- **Automatic Format**: Uses GD or ImageMagick based on availability
- **Memory Efficient**: Temp files automatically cleaned up

## Limitations

- Maximum dimensions: 10,000 x 10,000 pixels
- Supported formats: PNG, JPEG, WebP, GIF
- Quality control only for JPEG and WebP
- Image processing capabilities depend on PHP extension (GD or ImageMagick)

## Future Enhancements

Planned additions to the graphic editor suite:

- **adjust_image**: Brightness, contrast, saturation adjustments
- **apply_image_filter**: Grayscale, sepia, blur, sharpen filters
- **watermark_image**: Add text or image watermarks
- **compress_image**: Optimize file sizes
- **remove_background**: Background removal (requires external API)
- **merge_images**: Combine multiple images (collage, overlay)
- **batch_process_images**: Apply operations to multiple images
- **analyze_image**: Get dimensions, format, color information

## Contributing

When adding new image manipulation tools:

1. Extend `WP_MCP_AI_Tool_Image_Base`
2. Implement single responsibility principle
3. Follow existing naming conventions
4. Add comprehensive parameter validation
5. Include error handling
6. Write unit tests
7. Update this documentation

## See Also

- [Tool Reference](reference/tools/tool-reference.md)
- [REST API Documentation](reference/api/rest-api.md)
- [WordPress Image Editor](https://developer.wordpress.org/reference/classes/wp_image_editor/)
