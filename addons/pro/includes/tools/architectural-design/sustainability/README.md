# Sustainability

## Purpose

Houses 3 green-building certification and comfort-analysis tools: EDGE certification scoring (IFC methodology), LEED v4 certification scoring, and thermal comfort simulation — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Sustainability` for certification scoring engines and baseline data |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Score_Edge_Certification` | `class-wp-mcp-ai-tool-score-edge-certification.php` | tool registry |
| `WP_MCP_AI_Tool_Score_Leed_V4_Certification` | `class-wp-mcp-ai-tool-score-leed-v4-certification.php` | tool registry |
| `WP_MCP_AI_Tool_Simulate_Thermal_Comfort` | `class-wp-mcp-ai-tool-simulate-thermal-comfort.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; country code, building use, EUI/water/embodied-CO₂ values or direct savings percentages
- **Writes to:** None (scoring/analysis tools); fires `wp_mcp_ai_arch_edge_scored`
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Sustainability` (EDGE scoring, LEED checklist, thermal models, BoQ format catalog)
- **Events fired:** `wp_mcp_ai_arch_edge_scored`
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- All tools are `read-only` and `cacheable`.
- EDGE tool accepts either absolute proposed values (EUI, water, embodied CO₂) or direct savings percentages; computes Certified / Advanced / Zero Carbon tier.
- LEED tool follows v4 credit category structure with prerequisite checks.
- All outputs carry `Indicative scoring only — final certification requires accredited auditor.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/sustainability/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
