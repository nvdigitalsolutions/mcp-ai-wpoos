# Media Toolkit AI Tools - Quick Start Guide

This guide shows you how to use the Media Toolkit AI assistant tools to programmatically manage media templates and collections.

## Prerequisites

1. Enable Media Toolkit: **Settings → NV oOS → Tools & Features → Enable Media Toolkit**
2. Requires `upload_files` capability
3. Pro version required

## Available Tools

### 1. `list_media_templates` - Browse Templates

List all available templates with optional filtering.

**Basic Usage:**
```json
{
  "tool": "list_media_templates",
  "arguments": {}
}
```

**With Filters:**
```json
{
  "tool": "list_media_templates",
  "arguments": {
    "operation": "resize_graphic",
    "category": "social-media",
    "search": "instagram",
    "per_page": 20,
    "page": 1
  }
}
```

### 2. `create_media_template` - Create New Template

Create reusable templates for consistent image processing.

**Resize Template:**
```json
{
  "tool": "create_media_template",
  "arguments": {
    "title": "Instagram Square Post",
    "description": "Perfect square format for Instagram feed",
    "operation": "resize_graphic",
    "parameters": {
      "target_width": 1080,
      "target_height": 1080,
      "output_format": "jpg",
      "maintain_ratio": false,
      "quality": 90
    },
    "categories": ["social-media", "instagram"]
  }
}
```

**Logo Watermark Template:**
```json
{
  "tool": "create_media_template",
  "arguments": {
    "title": "Brand Watermark (Bottom Right)",
    "description": "Adds company logo in bottom-right corner",
    "operation": "add_logo",
    "parameters": {
      "logo_position": "bottom-right",
      "logo_scale": 0.15,
      "logo_margin": 20
    },
    "categories": ["branding"]
  }
}
```

### 3. `apply_media_template` - Process Single Image

Apply a template to process a single image.

```json
{
  "tool": "apply_media_template",
  "arguments": {
    "template_id": 123,
    "attachment_id": 456,
    "override_params": {
      "logo_attachment_id": 789
    }
  }
}
```

**Notes:**
- `template_id`: Get from `list_media_templates` or admin interface
- `attachment_id`: WordPress attachment (image) ID
- `override_params`: Optional parameters to override template defaults

### 4. `process_collection` - Batch Process

Process all images in a collection using assigned templates.

```json
{
  "tool": "process_collection",
  "arguments": {
    "collection_id": 101
  }
}
```

**With Specific Templates:**
```json
{
  "tool": "process_collection",
  "arguments": {
    "collection_id": 101,
    "template_ids": [123, 124]
  }
}
```

### 5. `apply_collection_template` - Assign & Process

Assign templates to a collection and optionally process immediately.

```json
{
  "tool": "apply_collection_template",
  "arguments": {
    "collection_id": 101,
    "template_ids": [123, 124, 125],
    "append": false,
    "process": true
  }
}
```

**Parameters:**
- `append`: `true` to add to existing templates, `false` to replace
- `process`: `true` to process immediately, `false` to just assign

## Common Workflows

### Workflow 1: Social Media Campaign

1. **Create templates for different platforms:**
```json
// Instagram Square
{"tool": "create_media_template", "arguments": {"title": "Instagram Square", "operation": "resize_graphic", "parameters": {"target_width": 1080, "target_height": 1080}}}

// Facebook Cover
{"tool": "create_media_template", "arguments": {"title": "Facebook Cover", "operation": "resize_graphic", "parameters": {"target_width": 820, "target_height": 312}}}

// Twitter Header
{"tool": "create_media_template", "arguments": {"title": "Twitter Header", "operation": "resize_graphic", "parameters": {"target_width": 1500, "target_height": 500}}}
```

2. **Apply all templates to a collection:**
```json
{
  "tool": "apply_collection_template",
  "arguments": {
    "collection_id": 101,
    "template_ids": [123, 124, 125],
    "process": true
  }
}
```

### Workflow 2: Product Photo Watermarking

1. **Create watermark template:**
```json
{
  "tool": "create_media_template",
  "arguments": {
    "title": "Product Watermark",
    "operation": "add_logo",
    "parameters": {
      "logo_position": "bottom-right",
      "logo_scale": 0.1,
      "logo_margin": 15
    },
    "categories": ["e-commerce", "branding"]
  }
}
```

2. **Apply to all products in collection:**
```json
{
  "tool": "process_collection",
  "arguments": {
    "collection_id": 201,
    "template_ids": [126]
  }
}
```

### Workflow 3: Batch Resize for Web

1. **Find existing resize templates:**
```json
{
  "tool": "list_media_templates",
  "arguments": {
    "operation": "resize_graphic",
    "category": "e-commerce"
  }
}
```

2. **Apply template to single image:**
```json
{
  "tool": "apply_media_template",
  "arguments": {
    "template_id": 97,
    "attachment_id": 302
  }
}
```

## Operation Types

The following operations are available for templates:

| Operation | Description | Key Parameters |
|-----------|-------------|----------------|
| `add_logo` | Overlay logo watermark | `logo_attachment_id`, `logo_position`, `logo_scale` |
| `resize_graphic` | Smart resize with format conversion | `target_width`, `target_height`, `output_format` |
| `expand_scene` | Canvas expansion | `expand_direction`, `expand_pixels` |
| `ai_enhance` | AI-powered enhancement | `enhancement_level` |
| `ai_style` | Change image style | `target_style` |
| `ai_background` | Background removal/change | `background_action` |
| `ai_retouch` | General AI retouching | `retouch_instructions` |

## Template Categories

Preset categories available:

- `social-media` - Social media platform formats
- `e-commerce` - Product images and store graphics
- `branding` - Logo overlays and watermarks
- `content` - Blog posts, newsletters, content marketing
- `marketing` - Promotional banners, badges, events

## Response Examples

### Successful Template Creation
```json
{
  "success": true,
  "template_id": 123,
  "template": {
    "id": 123,
    "title": "Instagram Square Post",
    "operation": "resize_graphic",
    "parameters": {...},
    "categories": [...],
    "usage_count": 0,
    "created": "2025-01-17T07:00:00Z"
  },
  "message": "Media template \"Instagram Square Post\" created successfully."
}
```

### Successful Collection Processing
```json
{
  "success": true,
  "collection": {
    "id": 101,
    "title": "Campaign Images",
    "process_count": 5
  },
  "statistics": {
    "total_operations": 15,
    "items_processed": 5,
    "templates_used": 3,
    "success_count": 14,
    "error_count": 1
  },
  "results": [...]
}
```

## Error Handling

All tools return standardized error responses:

```json
{
  "success": false,
  "error": "Error message explaining what went wrong"
}
```

Common errors:
- "Media Toolkit is not enabled" - Enable in settings
- "Invalid template ID" - Template doesn't exist or isn't published
- "Invalid attachment ID" - Image doesn't exist
- "Template operation is not configured" - Template missing operation type
- "Collection has no items" - Add images to collection first

## Tips

1. **Use Presets**: 15+ preset templates are automatically created when enabling Media Toolkit
2. **Test First**: Apply templates to single images before batch processing
3. **Track Usage**: Template usage statistics are automatically tracked
4. **Override When Needed**: Use `override_params` for logo operations with different logos
5. **Batch Efficiently**: Use collections for processing multiple images with multiple templates

## Next Steps

- Create your own custom templates for common operations
- Organize templates with categories
- Build collections of related images
- Automate workflows with batch processing
- Track template usage for consistency

For complete documentation, see: `addons/pro/docs/media-toolkit.md`
