# Graphic Editor Plus Tool Documentation

## Overview

The Graphic Editor Plus tool is a comprehensive pro-level image editing tool that combines local operations with AI-powered features for professional graphic manipulation via natural language chat commands.

## Operations

### LOCAL OPERATIONS (Fast, No API Cost)

#### 1. Add Logo (`add_logo`)
Overlay a logo onto an image with intelligent positioning and transparency handling.

**Parameters:**
- `logo_attachment_id` or `logo_url`: Logo image source (required)
- `logo_position`: Position (top-left, top-right, bottom-left, bottom-right, center) - Default: bottom-left
- `logo_scale`: Scale relative to image width (0.05-0.5) - Default: 0.15
- `logo_margin`: Margin in pixels from edge - Default: 20

**Example:**
```json
{
  "operation": "add_logo",
  "attachment_id": 123,
  "logo_attachment_id": 456,
  "logo_position": "bottom-left",
  "logo_scale": 0.15,
  "logo_margin": 20
}
```

**Chat Examples:**
- "Add logo to bottom-left corner"
- "Place logo at top-right with 30px margin"
- "Add watermark to center"

#### 2. Resize Graphic (`resize_graphic`)
Smart resize with format conversion and quality control.

**Parameters:**
- `target_width`: Width in pixels (optional if height provided)
- `target_height`: Height in pixels (optional if width provided)
- `output_format`: Format (png, jpg, webp) - Default: png
- `maintain_ratio`: Preserve aspect ratio - Default: true
- `quality`: Quality for JPG/WebP (1-100) - Default: 90

**Example:**
```json
{
  "operation": "resize_graphic",
  "attachment_id": 123,
  "target_width": 1920,
  "target_height": 1080,
  "output_format": "webp",
  "quality": 90
}
```

**Chat Examples:**
- "Resize to 1920x1080"
- "Resize to 800px wide and convert to JPG"
- "Make it 500px tall, maintain aspect ratio"

#### 3. Expand Scene (`expand_scene`)
*Currently not implemented - reserved for future enhancement.*

### AI-POWERED OPERATIONS (Intelligent, Using Gemini)

#### 4. AI Enhance (`ai_enhance`)
AI-powered photo enhancement with automatic quality improvements.

**Parameters:**
- `prompt`: Enhancement instructions (required)
- `model`: Gemini model - Default: gemini-2.0-flash-exp
- `aspect_ratio`: Aspect ratio - Default: 1:1

**Example:**
```json
{
  "operation": "ai_enhance",
  "attachment_id": 123,
  "prompt": "enhance brightness and contrast"
}
```

**Chat Examples:**
- "Enhance brightness and contrast"
- "Improve lighting and details"
- "Make the colors more vibrant"

#### 5. AI Style (`ai_style`)
Transform image style using AI (watercolor, sketch, artistic effects).

**Parameters:**
- `prompt`: Style transformation instructions (required)
- `model`: Gemini model - Default: gemini-2.0-flash-exp
- `aspect_ratio`: Aspect ratio - Default: 1:1

**Example:**
```json
{
  "operation": "ai_style",
  "attachment_id": 123,
  "prompt": "convert to watercolor painting"
}
```

**Chat Examples:**
- "Make it look like a watercolor painting"
- "Convert to pencil sketch"
- "Apply oil painting effect"

#### 6. AI Background (`ai_background`)
Remove or modify background with intelligent subject preservation.

**Parameters:**
- `prompt`: Background modification instructions (required)
- `model`: Gemini model - Default: gemini-2.0-flash-exp
- `aspect_ratio`: Aspect ratio - Default: 1:1

**Example:**
```json
{
  "operation": "ai_background",
  "attachment_id": 123,
  "prompt": "remove background and make transparent"
}
```

**Chat Examples:**
- "Remove background"
- "Change sky to sunset"
- "Make background transparent"

#### 7. AI Retouch (`ai_retouch`)
General AI-powered retouching and editing.

