# Vitals

## Purpose

Houses 6 files for the Medical Vitals sub-toolkit: vital trend analysis, BMI/growth percentile computation, abnormal vitals flagging, vaccination schedule retrieval, plus supporting CPT and vaccination-schedule infrastructure — all integrated with the unified healthcare engine.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Unified healthcare bootstrap via Pro tool registry |
| **Optional dependencies** | `enable_medical_vitals` (defaults to `enable_health_wellness_management`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Analyze_Vital_Trends` | `class-wp-mcp-ai-tool-analyze-vital-trends.php` | tool registry |
| `WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile` | `class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php` | tool registry |
| `WP_MCP_AI_Tool_Flag_Abnormal_Vitals` | `class-wp-mcp-ai-tool-flag-abnormal-vitals.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Vaccination_Schedule` | `class-wp-mcp-ai-tool-get-vaccination-schedule.php` | tool registry |
| `WP_MCP_AI_Healthcare_Vaccination_Schedules` | `class-wp-mcp-ai-healthcare-vaccination-schedules.php` | get_vaccination_schedule |
| `WP_MCP_AI_Healthcare_Vital_Log_CPT` | `class-wp-mcp-ai-healthcare-vital-log-cpt.php` | log_vital_signs |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_medical_vitals`), `wp_mcp_ai_healthcare_settings` (unit system, reference ranges), `mcp_ai_hc_vital_log` CPT
- **Writes to:** `mcp_ai_hc_vital_log` CPT (auxiliary); audit ledger
- **Upstream callers:** Pro tool registry, orchestrator, `WP_MCP_AI_Tool_Log_Vital_Signs` delegate
- **Downstream collaborators:** `WP_MCP_AI_Healthcare_Engine`, `WP_MCP_AI_Healthcare_Audit`, `WP_MCP_AI_Tool_Log_Vital_Signs`
- **Events fired:** `wp_mcp_ai_healthcare_before_vital_log`, `wp_mcp_ai_healthcare_after_vital_log`
- **Events listened to:** `wp_mcp_ai_healthcare_reference_ranges`

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- `is_available()` delegates to `WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' )`.
- Vaccination schedules ship with CDC paediatric/adult, WHO EPI, AAFP feline, and AAHA canine packs.
- Trend analysis delegates to `WP_MCP_AI_Tool_Log_Vital_Signs::execute()` with `analyze_trends` action.
- Unit system (metric/imperial) and reference ranges are driven from `wp_mcp_ai_healthcare_settings`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/healthcare/vitals/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Healthcare toolkit
