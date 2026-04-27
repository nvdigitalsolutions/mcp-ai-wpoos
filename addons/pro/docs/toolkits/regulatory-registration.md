# Regulatory Registration Toolkit

> Multi-country regulatory product-registration workflow: products, registrations,
> documents, country authorities, and requirements. Built for cosmetics, medical-device,
> pharma and food regulators such as **Sri Lanka NMRA**, **UAE MOHAP**, **Saudi SFDA**
> and similar agencies.

| | |
|---|---|
| **Activation setting** | `enable_regulatory_registration_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Regulatory Registration |
| **Tools** | **59** |
| **Custom Post Type** | 1 (Regulatory Registration) |
| **Available since** | Pro v1.x |

---

## What it provides

The toolkit registers a single CPT — `mcp_ai_reg_registration` — and a large suite of
tools that automate the full regulatory lifecycle:

1. **Product master data.** Create / update / duplicate / search regulated products,
   validate INCI ingredient lists, validate Excel imports, manage product documents.
2. **Registration workflow.** Create, submit, approve, renew, and update registration
   status; track timeline events; integrate with country e-portals (`sync_with_mohap`,
   `sync_with_nmra`).
3. **Document management.** Upload, version, validate against country-specific checklists,
   monitor expiry, generate PDF dossiers, submission packs and cover letters.
4. **Compliance.** Check HS codes, check product / authority status, generate compliance
   certificates and reports.
5. **Notifications & workflow rules.** Configurable expiry alerts, status-change
   notifications, and custom workflow rules with execution logging.
6. **Reporting.** Country performance, pipeline, expiry forecast, cost analysis, and
   Excel export of products and registrations.

Tools live in `addons/pro/includes/tools/regulatory-registration/` (59 PHP files; one
class per tool).

---

## Custom post type

| CPT slug | Purpose |
|---|---|
| `mcp_ai_reg_registration` | A single regulatory registration record (product × country × authority) |

CPT class: `addons/pro/includes/class-wp-mcp-ai-regulatory-registration-cpt.php`. A
migration helper (`class-wp-mcp-ai-migrate-requirement-post-type.php`) handles upgrades
from the legacy "requirement" post type.

---

## Tool families (selected)

| Family | Example slugs |
|---|---|
| Products | `create_reg_product`, `update_reg_product`, `duplicate_reg_product`, `search_reg_products`, `validate_reg_product`, `validate_inci_ingredients` |
| Registrations | `create_registration`, `submit_registration`, `approve_registration`, `renew_registration`, `update_registration_status`, `list_registrations`, `list_registrations_by_country`, `get_registration_timeline` |
| Documents | `upload_reg_document`, `update_reg_document`, `track_document_version`, `validate_document_checklist`, `check_document_expiry`, `list_reg_documents` |
| Country e-portals | `sync_with_mohap` (UAE), `sync_with_nmra` (Sri Lanka), `submit_to_authority`, `check_authority_status` |
| Compliance | `check_product_compliance`, `check_hs_code`, `generate_compliance_certificate`, `generate_compliance_report` |
| Notifications & rules | `configure_email_notifications`, `send_expiry_alerts`, `send_status_change_notification`, `create_workflow_rule`, `update_workflow_rule`, `delete_workflow_rule`, `list_workflow_rules`, `test_workflow_rule`, `get_workflow_execution_log` |
| Reporting & export | `generate_country_performance`, `generate_pipeline_report`, `generate_expiry_forecast`, `generate_cost_analysis`, `generate_pdf_dossier`, `generate_submission_pack`, `generate_cover_letter`, `export_products_to_excel`, `export_registrations_to_excel`, `import_products_from_excel`, `import_registrations_from_excel`, `validate_excel_import` |

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Regulatory Registration Toolkit** under **NV oOS → Settings → Pro Features**.
3. Configure country authorities, document checklists, and notification settings on the
   toolkit's settings page.

---

## Notes

- **Authority integrations.** `sync_with_*` tools wrap the published e-portal APIs of the
  named authorities. Authentication credentials should live in the
  [Password Vault](password-vault.md), not in source.
- **Migrations.** When upgrading from older Pro versions, the requirement-post-type
  migration runs once on activation; check the WP-CLI command `wp option get
  wp_mcp_ai_recent_activity` for migration logs.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/README.md`](../../README.md) — Pro overview
