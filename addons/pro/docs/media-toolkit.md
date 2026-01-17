# Media Toolkit - Pro Feature Documentation

## Overview

The **Media Toolkit** is a Pro feature that enhances the Graphic Editor Plus tool with CPT-based template and collection management for content (media) management workflows.

## Components

### 1. Media Templates CPT (`mcp_ai_media_tpl`)

Reusable operation configurations for the Graphic Editor Plus tool.

**Location**: Under Media menu → Media Templates

**Features**:
- Store operation type (add_logo, resize_graphic, ai_enhance, ai_style, ai_background, ai_retouch)
- JSON parameter configuration with validation
- Usage statistics tracking (times used, last used, popularity)
- Template categories taxonomy
- Admin columns: Operation, Usage, Last Used
- Duplicate template functionality

**Metaboxes**:
- **Operation Configuration**: Operation type selector + JSON parameter editor with comprehensive guide
- **Usage Statistics**: Times used, last used, popularity indicators

### 2. Media Collections CPT (`mcp_ai_media_coll`)

Group related media items for batch processing with templates.

**Location**: Under Media menu → Collections

**Features**:
- Visual media item selector (WordPress Media Library integration)
- Thumbnail grid with drag-and-drop interface
- Multi-template assignment for batch operations
- Processing pipeline visualization
- Smart statistics (items × templates = expected outputs)
- Admin columns: Items, Templates, Last Processed
- Collection categories taxonomy

**Metaboxes**:
- **Collection Media Items**: Visual media selector with thumbnails, add/remove items
- **Batch Operations & Templates**: Multi-select templates, processing pipeline preview
- **Collection Statistics**: Item count, template count, process count, ready-to-process status

## Settings

**Path**: Settings → NV oOS → Tools & Features → Features tab

**Setting**: `enable_media_toolkit`
- **Label**: Enable Media Toolkit (Pro Version only)
- **Description**: Enables template management and batch processing for Graphic Editor Plus
- **Default**: false (disabled)

## Requirements

- **Base Version Check**: Not available in Base Version mode
- **Feature Toggle**: Must be enabled in settings
- **Capabilities**: Requires `upload_files` capability
- **Dependencies**: Graphic Editor Plus tool (already Pro)

## Use Cases

1. **Social Media Campaigns**
   - Create collection of campaign images
   - Assign resize + logo templates
   - Batch process for consistent output

2. **Product Photo Management**
   - Group product photos by category
   - Apply consistent watermark + resize
   - Generate multiple sizes in one operation

3. **Event Galleries**
   - Organize event photos in collection
   - Batch apply logo and color enhancement
   - Export consistent branded assets

4. **Seasonal Promotions**
   - Create seasonal asset collections
   - Apply themed templates
   - Maintain brand consistency

5. **Brand Asset Management**
   - Store brand guidelines as templates
   - Apply to marketing materials
   - Track template usage for consistency

## Technical Details

### File Structure

```
addons/pro/includes/
├── class-wp-mcp-ai-media-template-cpt.php      # Template CPT
├── class-wp-mcp-ai-media-collection-cpt.php    # Collection CPT
├── media-toolkit-init.php                       # Initialization
└── metaboxes/
    ├── class-wp-mcp-ai-media-template-metabox-base.php
    ├── class-wp-mcp-ai-media-template-metabox-operation.php
    ├── class-wp-mcp-ai-media-template-metabox-stats.php
    ├── class-wp-mcp-ai-media-collection-metabox-items.php
    ├── class-wp-mcp-ai-media-collection-metabox-operations.php
    └── class-wp-mcp-ai-media-collection-metabox-stats.php
```

### Database Schema

**Template Post Meta**:
- `_mcp_ai_template_operation`: string (operation type)
- `_mcp_ai_template_parameters`: string (JSON-encoded parameters)
- `_mcp_ai_template_usage_count`: integer (number of uses)
- `_mcp_ai_template_last_used`: string (timestamp)

**Collection Post Meta**:
- `_mcp_ai_collection_items`: array (attachment IDs)
- `_mcp_ai_collection_templates`: array (template post IDs)
- `_mcp_ai_collection_process_count`: integer (processing count)
- `_mcp_ai_collection_last_processed`: string (timestamp)

### Admin Notices

The system provides contextual admin notices:

1. **Base Version Notice**: Shows when accessing toolkit in Base Version mode
2. **Disabled Feature Notice**: Shows when feature toggle is off
3. **Info Notices**: Contextual help on edit screens with use cases and AI tool references

### Taxonomies

1. `mcp_ai_tpl_category`: Template categories (hierarchical)
2. `mcp_ai_coll_category`: Collection categories (hierarchical)

## AI Assistant Tools (Phase 3) ✅

The following tools enable programmatic interaction with Media Templates and Collections via AI assistants:

### 1. `list_media_templates`
List available media templates with optional filtering.

