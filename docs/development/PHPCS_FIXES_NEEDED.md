# PHPCS Linting Fixes Required

## Summary
55 errors and 103 warnings detected across 7 files. Most are auto-fixable formatting issues, but some require manual intervention for security.

## Critical Errors to Fix (Priority Order)

### 1. Remote Sites Admin (38 errors, 32 warnings)
**File**: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

**Critical Security Issues** (16 errors - MUST FIX):
- Lines 86, 103, 124: $_GET and $_POST variables not unslashed before wp_verify_nonce()
- Lines 130-151: $_POST variables not unslashed before sanitization
- Lines 203, 215: $_GET variables not unslashed

**Fix Pattern**:
```php
// BEFORE (WRONG):
if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_connection_' . $_GET['connection_id'] ) ) {

// AFTER (CORRECT):
if ( ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'delete_connection_' . sanitize_key( wp_unslash( $_GET['connection_id'] ) ) ) ) {

// BEFORE (WRONG):
'name' => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',

// AFTER (CORRECT):
'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
```

**Formatting Issues** (22 warnings - AUTO-FIXABLE):
- Equals sign alignment (lines 91, 108, etc.)
- Array double arrow alignment (lines 298-301)
- urlencode() should be rawurlencode() (lines 96, 113, 157)
- WordPress spelling corrections (multiple lines)

### 2. Remote Site Manager (16 errors, 52 warnings)
**File**: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

**All Auto-fixable**:
- 16 trailing whitespace errors (lines 99, 106, 362, 392, etc.)
- 52 formatting warnings (equals sign alignment, array alignment)

**Fix**:
```bash
# Remove all trailing whitespace
sed -i 's/[[:space:]]*$//' addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php
```

### 3. PayHere Tool (1 error, 3 warnings)
**File**: `includes/tools/class-wp-mcp-ai-tool-payhere-get-payment.php`

**Issues**:
- 1 trailing whitespace error (line 115)
- 2 array alignment warnings (lines 59, 63)
- 1 unknown capability warning (line 92 - 'manage_woocommerce')

**Note**: The 'manage_woocommerce' capability is valid (from WooCommerce) but not recognized by PHPCS. Can be ignored or added to custom capabilities list.

### 4. Pro Main File (12 warnings)
**File**: `addons/pro/mcp-ai-wpoos-pro.php`

**All Auto-fixable**:
- 12 equals sign alignment warnings (lines 790-809)

### 5. EZuite ERP Tool (2 warnings)
**File**: `addons/pro/includes/tools/class-wp-mcp-ai-tool-ezuite-erp.php`

**Auto-fixable**:
- 2 array alignment warnings (lines 73-74)

### 6. Test Files (2 warnings each)
**Files**: `tests/test-ezuite-erp-tool.php`

**Issues**:
- WordPress spelling (line 277) - AUTO-FIXABLE
- base64_encode() warning (line 335) - INFORMATIONAL (used for auth, not obfuscation)

## Quick Fix Script

```bash
#!/bin/bash
# Run this to fix most issues automatically

cd /home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos

# Fix trailing whitespace in all files
find . -name "*.php" -path "*/addons/pro/*" -o -path "*/includes/*" -o -path "*/tests/*" | \
  while read file; do
    sed -i 's/[[:space:]]*$//' "$file"
  done

# Fix WordPress spelling
find . -name "*.php" | xargs sed -i 's/wordpress/WordPress/g'

# Replace urlencode with rawurlencode in admin files
sed -i 's/urlencode(/rawurlencode(/g' addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php
```

## Manual Fixes Required

### Remote Sites Admin - Security Fixes

Add wp_unslash() to all $_GET and $_POST accesses:

```php
// Lines 86-90
if ( 'delete' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
    $nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
    $conn_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'delete_connection_' . $conn_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
    }

    $connection_id = $conn_id;
    // ... rest of code
}

// Lines 128-151 - Update all $_POST accesses
$connection_data = array(
    'id'              => isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '',
    'name'            => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
    'url'             => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
    'connection_type' => isset( $_POST['connection_type'] ) ? sanitize_key( wp_unslash( $_POST['connection_type'] ) ) : 'wordpress',
    'auth_type'       => isset( $_POST['auth_type'] ) ? sanitize_key( wp_unslash( $_POST['auth_type'] ) ) : 'none',
    'username'        => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
    'password'        => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '',
    'token'           => isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '',
    'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
    'consumer_secret' => isset( $_POST['consumer_secret'] ) ? wp_unslash( $_POST['consumer_secret'] ) : '',
    'api_key'         => isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : '',
    'api_secret'      => isset( $_POST['api_secret'] ) ? wp_unslash( $_POST['api_secret'] ) : '',
    'client_id'       => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
    'client_secret'   => isset( $_POST['client_secret'] ) ? wp_unslash( $_POST['client_secret'] ) : '',
    'app_id'          => isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '',
    'app_secret'      => isset( $_POST['app_secret'] ) ? wp_unslash( $_POST['app_secret'] ) : '',
    'location_id'     => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
    'company_id'      => isset( $_POST['company_id'] ) ? sanitize_text_field( wp_unslash( $_POST['company_id'] ) ) : '',
    'sandbox_mode'    => ! empty( $_POST['sandbox_mode'] ),
    'has_woocommerce' => ! empty( $_POST['has_woocommerce'] ),
    'enabled'         => ! empty( $_POST['enabled'] ),
    'cache_ttl'       => isset( $_POST['cache_ttl'] ) ? max( 0, min( 3600, absint( $_POST['cache_ttl'] ) ) ) : 300,
    'test_endpoint'   => isset( $_POST['test_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['test_endpoint'] ) ) : '',
);
```

## Estimated Time

- **Auto-fixable issues**: 15 minutes (script + verify)
- **Manual security fixes**: 30 minutes (careful testing required)
- **Total**: 45 minutes

## Testing After Fixes

```bash
# Run linter to verify
composer run lint

# Run tests to ensure no breakage
composer run test

# Manual testing
# 1. Create a new connection via admin UI
# 2. Test connection
# 3. Use connection in PayHere tool
# 4. Verify no PHP errors in error log
```

## Priority

**CRITICAL**: The security issues in Remote Sites Admin MUST be fixed before merge. These are real vulnerabilities related to input validation.

**HIGH**: Trailing whitespace and formatting should be fixed for code quality.

**LOW**: Warnings about base64_encode() and manage_woocommerce capability can be suppressed with inline comments if needed.

## Suppressions for False Positives

Add these comments where needed:

```php
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for authentication, not obfuscation
$auth_base64 = base64_encode( $auth_string );

// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is a valid WooCommerce capability
if ( ! user_can( $user_id, 'manage_woocommerce' ) ) {
```

## Next Steps

1. Run the quick fix script above
2. Manually apply security fixes to Remote Sites Admin
3. Run composer run lint to verify
4. Commit with message: "Fix PHPCS linting errors (55 errors, 103 warnings)"
5. Run tests to ensure no breakage
