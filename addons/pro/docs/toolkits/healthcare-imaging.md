# Healthcare Imaging Toolkit

> DICOM-aware medical imaging viewer for WordPress: imaging studies as a CPT, a REST
> controller for radiology workflow, an admin viewer page, audit logging, and metadata
> extraction.

| | |
|---|---|
| **Activation setting** | `enable_healthcare_imaging` |
| **Admin location** | NV oOS → Settings → Pro Features → Healthcare Imaging |
| **Custom Post Type** | 1 (Imaging Study) |
| **REST namespace** | provided by `WP_MCP_AI_Imaging_REST_Controller` |
| **Status** | ⚠️ **PHI-bearing — must only be deployed on infrastructure compliant with HIPAA / your local health-data regulation.** |

---

## What it provides

The Healthcare Imaging Toolkit bootstraps, in order:

1. `WP_MCP_AI_Imaging_Capabilities` — capability helper (no hooks at boot time).
2. `WP_MCP_AI_Imaging_Audit_Log` — append-only log of every read/write action against
   imaging studies.
3. `WP_MCP_AI_DICOM_Metadata` — DICOM tag extraction (modality, study UID, series UID,
   patient identifiers, etc.) used to populate study metadata.
4. `WP_MCP_AI_Imaging_Study_CPT` — registers the `mcp_ai_imaging_study` CPT.
5. `WP_MCP_AI_Imaging_REST_Controller` — REST endpoints for the viewer UI (hooked to
   `rest_api_init`).
6. `WP_MCP_AI_Imaging_Admin_Page` — admin viewer page (hooked to `admin_menu`).

All six are conditionally loaded when `enable_healthcare_imaging` is set.

---

## Custom post type

| CPT slug | Purpose |
|---|---|
| `mcp_ai_imaging_study` | A single imaging study (CT, MRI, X-ray, etc.) with its DICOM metadata, attached series and audit history |

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Healthcare Imaging** under **NV oOS → Settings → Pro Features**.
3. Configure access roles and audit-log retention on the toolkit's settings page.

---

## Compliance & deployment

- **PHI handling.** Imaging studies are Protected Health Information under HIPAA and
  similar laws. Deploy only on:
  - WordPress hosts that sign a Business Associate Agreement (BAA).
  - Encrypted-at-rest storage (database + uploads + backups).
  - TLS in transit.
- **Capabilities.** Use `WP_MCP_AI_Imaging_Capabilities` to map hospital roles
  (radiologist, technologist, referring physician) to WordPress capabilities. Default
  capability is `manage_options`; you almost always want to narrow this.
- **Audit log.** Treat `WP_MCP_AI_Imaging_Audit_Log` as evidence: do not let users with
  delete privileges access its underlying table. Export logs off-site on a schedule.
- **DICOM identifiers.** The metadata extractor preserves Study/Series/SOP UIDs so studies
  can be cross-referenced with PACS systems via DICOMweb.

This toolkit is **not** a replacement for a PACS or a certified medical-device viewer;
it is a workflow and review tool that sits alongside them.

---

## Related docs

- [Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md) — shared engine, codes, FHIR builders, audit ledger, capability map
- [Pro Toolkits index](README.md)
- [Health & Wellness Management](health-wellness.md) — non-clinical health tracking
- [Medical Vitals](medical-vitals.md) — vitals tracking sub-toolkit
- [Password Vault](password-vault.md) — secure storage of PACS / DICOMweb credentials
