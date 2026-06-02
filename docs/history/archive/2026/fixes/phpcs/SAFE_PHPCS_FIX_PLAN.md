# Safe PHPCS Violation Fixes - Implementation Plan

**Generated:** 2026-02-01  
**Status:** Planning Phase  
**Author:** GitHub Copilot Agent  

---

## Executive Summary

After running PHPCBF auto-fix, **186 errors and 481 warnings remain**. This document provides a safety-categorized plan for addressing these violations.

### Quick Stats
- ✅ **Safe to fix:** 35 violations (Low risk)
- ⚠️ **Review required:** 36 violations (Medium risk)
- 🚫 **Suppress only:** 127 violations (High risk - breaking changes)
- 📝 **Intentional:** 469 violations (No action needed)

---

## 🎯 Phase 1: Quick Wins (1-2 hours)

### ✅ Missing @throws Tags (12 instances)
**Risk Level:** ⭐ Low (Documentation only)

**What:** Add `@throws` tags to function docblocks that throw exceptions

**Example:**
```php
/**
 * Upload and process file.
 * 
 * @param array $file File data.
 * @return int Attachment ID.
 * @throws WP_Error If upload fails.  // ← ADD THIS
 */
public function process_upload( $file ) {
    if ( ! $this->validate( $file ) ) {
        throw new WP_Error( 'invalid_file' );
    }
}
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Squiz.Commenting.FunctionComment.ThrowTagMissing
```

---

### ✅ Missing Translator Comments (4 instances)
**Risk Level:** ⭐ Low (Documentation only)

**What:** Add translator context comments above i18n strings

**Example:**
```php
// Before
$title = __( 'Settings', 'mcp-ai-wpoos' );

// After
/* translators: Admin page title for plugin settings */
$title = __( 'Settings', 'mcp-ai-wpoos' );
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.WP.I18n.MissingTranslatorsComment
```

---

### ✅ Lonely If Statements (6 instances)
**Risk Level:** ⭐ Low (Code style)

**What:** Combine unnecessary nested if statements

**Example:**
```php
// Before
if ( $user_can_edit ) {
    if ( $post_is_published ) {
        update_post_meta( $post_id, '_edited', true );
    }
}

// After
if ( $user_can_edit && $post_is_published ) {
    update_post_meta( $post_id, '_edited', true );
}
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Universal.ControlStructures.DisallowLonelyIf
```

---

## ⚙️ Phase 2: Performance & Safety (2-4 hours)

### ✅ Cache Array Sizes in Loops (3 instances)
**Risk Level:** ⭐⭐ Medium (Performance impact)

**What:** Move `count()` calls outside of loop conditions

**Example:**
```php
// Before (count() called every iteration)
for ( $i = 0; $i < count( $items ); $i++ ) {
    process_item( $items[ $i ] );
}

// After (count() called once)
$item_count = count( $items );
for ( $i = 0; $i < $item_count; $i++ ) {
    process_item( $items[ $i ] );
}
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Squiz.PHP.DisallowSizeFunctionsInLoops
```

**Benefits:** Improves loop performance, especially with large arrays

---

### ⚠️ Nonce Verification (4 instances)
**Risk Level:** ⭐⭐⭐⭐ High (Security)

**What:** Add proper nonce verification to form handlers

**Example:**
```php
// Before (SECURITY ISSUE)
if ( isset( $_POST['save_settings'] ) ) {
    update_option( 'my_setting', $_POST['value'] );
}

// After (SECURE)
if ( isset( $_POST['save_settings'] ) ) {
    // Verify nonce
    check_admin_referer( 'my_settings_action', 'my_settings_nonce' );
    
    // Sanitize and save
    $value = sanitize_text_field( wp_unslash( $_POST['value'] ) );
    update_option( 'my_setting', $value );
}
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.Security.NonceVerification
```

**⚠️ IMPORTANT:** Review each instance carefully - some may be REST API endpoints that use different authentication

---

### ⚠️ Global Variable Override (6 instances)
**Risk Level:** ⭐⭐⭐ Medium-High (Can break WordPress)

**What:** Rename variables that override WordPress globals

