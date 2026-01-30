# Regulatory Registration Toolkit - Complete Tool Reference

**Version:** Phase 2 Complete (32 Tools)  
**Status:** Production Ready ✅  
**Last Updated:** January 2026

---

## Table of Contents

1. [Product Management Tools](#product-management-tools) (8 tools)
2. [Registration Management Tools](#registration-management-tools) (10 tools)
3. [Document Management Tools](#document-management-tools) (8 tools)
4. [Compliance Tools](#compliance-tools) (6 tools)
5. [Common Patterns](#common-patterns)
6. [Capability Flags](#capability-flags)
7. [Example Workflows](#example-workflows)

---

## Product Management Tools

### 1. create_reg_product

**Purpose:** Create a new cosmetics/perfume product master record

**Arguments:**
- `title` (string, required) - Product name
- `category` (string, optional) - Product category (skincare, haircare, makeup, perfumes, fragrances)
- `brand` (string, optional) - Brand name
- `manufacturer` (string, optional) - Manufacturer name
- `origin_country` (string, optional) - Country of origin
- `inci_ingredients` (string, optional) - INCI ingredient list
- `hs_code` (string, optional) - Harmonized System code
- `pack_size` (string, optional) - Packaging size
- `barcode` (string, optional) - Product barcode

**Returns:**
```php
array(
    'success' => true,
    'product_id' => 123,
    'title' => 'Product Name',
    'status' => 'draft'
)
```

**Capability Flags:** `pro`, `database-write`

---

### 2. list_reg_products

**Purpose:** List products with filtering and pagination

**Arguments:**
- `category` (string, optional) - Filter by category
- `brand` (string, optional) - Filter by brand
- `status` (string, optional) - Filter by status (draft, published)
- `per_page` (int, optional) - Results per page (default: 10, max: 100)
- `page` (int, optional) - Page number (default: 1)

**Returns:**
```php
array(
    'success' => true,
    'products' => array( ... ),
    'total' => 50,
    'page' => 1,
    'per_page' => 10,
    'total_pages' => 5
)
```

**Capability Flags:** `pro`, `database-read`

---

### 3. get_reg_product

**Purpose:** Get detailed product information

**Arguments:**
- `product_id` (int, required) - Product ID
- `include_registrations` (bool, optional) - Include related registrations (default: false)

**Returns:**
```php
array(
    'success' => true,
    'product' => array(
        'id' => 123,
        'title' => 'Product Name',
        'category' => 'skincare',
        'brand' => 'Brand Name',
        'manufacturer' => 'Manufacturer',
        'inci_ingredients' => 'Aqua, Glycerin, ...',
        'hs_code' => '330410',
        'registrations' => array( ... ) // if include_registrations = true
    )
)
```

**Capability Flags:** `pro`, `database-read`

---

### 4. update_reg_product

**Purpose:** Update product metadata (conditional updates)

**Arguments:**
- `product_id` (int, required) - Product ID
- `title` (string, optional) - New title
- `category` (string, optional) - New category
- `brand` (string, optional) - New brand
- `manufacturer` (string, optional) - New manufacturer
- `inci_ingredients` (string, optional) - New INCI list
- `hs_code` (string, optional) - New HS code
- (other fields optional)

**Returns:**
```php
array(
    'success' => true,
    'product_id' => 123,
    'updated_fields' => array('title', 'manufacturer')
)
```

**Capability Flags:** `pro`, `database-write`

**Note:** Only provided fields are updated (conditional update pattern)

---

### 5. delete_reg_product

**Purpose:** Delete a product (destructive operation)

**Arguments:**
- `product_id` (int, required) - Product ID
- `force` (bool, optional) - Force delete even with registrations (default: false)

**Returns:**
```php
array(
    'success' => true,
    'product_id' => 123,
    'deleted' => true
)
```

**Capability Flags:** `pro`, `database-write`, `destructive`

**Warning:** Destructive operation. Requires explicit capability check.

---

### 6. search_reg_products

**Purpose:** Advanced search with combined meta and taxonomy queries

**Arguments:**
- `search_term` (string, optional) - Search in title/description
- `category` (string, optional) - Filter by category
- `brand` (string, optional) - Filter by brand
- `manufacturer` (string, optional) - Filter by manufacturer meta
- `inci_ingredients` (string, optional) - Search INCI ingredients
- `hs_code` (string, optional) - Filter by HS code
- `per_page` (int, optional) - Results per page (default: 10, max: 100)

**Returns:**
```php
array(
    'success' => true,
    'products' => array( ... ),
    'total' => 25,
    'filters_applied' => array('brand', 'hs_code')
)
```

**Capability Flags:** `pro`, `database-read`

---

### 7. duplicate_reg_product

**Purpose:** Clone a product with selective data copying

**Arguments:**
- `source_product_id` (int, required) - Product to duplicate
- `new_title` (string, required) - Title for duplicated product
- `copy_registrations` (bool, optional) - Copy registrations (default: false)
- `copy_documents` (bool, optional) - Copy documents (default: false)

**Returns:**
```php
array(
    'success' => true,
    'source_product_id' => 123,
    'new_product_id' => 456,
    'registrations_copied' => 3,
    'documents_copied' => 5
)
```

**Capability Flags:** `pro`, `database-write`, `idempotent`

---

### 8. validate_reg_product

**Purpose:** Comprehensive product validation

**Arguments:**
- `product_id` (int, required) - Product to validate
- `target_country` (string, optional) - Country code for country-specific validation

**Returns:**
```php
array(
    'success' => true,
    'is_valid' => true,
    'completeness' => array(
        'is_complete' => false,
        'score' => 85,
        'required_missing' => array('manufacturer'),
        'optional_missing' => array('barcode')
    ),
    'inci_validation' => array(
        'is_valid' => true,
        'errors' => array(),
        'warnings' => array()
    ),
    'hs_code_validation' => array(
        'is_valid' => true,
        'chapter' => '33',
        'category' => 'Cosmetics'
    ),
    'ready_for_registration' => false
)
```

**Capability Flags:** `pro`, `database-read`, `read-only`

---

## Registration Management Tools

### 9. create_registration

**Purpose:** Create a registration instance for a specific country

**Arguments:**
- `product_id` (int, required) - Product ID
- `country` (string, required) - Country code (LK, AE, SA, etc.)
- `authority` (string, required) - Regulatory authority (NMRA, MOHAP, SFDA)
- `registration_type` (string, optional) - new, renewal, variation (default: 'new')
- `status` (string, optional) - Initial status (default: 'draft')

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'product_id' => 123,
    'country' => 'LK',
    'authority' => 'NMRA'
)
```

**Capability Flags:** `pro`, `database-write`

---

### 10. list_registrations

**Purpose:** List registrations with filtering

**Arguments:**
- `product_id` (int, optional) - Filter by product
- `country` (string, optional) - Filter by country
- `status` (string, optional) - Filter by status
- `per_page` (int, optional) - Results per page (default: 10, max: 100)

**Returns:**
```php
array(
    'success' => true,
    'registrations' => array( ... ),
    'total' => 30,
    'page' => 1
)
```

**Capability Flags:** `pro`, `database-read`

---

### 11. get_registration

**Purpose:** Get detailed registration information

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `include_product` (bool, optional) - Include product details (default: false)
- `include_documents` (bool, optional) - Include documents (default: false)

**Returns:**
```php
array(
    'success' => true,
    'registration' => array(
        'id' => 789,
        'product_id' => 123,
        'country' => 'LK',
        'authority' => 'NMRA',
        'status' => 'approved',
        'cos_number' => 'COS-123456',
        'submission_date' => '2025-01-15',
        'approval_date' => '2025-06-20',
        'expiry_date' => '2028-06-20',
        'product' => array( ... ), // if include_product = true
        'documents' => array( ... ) // if include_documents = true
    )
)
```

**Capability Flags:** `pro`, `database-read`

---

### 12. update_registration_status

**Purpose:** Update registration status with automatic date tracking

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `new_status` (string, required) - New status (draft, pending, submitted, under_review, approved, etc.)

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'old_status' => 'submitted',
    'new_status' => 'approved',
    'dates_updated' => array('approval_date')
)
```

**Capability Flags:** `pro`, `database-write`

**Auto-Date Assignment:**
- `submitted` → Sets `submission_date`
- `approved` → Sets `approval_date` and `expiry_date` (approval + 3 years)

---

### 13. list_expiring_registrations

**Purpose:** Find registrations expiring soon (proactive renewal tracking)

**Arguments:**
- `days_threshold` (int, optional) - Days until expiry (default: 90)
- `country` (string, optional) - Filter by country
- `per_page` (int, optional) - Results per page (default: 10)

**Returns:**
```php
array(
    'success' => true,
    'registrations' => array(
        array(
            'id' => 789,
            'product_title' => 'Product Name',
            'country' => 'LK',
            'expiry_date' => '2026-03-15',
            'days_to_expiry' => 45,
            'alert_level' => 'warning' // critical, warning, ok
        )
    ),
    'total' => 5
)
```

**Capability Flags:** `pro`, `database-read`, `cacheable`

---

### 14. submit_registration

**Purpose:** Workflow helper to submit registration

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `submission_date` (string, optional) - Date (YYYY-MM-DD), defaults to today

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'status' => 'submitted',
    'submission_date' => '2026-01-15'
)
```

**Capability Flags:** `pro`, `database-write`

**Workflow:** Changes status to 'submitted' and sets submission_date

---

### 15. approve_registration

**Purpose:** Workflow helper to approve registration

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `cos_number` (string, required) - Certificate of Suitability number
- `approval_date` (string, optional) - Date (YYYY-MM-DD), defaults to today
- `expiry_years` (int, optional) - Years until expiry (default: 3)

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'status' => 'approved',
    'cos_number' => 'COS-123456',
    'approval_date' => '2026-01-15',
    'expiry_date' => '2029-01-15'
)
```

**Capability Flags:** `pro`, `database-write`

**Workflow:** Changes status to 'approved', sets approval_date, cos_number, and calculates expiry_date

---

### 16. renew_registration

**Purpose:** Create renewal registration from expiring registration

**Arguments:**
- `original_registration_id` (int, required) - Expiring registration ID

**Returns:**
```php
array(
    'success' => true,
    'original_registration_id' => 789,
    'renewal_registration_id' => 790,
    'registration_type' => 'renewal',
    'original_cos_number' => 'COS-123456'
)
```

**Capability Flags:** `pro`, `database-write`

**Workflow:** Creates new registration with type='renewal', links to original, resets dates and status

---

### 17. get_registration_timeline

**Purpose:** Calculate registration milestones and deadlines

**Arguments:**
- `registration_id` (int, required) - Registration ID

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'country' => 'LK',
    'timeline' => array(
        'preparation_days' => 45,
        'review_days' => 120,
        'total_expected_days' => 210
    ),
    'milestones' => array(
        array(
            'name' => 'Preparation Complete',
            'expected_date' => '2026-03-01',
            'status' => 'completed'
        ),
        array(
            'name' => 'Submission',
            'expected_date' => '2026-03-15',
            'status' => 'completed'
        ),
        array(
            'name' => 'Expected Approval',
            'expected_date' => '2026-07-12',
            'status' => 'pending'
        )
    ),
    'progress_percentage' => 65,
    'days_since_submission' => 120,
    'days_to_expected_approval' => 59
)
```

**Capability Flags:** `pro`, `database-read`, `read-only`, `cacheable`

**Country Timelines:** LK: 210 days, AE: 119 days, SA: 165 days, Other: 180 days

---

### 18. list_registrations_by_country

**Purpose:** Group registrations by country with statistics

**Arguments:**
- `include_stats` (bool, optional) - Include country statistics (default: true)
- `per_page` (int, optional) - Results per page (default: 100)

**Returns:**
```php
array(
    'success' => true,
    'countries' => array(
        'LK' => array(
            'country_name' => 'Sri Lanka',
            'authority' => 'NMRA',
            'registrations' => array( ... ),
            'stats' => array(
                'total' => 10,
                'approved' => 7,
                'pending' => 2,
                'expired' => 1,
                'expiring_soon' => 2
            )
        ),
        'AE' => array( ... )
    ),
    'total_registrations' => 25,
    'countries_count' => 3
)
```

**Capability Flags:** `pro`, `database-read`, `cacheable`

---

## Document Management Tools

### 19. list_reg_documents

**Purpose:** List documents with filtering

**Arguments:**
- `product_id` (int, optional) - Filter by product
- `registration_id` (int, optional) - Filter by registration
- `document_type` (string, optional) - Filter by type (loa, fsc, coa, gmp, etc.)
- `expiry_status` (string, optional) - Filter by expiry (valid, expiring_soon, expired)
- `per_page` (int, optional) - Results per page (default: 10)

**Returns:**
```php
array(
    'success' => true,
    'documents' => array( ... ),
    'total' => 15,
    'page' => 1
)
```

**Capability Flags:** `pro`, `database-read`

---

### 20. check_document_expiry

**Purpose:** Check document expiry status with alerts

**Arguments:**
- `document_id` (int, required) - Document ID

**Returns:**
```php
array(
    'success' => true,
    'document_id' => 456,
    'expiry_date' => '2026-06-30',
    'days_to_expiry' => 45,
    'alert_level' => 'warning', // critical (<0), warning (<=90), ok (>90)
    'is_expired' => false,
    'is_expiring_soon' => true
)
```

**Capability Flags:** `pro`, `database-read`, `read-only`

---

### 21. upload_reg_document

**Purpose:** Upload document with metadata

**Arguments:**
- `title` (string, required) - Document title
- `product_id` or `registration_id` (int, required) - Attach to product or registration
- `document_type` (string, required) - Type (loa, fsc, coa, gmp, msds, pif, artwork, formula, iso)
- `file_url` or `file_data` (string, required) - File URL or base64 data
- `file_name` (string, optional) - Filename
- `issue_date` (string, optional) - Issue date (YYYY-MM-DD)
- `expiry_date` (string, optional) - Expiry date (YYYY-MM-DD)
- `version` (string, optional) - Version number

**Returns:**
```php
array(
    'success' => true,
    'document_id' => 456,
    'title' => 'Letter of Authorization',
    'file_url' => 'https://...',
    'document_type' => 'loa'
)
```

**Capability Flags:** `pro`, `database-write`, `file-upload`

**Allowed File Types:** PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG

---

### 22. update_reg_document

**Purpose:** Update document metadata

**Arguments:**
- `document_id` (int, required) - Document ID
- `title` (string, optional) - New title
- `status` (string, optional) - Status (draft, pending, approved, rejected)
- `expiry_date` (string, optional) - New expiry date
- `version` (string, optional) - New version
- (other fields optional)

**Returns:**
```php
array(
    'success' => true,
    'document_id' => 456,
    'updated_fields' => array('status', 'expiry_date')
)
```

**Capability Flags:** `pro`, `database-write`

---

### 23. get_reg_document

**Purpose:** Get detailed document information

**Arguments:**
- `document_id` (int, required) - Document ID
- `include_product` (bool, optional) - Include product details (default: false)
- `include_registration` (bool, optional) - Include registration details (default: false)

**Returns:**
```php
array(
    'success' => true,
    'document' => array(
        'id' => 456,
        'title' => 'Letter of Authorization',
        'document_type' => 'loa',
        'file_url' => 'https://...',
        'issue_date' => '2025-01-01',
        'expiry_date' => '2026-06-30',
        'days_to_expiry' => 45,
        'expiry_status' => 'expiring_soon',
        'version' => '1.0',
        'product' => array( ... ), // if include_product = true
        'registration' => array( ... ) // if include_registration = true
    )
)
```

**Capability Flags:** `pro`, `database-read`

---

### 24. validate_document_checklist

**Purpose:** Validate required documents for country

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `country` (string, optional) - Country code (uses registration's country if not provided)

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'country' => 'LK',
    'required_documents' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'iso'),
    'present_documents' => array('loa', 'fsc', 'coa', 'gmp'),
    'missing_documents' => array('msds', 'pif', 'artwork', 'formula', 'iso'),
    'expired_documents' => array(),
    'completion_percentage' => 44.44,
    'compliance_status' => 'partially_compliant', // compliant, partially_compliant, non_compliant
    'is_ready_for_submission' => false
)
```

**Capability Flags:** `pro`, `database-read`, `read-only`

**Country Requirements:**
- LK: 9 documents (loa, fsc, coa, gmp, msds, pif, artwork, formula, iso)
- AE: 8 documents (all except iso)
- SA: 9 documents (same as LK)

---

### 25. generate_submission_pack

**Purpose:** Bundle documents for regulatory submission

**Arguments:**
- `registration_id` (int, required) - Registration ID
- `include_cover_letter` (bool, optional) - Generate cover letter (default: true)
- `include_index` (bool, optional) - Generate document index (default: true)

**Returns:**
```php
array(
    'success' => true,
    'registration_id' => 789,
    'pack_id' => 999,
    'documents_included' => 9,
    'cover_letter_url' => 'https://...',
    'index_url' => 'https://...',
    'submission_pack_meta' => array(
        'generated_date' => '2026-01-15',
        'country' => 'LK',
        'authority' => 'NMRA',
        'product' => 'Product Name'
    )
)
```

**Capability Flags:** `pro`, `database-write`, `file-upload`

**Validation:** Prevents generation if required documents are missing

---

### 26. track_document_version

**Purpose:** Document version history management

**Arguments:**
- `document_id` (int, required) - Document ID
- `action` (string, required) - Action: 'get_history', 'create_version', 'compare_versions'
- `new_version` (string, optional) - New version number (for create_version)
- `new_file_url` (string, optional) - New file URL (for create_version)
- `change_notes` (string, optional) - Change notes (for create_version)
- `compare_version_1` (string, optional) - First version to compare
- `compare_version_2` (string, optional) - Second version to compare

**Returns (get_history):**
```php
array(
    'success' => true,
    'document_id' => 456,
    'current_version' => '2.0',
    'version_history' => array(
        array(
            'version' => '1.0',
            'file_url' => 'https://...',
            'created_at' => '2025-01-01',
            'notes' => ''
        ),
        array(
            'version' => '2.0',
            'file_url' => 'https://...',
            'created_at' => '2026-01-15',
            'notes' => 'Updated manufacturer details'
        )
    )
)
```

**Capability Flags:** `pro`, `database-read` (get_history), `database-write` (create_version)

---

## Compliance Tools

### 27. add_regulatory_requirement

**Purpose:** Create country-specific regulatory requirement

**Arguments:**
- `country` (string, required) - Country code
- `authority` (string, required) - Regulatory authority
- `requirement_type` (string, required) - Type: document, test, certification, ingredient_restriction
- `name` (string, required) - Requirement name
- `description` (string, optional) - Description
- `is_mandatory` (bool, optional) - Is mandatory (default: true)
- `product_categories` (array, optional) - Applicable categories
- `effective_date` (string, optional) - Effective date
- `reference_url` (string, optional) - Reference URL

**Returns:**
```php
array(
    'success' => true,
    'requirement_id' => 111,
    'country' => 'LK',
    'name' => 'GMP Certificate Required'
)
```

**Capability Flags:** `pro`, `database-write`

---

### 28. get_regulatory_requirements

**Purpose:** List regulatory requirements with filtering

**Arguments:**
- `country` (string, optional) - Filter by country
- `authority` (string, optional) - Filter by authority
- `requirement_type` (string, optional) - Filter by type
- `product_category` (string, optional) - Filter by category
- `mandatory_only` (bool, optional) - Only mandatory requirements (default: false)
- `per_page` (int, optional) - Results per page (default: 20)

**Returns:**
```php
array(
    'success' => true,
    'requirements' => array( ... ),
    'total' => 15,
    'filters_applied' => array('country', 'mandatory_only')
)
```

**Capability Flags:** `pro`, `database-read`, `cacheable`

---

### 29. check_product_compliance

**Purpose:** Validate product against regulatory requirements

**Arguments:**
- `product_id` (int, required) - Product ID
- `country` (string, required) - Target country
- `registration_id` (int, optional) - Registration ID for document checks

**Returns:**
```php
array(
    'success' => true,
    'product_id' => 123,
    'country' => 'LK',
    'compliance_score' => 85.5,
    'compliance_status' => 'partially_compliant', // compliant, partially_compliant, non_compliant
    'requirements_checked' => 10,
    'requirements_met' => 8,
    'compliance_issues' => array(
        array(
            'type' => 'critical',
            'requirement' => 'GMP Certificate',
            'message' => 'GMP certificate is missing or expired'
        ),
        array(
            'type' => 'warning',
            'requirement' => 'Product testing',
            'message' => 'Some tests may be outdated'
        )
    ),
    'recommendations' => array(
        'Upload valid GMP certificate',
        'Update product testing documentation'
    )
)
```

**Capability Flags:** `pro`, `database-read`, `read-only`

---

### 30. validate_inci_ingredients

**Purpose:** Validate INCI ingredient nomenclature

**Arguments:**
- `inci_string` (string, required) - INCI ingredient list
- `country` (string, optional) - Country for country-specific restrictions

**Returns:**
```php
array(
    'success' => true,
    'is_valid' => true,
    'ingredients_count' => 15,
    'validation_score' => 95,
    'errors' => array(),
    'warnings' => array(
        'Ingredient "Parfum" is acceptable but consider listing components'
    ),
    'restricted_substances' => array(),
    'recommendations' => array(
        'Ensure ingredients are listed in descending order by weight'
    )
)
```

**Capability Flags:** `pro`, `read-only`

**Validation Checks:**
- Format validation (capitalization, characters)
- Common name lookup (Aqua → Water)
- Restricted substance detection
- Country-specific restrictions
- INCI database matching

---

### 31. check_hs_code

**Purpose:** Validate Harmonized System code for cosmetics

**Arguments:**
- `hs_code` (string, required) - HS code (6-10 digits)
- `product_type` (string, optional) - Product type for category matching

**Returns:**
```php
array(
    'success' => true,
    'hs_code' => '330410',
    'is_valid' => true,
    'format_valid' => true,
    'chapter' => '33',
    'chapter_description' => 'Essential oils and resinoids; perfumery, cosmetic or toilet preparations',
    'category' => 'Lip makeup preparations',
    'category_code' => '3304.10',
    'errors' => array(),
    'warnings' => array(),
    'suggestions' => array(
        '3304.10' => 'Lip makeup preparations',
        '3304.20' => 'Eye makeup preparations'
    )
)
```

**Capability Flags:** `pro`, `read-only`

**Validation Rules:**
- Must be 6-10 digits
- Must start with Chapter 33 (cosmetics)
- Format: XXXX.XX or XXXXXXXX

**Common Cosmetics HS Codes:**
- 3303: Perfumes and toilet waters
- 3304: Beauty/makeup preparations
- 3305: Hair preparations
- 3306: Oral/dental hygiene
- 3307: Pre-shave, shaving, after-shave preparations

---

### 32. get_regulatory_updates

**Purpose:** Track regulatory changes and updates

**Arguments:**
- `country` (string, optional) - Filter by country
- `authority` (string, optional) - Filter by authority
- `date_from` (string, optional) - Start date (YYYY-MM-DD), defaults to 30 days ago
- `date_to` (string, optional) - End date (YYYY-MM-DD), defaults to today
- `update_type` (string, optional) - Filter by type (new_regulation, amendment, guideline, restriction)
- `per_page` (int, optional) - Results per page (default: 10)

**Returns:**
```php
array(
    'success' => true,
    'updates' => array(
        array(
            'id' => 555,
            'country' => 'LK',
            'authority' => 'NMRA',
            'update_type' => 'amendment',
            'title' => 'Updated import requirements for cosmetics',
            'description' => 'New documentation requirements...',
            'effective_date' => '2026-02-01',
            'reference_url' => 'https://...',
            'published_date' => '2026-01-01'
        )
    ),
    'total' => 5,
    'date_range' => array(
        'from' => '2025-12-15',
        'to' => '2026-01-15'
    )
)
```

**Capability Flags:** `pro`, `database-read`, `cacheable`

---

## Common Patterns

### Conditional Updates

Many update tools only modify fields that are explicitly provided:

```php
// Only updates manufacturer, leaves other fields unchanged
update_reg_product(array(
    'product_id' => 123,
    'manufacturer' => 'New Manufacturer Name'
))
```

### Pagination

List tools support pagination with consistent parameters:

```php
list_reg_products(array(
    'per_page' => 20,  // Max 100
    'page' => 2
))
```

### Status Transitions

Registration status tools automatically track dates:

```php
// Sets submission_date automatically
submit_registration(array('registration_id' => 789))

// Sets approval_date and calculates expiry_date
approve_registration(array(
    'registration_id' => 789,
    'cos_number' => 'COS-123456'
))
```

### Alert Levels

Expiry-related tools use consistent alert levels:

- **critical**: Expired (days_to_expiry < 0)
- **warning**: Expiring soon (days_to_expiry <= 90)
- **ok**: Valid (days_to_expiry > 90)

### Compliance Status

Compliance tools use standard status values:

- **compliant**: 100% requirements met
- **partially_compliant**: 50-99% requirements met
- **non_compliant**: <50% requirements met

---

## Capability Flags

### Access Control Flags

- **`pro`**: Pro addon required
- **`database-read`**: Read-only database access
- **`database-write`**: Write access to database
- **`file-upload`**: File upload capability required
- **`destructive`**: Destructive operation (delete)

### Behavior Flags

- **`read-only`**: No side effects, safe for repeated calls
- **`idempotent`**: Multiple calls produce same result
- **`cacheable`**: Results can be cached for performance

---

## Example Workflows

### Complete Registration Flow

```php
// 1. Create product
$product = create_reg_product(array(
    'title' => 'Hydrating Face Cream',
    'category' => 'skincare',
    'brand' => 'BeautyBrand',
    'manufacturer' => 'ManufacturerCo',
    'inci_ingredients' => 'Aqua, Glycerin, Cetearyl Alcohol',
    'hs_code' => '330499'
));

// 2. Validate product
$validation = validate_reg_product(array(
    'product_id' => $product['product_id'],
    'target_country' => 'LK'
));

// 3. Create registration
$registration = create_registration(array(
    'product_id' => $product['product_id'],
    'country' => 'LK',
    'authority' => 'NMRA'
));

// 4. Upload documents
upload_reg_document(array(
    'registration_id' => $registration['registration_id'],
    'title' => 'Letter of Authorization',
    'document_type' => 'loa',
    'file_url' => 'https://...'
));

// 5. Validate document checklist
$checklist = validate_document_checklist(array(
    'registration_id' => $registration['registration_id']
));

// 6. Submit registration
submit_registration(array(
    'registration_id' => $registration['registration_id']
));

// 7. Check timeline
$timeline = get_registration_timeline(array(
    'registration_id' => $registration['registration_id']
));

// 8. Approve registration
approve_registration(array(
    'registration_id' => $registration['registration_id'],
    'cos_number' => 'COS-LK-2026-001'
));
```

### Compliance Checking Workflow

```php
// 1. Validate INCI ingredients
$inci_check = validate_inci_ingredients(array(
    'inci_string' => 'Aqua, Glycerin, Cetearyl Alcohol',
    'country' => 'LK'
));

// 2. Validate HS code
$hs_check = check_hs_code(array(
    'hs_code' => '330499',
    'product_type' => 'face cream'
));

// 3. Check overall compliance
$compliance = check_product_compliance(array(
    'product_id' => 123,
    'country' => 'LK',
    'registration_id' => 789
));
```

### Renewal Workflow

```php
// 1. Find expiring registrations
$expiring = list_expiring_registrations(array(
    'days_threshold' => 90
));

// 2. Create renewal
$renewal = renew_registration(array(
    'original_registration_id' => 789
));

// 3. Continue with normal registration flow...
```

---

## Documentation Version

**Version:** Phase 2 Complete  
**Tools:** 32  
**Last Updated:** January 2026  
**Status:** Production Ready ✅

For implementation details, see:
- `/docs/regulatory-registration-implementation-summary.md`
- `/docs/regulatory-registration-multi-agent-architecture.md`
- `/addons/pro/tests/test-regulatory-registration-ajax.php`
- `/addons/pro/tests/test-regulatory-registration-agentic-workflow.php`
