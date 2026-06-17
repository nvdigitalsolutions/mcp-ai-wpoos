# Regional Compliance

## Purpose

Houses 7 region-specific structural and planning compliance tools: seismic load calculation (ASCE 7 ELF for LK/JM/US), wind load calculation, JNBC hurricane compliance checking, UDA (Urban Development Authority) planning compliance, US IBC/IRC code checking, compliance dossier generation, and setbacks/FAR validation — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Engine` for seismic/wind load math; `WP_MCP_AI_Architectural_Codes` for code reference tables |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Calculate_Seismic_Loads` | `class-wp-mcp-ai-tool-calculate-seismic-loads.php` | tool registry |
| `WP_MCP_AI_Tool_Calculate_Wind_Loads` | `class-wp-mcp-ai-tool-calculate-wind-loads.php` | tool registry |
| `WP_MCP_AI_Tool_Check_Jnbc_Hurricane_Compliance` | `class-wp-mcp-ai-tool-check-jnbc-hurricane-compliance.php` | tool registry |
| `WP_MCP_AI_Tool_Check_Uda_Planning_Compliance` | `class-wp-mcp-ai-tool-check-uda-planning-compliance.php` | tool registry |
| `WP_MCP_AI_Tool_Check_Us_Ibc_Irc_Compliance` | `class-wp-mcp-ai-tool-check-us-ibc-irc-compliance.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Compliance_Dossier` | `class-wp-mcp-ai-tool-generate-compliance-dossier.php` | tool registry |
| `WP_MCP_AI_Tool_Validate_Setbacks_And_Far` | `class-wp-mcp-ai-tool-validate-setbacks-and-far.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; country/zone parameters; building weight/storey data; `sds_override` for site-specific seismic
- **Writes to:** None (analysis tools); fires `wp_mcp_ai_arch_after_seismic_calculation` and similar actions
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Engine` (seismic base shear, wind pressure, FAR math); `WP_MCP_AI_Architectural_Codes` (IBC/IRC/JNBC/UDA code tables)
- **Events fired:** `wp_mcp_ai_arch_after_seismic_calculation`, `wp_mcp_ai_arch_after_wind_calculation`, `wp_mcp_ai_arch_after_compliance_check`
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Country-aware dispatch: LK → IS 1893 (seismic) + UDA (planning); JM → JNBC/ASCE 7 Caribbean (seismic, hurricane); US → ASCE 7-22 (seismic), ASCE 7 (wind), IBC/IRC (code).
- All structural tools carry `Analytical / advisory output only — engage chartered structural engineer.` disclaimer.
- Zone-specific SDS values are sourced from `WP_MCP_AI_Architectural_Engine::get_seismic_design_parameters()`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/regional-compliance/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
