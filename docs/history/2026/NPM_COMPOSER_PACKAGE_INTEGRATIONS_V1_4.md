# NPM & Composer Package Integrations — Pro Toolkit Enhancement (v1.4.0)

## Overview

This document summarises the implementation of new npm package integrations
(`franc`, `iso-639-1`, `libphonenumber-js`, `google-translate-api-x`) and
the `phpoffice/phpspreadsheet` Composer library into Pro toolkit tools that
previously had stub `execute()` implementations or were missing package wiring.

---

## New Node.js Services

| Service file | Package(s) | Actions |
|---|---|---|
| `node-services/lang-detect-service.js` | `franc`, `iso-639-1` | `detect`, `list_languages`, `validate_code` |
| `node-services/phone-format-service.js` | `libphonenumber-js` | `format`, `validate`, `parse` |
| `node-services/translate-service.js` | `google-translate-api-x` | `translate`, `detect` |

All services follow the existing pattern used by `qrcode-service.js` and
`ocr-service.js`: they accept a single JSON argument on `argv[3]`, write
`{"success":true,"result":…}` to stdout, and exit non-zero with a JSON error
on stderr on failure.

---

## New PHP Service

**`addons/pro/includes/services/class-wp-mcp-ai-language-detection-service.php`**

`WP_MCP_AI_Language_Detection_Service` wraps the three Node.js services above
via WordPress filter hooks and provides PHP-native fallbacks for environments
without Node.js.

### Key methods

| Method | Description |
|---|---|
| `detect_language( $text )` | Detects language via franc → PHP Unicode heuristics (CJK/Arabic/Cyrillic/Hebrew/Greek/Thai). Returns `code`, `name`, `confidence`, `alternatives`, `source`. |
| `get_language_name( $code )` | Looks up ISO 639-1 name via Node filter → built-in PHP map. |
| `validate_language_code( $code )` | Returns bool; checks built-in ISO 639-1 map. |
| `format_phone( $phone, $country_code )` | Formats phone via libphonenumber-js → basic digit-count fallback. |

---

## npm Filter Handlers (`npm-integration-filters.php`)

Five new filter handlers registered in `wp_mcp_ai_register_all_npm_filters()`:

| Filter hook | Handler | Node service |
|---|---|---|
| `wp_mcp_ai_lang_detect` | `wp_mcp_ai_lang_detect_handler()` | `lang-detect-service.js` |
| `wp_mcp_ai_lang_code_info` | `wp_mcp_ai_lang_code_info_handler()` | `lang-detect-service.js` |
| `wp_mcp_ai_phone_format` | `wp_mcp_ai_phone_format_handler()` | `phone-format-service.js` |
| `wp_mcp_ai_validator_phone` | `wp_mcp_ai_validator_phone_handler()` | `phone-format-service.js` |
| `wp_mcp_ai_translate_text` | `wp_mcp_ai_translate_text_handler()` | `translate-service.js` |

The `wp_mcp_ai_validator_phone` handler integrates directly with
`WP_MCP_AI_Validator_Service::is_phone_number()` — when Node.js is available
the libphonenumber-js result replaces the fallback digit-count check.

---

## Multilingual Toolkit — Stub → Real Implementations

### `detect_content_language`

* Accepts `text` (direct) or `post_id` (reads `post_title + post_content`).
* Calls `WP_MCP_AI_Language_Detection_Service::detect_language()`.
* Returns: `language_code`, `language_name`, `confidence`, `alternatives`, `source`, `message`.

### `localize_dates_currencies`

* **Date formatting** via `IntlDateFormatter` (PHP Intl extension) → `wp_date()` fallback.
* **Currency formatting** via `NumberFormatter::CURRENCY` → `number_format()` fallback.
* **Phone formatting** via `WP_MCP_AI_Language_Detection_Service::format_phone()` (libphonenumber-js).
* New schema parameters: `phone` (string), `country_code` (ISO 3166-1 alpha-2, default `US`).

### `translation_quality_check`

