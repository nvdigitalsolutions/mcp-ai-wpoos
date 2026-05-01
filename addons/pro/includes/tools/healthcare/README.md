# Healthcare Toolkit

> Unified umbrella for the three healthcare sub-toolkits in NV oOS Pro:
> **Medical Vitals**, **Health & Wellness**, and **Healthcare Imaging**.

This directory mirrors the [Architectural Design Toolkit](../architectural-design/README.md) layout:

* **Module subdirectories** — sub-toolkit modules are grouped by concern.
* **Shared engine** — a single `WP_MCP_AI_Healthcare_Engine` class provides cross-cutting helpers (units, identity, settings, reference ranges).
* **Standards registry** — `WP_MCP_AI_Healthcare_Codes` exposes seedable code packs (ICD-10-CM, SNOMED CT, LOINC, RxNorm, CVX, CPT, DICOM modalities) so partners can plug in regional variants.
* **FHIR R4 builders** — `WP_MCP_AI_Healthcare_FHIR` produces resource arrays for `Patient`, `Observation`, `Condition`, `MedicationRequest`, `AllergyIntolerance`, `Encounter`, `Immunization`, `ImagingStudy`, `DiagnosticReport`, and `Bundle`.
* **Unified PHI audit ledger** — `WP_MCP_AI_Healthcare_Audit` records every read/write of a member, record, prescription, vital, or imaging study to the same append-only buffer.
* **Capability map** — `WP_MCP_AI_Healthcare_Capabilities` maps clinical roles (clinician, nurse, technologist, billing, patient, guardian) onto WordPress capabilities.
* **Per-toolkit settings** — `wp_mcp_ai_healthcare_settings` stores defaults for unit system, default code pack, FHIR base URL, audit retention, BAA acknowledgement, imaging viewer layout and DICOMweb endpoint.
* **`is_available()` / `get_unavailable_reason()`** on every healthcare tool (added incrementally during Phases B–E) so the orchestrator can skip the toolkit cleanly when its toggle is off or the BAA gate has not been acknowledged.

