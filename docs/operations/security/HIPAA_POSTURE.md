# HIPAA Posture — NV oOS Pro Medical Data Modules

> **Status:** Living document · Last reviewed: **April 2026** · Owner: NV Digital Solutions  
> This document describes the data flow, access controls, audit logging, and operator obligations for the two PHI-handling modules in NV oOS Pro: **Health & Wellness Management** and **Healthcare Imaging Toolkit (DICOM Viewer)**.

---

## 1. Scope

This document covers Protected Health Information (PHI) as defined by HIPAA/HITECH and equivalent regulations (GDPR "special category", PIPEDA sensitive data).  It does **not** provide legal advice — operators must obtain independent legal review and a Business Associate Agreement (BAA) with any Business Associate involved in PHI processing (including NV Digital Solutions if a managed-service arrangement is in place).

---

## 2. PHI Modules

### 2.1 Health & Wellness Management

| Attribute | Detail |
|-----------|--------|
| **PHP class** | `WP_MCP_AI_Health_Wellness_CPT` |
| **Init file** | `addons/pro/includes/health-wellness-management-init.php` |
| **CPT slugs** | `mcp_ai_member`, `mcp_ai_policy`, `mcp_ai_med_record`, `mcp_ai_checkup`, `mcp_ai_prescription`, `mcp_ai_allergy` |
| **Storage** | WordPress `wp_posts` + `wp_postmeta` tables |
| **Activation** | Admin setting `enable_health_wellness_management` |

**What PHI is stored**

| CPT | Typical PHI fields |
|-----|--------------------|
| `mcp_ai_member` | Name (post title), date of birth, contact details |
| `mcp_ai_med_record` | Diagnoses (ICD-10), lab values, care-team notes |
| `mcp_ai_checkup` | Vital signs (BP, heart rate, weight), provider notes |
| `mcp_ai_prescription` | Medication name (NDC/RxNorm), dosage, prescriber |
| `mcp_ai_allergy` | Allergen, reaction type, severity, onset date |
| `mcp_ai_policy` | Insurance member/group numbers, plan type |

**AI provider data flow**

The `parse_health_information` tool accepts *operator or staff-provided* free-text, parses it into structured CPT records, and writes those records locally.  The raw text is passed to the configured AI provider (OpenAI/Gemini/Ollama) for parsing.  **Operators must ensure they have a HIPAA BAA with their chosen AI provider before enabling this tool in production.**  For on-premises deployments, set the AI provider to Ollama so no data leaves the server.

The `compile_health_research_data`, `generate_health_chart`, and `get_member_health_summary` tools read existing CPT records and return structured data to the AI assistant for display — they do **not** send PHI to an external AI provider beyond the model that is already answering the operator's chat session.

---

### 2.2 Healthcare Imaging Toolkit (DICOM Viewer)

| Attribute | Detail |
|-----------|--------|
| **PHP class** | `WP_MCP_AI_Imaging_REST_Controller` |
| **Init file** | `addons/pro/includes/healthcare-imaging-toolkit-init.php` |
| **CPT slug** | `mcp_ai_imaging_study` |
| **File storage** | Outside public webroot (`wp-content/uploads/mcp-ai-imaging/`), protected by `.htaccess Deny from all` |
| **Activation** | Admin setting `enable_healthcare_imaging` |

**What PHI is stored**

| Source | PHI / de-identified? |
|--------|----------------------|
| `_imaging_study_instance_uid` | DICOM UID — not PHI |
| `_imaging_patient_id` | De-identified patient reference extracted from DICOM (0010,0020); may be a real MRN in non-anonymised studies |
| `_imaging_modality` | CT / MR / PT — not PHI |
| `_imaging_study_date` | YYYYMMDD — quasi-identifier |
| DICOM pixel data (`.dcm` files on disk) | Contains full DICOM tag set including `(0010,0010) PatientName` |

**`PatientName` is intentionally NOT stored in the WordPress database** — it exists only inside the raw `.dcm` files on disk.  No REST endpoint returns `PatientName`.  The `interpret_imaging_study` AI tool builds its prompt from `study_instance_uid`, `modality`, `study_date`, `series_description`, and pixel-preview data only — no patient-identifying tags are forwarded to the AI provider.

**Multisite guard (F-PRIV-03)**

On WordPress multisite networks, both modules will refuse to load unless the site-level setting `wp_mcp_ai_phi_acknowledged` is enabled (Settings → NV oOS → Tools → PHI Handling Acknowledgement).  This prevents accidental PHI ingestion on sub-sites whose administrators have not reviewed compliance obligations.

---

## 3. Access Controls

### 3.1 Custom capabilities

`WP_MCP_AI_Imaging_Capabilities` adds three custom capabilities to the Administrator role on first load:

| Capability | Description |
|------------|-------------|
| `view_medical_imaging` | Read study metadata + serve DICOM files |
| `upload_medical_imaging` | Upload new DICOM studies |
| `manage_medical_imaging` | Delete studies, view audit log |

