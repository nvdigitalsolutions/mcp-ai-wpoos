# Interoperability

## Purpose

Houses 4 BIM/CAD interoperability tools: gbXML export (for EnergyPlus/OpenStudio energy modelling), IFC export (BIM data exchange), DWG floor plan import, and IFC model import — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Interop` for format normalization and XML/IFC builders |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Export_To_Gbxml` | `class-wp-mcp-ai-tool-export-to-gbxml.php` | tool registry |
| `WP_MCP_AI_Tool_Export_To_Ifc` | `class-wp-mcp-ai-tool-export-to-ifc.php` | tool registry |
| `WP_MCP_AI_Tool_Import_Dwg_Floor_Plan` | `class-wp-mcp-ai-tool-import-dwg-floor-plan.php` | tool registry |
| `WP_MCP_AI_Tool_Import_Ifc_Model` | `class-wp-mcp-ai-tool-import-ifc-model.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; normalized floor plan data for export; uploaded DWG/IFC files for import
- **Writes to:** XML (gbXML 6.01), IFC (STEP/XML), or JSON-normalized floor plan data
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Interop` (normalization, gbXML builder, BEP catalog); `WP_MCP_AI_Architectural_Engine` (unit math)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Export tools are `read-only` and `cacheable`; import tools may require file upload capability.
- gbXML export produces geometry summary only (add surfaces/constructions in EnergyPlus/OpenStudio).
- IFC export targets IFC 4.3 with standard building element types.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/interoperability/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