**Parameters:**
- `prompt`: Retouching instructions (required)
- `model`: Gemini model - Default: gemini-2.0-flash-exp
- `aspect_ratio`: Aspect ratio - Default: 1:1

**Example:**
```json
{
  "operation": "ai_retouch",
  "attachment_id": 123,
  "prompt": "remove blemishes and smooth skin"
}
```

**Chat Examples:**
- "Remove blemishes"
- "Fix lighting issues"
- "Clean up the background"

## Technical Details

### Architecture
- **Extends**: `WP_MCP_AI_Tool_Image_Base`
- **Tool Slug**: `graphic_editor_plus`
- **Capability Flags**: `requires-capability`, `write`, `mixed-mode`, `idempotent`, `performance-impact`, `pro-tool`
- **Pro Tool**: Only available in full version (not base version)

### Image Editor Support
Local operations automatically use the best available image editor:
- **ImageMagick**: Used when available (Cloudways, modern hosting) - Superior quality and performance
- **GD**: Fallback for compatibility - Works on all PHP installations

### AI Integration
AI operations use the Gemini client for intelligent image transformations:
- Requires Gemini API key configuration
- Automatic base64 encoding of source images
- Result images stored as WordPress attachments

### Authentication & Permissions
- Requires user authentication (user_id or token)
- Requires `upload_files` capability
- Multisite compatible with proper blog membership checks

## Use Cases

### Logo Placement
- Add watermarks to images
- Brand photos with company logos
- Add copyright marks to content

### Format Conversion
- Convert PNGs to JPG for smaller file sizes
- Convert to WebP for modern browsers
- Resize and convert in one operation

### AI Enhancement
- Automatically improve photo quality
- Apply artistic styles
- Professional retouching without manual editing

### Background Operations
- Remove backgrounds for product photos
- Change scene backgrounds
- Create transparent PNGs

## Testing

The tool includes comprehensive unit tests covering:
- All 7 operations
- Authentication and permissions
- Parameter validation
- Error handling
- Position calculations
- Helper methods

Run tests with:
```bash
composer run test -- --filter=WP_MCP_AI_Graphic_Editor_Plus_Test
```

## Filters

### `wp_mcp_ai_graphic_editor_plus_add_logo_result`
Filter the add logo operation result.

**Parameters:**
- `$result` (array): Result array
- `$arguments` (array): Tool arguments

### `wp_mcp_ai_graphic_editor_plus_resize_result`
Filter the resize graphic operation result.

**Parameters:**
- `$result` (array): Result array
- `$arguments` (array): Tool arguments

### `wp_mcp_ai_graphic_editor_plus_ai_result`
Filter the AI operation result.

**Parameters:**
- `$result` (array): Result array
- `$arguments` (array): Tool arguments

## Integration

### With Chat
The tool integrates seamlessly with the chat interface. Users can use natural language:
- "Add logo to bottom-left" → Automatically calls `add_logo` operation
- "Resize to 1920x1080 PNG" → Automatically calls `resize_graphic` operation
- "Remove background" → Automatically calls `ai_background` operation

### With REST API
Direct tool execution via REST API:
```bash
POST /wp-json/mcp-ai/v1/tools
{
  "tool": "graphic_editor_plus",
  "arguments": {
    "operation": "add_logo",
    "attachment_id": 123,
    "logo_attachment_id": 456
  }
}
```

## Future Enhancements

### Planned Features
- **Expand Scene**: Local canvas expansion with solid colors or intelligent AI outpainting
- **Batch Operations**: Process multiple images in one call
- **Preset Templates**: Save commonly used operation configurations
- **Advanced Compositing**: Layer multiple images with blend modes

### Potential AI Operations
- **AI Upscale**: Increase resolution using AI
- **AI Restoration**: Restore old/damaged photos
- **AI Color Grading**: Professional color correction
- **AI Object Removal**: Remove unwanted objects intelligently
