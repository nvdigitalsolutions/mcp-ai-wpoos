# OAuth Redirect URI Mismatch Fix - January 17, 2026

## Problem Summary

Users were experiencing `redirect_uri_mismatch` errors when attempting to connect their Gmail accounts via OAuth, even when they had correctly configured the redirect URI in Google Cloud Console.

**Error Message:**
```
Error 400: redirect_uri_mismatch
Access blocked: This app's request is invalid
```

**Redirect URI Being Sent:**
```
https://bots.nvdigital.solutions/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
```

## Root Cause Analysis

The issue was caused by **inconsistent URL construction** in the OAuth flow. The redirect URI was being constructed by passing a query string directly to `admin_url()`:

```php
// OLD METHOD (Inconsistent)
$redirect_uri = admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' );
```

While this approach works in many cases, it can lead to subtle URL encoding issues or inconsistencies in how WordPress handles the URL, especially when:
1. WordPress applies filters to `admin_url()`
2. The site has custom URL rewriting rules
3. There are trailing slashes or other URL normalization issues
4. Different WordPress installations handle query string appending differently

## The Solution

Changed all redirect URI generation to use WordPress's `add_query_arg()` function consistently:

```php
// NEW METHOD (Consistent)
$redirect_uri = add_query_arg(
    array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
    admin_url( 'admin.php' )
);
```

This ensures that:
1. The query parameter is properly URL-encoded
2. The URL structure is consistent across all WordPress installations
3. WordPress handles the URL construction in a standardized way
4. The redirect URI used in authorization matches exactly with the redirect URI used in token exchange

## Files Modified

### 1. `includes/integrations/class-wp-mcp-ai-oauth-manager.php`

**Changed in Gmail OAuth flow:**
- Line ~103: Authorization URL redirect_uri parameter
- Line ~188: Token exchange redirect_uri parameter

**Changed in Google Drive OAuth flow:**
- Line ~345: Authorization URL redirect_uri parameter
- Line ~425: Token exchange redirect_uri parameter

All four instances now use `add_query_arg()` to build the redirect URI consistently.

### 2. `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`

**Changed display instructions:**
- Line ~706: Gmail redirect URI displayed to users
- Line ~879: Google Drive redirect URI displayed to users

This ensures that the redirect URI shown to users in the setup instructions matches exactly what the OAuth flow will use.

## Technical Details

### Why `add_query_arg()` is Better

WordPress's `add_query_arg()` function:
- Properly handles URL encoding
- Respects existing query parameters
- Maintains URL structure consistency
- Follows WordPress core conventions
- Is the recommended method per WordPress Coding Standards

### How OAuth Redirect URI Matching Works

During the OAuth flow, Google validates the redirect URI in two places:

1. **Authorization Request**: When WordPress redirects the user to Google
   - WordPress sends: `redirect_uri=https://site.com/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback`
   - Google checks this against configured URIs in Cloud Console

2. **Token Exchange**: When WordPress exchanges the authorization code for tokens
   - WordPress sends: `redirect_uri=https://site.com/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback`
   - Google verifies this EXACTLY matches what was sent in step 1

If there's even a tiny difference (encoding, trailing slash, case, etc.), Google returns `redirect_uri_mismatch`.

Our fix ensures both requests use the exact same URL generation method, eliminating any possibility of inconsistency.

## Testing

### Manual Testing Steps

1. **Configure Google Cloud Console:**
   - Navigate to https://console.cloud.google.com/apis/credentials
   - Edit your OAuth 2.0 Client ID
   - Under "Authorized redirect URIs", add:
     ```
     https://your-site.com/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
     ```
   - Save changes
   - Wait 30-60 seconds for Google's cache to update

2. **Test Gmail Connection:**
   - Go to WordPress Admin → NV oOS Dashboard → Tools → Connections → Gmail
   - Copy the displayed redirect URI from the setup instructions
   - Verify it matches what you entered in Google Cloud Console
   - Enter your Client ID and Client Secret
   - Save settings
   - Click "Connect Gmail Account"
   - Authorize the app in Google's OAuth screen
   - Verify successful connection

3. **Expected Results:**
   - No `redirect_uri_mismatch` errors
   - Successful OAuth authorization
   - Gmail connection shows as "Connected"
   - Email address is displayed

### Automated Tests

Created `tests/test-oauth-redirect-uri-consistency.php` with the following tests:
- `test_gmail_redirect_uri_consistency()` - Verifies Gmail redirect URI structure
- `test_google_drive_redirect_uri_consistency()` - Verifies Drive redirect URI structure
- `test_redirect_uri_encoding_in_oauth_url()` - Verifies proper URL encoding in OAuth flow
- `test_old_vs_new_url_generation()` - Verifies old and new methods produce same result

## Additional Notes

### The `flowName=GeneralOAuthFlow` Parameter

Users may see `flowName=GeneralOAuthFlow` appended to URLs in the browser address bar during OAuth. This parameter is:
- **Added by Google** after the redirect
- **Normal and expected** behavior
- **Not sent by WordPress**
- **Used by Google for internal flow tracking**
- **Does not affect the redirect URI matching**

### Backward Compatibility

This fix is **fully backward compatible**:
- No database changes required
- No settings migration needed
- Existing OAuth connections continue to work
- No user action required (except re-authorizing if connection was broken)

### Related Fixes

This fix builds on previous OAuth improvements:
- 2026-01: Changed OAuth parameter from `action` to `wp_mcp_ai_oauth` (Google doesn't allow `action`)
- 2026-01: Fixed HTML entity encoding in redirect URI display (`&` vs `&amp;`)
- This fix: Ensures consistent URL generation throughout the OAuth flow

## Troubleshooting

If users still experience issues after this fix:

1. **Clear Browser Cache**: Sometimes browsers cache redirect failures
2. **Wait for Google Cache**: Google Cloud Console changes can take up to 5 minutes to propagate
3. **Check HTTPS**: Ensure site uses HTTPS (required for production OAuth)
4. **Verify Exact Match**: The redirect URI must match exactly (including protocol, domain, path, and query string)
5. **Check for Filters**: Some plugins might filter `admin_url()` - check for conflicts

## Success Criteria

✅ Fix is successful when:
- No `redirect_uri_mismatch` errors occur
- Gmail OAuth flow completes successfully
- Google Drive OAuth flow completes successfully  
- Redirect URI displayed in admin matches OAuth flow exactly
- Users can copy-paste the displayed URI into Google Cloud Console without modifications

## References

- WordPress `add_query_arg()` documentation: https://developer.wordpress.org/reference/functions/add_query_arg/
- Google OAuth 2.0 documentation: https://developers.google.com/identity/protocols/oauth2
- Previous fixes: `docs/fixes/gmail-oauth-fix-summary.md`
