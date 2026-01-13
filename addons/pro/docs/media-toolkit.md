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

## Future Enhancements (Not Yet Implemented)

### Phase 3: Pro Tools
- `apply_media_template`: Apply template to single image via AI
- `list_media_templates`: List available templates
- `create_media_template`: Create template via AI
- `process_collection`: Batch process collection items
- `apply_collection_template`: Apply templates to entire collection

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

**Test File**: `addons/pro/tests/test-media-template-cpt.php`

**Tests Cover**:
- Admin notices (enabled/disabled states)
- CPT registration
- Template meta save/retrieve
- Admin columns

## Integration Points

1. **Graphic Editor Plus Tool**: Templates provide reusable configurations
2. **WordPress Media Library**: Collections select from media library
3. **Settings System**: Feature toggle in Tools & Features
4. **Pro Addon**: Follows Quiz CPT pattern exactly

## Known Limitations

1. **No Tools Yet**: AI assistant tools not yet implemented (Phase 3)
2. **No Batch Processing**: Collection processing logic not yet implemented
3. **No History Tracking**: Operation history not yet tracked
4. **Manual Only**: Currently admin-only, no programmatic API

## Migration Path

If implementing the remaining phases:

1. **Phase 3**: Create AI tools for template/collection operations
2. **Phase 5**: Add integration tests, validate all features
3. **Phase 6**: Complete documentation with examples

## Changelog

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
