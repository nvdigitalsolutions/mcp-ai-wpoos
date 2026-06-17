# Media Command Center

**Status:** Stable — v1.1.31  
**Category:** Pro Feature — Admin UI  
**Capability Required:** `manage_options`

## Overview

The Media Command Center provides a unified admin interface for managing NV oOS media workflows. It is accessible via a top-level **NV Media** menu in the WordPress admin sidebar.

## Access

`wp-admin/admin.php?page=nv-media`

## Tabs

| Tab | Purpose |
|-----|---------|
| **Templates** | Browse and manage media generation templates |
| **Presets** | Configure and apply scheduler presets for automated media workflows |
| **Blueprints** | View and manage media workflow blueprints |
| **Scheduler** | Schedule recurring media generation and processing tasks |

## Related Tools

The command center surfaces tools from the Media Toolkit, including:

- `generate_openai_image` — OpenAI DALL·E / GPT Image generation
- `generate_gemini_image` — Gemini multimodal image generation (default: `gemini-3.1-flash-image`)
- `generate_cloudflareai_image` — Cloudflare Workers AI image generation
- `vectorize_image` — Raster-to-SVG vectorization
- `graphic_editor_plus` — Local + AI-powered image editing

## Related Documentation

- [Media Toolkit MCP Servers](toolkit-mcp-servers.md)
- [Toolkit SPA Blueprint](../addons/toolkit-spa-blueprint.md)
- [Blueprints System](unified-blueprint-system.md)

## Version History

- **v1.1.31** — Initial release. Top-level admin menu, templates tab, preset scheduling, PHPCS cleanup, `implode()` WP_Error crash fix.
