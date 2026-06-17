# Regulatory Registration

## Purpose

Houses 56 regulatory registration tools for managing product registrations across countries and authorities. Covers the full lifecycle: product and registration CRUD, document management, compliance checking, submission packages, Excel import/export, workflow rules, expiry monitoring, notifications, authority sync (MOHAP, NMRA), and validation (document checklists, INCI ingredients, HS codes).

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry |
| **Optional dependencies** | `enable_regulatory_registration_toolkit` setting must be enabled |

## Public Surface

| Category | Count | Representative symbols |
|---|---|---|
| Product CRUD | 6 tools | `WP_MCP_AI_Tool_Create_Reg_Product`, `WP_MCP_AI_Tool_Update_Reg_Product`, `WP_MCP_AI_Tool_Delete_Reg_Product`, `WP_MCP_AI_Tool_Duplicate_Reg_Product`, `WP_MCP_AI_Tool_List_Reg_Products`, `WP_MCP_AI_Tool_Search_Reg_Products` |
| Registration CRUD | 8 tools | `WP_MCP_AI_Tool_Create_Registration`, `WP_MCP_AI_Tool_Get_Registration`, `WP_MCP_AI_Tool_List_Registrations`, `WP_MCP_AI_Tool_Update_Registration_Status`, `WP_MCP_AI_Tool_Approve_Registration`, `WP_MCP_AI_Tool_Submit_Registration`, `WP_MCP_AI_Tool_Renew_Registration`, `WP_MCP_AI_Tool_List_Registrations_By_Country` |
| Document Management | 4 tools | `WP_MCP_AI_Tool_Upload_Reg_Document`, `WP_MCP_AI_Tool_Get_Reg_Document`, `WP_MCP_AI_Tool_Update_Reg_Document`, `WP_MCP_AI_Tool_List_Reg_Documents` |
| Compliance & Validation | 8 tools | `WP_MCP_AI_Tool_Check_Product_Compliance`, `WP_MCP_AI_Tool_Check_HS_Code`, `WP_MCP_AI_Tool_Validate_Reg_Product`, `WP_MCP_AI_Tool_Validate_Document_Checklist`, `WP_MCP_AI_Tool_Validate_Inci_Ingredients`, `WP_MCP_AI_Tool_Validate_Excel_Import`, `WP_MCP_AI_Tool_Track_Document_Version`, `WP_MCP_AI_Tool_Check_Authority_Status` |
| Expiry & Monitoring | 4 tools | `WP_MCP_AI_Tool_Check_Document_Expiry`, `WP_MCP_AI_Tool_List_Expiring_Registrations`, `WP_MCP_AI_Tool_Send_Expiry_Alerts`, `WP_MCP_AI_Tool_Generate_Expiry_Forecast` |
| Reports & Generation | 8 tools | `WP_MCP_AI_Tool_Generate_Compliance_Certificate`, `WP_MCP_AI_Tool_Generate_Compliance_Report`, `WP_MCP_AI_Tool_Generate_Cost_Analysis`, `WP_MCP_AI_Tool_Generate_Country_Performance`, `WP_MCP_AI_Tool_Generate_Cover_Letter`, `WP_MCP_AI_Tool_Generate_PDF_Dossier`, `WP_MCP_AI_Tool_Generate_Pipeline_Report`, `WP_MCP_AI_Tool_Generate_Submission_Pack` |
| Import/Export | 4 tools | `WP_MCP_AI_Tool_Import_Products_From_Excel`, `WP_MCP_AI_Tool_Import_Registrations_From_Excel`, `WP_MCP_AI_Tool_Export_Products_To_Excel`, `WP_MCP_AI_Tool_Export_Registrations_To_Excel` |
| Workflow Rules | 6 tools | `WP_MCP_AI_Tool_Create_Workflow_Rule`, `WP_MCP_AI_Tool_Update_Workflow_Rule`, `WP_MCP_AI_Tool_Delete_Workflow_Rule`, `WP_MCP_AI_Tool_List_Workflow_Rules`, `WP_MCP_AI_Tool_Test_Workflow_Rule`, `WP_MCP_AI_Tool_Get_Workflow_Execution_Log` |
| Authority Sync | 4 tools | `WP_MCP_AI_Tool_Sync_With_MOHAP`, `WP_MCP_AI_Tool_Sync_With_NMRA`, `WP_MCP_AI_Tool_Submit_To_Authority`, `WP_MCP_AI_Tool_Get_Regulatory_Updates` |
| Notifications | 4 tools | `WP_MCP_AI_Tool_Configure_Email_Notifications`, `WP_MCP_AI_Tool_Send_Status_Change_Notification`, `WP_MCP_AI_Tool_Get_Notification_History`, `WP_MCP_AI_Tool_Get_Registration_Timeline` |
| Requirements | 2 tools | `WP_MCP_AI_Tool_Add_Regulatory_Requirement`, `WP_MCP_AI_Tool_Get_Regulatory_Requirements` |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_regulatory_registration_toolkit`), `mcp_ai_reg_product` CPT, `mcp_ai_registration` CPT, `mcp_ai_reg_status` taxonomy
- **Writes to:** `mcp_ai_reg_product` CPT, `mcp_ai_registration` CPT, attachment posts for documents
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** PhpSpreadsheet (Excel I/O), `wp_mcp_ai_log_activity()` (audit trail)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Products and registrations use separate CPTs: `mcp_ai_reg_product` and `mcp_ai_registration`.
- Registration status taxonomy: `mcp_ai_reg_status` (draft, pending_documents, ready_for_submission, submitted, under_review, approved, rejected, on_hold, renewal_due).
- Authority sync tools (MOHAP, NMRA) support country-specific regulatory endpoints.
- `WP_MCP_AI_Tool_Create_Registration` implements `WP_MCP_AI_Tool_Context_Restrictions_Interface` and uses `WP_MCP_AI_Tool_Restrict_From_Chat_Client` trait.
- Excel import/export use PhpSpreadsheet from `addons/pro/vendor/`.
- Activity is logged via `wp_mcp_ai_log_activity()` for state-changing operations.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/regulatory-registration/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Pro tools index