Three automated checks; each adds to a 0–100 quality score:

| Check | Deduction | Details |
|---|---|---|
| `completeness` | −30 | Word-count ratio (translation/source) outside 0.5–2.5 range |
| `consistency` | −25 | franc-detected language differs from `_translation_language` post meta |
| `formatting` | −20 | HTML tag count differs by > 20 % between source and translation |

Returns: `overall_score`, `rating` (`good` ≥ 80, `fair` ≥ 50, `poor` < 50), per-check `results` array.

---

## DJ Management

### `send_event_confirmation` — Enhanced Email Delivery

Four-tier delivery pipeline:

1. **MJML** (if `WP_MCP_AI_MJML_Service::is_available()`) — compiles a responsive HTML template with event details table and optional timeline.
2. **Nodemailer** (if `WP_MCP_AI_Nodemailer_Service::is_available()` and MJML compiled) — sends via configured SMTP with plain-text alternative.
3. **`wp_mail` HTML** — sends compiled MJML HTML directly via WordPress mailer.
4. **`wp_mail` plain-text** — final fallback with formatted plain-text body.

Response now includes `send_method` key: `nodemailer`, `wp_mail`, etc.

---

## CRM Toolkit

### `manage_crm_contact` — Phone Validation

Phone validation added to both `create` and `update` actions:

```php
// When 'phone' is present in contact_data:
$phone_valid = $validator->is_phone_number( $contact_data['phone'] );
if ( is_wp_error( $phone_valid ) ) {
    return [ 'success' => false, 'error' => $phone_valid->get_error_message() ];
}
```

When Node.js + libphonenumber-js are available, validation uses international
E.164 rules; otherwise falls back to digit-count (10–15 digits).

---

## Financial Planning

### `mortgage_calculator` — XLSX Amortization Export

New parameter: `export_xlsx` (boolean, default `false`).

When `true`, generates a full amortization schedule spreadsheet
(columns: Payment #, Payment, Principal, Interest, Balance) using
`phpoffice/phpspreadsheet` and saves it to
`wp-content/uploads/mcp-ai-wpoos/exports/amortization-{unique}.xlsx`.

Returns `amortization_xlsx` (public URL) or `amortization_xlsx_error` (message on failure).

```php
$result = $tool->execute([
    'loan_amount'   => 400000,
    'interest_rate' => 6.5,
    'export_xlsx'   => true,
]);
// $result['amortization_xlsx'] = 'https://…/mcp-ai-wpoos/exports/amortization-abc123.xlsx'
```

---

## DJ Management

### `equipment_inventory_report` — XLSX Inventory Export

New parameter: `export_xlsx` (boolean, default `false`).

When `true`, generates an XLSX spreadsheet with columns: Name, Type, Status,
Serial #, Purchase Price (if `include_values: true`), Last Maintenance.

Returns `inventory_xlsx` (public URL) or `inventory_xlsx_error` (message on failure).

```php
$result = $tool->execute([
    'status'       => 'available',
    'export_xlsx'  => true,
]);
// $result['inventory_xlsx'] = 'https://…/mcp-ai-wpoos/exports/dj-inventory-abc123.xlsx'
```

---

## Requirements

| Feature | Requirement |
|---|---|
| `detect_content_language` | Always works (PHP fallback) |
| `localize_dates_currencies` (date/currency) | PHP Intl extension (standard on PHP 7.4+) |
| `localize_dates_currencies` (phone) | Node.js + `libphonenumber-js` (graceful fallback) |
| `translation_quality_check` | Always works; franc improves consistency check |
| `send_event_confirmation` (MJML HTML) | Node.js + `mjml` package |
| `send_event_confirmation` (Nodemailer) | Node.js + `nodemailer` + `WP_MCP_AI_Nodemailer_Service` |
| `manage_crm_contact` phone validation | Node.js + `libphonenumber-js` (graceful fallback) |
| XLSX exports | `phpoffice/phpspreadsheet` in Pro vendor |
