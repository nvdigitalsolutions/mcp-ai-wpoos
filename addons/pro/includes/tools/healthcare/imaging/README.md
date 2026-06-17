# Imaging

## Purpose

Houses 7 Phase D medical imaging tools: DICOMweb connection management, study import/export, radiology report attachment, study comparison, and hanging protocol retrieval — backed by the shared `WP_MCP_AI_DICOMweb_Client`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/tools/healthcare/imaging-init.php` (forwarded from unified bootstrap) |
| **Optional dependencies** | `enable_healthcare_imaging` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_DICOMweb_Client` | `class-wp-mcp-ai-dicomweb-client.php` | Phase D tools, audit |
| `WP_MCP_AI_Tool_Connect_DICOMweb` | `class-wp-mcp-ai-tool-connect-dicomweb.php` | tool registry |
| `WP_MCP_AI_Tool_Import_DICOM_Study` | `class-wp-mcp-ai-tool-import-dicom-study.php` | tool registry |
| `WP_MCP_AI_Tool_Export_DICOM_Study` | `class-wp-mcp-ai-tool-export-dicom-study.php` | tool registry |
| `WP_MCP_AI_Tool_Attach_Radiology_Report` | `class-wp-mcp-ai-tool-attach-radiology-report.php` | tool registry |
| `WP_MCP_AI_Tool_Compare_Imaging_Studies` | `class-wp-mcp-ai-tool-compare-imaging-studies.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol` | `class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_healthcare_imaging`), `wp_mcp_ai_dicomweb_connection` option
- **Writes to:** `mcp_ai_imaging_study` CPT, `mcp_ai_radiology_report` CPT, audit ledger
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Healthcare_Audit`, `WP_MCP_AI_Healthcare_Engine`
- **Events fired:** `wp_mcp_ai_healthcare_after_dicom_import`, `wp_mcp_ai_healthcare_after_imaging_export`
- **Events listened to:** `wp_mcp_ai_healthcare_dicomweb_connection`, `wp_mcp_ai_healthcare_dicomweb_request_args`, `wp_mcp_ai_healthcare_hanging_protocols`

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- DICOMweb client implements PS3.18 (QIDO-RS, WADO-RS, STOW-RS).
- De-identification runs through `wp_mcp_ai_healthcare_before_imaging_export` filter.
- Connection secrets are redacted on read; stored under `wp_mcp_ai_dicomweb_connection` option.
- `is_available()` returns false when `enable_healthcare_imaging` is off.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/healthcare/imaging/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Healthcare toolkit
