# Healthcare Interoperability (Phase E)

Phase E completes the Healthcare Toolkit by adding cross-system data exchange and ready-to-import assistant blueprints. All tools register under the existing `enable_health_wellness_management` toggle, reuse the audit ledger, and never bypass capability checks.

## Tools

| Tool | Capability | Purpose |
|---|---|---|
| `import_fhir_bundle` | `edit_others_posts` | Parse a FHIR R4 Bundle JSON document and upsert resources into the local healthcare CPTs. |
| `export_ccda_document` | `edit_posts` | Emit a minimal HL7 C-CDA R2.1 Continuity of Care Document for a member. |
| `import_hl7v2_message` | `edit_others_posts` | Parse a pipe-delimited HL7 v2.x ER7 message (ADT/ORU) and upsert patient + observations. |
| `connect_to_ehr` | `manage_options` | Configure / test / get / disconnect Epic, Cerner, or generic SMART-on-FHIR connections. |

### `import_fhir_bundle`

Accepts either `bundle` (object) or `bundle_json` (string). Iterates `entry[].resource` and dispatches by `resourceType`:

* **Patient** → matches existing `mcp_ai_member` by `_member_mrn` / `_fhir_patient_identifier`, otherwise creates a new one. Sets first/last name, DOB, gender, MRN.
* **AllergyIntolerance** → `mcp_ai_allergy` linked to the imported member.
* **Condition** → `mcp_ai_med_record` (`_record_type = condition`).
* **MedicationStatement** / **MedicationRequest** → `mcp_ai_prescription`.
* **Immunization** → `mcp_ai_vaccination_record`.

Unknown resource types are reported back in `skipped` rather than treated as errors.

```php
$result = wp_mcp_ai_call_tool( 'import_fhir_bundle', array( 'bundle' => $bundle ) );
// $result['member_id'], $result['imported'], $result['skipped'], $result['errors']
```

### `export_ccda_document`

Emits a CDA R2 / C-CDA R2.1 Continuity of Care Document with a `recordTarget`/`patientRole` block and four `<section>` blocks (Allergies / Medications / Problems / Immunizations). The document `<id>` and patient `<id>` use a deterministic `2.16.840.1.113883.3.9999.{member_id}` pseudo-OID — sites should replace this with their assigning-authority OID via the `wp_mcp_ai_healthcare_ccda_document` filter.

### `import_hl7v2_message`

Splits on `\r`, `\n`, or `\r\n`; expects `MSH` first. Reads field 9 of `MSH` to drive routing (e.g. `ADT_A04`, `ADT_A08`, `ORU_R01`). Patient demographics come from `PID-3` (identifier), `PID-5` (`family^given`), `PID-7` (DOB), `PID-8` (sex). `OBX` segments become `mcp_ai_med_record` observations linked to the upserted member.

### `connect_to_ehr`

Stores connections in the `wp_mcp_ai_ehr_connections` option keyed by vendor (`epic`, `cerner`, `generic`). Test action runs an HTTP `client_credentials` grant against the configured `token_url` with HTTP Basic auth from the saved client_id/client_secret. Sites that prefer encrypted-at-rest storage should hook `wp_mcp_ai_healthcare_ehr_credentials`.

```php
wp_mcp_ai_call_tool( 'connect_to_ehr', array(
    'action'        => 'configure',
    'vendor'        => 'epic',
    'fhir_base_url' => 'https://fhir.epic.example.com/api/FHIR/R4',
    'token_url'     => 'https://fhir.epic.example.com/oauth2/token',
    'client_id'     => '…',
    'client_secret' => '…',
    'scope'         => 'system/*.read',
) );
wp_mcp_ai_call_tool( 'connect_to_ehr', array( 'action' => 'test', 'vendor' => 'epic' ) );
```

## Hooks added in Phase E

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_healthcare_fhir_resource_handlers` | filter | Map of FHIR resourceType → callable for the bundle importer. |
| `wp_mcp_ai_healthcare_ccda_document` | filter | Mutate the generated C-CDA XML before return. |
| `wp_mcp_ai_healthcare_hl7v2_segments` | filter | Mutate the parsed HL7 v2 segment map before persistence. |
| `wp_mcp_ai_healthcare_ehr_credentials` | filter | Override storage of EHR client credentials (delegate to the password vault). |

## Assistant blueprints

Located in [`addons/pro/includes/tools/healthcare/examples/`](../../includes/tools/healthcare/examples/). Each blueprint is a JSON document compatible with the assistant importer; copy and import via the assistant admin UI to bootstrap a configured assistant.

| File | Use case |
|---|---|
| `general-clinic.json` | Outpatient clinic front desk: registration, scheduling, FHIR/CCDA exchange. |
| `veterinary-practice.json` | Companion-animal clinic: pet members, species-aware drug interactions, vaccination schedules. |
| `personal-health-tracker.json` | Self-service tracker for the logged-in user; refuses to disclose other members. |
| `radiology-review.json` | Radiology workflow: DICOMweb sync, prior comparison, structured reports. |

## Compliance notes

* `import_fhir_bundle` and `import_hl7v2_message` are write tools — both run through `WP_MCP_AI_Healthcare_Audit::record()` with the resource counts and the importing user.
* `export_ccda_document` is read-only on patient data but is also audited (export events count toward the BAA log).
* `connect_to_ehr` never returns the stored `client_secret`; the redacted accessor returns `[redacted]` once a value has been saved.
* The HL7 v2 parser is intentionally strict-on-MSH but lenient elsewhere; partner integrations should tighten validation by hooking `wp_mcp_ai_healthcare_hl7v2_segments`.

## Related docs

* [Healthcare Toolkit umbrella](../../includes/tools/healthcare/README.md)
* [Healthcare Imaging](healthcare-imaging.md) — Phase D depth tools
* [Health & Wellness Management](health-wellness.md) — Phase C member/record breadth
* [Password Vault](password-vault.md) — secure storage of EHR client_secrets