**Example:**
```php
// Before (BAD - overrides $post global)
function process_post( $post_id ) {
    $post = get_post( $post_id );  // ← Overrides global $post
    // ...
}

// After (GOOD - uses different variable name)
function process_post( $post_id ) {
    $current_post = get_post( $post_id );
    // ...
}
```

**Common globals to avoid:**
- `$post`, `$wp_query`, `$wpdb`, `$wp`, `$wp_rewrite`
- `$wp_admin_bar`, `$wp_roles`, `$wp_taxonomies`

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.WP.GlobalVariablesOverride
```

---

## 🎨 Phase 3: Code Style (2-3 hours)

### ✅ Short Ternary Operators (13 instances)
**Risk Level:** ⭐⭐ Low-Medium (Logic change if done wrong)

**What:** Convert short ternary `?:` to full ternary `? :`

**Example:**
```php
// Before (short ternary)
$name = $user->display_name ?: 'Anonymous';

// After (full ternary)
$name = $user->display_name ? $user->display_name : 'Anonymous';

// Or use null coalescing (PHP 7+)
$name = $user->display_name ?? 'Anonymous';
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=Universal.Operators.DisallowShortTernary
```

**Note:** WordPress coding standards discourage short ternary for clarity

---

### ⚠️ Yoda Conditions (29 instances)
**Risk Level:** ⭐⭐ Medium (Easy to get wrong)

**What:** Convert comparisons to Yoda style (constant/literal first)

**WordPress Standard:**
```php
// Comparisons should use Yoda conditions
if ( true === $is_active ) {}      // CORRECT
if ( $is_active === true ) {}      // PHPCS violation

if ( 'admin' === $user_role ) {}   // CORRECT
if ( $user_role === 'admin' ) {}   // PHPCS violation

if ( 42 === $answer ) {}           // CORRECT
if ( $answer === 42 ) {}           // PHPCS violation
```

**When NOT to use Yoda:**
```php
// Object/array checks - no Yoda needed
if ( $object instanceof WP_Post ) {}        // CORRECT (no Yoda)
if ( is_array( $data ) ) {}                 // CORRECT (no Yoda)
if ( isset( $array['key'] ) ) {}            // CORRECT (no Yoda)
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.PHP.YodaConditions
```

**⚠️ IMPORTANT:** Review each case carefully to avoid introducing bugs

---

## 🚫 Phase 4: Suppressions (1 hour)

### 🚫 File Naming Conventions (38 instances)
**Risk Level:** ⭐⭐⭐⭐⭐ CRITICAL (Breaking change)

**What:** Add inline suppressions - DO NOT rename files

**Why:** Renaming breaks:
- Autoloader class mapping
- `require`/`include` statements  
- WordPress plugin hooks
- Backward compatibility

**Example:**
```php
<?php
/**
 * Tool implementation file.
 *
 * @package WP_MCP_AI
 */

// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
class WP_MCP_AI_Tool_Example extends WP_MCP_AI_Tool_Base {
    // ...
}
```

**How to find files:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.Files.FileName
```

---

### 🚫 Direct Database Queries (28 instances)
**Risk Level:** ⭐⭐⭐⭐ High (Architectural)

**What:** Add suppressions with justification - DO NOT refactor to avoid `$wpdb`

**Why:** Plugin architecture requires direct DB access for:
- Complex queries not supported by WP_Query
- Custom table operations
- Performance-critical queries

**Example:**
```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for custom table access
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Data is transient and changes frequently
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}custom_table WHERE status = %s",
        $status
    )
);
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.DB.DirectDatabaseQuery
```

---

### ⚠️ Prepared SQL (16 instances)
**Risk Level:** ⭐⭐⭐⭐⭐ CRITICAL (Security)

**What:** Review each case - FIX if unsafe, SUPPRESS if false positive

**Safe to suppress:**
```php
// Table names cannot be prepared
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped via $wpdb->prefix
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}my_table" );

// Dynamic column names from safe whitelist
$allowed_columns = array( 'name', 'email', 'phone' );
if ( in_array( $column, $allowed_columns, true ) ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column name validated against whitelist
    $sql = $wpdb->prepare(
        "SELECT * FROM {$wpdb->users} WHERE {$column} = %s",
        $value
    );
}
```