**Parameters**:
- `operation` (optional): Filter by operation type (add_logo, resize_graphic, etc.)
- `category` (optional): Filter by category slug
- `search` (optional): Search by title or description
- `include_preset` (optional): Include preset templates (default: true)
- `per_page` (optional): Results per page (default: 20, max: 100)
- `page` (optional): Page number (default: 1)

**Returns**: Array of templates with ID, title, description, operation, parameters, usage stats, and categories.

### 2. `create_media_template`
Create a new media template via AI.

**Parameters**:
- `title` (required): Template title
- `description` (optional): Template description
- `operation` (required): Operation type (add_logo, resize_graphic, expand_scene, ai_enhance, ai_style, ai_background, ai_retouch)
- `parameters` (required): Operation parameters as JSON object
- `categories` (optional): Array of category slugs/names

**Returns**: Created template ID and details.

### 3. `apply_media_template`
Apply a template to a single image.

**Parameters**:
- `template_id` (required): ID of template to apply
- `attachment_id` (required): ID of image to process
- `override_params` (optional): Parameters to override template defaults

**Returns**: Processed image details and updated template usage statistics.

### 4. `process_collection`
Batch process all items in a collection using assigned templates.

**Parameters**:
- `collection_id` (required): ID of collection to process
- `template_ids` (optional): Specific templates to use (overrides collection templates)

**Returns**: Processing statistics and results for each item/template combination.

### 5. `apply_collection_template`
Assign templates to a collection and optionally process immediately.

**Parameters**:
- `collection_id` (required): ID of collection
- `template_ids` (required): Array of template IDs to assign
- `append` (optional): Append to existing templates (default: false)
- `process` (optional): Process immediately after assigning (default: true)

**Returns**: Collection details, assigned templates, and processing results if processed.

## Admin UI Enhancements (Phase 4) ✅

Enhanced admin interface with bulk operations, quick actions, and visual improvements.

### Template Admin Features

**Bulk Actions:**
- **Duplicate Templates** - Create copies of multiple templates at once
- **Export Templates** - Export selected templates as JSON for backup/sharing

**Row Actions:**
- **Duplicate** - One-click template duplication with "(Copy)" suffix
- **Quick Apply** - Apply template to an image directly from the template list
- **Preview** - View template configuration and parameter summary

**Visual Enhancements:**
- Operation badges with color coding (Resize: Blue, Logo: Purple, AI: Green)
- Enhanced admin columns with usage statistics
- Hover effects and card-style presentation
- Template preview cards with configuration summary

### Collection Admin Features

**Bulk Actions:**
- **Process Collections** - Batch process multiple collections
- **Export Collections** - Export selected collections as JSON

**Row Actions:**
- **Quick Process** - Process collection items with assigned templates instantly

**Visual Enhancements:**
- Processing status indicators
- Item and template count displays
- Last processed timestamp with human-readable format

### JavaScript Features

**Interactive Operations:**
- AJAX-powered quick apply (uses WordPress Media Library modal)
- Real-time processing status updates
- Success/error notifications with auto-dismiss
- Smooth animations and transitions

**User Experience:**
- Confirmation dialogs for destructive actions
- Loading states during AJAX operations
- URL cleanup after bulk actions
- Download links for exports

### CSS Styling

**Enhanced Visual Design:**
- Card-style layouts with shadows and hover effects
- Color-coded operation badges
- Gradient backgrounds for template thumbnails
- Responsive design for mobile admin
- Success/error message animations

## Usage Examples

### Example 1: List All Social Media Templates
```json
{
  "tool": "list_media_templates",
  "arguments": {
    "category": "social-media",
    "per_page": 10
  }
}
```

### Example 2: Create a New Instagram Template
```json
{
  "tool": "create_media_template",
  "arguments": {
    "title": "Instagram Story Format",
    "description": "Perfect size for Instagram stories",
    "operation": "resize_graphic",
    "parameters": {
      "target_width": 1080,
      "target_height": 1920,
      "output_format": "jpg",
      "maintain_ratio": false,
      "quality": 90
    },
    "categories": ["social-media", "instagram"]
  }
}
```

### Example 3: Apply a Logo Template to an Image
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

### Example 4: Batch Process a Collection
```json
{
  "tool": "process_collection",
  "arguments": {
    "collection_id": 101
  }
}
```

### Example 5: Assign Templates and Process
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

### Example 6: Search for Logo Templates
```json
{
  "tool": "list_media_templates",
  "arguments": {
    "operation": "add_logo",
    "search": "watermark"
  }
}
```

## Future Enhancements (Not Yet Implemented)

### Additional Features
- Template presets (common configurations)
- Template export/import
- Collection export functionality
- Operation history logging
- Before/after image comparison
- Undo/redo capability
- Template scheduling (apply at specific times)
- Webhook integration for processing completion

## Testing

**Test Files**: 
- `addons/pro/tests/test-media-template-cpt.php` - CPT functionality
- `addons/pro/tests/test-media-template-presets.php` - Preset seeding
- `addons/pro/tests/test-media-toolkit-tools.php` - AI assistant tools

