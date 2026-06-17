# Floor Planning & Space Design

## Purpose

Houses 4 architectural floor planning tools: sketch-to-floor-plan conversion (computer vision), floor plan generation, floor plan variations, and space layout optimization — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Tools registered via the Pro tool registry; `WP_MCP_AI_Architectural_Engine` for shared math |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting must be enabled; vision model required for sketch conversion |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan` | `class-wp-mcp-ai-tool-convert-sketch-to-floor-plan.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_Create_Floor_Plan_Variations` | `class-wp-mcp-ai-tool-create-floor-plan-variations.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Floor_Plan` | `class-wp-mcp-ai-tool-generate-floor-plan.php` | tool registry |
| `WP_MCP_AI_Tool_Optimize_Space_Layout` | `class-wp-mcp-ai-tool-optimize-space-layout.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_architectural_design_toolkit` check); `wp_mcp_ai_arch_design_settings`
- **Writes to:** None (generative/analysis tools)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Engine` (units, FAR, occupancy); `WP_MCP_AI_Architectural_Interop` (floor plan normalization)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Sketch conversion requires a vision-capable model (`requires-vision-model` flag) and uses `WP_MCP_AI_Tool_Image_Response` trait.
- Outputs include SVG, DXF, or JSON format options.
- All outputs carry `Analytical / advisory output only.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/floor-planning/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
