# WordPress.org 18-Point Code Review — Findings

**Plugin:** NV oOS Graphify v1.0.0
**Review Date:** 2026-08-03
**Reviewer:** AI Agent (wp-security-audit + wp-i18n-audit skills)

---

## Summary

**Overall Verdict:** Well-architected, security-conscious plugin. Minor, easily fixable issues.
**Estimated time to fix:** ~30 minutes.

---

## Critical Issues

### 1. [CRITICAL — Guideline 11] Site-Wide Undismissible Admin Notices
**File:** `src/Plugin.php` L311-333
**Method:** `Plugin::renderAdminNotices()`

Two admin notices are missing the `is-dismissible` CSS class and appear on **every admin page** (not scoped to the plugin's own settings page):

- **OpenSSL warning** (L311-315): Missing `is-dismissible` class.
- **"Not enabled" warning** (L328-333): Missing `is-dismissible` class.

WordPress.org guideline 11 requires: *"Site wide notices or embedded dashboard widgets must be dismissible or self-dismiss when resolved."*

**Fix:** Add `is-dismissible` class to both notices. Consider scoping them to only appear on the plugin's settings page.

---

## Warning Issues

### 2. [WARNING — Security] `esc_html()` Used in JSON-LD Output
**File:** `src/Frontend/SchemaOrg.php` L61-63
**Method:** `SchemaOrg::inject()`

```php
$about[] = array(
    '@type' => 'Thing',
    'name'  => esc_html( $targetNode->label ),  // ❌ HTML entities in JSON-LD
    'url'   => esc_url( $targetNode->url ),
);
```

`esc_html()` converts `&` to `&amp;`, `<` to `&lt;`, etc. These HTML entity references are **not valid** in JSON-LD context — JSON parsers do not decode them. Google's Structured Data Testing Tool may flag these as malformed.

**Fix:** Use `wp_strip_all_tags()` instead, since `wp_json_encode()` handles UTF-8 encoding natively.

### 3. [WARNING — i18n] Untranslatable Legend Text in Graph Explorer
**File:** `src/Admin/GraphExplorer.php` L136-140
**Method:** `GraphExplorer::renderPage()`

```php
<span class="legend-dot" style="background:#e74c3c"></span> Post
<span class="legend-dot" style="background:#3498db"></span> Page
<span class="legend-dot" style="background:#2ecc71"></span> Term
<span class="legend-dot" style="background:#f39c12"></span> User
```

These hardcoded strings are not wrapped in `esc_html__()` and will not appear in translation templates.

**Fix:** Wrap each legend label with `esc_html__()`.

### 4. [WARNING — Guideline 7] Missing Privacy Notice in readme.txt
**File:** `readme.txt`

The plugin stores remote source credentials (API keys, tokens, passwords) in the database using AES-256-GCM encryption. WordPress.org guideline 7 requires: *"Documentation on how any user data is collected, and used, should be included in the plugin's readme, preferably with a clearly stated privacy policy."*

**Fix:** Add a brief privacy notice section to the readme.

### 5. [WARNING — Guideline 2] Missing Third-Party License Attribution
**File:** `readme.txt` (or new file)

The plugin vendors:
- Cytoscape.js v3.28.1 (MIT)
- cytoscape-fcose v2.2.0 (MIT)

WordPress.org expects third-party library licenses to be acknowledged.

**Fix:** Add a third-party licenses section to the readme or include license files in `assets/vendor/`.

---

## Info / Minor

### 6. [INFO] Webhook Permission Callback Returns `true`
**File:** `src/Rest/Controller.php` L858
**Method:** `Controller::checkWebhookPermission()`

```php
public function checkWebhookPermission(): bool {
    return true;
}
```

The HMAC-SHA256 signature verification is correctly performed inside `receiveWebhook()`. This is well-documented in the code. Automated scanners may flag this — no action required, but consider adding defense-in-depth if desired.

### 7. [INFO] i18n Audit Complete
Full internationalization audit performed:
- Text Domain: `nvoos-graphify` — consistent throughout ✅
- Domain Path: `/languages` — declared in plugin header ✅
- Proper `__()`, `esc_html__()`, `esc_attr__()` usage ✅
- Proper `sprintf()` with translator comments ✅
- No variable text-domains ✅
- No concatenation inside `__()` calls ✅
- 2 untranslated strings found (item #3 above)

---

## Security Audit Summary

| Check | Status | Notes |
|---|---|---|
| Nonce verification | ✅ | All AJAX + form endpoints protected |
| Capability checks | ✅ | Proper checks on all privileged operations |
| Input sanitization | ✅ | Comprehensive in REST, AJAX, DB layer |
| Output escaping | ⚠️ | SchemaOrg JSON-LD issue (item #2) |
| SQL preparation | ✅ | `$wpdb->prepare()` everywhere |
| SSRF protection | ✅ | `HttpClient` blocks private/loopback IPs |
| Credential storage | ✅ | AES-256-GCM via OpenSSL |
| File operations | N/A | No file uploads in core plugin |
| Direct DB queries | ✅ | Properly documented with phpcs suppression |
| Error handling | ✅ | WP_Error + try/catch + shutdown handler |

---

## Files Changed Summary

| File | Change | Issue # |
|---|---|---|
| `src/Plugin.php` | Add `is-dismissible` class to admin notices | #1 |
| `src/Frontend/SchemaOrg.php` | Replace `esc_html()` with `wp_strip_all_tags()` in JSON-LD | #2 |
| `src/Admin/GraphExplorer.php` | Add `esc_html__()` wrappers on legend labels | #3 |
| `readme.txt` | Add privacy notice and third-party license sections | #4, #5 |
