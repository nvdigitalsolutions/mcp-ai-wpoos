# Gmail OAuth redirect_uri Parameter Stripping Fix

## Issue Summary
The `&oauth_handler=gmail_oauth_callback` parameter was being stripped from the Gmail OAuth redirect URI in the Remote Sites admin, causing `redirect_uri_mismatch` errors from Google OAuth.

## Problem Statement
Error displayed:
```
Error Request details: redirect_uri=https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-remote-sites

Authorized Redirect URI:
https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
```

The `oauth_handler` parameter was missing from the redirect_uri sent to Google.

## Root Cause
The redirect URIs were constructed by passing query parameters directly in the URL string to `admin_url()`:

```php
admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback' )
```

While this approach works in many cases, it can cause URL encoding issues when the URL is later processed by `esc_url()` or other WordPress functions, potentially resulting in parameters being stripped or corrupted.

## Solution
Updated all OAuth redirect URI constructions in the Remote Sites admin to use `add_query_arg()` for proper URL encoding:

```php
add_query_arg(
    array(
        'page'          => 'wp-mcp-ai-remote-sites',
        'oauth_handler' => 'gmail_oauth_callback',
    ),
    admin_url( 'admin.php' )
)
```

## Benefits
1. **Proper URL Encoding**: `add_query_arg()` ensures all parameters are correctly URL-encoded
2. **Consistency**: Matches the pattern used in the base OAuth manager class
3. **Reliability**: Parameters are preserved through `esc_url()` and other sanitization functions
4. **Future-proof**: Using WordPress's recommended API for building URLs

## Files Changed
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` (8 locations)
- `tests/test-remote-sites-oauth-redirect-uri.php` (new test file)

## Locations Updated
1. Gmail redirect URI display field (line ~966)
2. Google Drive redirect URI display field (line ~1049)
3. Gmail OAuth connect button URL (line ~1123)
4. Google Drive OAuth connect button URL (line ~1153)
5. Gmail authorization request redirect_uri (line ~1425)
6. Gmail token exchange redirect_uri (line ~1516)
7. Google Drive authorization request redirect_uri (line ~1643)
8. Google Drive token exchange redirect_uri (line ~1740)

## Testing
- Created comprehensive test suite in `tests/test-remote-sites-oauth-redirect-uri.php`
- Verification script confirms URL construction preserves all parameters
- Code review passed with no comments
- Security checks passed

## Expected Outcome
After this fix:
1. The OAuth redirect URI will correctly include the `oauth_handler` parameter
2. Google OAuth will accept the redirect URI because it matches the authorized URI
3. Users will be able to successfully connect their Gmail and Google Drive accounts
4. The OAuth callback handler will be properly triggered after successful authorization

## Additional Notes
This fix also applies to Google Drive OAuth connections, ensuring both Gmail and Google Drive connections work correctly in the Remote Sites admin.