> **Clinical use note.** Used clinically, every artefact in this toolkit is Protected Health Information (PHI).  Deploy only on infrastructure compliant with HIPAA / your local health-data regulation.  See the [compliance & deployment notes](#compliance--deployment) below.

---

## Module map

| Module | Folder | Sub-toolkit | Phase introduced |
|---|---|---|---|
| Members | `members/` | Health & Wellness | Phase A |
| Medical Records | `medical-records/` | Health & Wellness | Phase A |
| Prescriptions | `prescriptions/` | Health & Wellness | Phase A |
| Allergies | `allergies/` | Health & Wellness | Phase A |
| Checkups | `checkups/` | Health & Wellness | Phase A |
| Policies | `policies/` | Health & Wellness | Phase A |
| Reminders, research & guides | `reminders-research/` | Health & Wellness | Phase A |
| Vitals | `vitals/` | Medical Vitals | Phase A → B |
| Imaging | `imaging/` | Medical Imaging | Phase A |
| Interoperability (FHIR / HL7 / CCDA / DICOMweb) | `interoperability/` | cross-cutting | Phase A → E |
| Region/specialty assistant blueprints | `examples/` | — | Phase E |

> Phase A introduces the umbrella and the shared infrastructure.  The
> existing flat tool files under `addons/pro/includes/tools/` continue to
> register normally; subsequent phases will physically relocate them into
> these subfolders without changing tool slugs.

---

## Phased roadmap

The healthcare toolkit follows the same A→E roadmap as the Architectural Design toolkit.

### Phase A — Foundations & relocation (this milestone)

Introduces the unified umbrella, the shared engine / codes / FHIR / audit / capabilities classes, the `wp_mcp_ai_healthcare_settings` option, the unified bootstrap (`includes/healthcare-toolkit-init.php`), and a backwards-compatible forwarder that preserves the old `healthcare-imaging-toolkit-init.php` entry point.  No new tools are introduced and no existing tool slugs change.

### Phase B — Medical Vitals depth ✅

Adds `analyze_vital_trends`, `flag_abnormal_vitals`, `compute_bmi_and_growth_percentile`, and `get_vaccination_schedule` (backed by `WP_MCP_AI_Healthcare_Vaccination_Schedules` with CDC paediatric / CDC adult / WHO EPI / AAFP feline / AAHA canine packs).  Promotes vital logs to the auxiliary `mcp_ai_hc_vital_log` CPT (existing options + JetEngine CCT storage continues to work as the primary store).  Fires `wp_mcp_ai_healthcare_before_vital_log` and `wp_mcp_ai_healthcare_after_vital_log` hooks from `log_vital_signs`.  Broader CSV / Apple Health / Google Fit / Withings imports for `import_vitals` are tracked for a Phase B follow-up.

### Phase C — Health & Wellness breadth ✅

Adds six cross-cutting Health & Wellness tools that close the gaps called out in [`addons/pro/docs/PRO_TOOLKIT_ENHANCEMENT_REVIEW.md`](../../../docs/PRO_TOOLKIT_ENHANCEMENT_REVIEW.md): `check_member_allergies`, `get_health_timeline`, `link_prescription_to_record`, `verify_prescription_interactions` (RxNorm-aligned offline registry, filter-extensible to RxNav), `generate_visit_summary`, and `merge_duplicate_members`.  `manage_care_plan` was already present.  Filters added: `wp_mcp_ai_healthcare_interaction_pairs`, `wp_mcp_ai_healthcare_rxnorm_lookup`, `wp_mcp_ai_healthcare_member_child_meta_map`.  Action added: `wp_mcp_ai_healthcare_after_merge_members`.

### Phase D — Imaging depth

Adds DICOMweb (QIDO-RS / WADO-RS / STOW-RS) connection helper, `import_dicom_study`, `export_dicom_study`, `attach_radiology_report`, `compare_imaging_studies` (prior/current), DICOM SR generation, and per-modality viewer hanging-protocols.

### Phase E — Interoperability & assistant blueprints

Adds `import_fhir_bundle`, `export_ccda_document`, `import_hl7v2_message`, `connect_to_ehr` (Epic / Cerner OAuth via the Pro credentials vault), and four region/specialty assistant blueprints in [`examples/`](examples/): general-clinic, veterinary-practice, personal-health-tracker, radiology-review.  Marks the toolkit roadmap complete.

---

## Settings option (`wp_mcp_ai_healthcare_settings`)

```php
array(
    'default_unit_system'      => 'metric',           // 'metric' | 'imperial'
    'default_member_type'      => 'person',           // 'person' | 'pet'
    'default_code_pack'        => 'icd10-cm-2025',    // see WP_MCP_AI_Healthcare_Codes
    'fhir_base_url'            => '',                 // outbound FHIR server, optional
    'audit_retention_days'     => 365,
    'require_baa_acknowledged' => false,              // hard gate before any PHI tool runs
    'imaging'                  => array(
        'viewer_layout'     => 'default',
        'dicomweb_endpoint' => '',
    ),
    'vitals'                   => array(
        'reference_ranges' => array(),                // override map
    ),
);
```

Programmatic access: `WP_MCP_AI_Healthcare_Engine::get_toolkit_settings()`.  Filterable via `wp_mcp_ai_healthcare_toolkit_settings`.

The three sub-toolkit toggles continue to live in `wp_mcp_ai_settings`:

| Toggle | Sub-toolkit |
|---|---|
| `enable_health_wellness_management` | Health & Wellness |
| `enable_healthcare_imaging` | Medical Imaging |
| `enable_medical_vitals` | Medical Vitals (defaults to `enable_health_wellness_management` for backwards compatibility) |

---

## Standards alignment

| Domain | Standards |
|---|---|
| Diagnoses | ICD-10-CM (default), ICD-10-WHO, ICD-11 (via filter) |
| Findings | SNOMED CT |
| Observations / Labs | LOINC |
| Medications | RxNorm (default), DM+D / NDC (via filter) |
| Vaccines | CDC CVX |
| Procedures | CPT |
| Imaging | DICOM PS3.3 / PS3.16 modality codes; DICOMweb (Phase D) |
| Exchange | FHIR R4 (`4.0.1`); HL7 v2 + CCDA (Phase E) |
| Compliance | HIPAA Privacy & Security Rule alignment; GDPR Article 9 (special category data) |

---

## Extending the toolkit

The toolkit is fully filterable for partner customisation:

| Filter / Action | Purpose |
|---|---|
| `wp_mcp_ai_healthcare_code_packs` | Register additional clinical code systems (ICD-11, SNOMED-UK, regional drug codes). |
| `wp_mcp_ai_healthcare_default_code_pack` | Map a deployment to its canonical pack. |
| `wp_mcp_ai_healthcare_reference_ranges` | Override vitals reference ranges by age / sex / species. |
| `wp_mcp_ai_healthcare_fhir_resource` | Mutate a FHIR resource just before serialisation. |
| `wp_mcp_ai_healthcare_dicom_modalities` | Register custom modality codes / colours (Phase D). |
| `wp_mcp_ai_healthcare_capabilities` | Override the role-to-capability map. |
| `wp_mcp_ai_healthcare_toolkit_settings` | Final-pass filter on the resolved settings array. |
| `wp_mcp_ai_healthcare_before_phi_access` / `…_after_phi_access` | Audit and policy hooks fired by every PHI read / write. |
| `wp_mcp_ai_healthcare_before_prescription_create` / `…_after_…` | Drug-interaction hook points (Phase C). |
| `wp_mcp_ai_healthcare_before_imaging_export` / `…_after_…` | DICOM de-identification / re-identification injection (Phase D). |
| `wp_mcp_ai_healthcare_before_vital_log` / `…_after_vital_log` | Vital-log ingestion hooks (Phase B). |

---

## Compliance & deployment

* **PHI handling.**  Used clinically, members, records, prescriptions, allergies, vitals and imaging studies are all PHI.  Deploy only on:
  * WordPress hosts that sign a Business Associate Agreement (BAA).
  * Encrypted-at-rest storage (database + uploads + backups).
  * TLS in transit.
* **Capabilities.**  Use `WP_MCP_AI_Healthcare_Capabilities` to map clinical roles onto WordPress capabilities.  The default mapping is conservative; almost every deployment should narrow it.
* **Audit log.**  Every PHI read / write goes through `WP_MCP_AI_Healthcare_Audit::record()`.  Subscribe to `wp_mcp_ai_healthcare_after_phi_access` to forward entries to an external SIEM.
* **DICOM identifiers.**  The metadata extractor preserves Study / Series / SOP UIDs so studies can be cross-referenced with PACS systems via DICOMweb.
* **De-identification.**  Phase D's DICOM export tool runs through the `wp_mcp_ai_healthcare_before_imaging_export` filter point so partners can plug a de-identifier in by default.

This toolkit is **not** a replacement for a PACS, an EHR, or a certified medical-device viewer; it is a workflow and review tool that sits alongside them.

---

## Backwards compatibility

* **Tool slugs do not change.**  Existing tool slugs (`interpret_imaging_study`, `create_member`, `log_vital_signs`, …) remain stable across phases.
* **`healthcare-imaging-toolkit-init.php`** is now a thin forwarder to the unified bootstrap; partner code that requires it directly continues to work.
* **`enable_healthcare_imaging`** and **`enable_health_wellness_management`** keep their meanings.  The new `enable_medical_vitals` defaults to the same value as `enable_health_wellness_management` so existing installs auto-opt-in to the renamed grouping.
* **Existing `WP_MCP_AI_Imaging_*` classes** remain in `addons/pro/includes/` and are still registered identically — Phase A only adds the unified umbrella around them.

---

## Related docs

* [Pro Toolkits index](../../../docs/toolkits/README.md)
* [Health & Wellness](../../../docs/toolkits/health-wellness.md) — sub-toolkit B operator overview
* [Healthcare Imaging](../../../docs/toolkits/healthcare-imaging.md) — sub-toolkit C operator overview
* [Medical Vitals](../../../docs/toolkits/medical-vitals.md) — sub-toolkit A operator overview
* [`addons/pro/docs/HEALTH_WELLNESS_IMPLEMENTATION.md`](../../../docs/HEALTH_WELLNESS_IMPLEMENTATION.md)
* [`addons/pro/docs/PRO_TOOLKIT_ENHANCEMENT_REVIEW.md`](../../../docs/PRO_TOOLKIT_ENHANCEMENT_REVIEW.md)
