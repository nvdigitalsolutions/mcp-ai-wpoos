# OAuth Redirect URI Setup Guide

## Problem: redirect_uri_mismatch Error

If you're seeing an error like this from Google OAuth:

```
Error 400: redirect_uri_mismatch
Request details: redirect_uri=https://your-site.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites
```

This means the redirect URI you registered in Google Cloud Console doesn't match the one being sent in the OAuth request.

## Common Causes

1. **Incomplete URL Copy**: You copied only part of the URL, missing important query parameters
2. **Manual Typing Error**: You manually typed the URL instead of copying it
3. **Old Configuration**: You registered an old/incorrect URL before the fix was applied

## Solution: Use the Copy Button

As of this fix, the Remote Sites admin page now includes a **"Copy" button** next to the redirect URI field. This ensures you always copy the complete, correctly formatted URL.

### Step-by-Step Instructions

#### 1. Navigate to Remote Sites Page
- Go to **WordPress Admin** → **NV oOS Pro** → **Remote Sites**
- Click **"Add New Connection"** or edit an existing connection
- Select **"Gmail"** or **"Google Drive"** as the connection type

#### 2. Find the Authorized Redirect URI Field
Look for the section labeled **"Authorized Redirect URI"**. You should see:
- A read-only text field with the complete URL
- A **"Copy"** button with a clipboard icon
- Detailed instructions with parameter requirements

#### 3. Copy the Redirect URI
**IMPORTANT**: Do NOT manually select and copy the URL from the text field!

Instead:
1. Click the **"Copy"** button
2. You'll see a checkmark appear briefly
3. The button will show "Copied!" for 2 seconds
4. The complete URL is now in your clipboard

#### 4. Register in Google Cloud Console
1. Open [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Select your project
3. Navigate to **APIs & Services** → **Credentials**
4. Find your OAuth 2.0 Client ID (or create a new one)
5. Click to edit the OAuth client
6. Scroll to **"Authorized redirect URIs"**
7. Click **"ADD URI"**
8. **Paste** the URL from your clipboard (Ctrl+V or Cmd+V)
9. Verify the URL includes BOTH parameters:
   - `page=wp-mcp-ai-remote-sites`
   - `oauth_handler=gmail_oauth_callback` (or `google_drive_oauth_callback`)
10. Click **"Save"**

### Gmail Redirect URI Format

Your Gmail redirect URI should look like this:

```
https://your-site.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
```

**Required Parameters:**
- `page=wp-mcp-ai-remote-sites`
- `oauth_handler=gmail_oauth_callback`

### Google Drive Redirect URI Format

Your Google Drive redirect URI should look like this:

```
https://your-site.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=google_drive_oauth_callback
```

**Required Parameters:**
- `page=wp-mcp-ai-remote-sites`
- `oauth_handler=google_drive_oauth_callback`

## Troubleshooting

### Still Getting redirect_uri_mismatch?

1. **Verify the Registered URL**
   - Log in to Google Cloud Console
   - Go to your OAuth 2.0 Client ID settings
   - Check that the Authorized redirect URI includes BOTH parameters
   - The URL must match EXACTLY (including protocol: https://)

2. **Check for Typos**
   - Make sure there are no extra spaces
   - Verify the domain matches your WordPress site exactly
   - Ensure you're using the correct protocol (http vs https)

3. **Multiple URLs Registered**
   - You can register multiple redirect URIs in Google Cloud Console
   - Make sure you have the correct one for your environment (staging vs production)

4. **Clear Browser Cache**
   - Sometimes old OAuth data gets cached
   - Try clearing your browser cache or using an incognito/private window

5. **Wait for Propagation**
   - After updating Google Cloud Console settings, wait 5-10 minutes
   - Google's servers need time to propagate the changes

### Base Plugin vs Pro Plugin

**Important**: The Base plugin and Pro plugin use DIFFERENT redirect URIs:

- **Base Plugin** (Settings → Tools → Connections):
  ```
  https://your-site.com/wp-admin/admin.php?wp_mcp_ai_oauth=gmail_callback
  ```

- **Pro Plugin** (Remote Sites):
  ```
  https://your-site.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
  ```

If you're using both:
1. Register BOTH URLs in Google Cloud Console
2. Use the correct OAuth flow for each (Base for single connection, Pro for multiple connections)

## Technical Details

### Why This Fix Was Needed

Previously, users had to manually copy the redirect URI from the text field. This led to errors:
- Copying from browser DevTools could include HTML entities (`&amp;` instead of `&`)
- Selecting text sometimes missed part of the URL
- Browser behavior varied across different browsers

### How the Copy Button Works

The new copy button:
1. Uses modern `navigator.clipboard` API when available
2. Falls back to legacy `document.execCommand('copy')` for older browsers
3. Provides visual feedback (checkmark for success, warning for failure)
4. Always copies the complete, unmodified URL from JavaScript

### Security Considerations

The copy button is safe to use:
- URLs are generated server-side by WordPress
- JavaScript only copies the pre-generated URL
- No user input is involved in URL generation
- All parameters are properly escaped and sanitized

## Additional Resources

- [Google OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Google Cloud Console](https://console.cloud.google.com/)
- [Plugin Documentation](../getting-started/installation-setup/google-oauth-setup.md)

## Need Help?

If you're still experiencing issues:
1. Check the [Troubleshooting Guide](../deployment-troubleshooting.md)
2. Review the [Security Guide](../../SECURITY.md)
3. Open an issue on [GitHub](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
