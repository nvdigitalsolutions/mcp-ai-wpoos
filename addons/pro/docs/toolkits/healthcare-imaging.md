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

## Phase D — DICOMweb depth (added in 1.4.0)

Phase D extends the imaging sub-toolkit with first-class DICOMweb (PS3.18) connectivity, study lifecycle, comparison, structured reporting and per-modality hanging-protocols.  All six tools register under the existing `enable_healthcare_imaging` toggle and reuse the existing audit ledger.

### `WP_MCP_AI_DICOMweb_Client`

Lightweight HTTP client for QIDO-RS / WADO-RS / STOW-RS.  Stores its connection in the `wp_mcp_ai_dicomweb_connection` option and exposes the filter `wp_mcp_ai_healthcare_dicomweb_request_args` for partner code that needs to swap the transport (e.g. multipart/related for pixel-data uploads) or pin TLS roots.

### Tools

| Tool | Purpose |
|---|---|
| `connect_dicomweb` | Configure (`base_url`, `auth_type`, credentials, timeout), test (QIDO `/studies?limit=1` ping), get redacted config, or disconnect. |
| `import_dicom_study` | QIDO-confirm a `study_uid`, then mirror its WADO-RS metadata (modality, date, description, patient_id, series + instance counts) into `mcp_ai_imaging_study`.  Pixel data is **not** downloaded.  Honours an `overwrite` flag for refreshes. |
| `export_dicom_study` | Build a DICOM JSON instance list from a stored study and STOW-RS it to the configured endpoint.  Runs through `wp_mcp_ai_healthcare_before_imaging_export` so a de-identifier can scrub PHI before transmission. |
| `attach_radiology_report` | Auto-registers a `mcp_ai_radiology_report` CPT, stores findings + impression as a child post of the study, and (optionally) emits a Basic Text DICOM SR (SOP Class `1.2.840.10008.5.1.4.1.1.88.11`) JSON document into post-meta. |
| `compare_imaging_studies` | Diff prior vs current studies on modality, dates, series count, instance count, description, and the first stored impression.  Returns `days_between` when both studies have parsable DA-format dates. |
| `get_imaging_hanging_protocol` | Returns a default viewer hanging-protocol for CT, MR, CR/DX, US, MG, NM, PT or SR (or for the modality of a stored study).  Filterable via `wp_mcp_ai_healthcare_hanging_protocols`. |

### Hooks added in Phase D

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_healthcare_dicomweb_connection` | filter | Final-pass filter on the resolved DICOMweb connection. |
| `wp_mcp_ai_healthcare_dicomweb_request_args` | filter | Mutate `wp_remote_*` args (headers, timeout, body) before each DICOMweb call. |
| `wp_mcp_ai_healthcare_before_imaging_export` | filter | Mutate the DICOM JSON instance payload before STOW-RS — primary de-identification hook. |
| `wp_mcp_ai_healthcare_after_dicom_import` | action | Fires after `import_dicom_study` writes the local study. |
| `wp_mcp_ai_healthcare_after_imaging_export` | action | Fires after a successful STOW-RS export. |
| `wp_mcp_ai_healthcare_hanging_protocols` | filter | Override / extend the per-modality default viewer layouts. |

### Compliance notes

* The DICOMweb client only persists what the operator explicitly saves; bearer tokens and basic-auth passwords are stored in the `wp_mcp_ai_dicomweb_connection` option (use the credentials vault filter to swap to encrypted storage).
* `connect_dicomweb` and `export_dicom_study` require `manage_options`; `import_dicom_study` and `attach_radiology_report` require `edit_others_posts`; `compare_imaging_studies` and `get_imaging_hanging_protocol` are read-only.
* Both import and export pipelines call `WP_MCP_AI_Healthcare_Audit::record()` so every PACS interaction is logged.

---

## Related docs

- [Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md) — shared engine, codes, FHIR builders, audit ledger, capability map
- [Pro Toolkits index](README.md)
- [Health & Wellness Management](health-wellness.md) — non-clinical health tracking
- [Medical Vitals](medical-vitals.md) — vitals tracking sub-toolkit
- [Password Vault](password-vault.md) — secure storage of PACS / DICOMweb credentials
