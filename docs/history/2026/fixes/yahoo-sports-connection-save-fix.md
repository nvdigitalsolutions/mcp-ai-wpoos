# Yahoo Sports Connection Save Fix

**Date:** 2026-02-03  
**Issue:** Yahoo Sports connection settings redirect to Gmail page after save instead of staying on Yahoo Sports page

## Problem

When accessing the Yahoo Sports connection page via the URL:
```
/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=yahoo_sports
```

And saving settings, the page would redirect to:
```
/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&updated=true
```

Notice the missing `connection=yahoo_sports` parameter, which causes the page to default to the first connection (Gmail).

## Root Cause

The Settings Dashboard's `handle_save_settings()` method was not preserving the `connection` parameter during the redirect after save. The redirect logic only preserved:
- `tab` parameter
- `subtab` parameter
- `view` parameter

But the `connection` parameter was missing.

## Solution

Made three minimal changes:

### 1. Added Hidden Field in Integrations Section
**File:** `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`

Added a hidden input field to submit the `connection` parameter when it's present in the URL:

```php
<?php
// If accessed via connection parameter (e.g., from Tools > Connections),
// preserve it for redirect after save.
if ( isset( $_GET['connection'] ) ) :
    ?>
    <input type="hidden" name="connection" value="<?php echo esc_attr( sanitize_key( $_GET['connection'] ) ); ?>" />
    <?php
endif;
?>
```

### 2. Read Connection Parameter in Save Handler
**File:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php` (Line ~308)

Added code to read the `connection` parameter from POST data:

```php
// Check for 'connection' parameter (used in Integrations section).
$active_connection = isset( $_POST['connection'] ) ? sanitize_key( $_POST['connection'] ) : '';
```

### 3. Include Connection in Redirect URL
**File:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php` (Line ~764)

Added the connection parameter to the redirect arguments:

```php
// Preserve connection parameter for Integrations section connections.
if ( ! empty( $active_connection ) ) {
    $redirect_args['connection'] = $active_connection;
}
```

## Testing

Created a test script that validates the redirect URL generation logic:

```bash
php /tmp/test-connection-redirect.php
```

Results:
- ✅ Yahoo Sports connection: Correctly includes `connection=yahoo_sports`
- ✅ Gmail connection: Correctly includes `connection=gmail`
- ✅ Direct subtab access: No connection parameter (as expected)
- ✅ Tab-only access: No connection or subtab parameters (as expected)

## Impact

- **Minimal:** Only 3 small changes across 2 files
- **Focused:** Only affects the Integrations section with connection-based navigation
- **Backward Compatible:** Does not affect other tabs or sections
- **Safe:** Uses existing sanitization patterns (`sanitize_key()`)

## Affected Connections

This fix benefits all connections in the Integrations section that use the `connection` parameter:
- Gmail
- Google Drive
- GitHub
- Mailjet
- Cloudways
- Cloudflare
- Meta (Facebook)
- QuickBooks
- TikTok
- **Yahoo Sports** (the reported issue)

## Files Changed

1. `includes/admin/sections/class-wp-mcp-ai-section-integrations.php` (+11 lines)
2. `includes/admin/class-wp-mcp-ai-settings-dashboard.php` (+9 lines)

Total: 20 lines added
