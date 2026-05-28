# Visualization

## Purpose

Houses 3 architectural visualization tools: walkthrough animation generation (virtual building tours with camera paths and narration), 3D model generation from floor plans, and architectural view rendering (perspectives, elevations, sections) — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry via Architectural Design toolkit bootstrap |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Create_Walkthrough_Animation` | `class-wp-mcp-ai-tool-create-walkthrough-animation.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_3d_Model` | `class-wp-mcp-ai-tool-generate-3d-model.php` | tool registry |
| `WP_MCP_AI_Tool_Render_Architectural_View` | `class-wp-mcp-ai-tool-render-architectural-view.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; 3D model/floor plan data from upstream tools
- **Writes to:** Generated visualization metadata (video URLs, image URLs, model data)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Tool_Video_Response` trait (walkthrough); `WP_MCP_AI_Tool_Image_Response` trait (3D model, architectural view); `WP_MCP_AI_Architectural_Engine` (geometry)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Walkthrough uses `WP_MCP_AI_Tool_Video_Response` trait; 3D model and architectural view use `WP_MCP_AI_Tool_Image_Response`.
- Walkthrough is `background-only`, `long-running`, and `performance-impact` flagged.
- Output formats: walkthrough (mp4/webm/mov at 720p/1080p/4k), 3D model (glb/obj/fbx), architectural view (png/jpg/svg).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/visualization/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