**Must fix:**
```php
// UNSAFE - user input in query
$sql = "SELECT * FROM {$wpdb->posts} WHERE post_title = '{$_GET['title']}'";

// SAFE - use prepare()
$sql = $wpdb->prepare(
    "SELECT * FROM {$wpdb->posts} WHERE post_title = %s",
    sanitize_text_field( wp_unslash( $_GET['title'] ) )
);
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.DB.PreparedSQL
```

---

### 🚫 Development Functions (45 instances)
**Risk Level:** ⭐⭐ Low-Medium (Debug code)

**What:** Add suppressions - DO NOT remove `error_log()` calls

**Why:** Plugin has `WP_MCP_AI_Logger` architecture that wraps error_log

**Example:**
```php
/**
 * Log critical error for debugging.
 */
private function log_error( $message, $context = array() ) {
    if ( WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Part of logging architecture
        error_log( sprintf( '[WP_MCP_AI] %s: %s', $context['operation'] ?? 'unknown', $message ) );
    }
}
```

**How to find:**
```bash
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ \
  --sniffs=WordPress.PHP.DevelopmentFunctions
```

---

## 📝 No Action Required

### 📝 Unused Function Parameters (90 instances)
**Status:** Documented in `PHPCS_IGNORE_ANALYSIS.md`

**Categories:**
- **59 instances:** Interface/abstract method requirements
- **29 instances:** WordPress hook callback signatures
- **19 instances:** Future features (documented TODOs)

**No action needed** - These are intentional design patterns

---

## 📊 Implementation Timeline

| Phase | Tasks | Violations Fixed | Est. Time | Risk |
|-------|-------|------------------|-----------|------|
| **Phase 1** | Quick wins (docs) | 22 | 1-2h | Low ⭐ |
| **Phase 2** | Performance & security | 13 | 2-4h | Medium ⭐⭐⭐ |
| **Phase 3** | Code style | 42 | 2-3h | Medium ⭐⭐ |
| **Phase 4** | Suppressions | 127 | 1h | None (docs) |
| **TOTAL** | All safe fixes | 204 | 6-10h | - |

---

## 🎯 Expected Outcome

### Before Implementation
- 186 errors, 481 warnings
- 835 existing suppressions

### After Implementation
- ~77 violations fixed directly
- ~127 violations properly suppressed with explanations
- ~469 violations remain (intentional/documented)
- Total suppressions: ~962

### Benefits
- ✅ Better security (nonce verification, SQL preparation)
- ✅ Improved performance (cached loop conditions)
- ✅ Clearer documentation (@throws, translator comments)
- ✅ WordPress coding standards compliance
- ✅ No breaking changes
- ✅ All violations explained or resolved

---

## 🚀 Getting Started

### Prerequisites
```bash
# Install dependencies
composer install

# Run PHPCS to see current violations
vendor/bin/phpcs --standard=phpcs.xml.dist includes/
```

### Implementation Steps

1. **Start with Phase 1** (lowest risk)
2. **Test after each phase** (ensure no breakage)
3. **Commit incrementally** (easier to review/rollback)
4. **Document suppressions** (explain why each suppression is needed)

### Testing Checklist
- [ ] Run PHPCS after each phase
- [ ] Run PHPUnit test suite
- [ ] Test plugin activation/deactivation
- [ ] Test key features manually
- [ ] Review git diff for unexpected changes

---

## 📚 References

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- [PHPCS Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)
- [WordPress PHPCS Sniffs](https://github.com/WordPress/WordPress-Coding-Standards)

---

## 📝 Notes

- **Always test after changes** - Even "safe" fixes can have edge cases
- **Use git branches** - Work on fixes in feature branches
- **Document suppressions** - Future developers need context
- **Review security fixes carefully** - Nonce and SQL issues are critical
- **Don't rush Yoda conditions** - Easy to introduce bugs if not careful

---

**Last Updated:** 2026-02-01  
**Next Review:** After Phase 1 completion
