# Places Management Toolkit

> Location/place CRUD with search and geospatial capabilities.

## Purpose

Tools for creating, searching, and managing place/location records with geospatial data support.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Create Place | `create_place` | Register a new place/location record |
| Delete Place | `delete_place` | Remove a place record |
| Get Place | `get_place` | Fetch a place by ID |
| List Places | `list_places` | List all places with filtering |
| Research Place | `research_place` | AI-assisted place research |
| Search and Save Places | `search_and_save_places` | Search external APIs and persist results |
| Update Place | `update_place` | Modify an existing place |

## Dependencies

- WordPress 6.0+

## Registration

Loaded by `places-management-init.php` in `addons/pro/includes/`. Gated on `enable_places_management` setting.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Place CPT: `addons/pro/includes/class-wp-mcp-ai-place-cpt.php`](../../class-wp-mcp-ai-place-cpt.php)
