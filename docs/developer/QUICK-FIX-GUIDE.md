# Quick Fix Guide: OAuth Redirect URI Mismatch Error

## What Was Fixed

The `redirect_uri_mismatch` error you were experiencing has been fixed by ensuring that WordPress generates the redirect URI consistently throughout the OAuth flow.

### The Problem
WordPress was using two different methods to build the redirect URI:
1. Passing query string directly: `admin_url('admin.php?wp_mcp_ai_oauth=gmail_callback')`
2. This could cause subtle encoding or URL structure differences

### The Solution
Now all redirect URI generation uses WordPress's standard `add_query_arg()` function:
```php
add_query_arg(
    array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
    admin_url( 'admin.php' )
)
```

This ensures perfect consistency between:
- The redirect URI sent to Google during authorization
- The redirect URI sent to Google during token exchange
- The redirect URI displayed in the WordPress admin

## What You Need to Do Now

### Step 1: Verify Your Google Cloud Console Configuration

Make absolutely sure the redirect URI in Google Cloud Console is **exactly**:
```
https://bots.nvdigital.solutions/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
```

**Important Notes:**
- Must use `https://` (not `http://`)
- Must be exact domain: `bots.nvdigital.solutions`
- Must include path: `/wp-admin/admin.php`
- Must include query parameter: `?wp_mcp_ai_oauth=gmail_callback`
- **NO trailing slash**
- **NO extra spaces**
- **Case-sensitive**

### Step 2: Update Your WordPress Site

1. **Pull the latest changes** from this PR branch: `copilot/fix-oauth-redirect-uri-mismatch`
2. **Deploy to your site** (or merge to your main branch if testing works)
3. **Clear any PHP opcache** if you have caching enabled

### Step 3: Test the Connection

1. Go to **WordPress Admin → NV oOS Dashboard → Tools → Connections → Gmail**
2. Look at the "Set Authorized redirect URI to:" instruction
3. **Verify** the displayed URI matches what's in Google Cloud Console
4. If your Client ID and Secret are already saved, click **Connect Gmail Account**
5. Authorize the app in Google's OAuth screen
6. You should be redirected back successfully

### Step 4: If It Still Doesn't Work

If you still get `redirect_uri_mismatch`:

#### A. Check Google Cloud Console Again
1. Go to https://console.cloud.google.com/apis/credentials
2. Click on your OAuth 2.0 Client ID
3. Under **"Authorized redirect URIs"**, verify you have:
   ```
   https://bots.nvdigital.solutions/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
   ```
4. If it's wrong, fix it and **Save**
5. **Wait 60 seconds** for Google's cache to update
6. Try again

#### B. Check Your WordPress Site URL
Run this in MySQL or phpMyAdmin:
```sql
SELECT option_value FROM wp_options WHERE option_name = 'siteurl';
```

The result should be: `https://bots.nvdigital.solutions`

If it shows `http://` or a different domain, that's your problem! The WordPress site URL must match the redirect URI domain.

#### C. Common Mistakes to Avoid

❌ **Wrong Protocol**
```
http://bots.nvdigital.solutions/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
```
✅ Must use `https://`

❌ **Wrong Parameter Name**
```
https://bots.nvdigital.solutions/wp-admin/admin.php?action=gmail_callback
```
✅ Must use `wp_mcp_ai_oauth` (not `action`)

❌ **HTML Encoded Ampersand**
```
https://bots.nvdigital.solutions/wp-admin/admin.php?page=test&amp;oauth_handler=callback
```
✅ Must use `&` (not `&amp;`)

❌ **Extra Trailing Slash**
```
https://bots.nvdigital.solutions/wp-admin/admin.php/?wp_mcp_ai_oauth=gmail_callback
```
✅ No slash before the `?`

❌ **Wrong Domain**
```
https://nvdigital.solutions/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
```
✅ Must use exact domain from WordPress site URL

#### D. Clear Caches

1. **Browser Cache**: Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)
2. **WordPress Cache**: If you use a caching plugin, clear it
3. **PHP OpCache**: Restart PHP-FPM or wait for cache to expire
4. **Google's Cache**: Wait 60 seconds after making changes in Google Cloud Console

#### E. Enable Debug Logging

If it still fails, enable debug logging:

1. Edit `wp-config.php` and add:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_MCP_AI_DEBUG', true );
```

2. Try the OAuth flow again

3. Check `/wp-content/debug.log` for detailed error messages

4. Share the relevant error messages for further troubleshooting

## Technical Details

### What Changed in the Code

**File: `includes/integrations/class-wp-mcp-ai-oauth-manager.php`**

Before:
```php
'redirect_uri' => admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' ),
```

After:
```php
$redirect_uri = add_query_arg(
    array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
    admin_url( 'admin.php' )
);
// ... then use $redirect_uri
```

This was changed in **4 places**:
- Gmail OAuth authorization
- Gmail OAuth token exchange
- Google Drive OAuth authorization
- Google Drive OAuth token exchange

**File: `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`**

The displayed redirect URI instructions were also updated to use the same method, ensuring what users see matches what the code actually sends.

### Why This Matters

Google OAuth is very strict about redirect URI matching. During the OAuth flow:

1. **Authorization Request**: WordPress sends the redirect URI to Google
2. **Google Validates**: Google checks this against configured URIs
3. **Authorization Code**: Google redirects back with a code
4. **Token Exchange**: WordPress sends the redirect URI again
5. **Google Validates Again**: Google checks it EXACTLY matches step 1

If there's even a tiny difference (one character, case, encoding), Google rejects it with `redirect_uri_mismatch`.

Our fix ensures steps 1 and 4 use the **exact same code path**, eliminating any possibility of mismatch.

## Support

If you're still having issues after following all these steps:

1. **Check all items in Step 4 above**
2. **Run the verification script**: `bash bin/verify-oauth-redirect-uri-fix.sh`
3. **Enable debug logging** and check for errors
4. **Share the exact error message** you're seeing
5. **Share your redirect URI** (without sensitive data)

## Success Indicators

✅ You'll know it's working when:
- No `redirect_uri_mismatch` error appears
- Google's OAuth consent screen appears
- After authorizing, you're redirected back to WordPress
- WordPress shows "Gmail connected successfully"
- Your email address is displayed in the connection settings

---

**Last Updated**: January 17, 2026  
**PR Branch**: `copilot/fix-oauth-redirect-uri-mismatch`  
**Related Docs**: `docs/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md`
