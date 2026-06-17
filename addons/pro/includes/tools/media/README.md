# Media Toolkit

> Media collection, template, and processing tools.

## Purpose

Tools for creating and applying media collections and templates, processing image/video collections with predefined layouts and transformations.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Apply Collection Template | `apply_collection_template` | Apply a template to an existing media collection |
| Apply Media Template | `apply_media_template` | Apply transformations from a media template |
| Cleanup Orphaned Media | `cleanup_orphaned_media` | Remove orphaned media files and broken attachment records |
| Create Media Collection | `create_media_collection` | Create a new grouped media collection |
| Create Media Template | `create_media_template` | Define a reusable media transformation template |
| List Media Templates | `list_media_templates` | List available media templates |
| Process Collection | `process_collection` | Execute batch processing on a collection |
| Scan Orphaned Media | `scan_orphaned_media` | Scan for orphaned and unreferenced media files |

## Dependencies

- WordPress 6.0+
- FFmpeg (for video processing within collections)

## Registration

Loaded by `media-toolkit-init.php` in `addons/pro/includes/`. Gated on `enable_media_toolkit` setting.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Media CPT classes: `addons/pro/includes/class-wp-mcp-ai-media-*-cpt.php`](../../)
