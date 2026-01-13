# Google OAuth Setup Guide for Gmail and Google Drive Integration

This guide explains how to configure OAuth 2.0 credentials in the Google Cloud Console for Gmail and Google Drive integrations in NV oOS (Open Operator System).

**This implementation follows:**
- [Google OAuth 2.0 Best Practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices)
- [Google Apigee OAuth Guidelines](https://docs.cloud.google.com/apigee/docs/api-platform/security/oauth/access-tokens)
- [OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)

For a comprehensive verification checklist, see [OAuth Verification Checklist](./OAUTH_VERIFICATION_CHECKLIST.md).

## Prerequisites

1. A Google Cloud account
2. Access to the Google Cloud Console (https://console.cloud.google.com/)
3. Admin access to your WordPress site
4. **Your WordPress site MUST use HTTPS** (required by Google OAuth in production)

## Step-by-Step Setup

### 1. Create or Select a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Either:
   - Create a new project, or
   - Select an existing project from the dropdown at the top

### 2. Enable Required APIs

**For Gmail integration:**
1. In the Google Cloud Console, go to **APIs & Services** > **Library**
2. Search for "Gmail API"
3. Click on it and press **Enable**

**For Google Drive integration:**
1. In the Google Cloud Console, go to **APIs & Services** > **Library**
2. Search for "Google Drive API"
3. Click on it and press **Enable**

### 3. Create OAuth 2.0 Credentials

1. Go to **APIs & Services** > **Credentials**
2. Click **+ CREATE CREDENTIALS** at the top
3. Select **OAuth client ID**
4. If prompted, configure the OAuth consent screen first:
   - Choose **External** for public sites or **Internal** for Google Workspace organizations
   - Fill in the required fields (App name, User support email, etc.)
   - Add scopes: `https://www.googleapis.com/auth/gmail.readonly`
   - Add test users if needed (for development)
   - Submit the consent screen configuration

### 4. Configure OAuth Client

1. For "Application type", select **Web application**
2. Enter a name for your OAuth client (e.g., "NV oOS Gmail Integration")

#### Required Configuration for Your Site

⚠️ **CRITICAL: The redirect URI must match EXACTLY** as specified by Google OAuth 2.0 best practices and Apigee guidelines. Even minor differences will cause authentication to fail.

Based on your site URL (e.g., `https://bots.nvdigital.solutions`), configure the following:

##### **Authorized JavaScript origins** (Optional)
Add your site's base URL without any path:
```
https://bots.nvdigital.solutions
```

**Note:** Do NOT include trailing slashes or paths like `/wp-admin/`

##### **Authorized redirect URIs** (Required)

The exact redirect URI will be displayed in the NV oOS admin interface when you create a connection. It will look like one of these:

**For Gmail connections (Pro addon):**
```
https://YOUR-SITE-URL/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
```

**For Google Drive connections (Pro addon):**
```
https://YOUR-SITE-URL/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=google_drive_oauth_callback
```

**For Google Drive connections (Legacy core integration):**
```
https://YOUR-SITE-URL/wp-admin/admin-post.php?action=wp_mcp_ai_google_drive_oauth_callback
```

**Important OAuth 2.0 Best Practices:**
- The redirect URI must be **EXACTLY** the same in authorization requests and token exchange (our implementation handles this automatically)
- No wildcards are allowed in redirect URIs
- The redirect URI parameter `oauth_handler` is used for Pro connections to avoid Google's restrictions on the `action` parameter
- Must use HTTPS in production (http://localhost is only allowed for development)
- The URI is case-sensitive

### 5. Save and Copy Credentials

1. Click **CREATE**
2. A dialog will appear with your:
   - **Client ID** (looks like: `123456789-abcdef.apps.googleusercontent.com`)
   - **Client Secret** (looks like: `GOCSPX-abcd1234...`)
3. **Copy both values** - you'll need them in the next step

### 6. Configure NV oOS

**For Gmail or Google Drive connections (Pro addon):**

1. In your WordPress admin panel, go to **NV oOS** > **Remote Sites**
2. Click **Add New Connection**
3. Fill in the form:
   - **Connection Name**: A descriptive name (e.g., "My Gmail" or "My Google Drive")
   - **Connection Type**: Select "Gmail (Email Service)" or "Google Drive (Cloud Storage)"
   - **Site URL**: Will be set automatically based on connection type
   - **OAuth Client ID**: Paste the Client ID from step 5
   - **OAuth Client Secret**: Paste the Client Secret from step 5
4. **Important:** Copy the **Authorized Redirect URI** shown in the form (read-only field)
5. Go back to Google Cloud Console and verify this URI is added exactly as shown
6. Click **Save Connection**
7. After saving, click the **Connect to Gmail** or **Connect to Google Drive** button
8. Authorize NV oOS to access your account
9. After successful authorization, you'll see the refresh token and user email populated

**For Google Drive (Legacy core integration):**

1. In your WordPress admin panel, go to **NV oOS Dashboard** > **Tools** > **Connections**
2. Click on the **Google Drive** tab
3. Enter your:
   - **Google Drive OAuth Client ID**: Paste the Client ID from step 5
   - **Google Drive OAuth Client Secret**: Paste the Client Secret from step 5
4. **Important:** Copy the **Authorized Redirect URI** shown below the form fields
5. Go back to Google Cloud Console and verify this URI is added exactly as shown
6. Click **Save Settings**
7. After saving, click **Connect Google Drive**
8. Authorize NV oOS to access your Google Drive

## Troubleshooting

For a comprehensive troubleshooting guide, see the [OAuth Verification Checklist](./OAUTH_VERIFICATION_CHECKLIST.md#common-oauth-errors-and-solutions).

### 400 Bad Request Error

If you encounter a "400 Bad Request" error when clicking the Connect button:

1. **Verify your site is using HTTPS** - Google OAuth requires HTTPS in production
2. **Check the redirect URI** in Google Cloud Console matches exactly what's shown in the NV oOS admin interface
3. Make sure there are no extra spaces or characters in the redirect URI
4. Ensure the OAuth client type is set to "Web application"
5. Wait 30-60 seconds after saving in Google Cloud Console for changes to propagate
6. Clear your browser cache and try again in incognito/private mode

### "redirect_uri_mismatch" Error

**This is the most common OAuth error.** It occurs when the redirect URI doesn't match EXACTLY what's configured in Google Cloud Console.

**According to Google OAuth 2.0 and Apigee best practices, the redirect URI must:**
- Match exactly (including protocol, domain, path, and query parameters)
- Not use wildcards
- Be consistent between authorization request and token exchange (our implementation ensures this)

**To fix:**

1. Copy the **exact** redirect URI from the NV oOS admin interface (it's shown in a read-only field)
2. Go to [Google Cloud Console - Credentials](https://console.cloud.google.com/apis/credentials)
3. Edit your OAuth 2.0 Client ID
4. Under "Authorized redirect URIs", click "+ ADD URI"
5. Paste the exact URI you copied (no modifications)
6. Click SAVE
7. Wait 30-60 seconds for changes to propagate
8. Try the OAuth flow again in a new incognito/private browsing window

**Common causes of mismatch:**
- Using `http://` instead of `https://`
- Using `www.domain.com` when site is configured as `domain.com` (or vice versa)
- Extra or missing trailing slashes
- Different query parameters
- Typos or extra spaces
- Using a different port number

### Missing Refresh Token

If you see "Google did not return a refresh token":

1. Go to https://myaccount.google.com/permissions
2. Find your app in the list
3. Remove access to it
4. Go back to NV oOS and click "Connect Gmail Account" again
5. Make sure to grant all requested permissions

## Security Best Practices

Following [Google OAuth 2.0 Best Practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices) and [Apigee Security Guidelines](https://docs.cloud.google.com/architecture/best-practices-securing-applications-and-apis-using-apigee):

1. **Never share your Client Secret** - treat it like a password
   - NV oOS automatically encrypts Client Secret before storage
   - Never commit credentials to version control
   - Use environment variables for credentials when possible

2. **Use HTTPS** - OAuth requires secure connections in production
   - Google OAuth will reject http:// redirect URIs (except localhost)
   - Use a valid SSL/TLS certificate from a trusted CA
   - Test OAuth flows in production-like environment

3. **Limit scopes** - only request the permissions you need
   - Gmail: `gmail.readonly` for read-only access
   - Google Drive: `drive.readonly` and `drive.file` for file access
   - Never request write access unless absolutely necessary

4. **State parameter (CSRF protection)** - automatically handled by NV oOS
   - State parameter is generated using `wp_generate_uuid4()`
   - State is validated on callback to prevent CSRF attacks
   - State expires after 10 minutes

5. **Rotate credentials** - periodically generate new credentials
   - Create new OAuth clients for different environments
   - Revoke old credentials after migration
   - Monitor for compromised credentials

6. **Monitor usage** - check Google Cloud Console for unusual API activity
   - Review API quotas and usage
   - Set up alerts for quota limits
   - Review authorized applications periodically at https://myaccount.google.com/permissions

7. **Token security** - handled automatically by NV oOS
   - Refresh tokens are encrypted before storage
   - Tokens are never exposed in URLs or logs
   - Tokens are automatically refreshed when expired

## OAuth Implementation Details

**This implementation is compliant with:**

✅ **Google OAuth 2.0 Best Practices**
- Exact redirect URI matching between authorization and token exchange
- State parameter for CSRF protection
- Secure token storage with encryption
- Proper error handling
- HTTPS requirement enforced
- Minimal scope requests

✅ **Apigee OAuth Guidelines**
- Authorization code grant flow
- No wildcards in redirect URIs
- Proper redirect URI configuration
- Token endpoint security
- Correct grant_type usage

✅ **OAuth 2.0 RFC 6749**
- Authorization code flow
- Refresh token support
- Access token refresh
- Error response handling

**For implementation details:**
- Gmail OAuth: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` (lines 1387-1596)
- Google Drive OAuth: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` (lines 1605-1820)
- Legacy Google Drive: `includes/integrations/class-wp-mcp-ai-google-drive-oauth-handler.php`

## Additional Resources

**NV oOS Documentation:**
- [OAuth Verification Checklist](./OAUTH_VERIFICATION_CHECKLIST.md) - Comprehensive verification guide
- [Google Drive Connection Setup](../../GOOGLE_DRIVE_CONNECTION_SETUP.md) - Detailed Drive setup
- [Gmail OAuth Fix Summary](../../../GMAIL_OAUTH_FIX_SUMMARY.md) - Historical OAuth fixes

**Google Documentation:**
- [OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [OAuth 2.0 Best Practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices)
- [Gmail API Reference](https://developers.google.com/gmail/api)
- [Google Drive API Reference](https://developers.google.com/drive/api)

**Apigee Documentation:**
- [OAuth Access Tokens](https://docs.cloud.google.com/apigee/docs/api-platform/security/oauth/access-tokens)
- [Advanced OAuth Topics](https://docs.cloud.google.com/apigee/docs/api-platform/security/oauth/advanced-oauth-20-topics)
- [API Security Best Practices](https://docs.cloud.google.com/architecture/best-practices-securing-applications-and-apis-using-apigee)

## Support

For issues specific to NV oOS:
- Check the [OAuth Verification Checklist](./OAUTH_VERIFICATION_CHECKLIST.md)
- Review the plugin documentation at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs
- Contact support at https://nvdigitalsolutions.com
- Report issues at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

For Google OAuth issues:
- Visit [Google OAuth 2.0 Troubleshooting](https://developers.google.com/identity/protocols/oauth2/resources/troubleshooting)
- Check [Stack Overflow - google-oauth](https://stackoverflow.com/questions/tagged/google-oauth)
- Review [Google Cloud Support](https://cloud.google.com/support)
