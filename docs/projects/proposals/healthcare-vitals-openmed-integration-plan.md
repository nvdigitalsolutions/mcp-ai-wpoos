# Healthcare Vitals Toolkit — OpenMed Integration Plan

**Status:** Draft  
**Date:** 2026-06-09  
**Source:** Review of [OpenMed v1.5.5](https://github.com/maziyarpanahi/openmed) (Apache-2.0)

---

## Executive Summary

OpenMed is a local-first clinical NLP library with 1,000+ specialized NER models, HIPAA-compliant PII de-identification (all 18 Safe Harbor identifiers, 12 languages, 247 checkpoints), and a Docker-friendly REST API. This plan outlines how to integrate its capabilities into the NV oOS Pro Healthcare Vitals Toolkit, filling two critical gaps (PHI de-identification and clinical entity extraction from unstructured text) and enhancing several existing subsystems.

---

## Phase 1 — Foundation (Days 1–3)

### 1.1 OpenMed Service Client (`class-wp-mcp-ai-openmed-client.php`)

**Purpose:** A reusable HTTP client that all OpenMed-aware tools share. Follows the existing `WP_MCP_AI_Healthcare_Engine` pattern — a shared infrastructure class loaded eagerly by `init.php`.

**Location:** `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php`

**Design:**

```
class WP_MCP_AI_OpenMed_Client {
    const SETTINGS_OPTION = 'wp_mcp_ai_openmed_settings';

    // Connection config
    private $base_url;      // e.g. 'http://localhost:8080'
    private $timeout;       // default: 30s, override for batch
    private $verify_ssl;    // default: true

    // --- Lifecycle ---
    public static function get_instance(): self;
    public static function is_configured(): bool;

    // --- Endpoint wrappers (each returns array|WP_Error) ---
    public function health(): array|WP_Error;
    public function analyze_text( string $text, string $model_name, array $opts = [] ): array|WP_Error;
    public function extract_pii( string $text, array $opts = [] ): array|WP_Error;
    public function deidentify( string $text, string $method, array $opts = [] ): array|WP_Error;
    public function get_loaded_models(): array|WP_Error;
    public function unload_model( ?string $model_name, bool $all = false ): array|WP_Error;

    // --- Internal ---
    private function request( string $method, string $path, ?array $body = null ): array|WP_Error;
    private function normalise_error( array $response ): WP_Error;
}
```

**Settings** (`wp_mcp_ai_openmed_settings`):

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `service_url` | string | `''` | OpenMed REST base URL (e.g. `http://localhost:8080`) |
| `timeout` | int | `30` | Request timeout in seconds |
| `default_pii_model` | string | `OpenMed/OpenMed-PII-SuperClinical-Small-44M-v1` | Default PII detection model |
| `default_ner_model` | string | `disease_detection_superclinical` | Default clinical NER model |
| `default_lang` | string | `en` | Default language for PII detection |
| `keep_alive` | string | `10m` | Model keep-alive duration |
| `verify_ssl` | bool | `true` | Verify SSL certificate |

**HTTP implementation:** Uses `wp_remote_post()` / `wp_remote_get()` with `wp_remote_retrieve_body()` and `wp_remote_retrieve_response_code()`. All responses go through OpenMed's unified error envelope normalisation.

**Error mapping:**

| OpenMed Error Code | WP_Error Code |
|-------------------|---------------|
| `validation_error` | `openmed_validation_error` |
| `bad_request` | `openmed_bad_request` |
| `timeout` | `openmed_timeout` |
| `internal_error` | `openmed_internal_error` |

**Wire-up in `init.php`** (after line 30):
```php
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php';
```

### 1.2 Settings UI for OpenMed Connection

**Location:** Extend the existing Healthcare Settings page (`class-wp-mcp-ai-crm-settings-page.php` pattern) or add a section to the Toolkit MCP Servers page.

**UI elements:**
- Service URL input + "Test Connection" button (calls `GET /health` via AJAX)
- Model selection dropdowns (populated from OpenMed model registry or static allowlist)
- Timeout slider
- SSL verification toggle
- Status indicator: connected / disconnected / error

---

## Phase 2 — PII/PHI De-identification Tool (Days 3–5)

### 2.1 `deidentify_health_record` Tool

**Location:** `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-deidentify-health-record.php`

**Slug:** `deidentify_health_record`

**Capability flags:** `pro`, `external-api`, `network-dependent`, `pii-data`, `read-only` (does not mutate original data)

**Actions:**

| Action | Description |
|--------|-------------|
| `extract_pii` | Detect all PII entities in text without redacting |
| `deidentify` | Redact PII from text using the chosen method |
| `deidentify_member` | De-identify all records for a member (batch) |
| `reidentify` | Restore original values from a mapping (requires auth) |

**De-identification methods:**

| Method | OpenMed Param | Description |
|--------|--------------|-------------|
| `mask` | `method="mask"` | Replace with typed placeholders: `[NAME]`, `[DATE]` |
| `remove` | `method="remove"` | Delete PII spans entirely |
| `replace` | `method="replace"` | Locale-aware Faker surrogates with valid checksums |
| `hash` | `method="hash"` | Cryptographic digest — consistent across documents |
| `shift_dates` | `method="shift_dates"` | Shift dates by N days (preserves temporal relationships) |

**Parameters schema (key additions beyond standard):**

```php
'text'              => [ 'type' => 'string',  'description' => 'Text to de-identify' ],
'method'            => [ 'type' => 'string',  'enum' => ['mask','remove','replace','hash','shift_dates'] ],
'lang'              => [ 'type' => 'string',  'enum' => ['en','fr','de','it','es','nl','hi','te','pt','ar','ja','tr'] ],
'date_shift_days'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3650 ],
'keep_mapping'      => [ 'type' => 'boolean', 'description' => 'Store reversible mapping for re-identification' ],
'consistent'        => [ 'type' => 'boolean', 'description' => 'Same entity → same surrogate within batch' ],
'seed'              => [ 'type' => 'integer', 'description' => 'Reproducible surrogate generation' ],
'confidence_threshold' => [ 'type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0, 'default' => 0.7 ],
```

**Audit trail:** Every de-identification call records a `deidentify_executed` event via `WP_MCP_AI_Healthcare_Audit::record()` with:
- Method used
- Original text length → de-identified text length  
- Number of entities found and redacted
- Whether mapping was preserved
- SHA-256 hash of the original text (for later verification — not the text itself)

**Re-identification security:** The `reidentify` action requires `manage_options` capability AND stores a SHA-256 audit entry. Mapping data is stored in a separate option keyed by a random UUID, not the member ID, so data at rest is not trivially re-identifiable.

### 2.2 Integration with Existing Health Record Export

Modify `class-wp-mcp-ai-tool-export-fhir-data.php` and `class-wp-mcp-ai-tool-export-ccda-document.php` to accept an optional `deidentify: true` parameter. When set:
1. Export as normal
2. Run the exported text through `deidentify(method="replace", consistent=true, keep_mapping=false)`
3. Return the de-identified export

---

## Phase 3 — Clinical NER for Unstructured Text (Days 5–8)

### 3.1 `extract_clinical_entities` Tool

**Location:** `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-extract-clinical-entities.php`

**Slug:** `extract_clinical_entities`

**Capability flags:** `pro`, `external-api`, `network-dependent`, `pii-data`

**Models exposed (from OpenMed registry):**

| Model Slug | Category | Entities Extracted |
|-----------|----------|-------------------|
| `disease_detection_superclinical` | Disease | DISEASE, CONDITION, DIAGNOSIS |
| `pharma_detection_superclinical` | Pharma | DRUG, MEDICATION, TREATMENT |
| `anatomy_detection_electramed` | Anatomy | ANATOMY, ORGAN, BODY_PART |
| `gene_detection_genecorpus` | Genomics | GENE, PROTEIN |

**Actions:**

| Action | Description |
|--------|-------------|
| `extract` | Run NER on text with specified model(s) |
| `extract_and_import` | Extract entities AND attempt to write them into structured CPTs/CCTs |
| `extract_multi` | Run multiple models against the same text in one call |

**`extract_and_import` mapping:**

| Extracted Entity | Target Storage |
|-----------------|----------------|
| DISEASE, CONDITION, DIAGNOSIS | `mcp_ai_med_record` CPT (diagnosis field) |
| DRUG, MEDICATION, TREATMENT | `mcp_ai_prescription` CPT |
| ANATOMY, ORGAN, BODY_PART | `mcp_ai_med_record` CPT (affected body part) |
| Numeric values + units (e.g. "140/90 mmHg") | `vitals_log` CCT (via `log_vital_signs`) |

### 3.2 Integration with Document Upload

Extend `class-wp-mcp-ai-health-records-consolidate-page.php` (the consolidation page that already has `handle_document_upload` AJAX):

1. After document upload → extract text (PDF extraction already exists elsewhere in the codebase)
2. Pass extracted text to `extract_clinical_entities` with `action=extract_and_import`
3. Show extracted entities in a review UI before import
4. User confirms → entities are written to the appropriate CPTs/CCTs

---

## Phase 4 — Declarative Vitals Measurement Registry (Days 3–5, parallel with Phase 2)

### 4.1 `class-wp-mcp-ai-vitals-measurement-registry.php`

**Location:** `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-vitals-measurement-registry.php`

**Purpose:** Replace hardcoded assessment thresholds in `assess_*` methods with a declarative registry. Each measurement defines its metadata once — normal ranges, units, LOINC codes, severity tiers, and assessment rules.

**Pattern (inspired by OpenMed's `model_registry.py`):**

```php
class WP_MCP_AI_Vitals_Measurement_Registry {
    /**
     * @return array[] Indexed by field slug.
     */
    public static function get_all(): array;

    /**
     * @return array|null Null if unknown slug.
     */
    public static function get( string $slug ): ?array;

    /**
     * Filter measurements by category.
     */
    public static function get_by_category( string $category ): array;

    /**
     * Get LOINC code for a measurement.
     */
    public static function get_loinc( string $slug ): ?string;

    /**
     * Assess a value against the registered normal range.
     * Returns ['status' => 'normal'|'low'|'high'|..., 'tier' => int, 'message' => string]
     */
    public static function assess( string $slug, $value, array $context = [] ): array;
}
```

**Registry entry shape:**

```php
'blood_pressure_systolic' => [
    'slug'        => 'blood_pressure_systolic',
    'label'       => __( 'Systolic Blood Pressure', 'mcp-ai-wpoos-pro' ),
    'category'    => 'cardiovascular',
    'unit'        => 'mmHg',
    'loinc'       => '8480-6',
    'data_type'   => 'integer',
    'range_min'   => 50,
    'range_max'   => 300,
    'tiers'       => [
        ['max' => 120, 'status' => 'normal',               'label' => 'Normal'],
        ['max' => 130, 'status' => 'elevated',              'label' => 'Elevated'],
        ['max' => 140, 'status' => 'stage_1_hypertension',  'label' => 'Stage 1 Hypertension'],
        ['max' => 180, 'status' => 'stage_2_hypertension',  'label' => 'Stage 2 Hypertension'],
        ['max' => 300, 'status' => 'hypertensive_crisis',   'label' => 'Hypertensive Crisis'],
    ],
    // For paired measurements:
    'paired_with' => 'blood_pressure_diastolic',
],
```

### 4.2 Migration of Existing `assess_*` Methods

The `log_vital_signs` tool's private `assess_*` methods are refactored to delegate to the registry:

```php
// Before:
private function assess_blood_pressure( $systolic, $diastolic ) {
    if ( ! $systolic || ! $diastolic ) return 'incomplete';
    if ( $systolic < 120 && $diastolic < 80 ) return 'normal';
    // ...
}

// After:
private function assess_blood_pressure( $systolic, $diastolic ) {
    return WP_MCP_AI_Vitals_Measurement_Registry::assess(
        'blood_pressure',
        ['systolic' => $systolic, 'diastolic' => $diastolic]
    );
}
```

**Benefits:**
- Clinics can override ranges via a filter: `apply_filters( 'wp_mcp_ai_vitals_measurement_registry', $registry )`
- Pediatric vs. adult vs. geriatric ranges become configurable profiles
- New measurements added without touching assessment code
- Registry drives the admin UI form fields dynamically

---

## Phase 5 — Enhanced Batch Import (Days 8–10)

### 5.1 Per-Item Status Tracking

**Current state:** `import_vitals` processes items sequentially; a single failure can be unclear.

**Target:** OpenMed's `BatchProcessor` pattern — each item returns `BatchItemResult` with individual status:

```php
class WP_MCP_AI_Vitals_Batch_Result {
    public int    $total;
    public int    $succeeded;
    public int    $failed;
    public int    $skipped;
    public array  $items;  // WP_MCP_AI_Vitals_Batch_Item_Result[]
}

class WP_MCP_AI_Vitals_Batch_Item_Result {
    public int    $index;
    public string $status;     // 'success' | 'failure' | 'skipped'
    public ?int   $cct_id;     // CCT row ID if inserted
    public ?string $error;     // Error message if failed
    public ?string $warning;   // Non-fatal warning
    public ?array $assessment; // Normal-range assessment per measurement
}
```

### 5.2 Pre-import Validation Dry Run

Add a `dry_run: true` parameter to `import_vitals`. When set:
1. Parse and validate all rows
2. Return per-item results with assessments but do NOT write to CCT
3. User reviews and re-submits with `dry_run: false`

---

## Phase 6 — REST API Hardening (Days 6–7, parallel with Phase 3)

### 6.1 Unified Error Envelope

Adopt OpenMed's error envelope pattern for all healthcare MCP REST endpoints:

```php
// Proposed helper — mirrors OpenMed's error envelope
function wp_mcp_ai_healthcare_error_response(
    string $code,      // 'validation_error' | 'bad_request' | 'timeout' | 'internal_error'
    string $message,
    mixed  $details = null,
    int    $http_status = 400
): WP_REST_Response {
    return new WP_REST_Response([
        'error' => [
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        ]
    ], $http_status);
}
```

Apply to existing REST endpoints in healthcare tools (any `register_rest_route` calls returning errors).

### 6.2 Health Check Endpoint

Add `GET /wp-json/mcp-ai/v1/healthcare/health` that reports:
- OpenMed connection status (if configured)
- CCT table existence
- Member CPT count
- Last audit entry timestamp
- Sub-toolkit enablement status

---

## Phase 7 — Additional Tools (Days 10–14)

### 7.1 `scan_document_for_phi` Tool

**Location:** `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-scan-document-for-phi.php`

**Slug:** `scan_document_for_phi`

Scans uploaded documents (PDF, text, DOCX) for PHI before storage. Returns a report:
- PHI entity count by type
- Risk score (low/medium/high)
- Recommended de-identification method
- HIPAA Safe Harbor compliance checklist

### 7.2 `generate_clinical_summary` Tool

**Location:** `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-generate-clinical-summary.php`

**Slug:** `generate_clinical_summary`

Combines NER extraction + vitals history to produce a structured clinical summary:
1. Extract entities from encounter notes via OpenMed
2. Pull latest vitals from CCT
3. Pull active prescriptions and allergies
4. Format into a de-identified (optional) summary for sharing/referral

### 7.3 `validate_health_record_compliance` Tool

**Location:** `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-validate-health-record-compliance.php`

**Slug:** `validate_health_record_compliance`

Runs a compliance check on stored health records:
- PHI presence scan (should PHI be in this field?)
- Required field completeness (USCDI data classes)
- Audit trail integrity
- Suggested remediation actions

---

## File Manifest

### New Files

```
addons/pro/includes/tools/healthcare/
├── class-wp-mcp-ai-openmed-client.php             # Phase 1 — HTTP client
├── class-wp-mcp-ai-vitals-measurement-registry.php # Phase 4 — declarative registry
├── class-wp-mcp-ai-vitals-batch-result.php         # Phase 5 — batch result DTOs
├── vitals/
│   ├── class-wp-mcp-ai-tool-deidentify-health-record.php     # Phase 2
│   ├── class-wp-mcp-ai-tool-extract-clinical-entities.php    # Phase 3
│   ├── class-wp-mcp-ai-tool-scan-document-for-phi.php        # Phase 7.1
│   ├── class-wp-mcp-ai-tool-generate-clinical-summary.php    # Phase 7.2
│   └── class-wp-mcp-ai-tool-validate-health-record-compliance.php # Phase 7.3
```

### Modified Files

```
addons/pro/includes/tools/healthcare/
├── init.php                              # Phase 1 — load OpenMed client
├── vitals/class-wp-mcp-ai-tool-log-vital-signs.php  # Phase 4 — refactor assess_* to registry
├── vitals/class-wp-mcp-ai-tool-import-vitals.php    # Phase 5 — batch result tracking
├── vitals/class-wp-mcp-ai-tool-flag-abnormal-vitals.php # Phase 4 — registry usage
├── interop/class-wp-mcp-ai-tool-export-fhir-data.php    # Phase 2 — deidentify option
├── interop/class-wp-mcp-ai-tool-export-ccda-document.php # Phase 2 — deidentify option
└── class-wp-mcp-ai-healthcare-audit.php  # Phase 2 — new event types
```

---

## Testing Strategy

### Unit Tests (PHPUnit)

| Test File | Covers |
|-----------|--------|
| `tests/pro/test-openmed-client.php` | HTTP client: request building, error normalisation, timeout handling, SSL config |
| `tests/pro/test-deidentify-health-record.php` | Tool: all 5 methods, parameter validation, audit trail recording |
| `tests/pro/test-extract-clinical-entities.php` | Tool: model selection, entity mapping, extract_and_import |
| `tests/pro/test-vitals-measurement-registry.php` | Registry: lookup, assessment, tiers, filter overrides |
| `tests/pro/test-vitals-batch-result.php` | Batch DTOs: serialisation, status aggregation |

### Integration Tests

| Test File | Covers |
|-----------|--------|
| `tests/pro/test-openmed-integration.php` | End-to-end against a real/simulated OpenMed service |
| `tests/pro/test-deidentify-export.php` | FHIR/CCDA export with deidentification flag |

### Mock Strategy

The OpenMed client accepts a configurable `$http_transport` callable for testing:

```php
// In tests:
add_filter( 'wp_mcp_ai_openmed_http_transport', function() {
    return function( $url, $args ) {
        return [ 'body' => json_encode( [ 'entities' => [] ] ), 'response' => [ 'code' => 200 ] ];
    };
} );
```

---

## Deployment Considerations

### Docker Compose Addition

```yaml
# docker-compose.yml addition
services:
  openmed:
    image: openmed:1.5.5
    ports:
      - "8080:8080"
    environment:
      OPENMED_PROFILE: prod
      OPENMED_SERVICE_KEEP_ALIVE: 10m
      OPENMED_SERVICE_PRELOAD_MODELS: >
        OpenMed/OpenMed-PII-SuperClinical-Small-44M-v1,
        disease_detection_superclinical
    volumes:
      - openmed_cache:/root/.cache/huggingface
    restart: unless-stopped

volumes:
  openmed_cache:
```

### Feature Flags

All new tools gate on `wp_mcp_ai_openmed_settings['service_url']` being non-empty. The `is_available()` method returns `false` with a clear message when OpenMed is not configured.

### Graceful Degradation

- OpenMed client implements a circuit breaker: 3 consecutive failures → 60-second cooldown
- Tools return `WP_Error` with code `openmed_unavailable` when the service is down
- Existing vitals functionality is unaffected — new tools are additive only

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| OpenMed service availability | Medium | Medium (new tools fail, existing unaffected) | Circuit breaker + clear error messages |
| Large model download on first run | High (first deployment) | Low (one-time, ~500MB) | Preload at container build time; document in deployment guide |
| PHI sent to external service | Low (local Docker) | Critical | Enforce localhost-only in settings validation; SSL pinning; audit every call |
| Performance impact of NER on large documents | Medium | Low (async-capable) | Chunk documents; use sentence-level segmentation (already in OpenMed) |
| False positives in PII detection | Medium | Medium | Configurable confidence threshold; review-before-commit workflow for extract_and_import |
| Re-identification mapping security | Low | Critical | Encrypted option storage; manage_options requirement; audit trail |

---

## Success Metrics

- **PII Detection Accuracy:** ≥95% on HIPAA Safe Harbor test corpus (validated against OpenMed benchmarks)
- **Import Resilience:** 0% data loss on partial batch failures (per-item tracking)
- **Registry Coverage:** 100% of existing `assess_*` methods migrated to registry
- **Audit Completeness:** 100% of de-identification calls logged with method + entity count
- **Response Time:** <5s for document scanning (up to 10KB clinical text)

---

## Timeline Summary

```
Week 1:
  Day 1–3: Phase 1 (Client + Settings) + Phase 4 (Registry)
  Day 3–5: Phase 2 (De-identification tool)

Week 2:
  Day 5–7: Phase 3 (Clinical NER) + Phase 6 (REST hardening)
  Day 8–10: Phase 5 (Batch import enhancements)

Week 3:
  Day 10–14: Phase 7 (Additional tools)
  Day 14: Integration testing, documentation, PR
```

---

*End of plan. For questions or scope adjustments, reference the [OpenMed REST Service docs](https://github.com/maziyarpanahi/openmed/blob/master/docs/rest-service.md) and the [anonymization guide](https://github.com/maziyarpanahi/openmed/blob/master/docs/anonymization.md).*
