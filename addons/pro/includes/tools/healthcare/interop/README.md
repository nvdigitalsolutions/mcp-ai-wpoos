# Interoperability

## Purpose

Houses 4 Phase E healthcare interoperability tools: FHIR R4 Bundle import, HL7 C-CDA document export, HL7v2 message parsing, and EHR connection management — the cross-cutting exchange layer for all healthcare sub-toolkits.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Unified healthcare bootstrap via Pro tool registry |
| **Optional dependencies** | `enable_health_wellness_management` (import_fhir_bundle); others require relevant healthcare toggles |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Import_FHIR_Bundle` | `class-wp-mcp-ai-tool-import-fhir-bundle.php` | tool registry |
| `WP_MCP_AI_Tool_Export_CCDA_Document` | `class-wp-mcp-ai-tool-export-ccda-document.php` | tool registry |
| `WP_MCP_AI_Tool_Import_HL7v2_Message` | `class-wp-mcp-ai-tool-import-hl7v2-message.php` | tool registry |
| `WP_MCP_AI_Tool_Connect_To_EHR` | `class-wp-mcp-ai-tool-connect-to-ehr.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`, `wp_mcp_ai_ehr_connections` option, `wp_mcp_ai_healthcare_settings`
- **Writes to:** `mcp_ai_member`, `mcp_ai_allergy`, `mcp_ai_med_record`, `mcp_ai_prescription`, `mcp_ai_vaccination_record` CPTs; audit ledger
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Healthcare_FHIR`, `WP_MCP_AI_Healthcare_Audit`, `WP_MCP_AI_Healthcare_Engine`
- **Events fired:** `wp_mcp_ai_healthcare_after_phi_access`
- **Events listened to:** `wp_mcp_ai_healthcare_fhir_resource_handlers`, `wp_mcp_ai_healthcare_ccda_document`, `wp_mcp_ai_healthcare_hl7v2_segments`, `wp_mcp_ai_healthcare_ehr_credentials`

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- FHIR R4 (`4.0.1`) bundles are the canonical interchange format; HL7v2 (`ADT^A04`, `ADT^A08`, `ORU^R01`) and C-CDA R2.1 are secondary.
- Resource handlers are pluggable via `wp_mcp_ai_healthcare_fhir_resource_handlers` filter.
- Every PHI access is audited through `WP_MCP_AI_Healthcare_Audit`.
- SMART-on-FHIR `client_credentials` connections are stored with redacted secrets.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/healthcare/interop/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Healthcare toolkit
