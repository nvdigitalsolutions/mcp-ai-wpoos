# Gmail OAuth Setup Instructions - How to Fix redirect_uri_mismatch Error

## Problem
You're seeing this error when trying to connect Gmail:
```
Access blocked: This app's request is invalid
Error 400: redirect_uri_mismatch
```

## Root Cause
The redirect URI being sent to Google doesn't match what's configured in your Google Cloud Console OAuth 2.0 credentials.

## Solution (Step-by-Step)

### Step 1: Get Your Exact Redirect URI from NV oOS

1. Go to **WordPress Admin → NV oOS Dashboard → Remote Sites**
2. Click **Edit** on your Gmail connection (or create a new one if it doesn't exist)
3. Set **Connection Type** to **Gmail (Email Service)**
4. Look for the **"Authorized Redirect URI"** field - it will display something like:
   ```
   https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
   ```
5. **Click on the URL to select it, then copy it** (Ctrl+C or Cmd+C)

### Step 2: Configure Google Cloud Console

1. Go to [Google Cloud Console - Credentials](https://console.cloud.google.com/apis/credentials)
2. Sign in with your Google account (the one that created the OAuth client)
3. Find your OAuth 2.0 Client ID in the list
4. Click the **Edit** icon (pencil) next to your client
5. Scroll down to **"Authorized redirect URIs"**
6. Click **"+ ADD URI"**
7. **Paste the exact URL** you copied from Step 1
8. Click **SAVE** at the bottom
9. Wait 30-60 seconds for Google's systems to update

### Step 3: Enter Your Credentials in NV oOS

1. Back in WordPress, in your Gmail connection settings:
   - **OAuth Client ID**: Paste your Client ID from Google Cloud Console
   - **OAuth Client Secret**: Paste your Client Secret from Google Cloud Console
2. Click **"Update Connection"** to save

### Step 4: Connect to Gmail

1. After saving, you'll see a **"Connect to Gmail"** button
2. Click it - you'll be redirected to Google's authorization page
3. Sign in with the Gmail account you want to connect
4. Review and accept the permissions
5. You'll be redirected back to your WordPress site
6. You should see: **"Gmail connected successfully!"**

## Verification

Your Gmail connection is working if you see:
- ✅ Green checkmark next to the refresh token field
- ✅ Your email address displayed in the connection settings
- ✅ No error messages

## Common Issues

### Issue: Still getting redirect_uri_mismatch

**Causes:**
- URL not added to Google Cloud Console (go back to Step 2)
- HTTP vs HTTPS mismatch (your site must use HTTPS)
- Typo in the redirect URI in Google Cloud Console
- Using a different Google Cloud project than where you created the OAuth client

**Solution:**
- Double-check the redirect URI is EXACTLY the same (including `https://`)
- Make sure you saved changes in Google Cloud Console
- Wait a full minute after saving in Google Cloud Console
- Try in an incognito/private browsing window

### Issue: Error 400: invalid_request (different error)

This was a previous error that has been fixed. If you're still seeing it:
- Make sure you're using the latest version of the plugin
- The redirect URI should use `oauth_handler` parameter, NOT `action`
- Clear your browser cache

### Issue: Can't find OAuth 2.0 Client in Google Cloud Console

**Solution:**
1. Make sure you're signed in with the correct Google account
2. Check if you're in the right Google Cloud project (dropdown at the top)
3. If you haven't created one yet, you need to:
   - Create a new OAuth 2.0 Client ID
   - Choose "Web application" as the type
   - Add the authorized redirect URI from Step 1 above

## Support

If you're still having issues:
1. Check the WordPress debug.log for detailed error messages
2. Verify your site is using HTTPS (required for OAuth)
3. Make sure the Gmail API is enabled in Google Cloud Console
4. Contact support with:
   - The exact error message you're seeing
   - Your site URL
   - Screenshot of your Google Cloud Console OAuth configuration

## Reference

- Full Gmail OAuth documentation: `docs/fixes/gmail-oauth-fix-summary.md`
- Google OAuth setup guide: `docs/getting-started/installation-setup/google-oauth-setup.md`