All REST endpoints enforce these capabilities in their `permission_callback`.  No anonymous access to PHI is permitted.

### 3.2 Health & Wellness CPTs

All health CPTs are registered with `'capability_type' => 'post'` (inheriting standard `edit_posts`/`publish_posts`/`delete_posts`) and with `'public' => false` to prevent accidental front-end exposure.  Access should be further locked down via a role-management plugin (e.g. Members, User Role Editor) for production deployments.

---

## 4. Audit Logging

### 4.1 DICOM imaging

Every action on a DICOM study is written to `WP_MCP_AI_Imaging_Audit_Log` (stored in `wp_mcp_ai_imaging_audit` custom table):

| Event | Trigger |
|-------|---------|
| `study_uploaded` | `POST /imaging/upload` success |
| `study_interpreted` | `interpret_imaging_study` tool execution |
| `study_deleted` | `DELETE /imaging/studies/{id}` |
| `study_accessed` | `GET /imaging/studies/{id}` |
| `instance_served` | `GET /imaging/instances/{id}/file` |
| `audit_log_accessed` | `GET /imaging/audit` |

### 4.2 Health & Wellness CPTs

Single-post reads (admin edit screen or front-end singular template) are logged via the `the_post` hook in `wp_mcp_ai_health_cpt_read_audit()`, which writes to `WP_MCP_AI_Logger`.  Writes (create/update/delete) are covered by standard WordPress post-status hooks and can be extended via the `wp_mcp_ai_after_tool_execution` action.

---

## 5. Data Retention and Erasure

### 5.1 DICOM files

DICOM files are stored outside the public upload directory and are **not** accessible via `wp-content` URLs.  They are served exclusively through signed, capability-gated REST URLs (`GET /imaging/instances/{id}/file`).  Files are retained until a study is explicitly deleted via the REST API or WP admin.

### 5.2 WordPress Privacy API (GDPR / CCPA erasure)

`WP_MCP_AI_Pro_Privacy` (registered in `addons/pro/mcp-ai-wpoos-pro.php`) wires both modules into the standard WordPress "Tools → Export Personal Data" and "Erase Personal Data" workflow:

| Module | Exporter | Eraser |
|--------|---------|--------|
| Health & Wellness CPTs | ✅ Exports all posts authored by user | ✅ Hard-deletes (no trash) |
| Imaging studies | ✅ Exports study metadata | ✅ Removes CPT post **and** DICOM files on disk |

**Note:** Raw `.dcm` pixel data is NOT included in the export bundle (binary data is not suitable for the WordPress export format).  The export contains study metadata only.  Operators should arrange separate imaging-archive exports if pixel data must be portable.

---

## 6. Breach Notification

HIPAA Breach Notification Rule (45 CFR §164.400–414) and similar regulations require timely notification if unsecured PHI is compromised.  The operator (Covered Entity) is responsible for breach notification.  NV Digital Solutions, as a Business Associate, will assist in breach investigation per the terms of the executed BAA.

**Indicators to monitor:**

- `WP_MCP_AI_Imaging_Audit_Log` entries with unexpected `user_id = 0` or from untrusted IP addresses
- REST API `401`/`403` responses at high volume on `/wp-json/mcp-ai/v1/imaging/*`
- Unexpected file-system access to the DICOM storage directory

---

## 7. Operator Checklist

Before going live with PHI data:

- [ ] Obtain a signed Business Associate Agreement (BAA) with your AI provider (OpenAI, Google, Anthropic) if you are a HIPAA Covered Entity
- [ ] For maximum privacy, switch to Ollama (local AI) so PHI never leaves your server
- [ ] Enable `wp_mcp_ai_phi_acknowledged` on every multisite sub-site that will handle PHI
- [ ] Restrict `view_medical_imaging`, `upload_medical_imaging`, `manage_medical_imaging` capabilities to only the roles that require them
- [ ] Confirm the DICOM storage directory is outside the public webroot and protected by `.htaccess`
- [ ] Review and test the WordPress Privacy API export/erase flow (Tools → Export Personal Data)
- [ ] Enable WordPress force-SSL (`define('FORCE_SSL_ADMIN', true)`) so all PHI is encrypted in transit
- [ ] Configure WordPress salts/keys and database encryption at rest if required by your compliance framework
- [ ] Schedule regular review of `WP_MCP_AI_Imaging_Audit_Log` entries
- [ ] Document your Data Processing Agreements with any sub-processors who have access to the WordPress database

---

## 8. Responsible Disclosure

Security vulnerabilities related to PHI handling should be reported via the process described in `SECURITY.md`.  Do **not** open public GitHub issues for PHI-related vulnerabilities.

---

*This document was last updated by the Copilot security hardening wave series (Waves 15–23) in April 2026.*
