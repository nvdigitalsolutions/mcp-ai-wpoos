# DICOM PHI Handling — Healthcare Deployment Guide

> **Since:** 1.2.0 · **Filter:** `wp_mcp_ai_dicom_strip_phi` · **Status:** Opt-in

## ⚠️ Important Warning

DICOM (Digital Imaging and Communications in Medicine) files **may contain Protected Health Information (PHI)** including:

- Patient names and IDs
- Birth dates
- Referring physician names
- Institution and department names
- Study descriptions that include patient context
- Accession numbers

Storing PHI in WordPress makes your site subject to healthcare privacy regulations including **HIPAA** (US), **GDPR** (EU), and similar laws worldwide. NV oOS does not automatically strip PHI from DICOM files.

## Built-in PHI Filter

NV oOS provides the `wp_mcp_ai_dicom_strip_phi` filter hook that fires after DICOM metadata extraction and before storage. Use this hook to redact or pseudonymize PHI fields.

### Basic Usage — Strip All PHI

```php
add_filter( 'wp_mcp_ai_dicom_strip_phi', function( $meta, $tmp_path ) {
    // Remove all PHI fields before storage.
    unset( $meta['patient_id'] );
    unset( $meta['patient_name'] );
    unset( $meta['patient_birth_date'] );
    unset( $meta['patient_sex'] );
    unset( $meta['referring_physician_name'] );
    unset( $meta['institution_name'] );
    unset( $meta['institution_address'] );
    unset( $meta['accession_number'] );
    
    return $meta;
}, 10, 2 );
```

### Advanced — Pseudonymize with Consistent Hash

```php
add_filter( 'wp_mcp_ai_dicom_strip_phi', function( $meta, $tmp_path ) {
    $site_salt = wp_salt( 'auth' );
    
    if ( ! empty( $meta['patient_id'] ) ) {
        // Replace with a consistent pseudonym.
        $meta['patient_id'] = 'PSEUDO_' . hash_hmac( 'sha256', $meta['patient_id'], $site_salt );
        
        // Also pseudonymize the filename if it contains patient ID.
        $meta['_original_patient_id'] = $meta['patient_id']; // Keep for audit.
    }
    
    // Remove names but keep study data.
    unset( $meta['patient_name'] );
    unset( $meta['referring_physician_name'] );
    
    // Keep institution info for asset tracking but redact patient details.
    $meta['institution_name'] = $meta['institution_name'] ?? 'REDACTED';
    
    return $meta;
}, 10, 2 );
```

### Filter Signature

```php
/**
 * @param array  $meta     Extracted DICOM metadata. Contains keys like:
 *                         - patient_id
 *                         - patient_name
 *                         - patient_birth_date
 *                         - patient_sex
 *                         - referring_physician_name
 *                         - institution_name
 *                         - institution_address
 *                         - accession_number
 *                         - study_instance_uid
 *                         - series_instance_uid
 *                         - sop_instance_uid
 *                         - modality
 *                         - study_date
 *                         - study_description
 *                         - series_description
 * @param string $tmp_path Path to the uploaded DICOM file on disk.
 * @return array Modified metadata.
 */
apply_filters( 'wp_mcp_ai_dicom_strip_phi', $meta, $tmp_path );
```

## Deployment Recommendations

### For Healthcare Organizations

1. **Always** attach a PHI redaction callback to `wp_mcp_ai_dicom_strip_phi`
2. Store pseudonymized data only — keep the original DICOM files on a HIPAA-compliant PACS server
3. Use WordPress on a isolated server, not shared hosting
4. Enable at-rest encryption for the WordPress database
5. Configure audit logging (`enable_security_audit_log`) to track all DICOM access
6. Set short retention periods for uploaded DICOM files

### For Research / Non-Clinical Use

- If your DICOM files come from public datasets (e.g., The Cancer Imaging Archive) and contain no PHI, no action is needed
- Verify that `PatientName` and `PatientID` tags are truly anonymized before uploading

### For Plugin Development

When integrating with the DICOM workflow, always call through the filter:

```php
$meta = apply_filters( 'wp_mcp_ai_dicom_strip_phi', $meta, $file_path );
```

## Related Settings

| Setting | Page | Purpose |
|---------|------|---------|
| `enable_security_audit_log` | Security → Audit | Track DICOM access |
| `api_error_verbosity` | Security → Network | Control error detail (set to "Safe" in production) |
| `max_request_body_size_kb` | Security → Network | Limit DICOM upload size |
