# Health & Wellness Management

> CPT-based health-records management for people **and pets**: members, medical records,
> checkups, prescriptions, allergies, insurance policies, and reminders.

| | |
|---|---|
| **Activation setting** | `enable_health_wellness_management` |
| **Admin location** | NV oOS → Settings → Pro Features → Health & Wellness |
| **Custom Post Types** | Multiple (members, records, prescriptions, allergies, policies) |
| **Status** | ⚠️ **PHI when used clinically — see compliance notes below.** |

---

## What it provides

A general-purpose health-tracking system that works for clinics, wellness centers,
veterinary practices, and personal use:

- **Members** — patients (people or pets) with demographics and contact info.
- **Medical records & checkups** — encounter notes, vitals, lab results.
- **Prescriptions** — medication tracking with refills and reminders.
- **Allergies** — allergy registry tied to a member.
- **Policies** — insurance / membership policies.
- **Reminders** — `create_health_reminder`-driven automated reminders.
- **Charts & FHIR export** — `generate_health_chart`, `export_fhir_data`.

### Selected tools

`create_member`, `delete_member`, `create_medical_record`, `delete_medical_record`,
`create_checkup`, `delete_checkup`, `create_prescription`, `delete_prescription`,
`create_allergy`, `delete_allergy`, `create_policy`, `delete_policy`,
`create_health_reminder`, `compile_health_research_data`, `generate_health_chart`,
`export_fhir_data`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Health & Wellness Management** under **NV oOS → Settings → Pro Features**.
3. Configure default member type (person / pet) and JetEngine vitals-log integration on
   the toolkit settings page.

---

## Compliance notes

If you use this toolkit in a clinical context:

- Treat data as PHI; deploy under HIPAA-compliant hosting with a BAA.
- Use `export_fhir_data` for interoperability with EHR systems.
- For radiology / DICOM imaging, use the [Healthcare Imaging](healthcare-imaging.md)
  toolkit instead.
- Restrict the toolkit to staff capabilities; default `manage_options` is too broad.

For non-clinical use (personal health tracking, fitness coaching, vet bookkeeping), the
defaults are usually appropriate.

---

## Related docs

- [Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md) — shared engine, codes, FHIR builders, audit ledger, capability map
- [Pro Toolkits index](README.md)
- [Medical Vitals](medical-vitals.md) — vitals tracking sub-toolkit
- [Healthcare Imaging](healthcare-imaging.md) — DICOM-aware imaging viewer
- [`addons/pro/docs/HEALTH_WELLNESS_IMPLEMENTATION.md`](../HEALTH_WELLNESS_IMPLEMENTATION.md)
- [`addons/pro/docs/MIGRATION_MEDICAL_RECORD_POST_TYPE.md`](../MIGRATION_MEDICAL_RECORD_POST_TYPE.md)
