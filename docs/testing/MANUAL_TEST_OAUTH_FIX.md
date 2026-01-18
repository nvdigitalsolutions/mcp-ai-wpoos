# Manual Test Guide: OAuth Redirect URI Fix

## Purpose
Verify that the HTML entity encoding fix for OAuth redirect URIs works correctly.

## Prerequisites
- WordPress site with NV oOS Pro addon installed
- Access to WordPress admin panel
- Browser DevTools access

## Test Steps

### 1. View the Gmail Connection Page
1. Navigate to **WordPress Admin → NV oOS Dashboard → Remote Sites**
2. Click **"Add New"** or edit an existing Gmail connection
3. Select **Connection Type: Gmail (Email Service)**
4. The form should display with Gmail-specific fields

### 2. Inspect the "Authorized Redirect URI" Field
1. Locate the **"Authorized Redirect URI"** field in the Gmail section
2. The field should display a URL like:
   ```
   https://your-site.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
   ```

### 3. Verify No HTML Entities in Display
**Test the displayed value:**
1. Click on the redirect URI input field to select it
2. Copy the value (Ctrl+C / Cmd+C)
3. Paste it into a text editor

**Expected Result:** The pasted URL should contain a plain `&` character:
```
&oauth_handler=
```

**NOT** an HTML entity:
```
&amp;oauth_handler=  ❌ WRONG
```

### 4. Verify HTML Source Shows Plain Ampersand
**Using Browser DevTools:**
1. Right-click on the redirect URI input field
2. Select **"Inspect"** or **"Inspect Element"**
3. Look at the HTML source in DevTools
4. Find the `value` attribute of the input element

**Expected Result:** The HTML should show:
```html
<input ... value="...admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback">
```

**NOT:**
```html
<input ... value="...admin.php?page=wp-mcp-ai-remote-sites&amp;oauth_handler=gmail_oauth_callback">  ❌ WRONG
```

### 5. Test Copy-Paste to Google Cloud Console
1. Copy the redirect URI from the NV oOS admin interface
2. Open [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials)
3. Edit your OAuth 2.0 Client ID
4. Paste the redirect URI into **"Authorized redirect URIs"**
5. The pasted URL should have a plain `&` character (not `&amp;`)

### 6. Test Google Drive Connection (Same Steps)
Repeat steps 1-5 but with:
- **Connection Type: Google Drive**
- Look for the redirect URI ending in `&oauth_handler=google_drive_oauth_callback`

## Success Criteria
✅ All tests pass if:
1. The displayed redirect URI contains plain `&` (not `&amp;`)
2. Copying and pasting produces the correct URL
3. Browser DevTools shows plain `&` in the HTML value attribute
4. Google Cloud Console accepts the pasted URI without errors

## Failure Indicators
❌ The fix has failed if:
1. The redirect URI contains `&amp;` in the displayed value
2. Copying gives you `&amp;` instead of `&`
3. Browser DevTools shows `&amp;` in the HTML source
4. Google OAuth returns `redirect_uri_mismatch` error

## Notes
- This fix only affects the **display** of the redirect URI in the admin interface
- The actual OAuth flow (authorization and token exchange) is unchanged
- The fix prevents copy-paste errors that lead to `redirect_uri_mismatch` from Google

## Automated Test
To run automated tests for OAuth functionality:
```bash
composer test -- --filter test_gmail_oauth addons/pro/tests/test-remote-sites-admin.php
```

---
**Last Updated:** January 13, 2026  
**Related PR:** copilot/fix-gmail-connection-error
