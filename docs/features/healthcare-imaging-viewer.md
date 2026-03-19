# Healthcare DICOM Imaging Viewer

**Feature:** Medical Imaging Viewer (Pro Add-on)  
**Admin Page:** Health & Wellness → Imaging Viewer  
**URL slug:** `healthcare-imaging-viewer`  
**Required capability:** `view_medical_imaging`  
**Last Updated:** March 2026

---

## Overview

The Healthcare DICOM Imaging Viewer is a full-featured, HIPAA-aware medical imaging study manager built into the WordPress admin panel. It enables clinical staff to:

- Upload and store DICOM (`.dcm`) study files directly on the server.
- Browse, search, and filter all uploaded studies in a structured table.
- View individual studies in an embedded Cornerstone3D viewer with interactive W/L controls.
- Run AI-powered clinical interpretation on any study.
- Review a tamper-evident audit log of every access, upload, delete, and AI event.
- Reference keyboard shortcuts, W/L clinical presets, modality codes, and the REST API — all inside the Documentation tab.

All DICOM files are stored in a protected, HTTP-inaccessible directory and served exclusively through short-lived signed REST API tokens. No PHI is stored in the WordPress database or printed into the HTML source.

---

## Quick Start

1. Go to **Health & Wellness → Imaging Viewer** in the WordPress admin.
2. Click **Upload Study** and select one or more `.dcm` DICOM files.
3. The study appears in the **Studies** table. Click **View** to open the Cornerstone3D viewer.
4. In the viewer, select a series in the left sidebar and use the toolbar to adjust brightness/contrast.
5. Switch to the **AI Tools** tab and click **Run AI Analysis** to get an instant AI interpretation.
6. Use the **Documentation** tab as an in-app reference (keyboard shortcuts, W/L presets, REST API).

---

## Admin Page Tabs

### Studies Tab

The default tab. Displays all uploaded DICOM studies in a paginated table.

| Column | Description |
|--------|-------------|
| Study UID | DICOM `StudyInstanceUID` |
| Modality | DICOM modality code (CT, MR, PT, etc.) |
| Study Date | `StudyDate` tag in `YYYYMMDD` format |
| Series | Number of series in the study |
| Instances | Total slice/frame count |
| Actions | View · Delete |

#### Search & Filter Bar

| Control | Description |
|---------|-------------|
| Search box | Filter by `StudyInstanceUID` (partial match) |
| Modality | Drop-down for CT, MR, PT, US, DX, CR, MG, NM, RF, XA |
| From / To | Date range filter (ISO date picker) |
| Filter button | Apply all active filters |
| Clear button | Reset all filters and reload |

Pressing **Enter** in the search box triggers the filter.

### AI Tools Tab

#### AI Study Interpretation

1. Enter (or auto-filled from the open study) the **Study UID**.
2. Choose an **Analysis Focus**:
   - **Full Analysis** — comprehensive clinical review of all metadata.
   - **Image Quality** — assesses acquisition parameters and technical quality.
   - **Study Completeness** — checks for missing series, required sequences, etc.
   - **Workflow & Next Steps** — suggests clinical follow-up actions.
3. Click **Run AI Analysis**.

The result is displayed as formatted paragraphs with a green success box (or red error box if the AI provider is not configured). Every interpretation is logged in the Audit Log.

> **Note:** AI analysis requires an OpenAI or Gemini API key configured in **Settings → NV oOS**. When no AI credentials are present the endpoint returns a `503` with an actionable message.

#### Available AI Tools Reference

| Tool slug | Description |
|-----------|-------------|
| `interpret_imaging_study` | Analyses DICOM metadata via AI. Supports `focus`: `quality` / `completeness` / `workflow` / `full`. Optional `include_pixel_preview=true` sends a 512 px PNG of the first frame to a vision model. |
| `manage_imaging_studies` | Lists studies, retrieves details, summarises findings, reads the audit log. Usable from any NV oOS AI assistant. |

### Audit Log Tab

Lazy-loaded table of the most recent 100 audit events. Loaded once per page load.

| Column | Description |
|--------|-------------|
| Time | ISO 8601 timestamp of the event |
| Action | Event type (see table below) |
| Study | `StudyInstanceUID` of the affected study |
| User | WordPress user ID who triggered the event |

**Audit event types:**

| Event | Trigger |
|-------|---------|
| `study_uploaded` | New study created via Upload |
| `study_viewed` | Study opened in the Cornerstone3D viewer |
| `study_interpreted` | AI interpretation run via Tools tab or REST API |
| `study_delete_file_failed` | A `.dcm` file could not be deleted from disk |
| `study_delete_dir_failed` | The study directory could not be removed from disk |
| `instance_file_accessed` | A DICOM instance file was streamed to the browser |
| `instance_file_access_denied` | Invalid or expired access token on file stream |
| `instance_file_path_traversal_attempt` | File path was outside the storage root |
| `audit_log_viewed` | Audit log endpoint was queried |

