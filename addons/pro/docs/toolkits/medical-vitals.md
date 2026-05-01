# Medical Vitals

> Vital-sign tracking, health metrics, vaccination scheduling, medication
> schedules, and chart generation for people **and pets** — the third side
> of the unified Healthcare Toolkit alongside Health & Wellness and
> Healthcare Imaging.

| | |
|---|---|
| **Activation setting** | `enable_medical_vitals` (defaults to `enable_health_wellness_management` for backwards compatibility) |
| **Admin location** | NV oOS → Settings → Pro Features → Healthcare → Medical Vitals |
| **Custom Post Types** | shares `mcp_ai_member`; vital logs are stored as JetEngine CCT today, promoted to a CPT in Phase B |
| **Status** | ⚠️ **PHI when used clinically — see compliance notes below.** |

---

## What it provides

A vitals-focused tracker that works for clinics, wellness centres,
veterinary practices, and personal use.  Sub-toolkit A of the
[Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md).

* **Vital sign logging** — `log_vital_signs` (heart rate, blood pressure, temperature, respiratory rate, SpO₂).
* **Health metrics** — `log_health_metrics` (weight, glucose, custom metrics).
* **Imports** — `import_vitals` (CSV today; Apple Health / Google Fit / Withings in Phase B).
* **Vaccination tracking** — `track_vaccinations` against CDC CVX codes.
* **Medication schedules** — `get_medication_schedule` per member.
* **Charts & reminders** — `generate_health_chart`, `create_health_reminder`.

---

## Standards alignment

| Domain | Standard |
|---|---|
| Observation codes | LOINC (e.g. `8867-4` heart rate, `8480-6` systolic BP) via `WP_MCP_AI_Healthcare_Codes` |
| Vaccine codes | CDC CVX |
| Reference ranges | Age / sex / species-aware via `WP_MCP_AI_Healthcare_Engine::reference_ranges()` |
| Exchange | FHIR R4 `Observation`, `Immunization` via `WP_MCP_AI_Healthcare_FHIR` |

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Medical Vitals** under **NV oOS → Settings → Pro Features**.  When the toggle has never been set, it defaults to `enable_health_wellness_management` so existing installs auto-opt-in.
3. Configure default unit system (metric / imperial) and reference-range overrides on the Healthcare toolkit's settings page.

---

## Roadmap (per the unified toolkit)

* **Phase A — Foundations.**  Sub-toolkit lives under the unified Healthcare Toolkit umbrella; shares engine, codes, FHIR builders, audit ledger, and capability map with Health & Wellness and Imaging.
* **Phase B — Depth.**  Promotes vital logs to a CPT (`mcp_ai_hc_vital_log`); adds `analyze_vital_trends`, `flag_abnormal_vitals`, `compute_bmi_and_growth_percentile`, vaccination schedule engine, and broadens `import_vitals` to Apple Health / Google Fit / Withings.

See [`addons/pro/includes/tools/healthcare/README.md`](../../includes/tools/healthcare/README.md) for the full A→E roadmap.

---

## Compliance notes

If you use this toolkit in a clinical context:

* Treat vital-sign data as PHI; deploy under HIPAA-compliant hosting with a BAA.
* Use `export_fhir_data` (Health & Wellness) for interoperability with EHR systems.
* For radiology / DICOM imaging, use the [Healthcare Imaging](healthcare-imaging.md) toolkit instead.
* Restrict the toolkit to staff capabilities; default `manage_options` is too broad.

For non-clinical use (personal health tracking, fitness coaching, vet bookkeeping), the defaults are usually appropriate.

---

## Related docs

* [Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md)
* [Health & Wellness](health-wellness.md) — members / records / prescriptions / allergies / checkups / policies
* [Healthcare Imaging](healthcare-imaging.md) — DICOM-aware imaging viewer
* [Pro Toolkits index](README.md)
