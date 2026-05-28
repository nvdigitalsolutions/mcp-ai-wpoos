# Documentation

## Purpose

Houses 3 architectural documentation generation tools: export to CAD/BIM formats (PDF, DWG, DXF, IFC, Revit), construction drawing blueprint generation, and detail drawing production — part of the Architectural Design Toolkit.

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
| `WP_MCP_AI_Tool_Export_Architectural_Documents` | `class-wp-mcp-ai-tool-export-architectural-documents.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Construction_Drawings` | `class-wp-mcp-ai-tool-generate-construction-drawings.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Detail_Drawings` | `class-wp-mcp-ai-tool-generate-detail-drawings.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (toolkit enable); floor plan / model data from upstream tools
- **Writes to:** Generated drawing metadata arrays (no persistent storage by default)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Tool_Image_Response` trait (image HTML wrapping); `WP_MCP_AI_Architectural_Interop` (format normalization)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Construction and detail drawings use `WP_MCP_AI_Tool_Image_Response` trait for inline image rendering.
- Export tool supports 6 formats: pdf, dwg, dxf, ifc, revit, sketchup.
- Detail drawings carry `model-dependent` and `consumes-tokens` flags (AI-generated content).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/documentation/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