**Tests Cover**:
- Admin notices (enabled/disabled states)
- CPT registration and taxonomy
- Template meta save/retrieve
- Admin columns
- Preset template seeding and management
- Tool parameter validation
- Tool execution with various filters
- Category assignment and filtering
- Error handling and edge cases
- Capability requirements

## Integration Points

1. **Graphic Editor Plus Tool**: Templates provide reusable configurations
2. **WordPress Media Library**: Collections select from media library
3. **Settings System**: Feature toggle in Tools & Features
4. **Pro Addon**: Follows Quiz CPT pattern exactly

## Known Limitations

1. ~~**No Tools Yet**: AI assistant tools not yet implemented (Phase 3)~~ ✅ Completed
2. ~~**No Batch Processing**: Collection processing logic not yet implemented~~ ✅ Completed
3. **No History Tracking**: Operation history not yet tracked
4. ~~**Manual Only**: Currently admin-only, no programmatic API~~ ✅ Completed - AI tools now available

## Migration Path

All phases have been completed! ✅

1. ~~**Phase 3**: Create AI tools for template/collection operations~~ ✅ Completed
2. ~~**Phase 4**: Admin UI enhancements and bulk operations~~ ✅ Completed
3. ~~**Phase 5**: Add integration tests, validate all features~~ ✅ Completed
4. ~~**Phase 6**: Complete documentation with examples~~ ✅ Completed

## Documentation

The Media Toolkit includes comprehensive documentation across multiple files:

### Core Documentation
- **media-toolkit.md** (this file) - Feature overview, API reference, technical details
- **media-toolkit-tools-guide.md** - Quick start guide for AI tools with usage examples
- **media-toolkit-tutorials.md** - Step-by-step tutorials and real-world workflows

### Quick Links
- [Getting Started Tutorial](media-toolkit-tutorials.md#getting-started)
- [AI Tools Reference](media-toolkit-tools-guide.md)
- [Social Media Campaign Workflow](media-toolkit-tutorials.md#tutorial-2-social-media-campaign-workflow)
- [E-commerce Pipeline](media-toolkit-tutorials.md#tutorial-3-e-commerce-product-image-pipeline)
- [Troubleshooting Guide](media-toolkit-tutorials.md#troubleshooting-guide)
- [Best Practices](media-toolkit-tutorials.md#best-practices)

## Changelog

### Version 1.3.0 (Phase 6 - Complete Documentation)
- ✅ Comprehensive tutorial guide with 5 detailed tutorials
- ✅ Getting Started section for beginners
- ✅ Social media campaign workflow example
- ✅ E-commerce product pipeline tutorial
- ✅ Batch processing with collections guide
- ✅ AI tool integration examples
- ✅ Advanced workflows section
- ✅ Troubleshooting guide with solutions
- ✅ Best practices for templates and collections
- ✅ Real-world usage examples (News, Real Estate, Marketing, E-learning)
- ✅ Keyboard shortcuts and tips
- ✅ Performance optimization guidelines

### Version 1.2.0 (Phase 4 - Admin UI Enhancements)
- ✅ Bulk actions for templates (duplicate, export)
- ✅ Bulk actions for collections (process, export)
- ✅ Quick Apply template from admin row actions
- ✅ Quick Process collection from admin row actions
- ✅ Enhanced admin CSS with operation badges and visual improvements
- ✅ Template preview with parameter summary
- ✅ One-click template duplication
- ✅ Export templates/collections as JSON
- ✅ AJAX-powered quick operations
- ✅ Admin JavaScript for interactive functionality
- ✅ Success/error message notifications
- ✅ Contextual help and improved notices

### Version 1.1.0 (Phase 3 - AI Tools)
- ✅ AI assistant tools for programmatic access
- ✅ `list_media_templates` - List and filter templates
- ✅ `create_media_template` - Create templates via AI
- ✅ `apply_media_template` - Apply template to single image
- ✅ `process_collection` - Batch process collection items
- ✅ `apply_collection_template` - Assign and process templates
- ✅ Comprehensive unit tests for all tools
- ✅ Template usage statistics tracking
- ✅ Integration with Graphic Editor Plus tool

### Version 1.0.0 (Initial Implementation)
- ✅ Media Template CPT with operation configuration
- ✅ Media Collection CPT with visual media selector
- ✅ Template and collection metaboxes
- ✅ Admin columns and statistics
- ✅ Settings integration
- ✅ Base version checks
- ✅ Admin notices
- ✅ Unit tests for template CPT

## Support

For issues or questions:
- Check settings: Settings → NV oOS → Tools & Features → Features tab
- Enable logging: Settings → NV oOS → Advanced → Enable Logging
- Review documentation: `docs/graphic-editor-plus-tool.md`

## License

Pro feature - Proprietary software
Copyright (c) 2025 NV Digital Solutions