### Documentation Tab

Built-in reference panels (no external links required):

- **Quick Start** — 5-step guide to uploading and viewing a study.
- **Keyboard Shortcuts** — all viewer keyboard actions.
- **W/L Clinical Presets** — the 11 industry-standard window/level presets.
- **DICOM Modality Abbreviations** — 12 standard codes.
- **REST API Reference** — all 9 endpoints with method, path, required capability, and description.
- **Privacy & HIPAA Notes** — storage protection, signed tokens, audit logging, AI provider BAA guidance.

---

## Cornerstone3D Viewer

The viewer is bootstrapped with [Cornerstone3D](https://github.com/cornerstonejs/cornerstone3D) loaded from the `esm.sh` CDN via ES module import. No local bundle compilation is required.

### Viewer Layout

```
┌─────────────────────────────────────────────────────┐
│  ← Back   Study UID label   [W/L toolbar] [Tools]   │
├────────────────────┬────────────────────────────────┤
│  Series sidebar    │  Cornerstone3D canvas          │
│  ──────────        │                                │
│  Series 1          │  (3 / 42)  ← instance counter │
│  Series 2          │                                │
│  ──────────        │                                │
│  Metadata DL       │                                │
└────────────────────┴────────────────────────────────┘
```

### Toolbar Controls

#### W/L (Window / Level) Toolbar

| Button | Action |
|--------|--------|
| **Reset** | Restore default W/L and camera position |
| **Invert** | Toggle image inversion |
| Modality preset buttons | Apply a clinical W/L preset (see table below) |

**CT Presets:**

| Preset | WW | WL |
|--------|----|----|
| Soft Tissue | 350 | 40 |
| Lung | 1500 | −600 |
| Brain | 80 | 40 |
| Bone | 2000 | 400 |
| Abdomen | 400 | 50 |
| Liver | 150 | 80 |
| Mediastinum | 350 | 50 |

**MR Presets:**

| Preset | WW | WL |
|--------|----|----|
| Brain | 1000 | 500 |
| Spine | 1200 | 600 |
| Soft Tissue | 500 | 250 |

**PET Preset:**

| Preset | WW | WL |
|--------|----|----|
| SUV Max | 5 | 2.5 |

#### Extra Tool Buttons

| Button | Action |
|--------|--------|
| ⇔ Flip H | Flip image horizontally |
| ⇕ Flip V | Flip image vertically |
| ↻ Rotate CW | Rotate 90° clockwise |
| ↺ Rotate CCW | Rotate 90° counter-clockwise |
| 📷 Screenshot | Download viewport as PNG |

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `↑` / `←` | Previous slice / frame |
| `↓` / `→` | Next slice / frame |
| `R` | Reset W/L and camera |
| `I` | Invert image |
| Left drag | Adjust Window / Level |
| Right drag | Pan image |
| Scroll wheel | Scroll through slices |
| Middle drag | Zoom in / out |

### Instance Counter

An overlay in the top-right corner of the canvas shows the current slice index and total count (e.g. `3 / 42`). It updates automatically as you scroll.

### Auto W/L from Pixel Data

When a DICOM file has no `WindowCenter` / `WindowWidth` metadata tags (common for secondary captures and some ultrasound), the viewer automatically computes the VOI range from the actual pixel `min` / `max` values on the first rendered frame.

---

## DICOM File Upload

### Via Admin UI

1. Click **Upload Study** (top right of the page).
2. Select one or more `.dcm` files (multi-file select supported).
3. Click **Upload**.

Multiple files from different studies can be uploaded in a single batch — each file is routed to the correct study post by its `StudyInstanceUID`.

### Via REST API

```http
POST /wp-json/mcp-ai/v1/imaging/upload
Authorization: X-WP-Nonce: <nonce>
Content-Type: multipart/form-data

dicom_files[]=@study1_slice1.dcm
dicom_files[]=@study1_slice2.dcm
```

**Response (201):**
```json
{
  "study_id": "1.2.840.10008.5.1.4.1.1.2.1234",
  "files": [
    { "success": true, "file": "study1_slice1.dcm", "instance_uid": "…" },
    { "success": true, "file": "study1_slice2.dcm", "instance_uid": "…" }
  ]
}
```

### DICOM Storage Layout

```
{wp-uploads}/mcp-ai-imaging/
└── {StudyInstanceUID}/
    ├── .htaccess          ← "Deny from all" — blocks direct HTTP access
    ├── index.php          ← silent fallback
    ├── slice_001.dcm
    ├── slice_002.dcm
    └── …
```

---

## Deleting a Study

Studies can be deleted by users with the `manage_medical_imaging` capability.

1. In the Studies table, click the **Delete** button for a study.
2. An **inline confirmation row** appears (no browser popup):
   - Click **Yes, delete** to permanently remove the study post and all `.dcm` files.
   - Click **Cancel** to dismiss without deleting.

Deletion is a **hard-delete** — files are physically removed from disk and bypasses the WordPress trash. Any file or directory removal failures are written to the Audit Log for compliance tracking.

---

## REST API Reference

**Base URL:** `/wp-json/mcp-ai/v1/imaging`  
**Authentication:** `X-WP-Nonce: <nonce>` header (or WordPress cookie session)

| Method | Endpoint | Capability | Description |
|--------|----------|------------|-------------|
| `GET` | `/studies` | `view_medical_imaging` | List studies. Params: `per_page`, `page`, `modality`, `date_from`, `date_to`, `search` |
| `GET` | `/studies/{uid}` | `view_medical_imaging` | Get a single study by `StudyInstanceUID` |
| `GET` | `/studies/{uid}/manifest` | `view_medical_imaging` | Cornerstone3D-compatible manifest with signed `imageIds` |
| `DELETE` | `/studies/{uid}` | `manage_medical_imaging` | Hard-delete study post and DICOM files from disk |
| `POST` | `/upload` | `upload_medical_imaging` | Upload `.dcm` files (`multipart/form-data`, field: `dicom_files[]`) |
| `GET` | `/instances/{uid}/file` | `view_medical_imaging` | Stream raw DICOM bytes. Requires signed `?token=` query param |
| `GET` | `/stats` | `view_medical_imaging` | Summary: `total_studies`, `by_modality[]`, `storage_bytes`, `recent_studies[]` |
| `POST` | `/interpret` | `view_medical_imaging` | AI interpretation. Body: `{"study_uid": "…", "focus": "full\|quality\|completeness\|workflow"}` |
| `GET` | `/audit` | `manage_medical_imaging` | Recent audit events. Params: `limit`, `study_id` |

### GET /imaging/stats — Response Example

```json
{
  "total_studies": 12,
  "by_modality": [
    { "modality": "CT", "count": 7 },
    { "modality": "MR", "count": 3 },
    { "modality": "PT", "count": 2 }
  ],
  "storage_bytes": 2147483648,
  "recent_studies": [ … ]
}
```

### POST /imaging/interpret — Request & Response

```http
POST /wp-json/mcp-ai/v1/imaging/interpret
Content-Type: application/json
X-WP-Nonce: <nonce>

{
  "study_uid": "1.2.840.10008.5.1.4.1.1.2.1234",
  "focus": "full"
}
```

```json
{
  "study_uid": "1.2.840.10008.5.1.4.1.1.2.1234",
  "focus": "full",
  "interpretation": "The CT study contains 3 series … [AI output] …"
}
```

---

## Capabilities & Permissions

| WordPress Capability | Grants |
|---------------------|--------|
| `view_medical_imaging` | View studies, open viewer, read stats, stream DICOM files (signed token), run AI interpretation |
| `upload_medical_imaging` | Upload new DICOM study files |
| `manage_medical_imaging` | Delete studies, read audit log, see DICOM storage info box |

Capabilities are registered by `WP_MCP_AI_Imaging_Capabilities` and assigned to the **Administrator** role by default. They can be assigned to custom roles via `WP_MCP_AI_Imaging_Capabilities::add_caps()` or the WordPress role editor.

---

## DICOM Modality Reference

| Code | Modality |
|------|----------|
| CT | Computed Tomography |
| MR | Magnetic Resonance Imaging |
| PT | Positron Emission Tomography (PET) |
| US | Ultrasound |
| DX | Digital X-Ray |
| CR | Computed Radiography |
| MG | Mammography |
| NM | Nuclear Medicine |
| RF | Fluoroscopy / Radiofluoroscopy |
| XA | X-Ray Angiography |
| ECG | Electrocardiography |
| OT | Other (miscellaneous) |

---

## Privacy & HIPAA Compliance Notes

- **Storage protection** — DICOM files live in a server directory blocked by `.htaccess "Deny from all"`. Direct HTTP access is impossible.
- **Signed tokens** — Each file stream request requires a short-lived signed token (`X-WP-Nonce`). No file URL is ever placed in the HTML source or JavaScript.
- **No PHI in the database** — Study metadata is de-identified; only UIDs are stored. Patient names and birth dates are never persisted.
- **Audit trail** — Every study view, upload, delete, and AI interpretation is written to the Audit Log with timestamp and WordPress user ID.
- **Hard-delete** — Deleting a study physically removes all `.dcm` files from disk. Ensure your backup policy covers the DICOM storage directory.
- **AI provider data sharing** — When using AI Interpretation, the study UID and a metadata summary are sent to the configured AI provider (OpenAI / Gemini). Do **not** enable this feature for studies containing identifiable patient data unless you have a Business Associate Agreement (BAA) with that provider.
- **HIPAA Security Rule** — The plugin achieves **98% compliance** with the HIPAA Security Rule (42 of 43 safeguards). See [compliance/hipaa/](../compliance/hipaa/) for the full assessment.

---

## Architecture & File Map

| File | Role |
|------|------|
| `addons/pro/includes/admin/class-wp-mcp-ai-imaging-admin-page.php` | Admin page registration, asset enqueue, HTML shell |
| `addons/pro/includes/class-wp-mcp-ai-imaging-rest-controller.php` | All 9 REST endpoints |
| `addons/pro/includes/class-wp-mcp-ai-imaging-study-cpt.php` | `mcp_ai_imaging_study` CPT: create, get, list with filters, add series |
| `addons/pro/includes/class-wp-mcp-ai-dicom-metadata.php` | DICOM binary parser (magic byte validation, tag extraction) |
| `addons/pro/includes/class-wp-mcp-ai-imaging-audit-log.php` | Append-only option-backed audit log |
| `addons/pro/includes/class-wp-mcp-ai-imaging-capabilities.php` | Capability definitions and assignment helpers |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-manage-imaging-studies.php` | `manage_imaging_studies` AI tool |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-interpret-imaging-study.php` | `interpret_imaging_study` AI tool |
| `addons/pro/assets/js/imaging-viewer.js` | Frontend JS (Cornerstone3D bootstrap, tabs, filters, W/L toolbar, AI interpretation form, keyboard shortcuts) |
| `addons/pro/assets/css/imaging-viewer.css` | All viewer and manager styles |

### CDN Strategy

Cornerstone3D packages are loaded from the `esm.sh` CDN as ES modules via an `importmap`. For **air-gapped deployments**, run:

```bash
npm install @cornerstonejs/core @cornerstonejs/tools @cornerstonejs/dicom-image-loader
```

Then update the `importmap` entries in `imaging-viewer.js` to local paths.

---

## Configuration

### Enable / Disable the Module

The imaging viewer is enabled by default when the Pro Add-on is active. To disable:

```php
// In wp-config.php or a mu-plugin:
update_option( 'wp_mcp_ai_settings', array_merge(
    get_option( 'wp_mcp_ai_settings', array() ),
    array( 'enable_healthcare_imaging' => false )
) );
```

### Storage Directory

DICOM files are stored at:

```
{WordPress uploads dir}/mcp-ai-imaging/{StudyInstanceUID}/
```

The directory is created automatically on first upload. To customise the path, filter the REST controller's `get_storage_root()` method (see `class-wp-mcp-ai-imaging-rest-controller.php`).

---

## Troubleshooting

| Symptom | Likely Cause | Resolution |
|---------|-------------|------------|
| Images render as a black canvas | Cornerstone3D not initialised or missing `csDicomImageLoader.external.cornerstone` link | Ensure `imaging-viewer.js` is loaded and `bootCornerstone()` runs without errors (check browser console) |
| Only the last uploaded study appears | Study lookup skipped for multi-file batch (old bug — now fixed) | Update to the latest version |
| Upload returns "No valid DICOM files" | Files are not valid DICOM (missing `DICM` magic bytes at offset 128) | Verify files with a DICOM validator; re-export from your imaging system |
| AI interpretation returns 503 | No OpenAI / Gemini API key configured | Add credentials in **Settings → NV oOS → AI Providers** |
| Viewer shows "Unable to load imaging study" | Signed token expired or REST API unreachable | Refresh the page and re-open the study |
| Storage directory not writable | PHP process lacks write permission | Run `chmod 775 {uploads}/mcp-ai-imaging/` or adjust server permissions |
| Delete fails silently | File removal failed (logged in Audit Log) | Check Audit Log tab for `study_delete_file_failed` events; inspect server disk permissions |

---

## Related Documentation

- [health-wellness-document-tools.md](../health-wellness-document-tools.md) — PDF, Excel, and document processing tools for health records
- [health-wellness-document-tools-quick-reference.md](../health-wellness-document-tools-quick-reference.md) — Quick reference for health document tools
- [compliance/hipaa/](../compliance/hipaa/) — Full HIPAA Security Rule compliance assessment
- [telegram-mini-app-templates.md](../telegram-mini-app-templates.md) — Health & Wellness and Medical Vitals Telegram Mini App templates
- [SECURITY.md](../../SECURITY.md) — Plugin security policies and responsible disclosure
