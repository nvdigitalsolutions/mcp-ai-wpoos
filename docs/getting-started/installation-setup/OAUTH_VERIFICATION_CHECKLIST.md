# OAuth 2.0 Configuration Verification Checklist

This document provides a comprehensive checklist for verifying that Gmail and Google Drive OAuth connections are configured correctly according to Google's OAuth 2.0 best practices and Apigee recommendations.

## Overview

The NV oOS plugin implements OAuth 2.0 authorization code grant flow for both Gmail and Google Drive connections. This implementation has been verified against:

- [Google OAuth 2.0 Best Practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices)
- [Apigee OAuth Documentation](https://docs.cloud.google.com/apigee/docs/api-platform/security/oauth/access-tokens)
- [Google Cloud API Security](https://docs.cloud.google.com/architecture/best-practices-securing-applications-and-apis-using-apigee)

## Critical Requirements

### 1. HTTPS Requirement

**⚠️ MANDATORY: Your WordPress site MUST use HTTPS in production.**

- ✅ Google OAuth only allows HTTPS redirect URIs in production
- ✅ Only `http://localhost` is permitted for local development
- ✅ Self-signed certificates are NOT accepted by Google
- ✅ Use a valid SSL/TLS certificate from a trusted Certificate Authority

**How to verify:**
```bash
# Your site URL should start with https://
wp option get siteurl
```

If your site is not using HTTPS:
1. Obtain an SSL certificate (Let's Encrypt, Cloudflare, or commercial CA)
2. Install the certificate on your web server
3. Update WordPress site URL to use https://
4. Force HTTPS redirects in your web server configuration

### 2. Redirect URI Exact Match

**The redirect URI must match EXACTLY between:**
- The URI registered in Google Cloud Console OAuth credentials
- The URI sent in authorization requests
- The URI sent in token exchange requests

**Gmail Redirect URI Format:**
```
https://YOUR-DOMAIN.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback
```

**Google Drive Redirect URI Format:**
```
https://YOUR-DOMAIN.com/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=google_drive_oauth_callback
```

**Legacy Google Drive Redirect URI Format (if using core integration):**
```
https://YOUR-DOMAIN.com/wp-admin/admin-post.php?action=wp_mcp_ai_google_drive_oauth_callback
```

**Common mistakes that cause `redirect_uri_mismatch` errors:**
- ❌ Using `http://` instead of `https://`
- ❌ Using `www.domain.com` when site is configured as `domain.com` (or vice versa)
- ❌ Trailing slash: `/wp-admin/` vs `/wp-admin`
- ❌ Extra spaces or characters in the URI
- ❌ Using wildcards (not supported by Google)
- ❌ Using a different port number
- ❌ Using IP address instead of domain name

**How to verify:**

1. Go to **WordPress Admin → NV oOS → Remote Sites**
2. Edit your Gmail or Google Drive connection
3. Copy the **Authorized Redirect URI** displayed in the form (read-only field)
4. Go to [Google Cloud Console - Credentials](https://console.cloud.google.com/apis/credentials)
5. Edit your OAuth 2.0 Client ID
6. Under "Authorized redirect URIs", verify it matches EXACTLY

### 3. OAuth Client Credentials

**Required credentials from Google Cloud Console:**
- Client ID (public identifier)
- Client Secret (must be kept secret)

**Security requirements:**
✅ Client Secret is encrypted before storage in WordPress database
✅ Never commit Client Secret to version control
✅ Never expose Client Secret in client-side code
✅ Rotate credentials if compromised

**How to verify:**

1. Go to [Google Cloud Console - Credentials](https://console.cloud.google.com/apis/credentials)
2. Find your OAuth 2.0 Client ID
3. Copy the Client ID and Client Secret
4. In WordPress Admin → NV oOS → Remote Sites, paste them into your connection
5. Save the connection
6. Verify the Client Secret is not visible in plain text after saving

### 4. OAuth Scopes

**Gmail Connection Scopes:**
```
https://www.googleapis.com/auth/gmail.readonly
```

**Permissions granted:**
- Read all email messages
- Read email metadata (sender, subject, date)
- Search emails
- Read labels and categories

**⚠️ Does NOT grant permission to:**
- Send emails
- Delete emails
- Modify emails
- Access other Google services

**Google Drive Connection Scopes:**
```
https://www.googleapis.com/auth/drive.file
https://www.googleapis.com/auth/drive.readonly
```

**Permissions granted:**
- Read files and metadata
- Access files created by the app
- List folders and files
- Download file content

**⚠️ Does NOT grant permission to:**
- Delete files
- Modify existing files (that weren't created by the app)
- Share files
- Change permissions
- Access other Google services

**How to verify scopes are appropriate:**

1. Review your use case requirements
2. Ensure you're only requesting scopes you actually need
3. Document why each scope is required
4. If you need additional scopes, update the authorization flow

**To modify scopes (requires code change):**

For Gmail, edit line 1426 in `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`:
```php
'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
```

For Google Drive, edit line 1644 in the same file:
```php
'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly',
```

### 5. State Parameter (CSRF Protection)

**The implementation already handles this correctly.**

✅ State parameter is generated using `wp_generate_uuid4()`
✅ State is stored in WordPress transient with user ID and connection ID
✅ State expires after 10 minutes
✅ State is validated on callback before processing authorization code
✅ Request is rejected if state doesn't match

**You don't need to configure this - it's automatic.**

### 6. Refresh Token Acquisition

**The implementation already handles this correctly.**

✅ Uses `access_type=offline` to request refresh tokens
✅ Uses `prompt=consent` to ensure refresh token is always returned
✅ Preserves existing refresh token if Google doesn't return a new one
✅ Encrypts refresh token before storage

**Common issue: "No refresh token received"**

This happens when:
1. User has previously authorized the app
2. User doesn't revoke previous authorization
3. Google doesn't return a new refresh token

**Solution:**
1. Go to [Google Account Permissions](https://myaccount.google.com/permissions)
2. Find your app in the list
3. Click "Remove access"
4. Try the OAuth flow again

### 7. API Enablement in Google Cloud

**Required APIs:**

For Gmail connections:
- ✅ Gmail API must be enabled

For Google Drive connections:
- ✅ Google Drive API must be enabled

**How to verify:**

1. Go to [Google Cloud Console - APIs & Services - Library](https://console.cloud.google.com/apis/library)
2. Search for "Gmail API" or "Google Drive API"
3. Click on the API
4. Verify it shows "API enabled"
5. If not enabled, click "Enable"

### 8. OAuth Consent Screen Configuration

**Required for production:**

1. Go to [Google Cloud Console - OAuth consent screen](https://console.cloud.google.com/apis/credentials/consent)
2. Configure the consent screen with:
   - App name
   - User support email
   - Developer contact email
   - Privacy policy URL (if publishing)
   - Terms of service URL (if publishing)
3. Add required scopes (Gmail API and/or Google Drive API)
4. Add test users (if app is in testing mode)

**Publishing status:**
- **Testing:** Only test users can authorize (max 100 users)
- **In production:** Any Google user can authorize (requires verification for sensitive scopes)

**For internal use, "Testing" status is sufficient.**

## Verification Checklist

Use this checklist to verify your OAuth setup:

### Pre-Setup Verification

- [ ] WordPress site is using HTTPS with valid SSL certificate
- [ ] Google Cloud Project is created
- [ ] Gmail API is enabled (for Gmail connections)
- [ ] Google Drive API is enabled (for Google Drive connections)
- [ ] OAuth consent screen is configured
- [ ] OAuth 2.0 Client ID is created (type: Web application)

### Gmail Connection Setup

- [ ] Client ID is copied from Google Cloud Console
- [ ] Client Secret is copied from Google Cloud Console
- [ ] Redirect URI is copied from NV oOS admin UI (exact copy)
- [ ] Redirect URI is added to Google Cloud Console OAuth credentials
- [ ] Redirect URI matches exactly (HTTPS, domain, path, parameters)
- [ ] Connection is saved in WordPress
- [ ] "Connect to Gmail" button is clicked
- [ ] Google authorization screen appears
- [ ] Correct Google account is selected
- [ ] Permissions are reviewed and accepted
- [ ] Redirected back to WordPress with success message
- [ ] User email is displayed in connection settings
- [ ] Refresh token field shows encrypted value

### Google Drive Connection Setup

- [ ] Client ID is copied from Google Cloud Console
- [ ] Client Secret is copied from Google Cloud Console
- [ ] Redirect URI is copied from NV oOS admin UI (exact copy)
- [ ] Redirect URI is added to Google Cloud Console OAuth credentials
- [ ] Redirect URI matches exactly (HTTPS, domain, path, parameters)
- [ ] Connection is saved in WordPress
- [ ] "Connect to Google Drive" button is clicked
- [ ] Google authorization screen appears
- [ ] Correct Google account is selected
- [ ] Permissions are reviewed and accepted
- [ ] Redirected back to WordPress with success message
- [ ] User email is displayed in connection settings
- [ ] Refresh token field shows encrypted value
- [ ] Optional: Folder ID is specified (if scoping access)

### Post-Setup Verification

- [ ] Connection test passes successfully
- [ ] Assistant can access Gmail/Google Drive via tools
- [ ] OAuth tokens are being refreshed automatically
- [ ] No errors in WordPress debug log related to OAuth
- [ ] No errors in browser console

## Common OAuth Errors and Solutions

### Error: `redirect_uri_mismatch`

**Cause:** The redirect URI doesn't match what's registered in Google Cloud Console.

**Solutions:**
1. Copy the exact redirect URI from NV oOS admin UI (it's in a read-only field)
2. Go to Google Cloud Console and verify it matches exactly
3. Check for:
   - HTTP vs HTTPS
   - www vs non-www
   - Trailing slashes
   - Extra spaces or characters
   - Different parameters
4. Wait 30-60 seconds after saving in Google Cloud Console
5. Try OAuth flow again in incognito/private browsing mode

### Error: `invalid_client`

**Cause:** Client ID or Client Secret is incorrect.

**Solutions:**
1. Verify you copied the correct Client ID from Google Cloud Console
2. Verify you copied the correct Client Secret from Google Cloud Console
3. Make sure you're using the Web application OAuth client (not Android, iOS, etc.)
4. Check that you're in the correct Google Cloud Project
5. Try creating a new OAuth client and using those credentials

### Error: `access_denied`

**Cause:** User denied permission during authorization.

**Solutions:**
1. Try the OAuth flow again
2. Make sure to click "Allow" on the Google consent screen
3. Review the requested permissions - if they seem excessive, contact support

### Error: `No refresh token received`

**Cause:** Google didn't return a refresh token because user previously authorized.

**Solutions:**
1. Go to https://myaccount.google.com/permissions
2. Find your app
3. Click "Remove access"
4. Try OAuth flow again
5. Make sure to select the same Google account

### Error: `API not enabled`

**Cause:** Gmail API or Google Drive API is not enabled in Google Cloud Project.

**Solutions:**
1. Go to Google Cloud Console - APIs & Services - Library
2. Search for the required API (Gmail or Google Drive)
3. Click the API
4. Click "Enable"
5. Wait a few moments for enablement to propagate
6. Try OAuth flow again

### Error: `User rate limit exceeded`

**Cause:** Too many OAuth requests in a short time.

**Solutions:**
1. Wait 60 seconds
2. Try OAuth flow again
3. Implement request throttling in your application if needed

## Security Best Practices

### Token Security

✅ **DO:**
- Store refresh tokens encrypted
- Store client secrets encrypted
- Use HTTPS for all OAuth flows
- Validate state parameter on every callback
- Implement proper error handling
- Log OAuth errors for debugging
- Rotate credentials periodically
- Revoke tokens when no longer needed

❌ **DON'T:**
- Store tokens in plain text
- Commit credentials to version control
- Share Client Secret publicly
- Use HTTP in production
- Skip state validation
- Ignore OAuth errors
- Reuse credentials across environments
- Keep unused tokens active

### Access Control

✅ **DO:**
- Limit OAuth access to admin users only (`manage_options` capability)
- Verify user capabilities before starting OAuth flow
- Validate connection ownership in callback
- Use WordPress nonces for connection management actions
- Implement proper session management

❌ **DON'T:**
- Allow any user to configure OAuth connections
- Skip capability checks
- Allow cross-user OAuth flows
- Bypass WordPress security features

### Monitoring and Auditing

✅ **DO:**
- Monitor OAuth error rates
- Log OAuth authorization events
- Track token refresh failures
- Alert on suspicious OAuth activity
- Periodically review authorized applications in Google

❌ **DON'T:**
- Ignore OAuth errors
- Skip logging
- Leave stale connections active
- Forget to review permissions

## Testing OAuth Flows

### Test Environment Setup

1. Use a separate Google Cloud Project for testing
2. Use test Google accounts (not production accounts)
3. Enable debug logging in WordPress:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```
4. Use browser developer tools to inspect network requests

### Test Cases

**Test 1: First-time authorization**
1. Create a new connection
2. Enter Client ID and Client Secret
3. Save connection
4. Click "Connect" button
5. Verify redirect to Google
6. Verify consent screen appears
7. Grant permissions
8. Verify redirect back to WordPress
9. Verify refresh token is stored
10. Verify user email is displayed

**Test 2: Re-authorization with existing token**
1. Use an existing connection with refresh token
2. Click "Connect" button
3. Verify redirect to Google
4. Verify consent screen appears (prompt=consent)
5. Grant permissions
6. Verify redirect back to WordPress
7. Verify refresh token is updated or preserved

**Test 3: Error handling - User denies permission**
1. Start OAuth flow
2. Click "Deny" on consent screen
3. Verify error message is displayed
4. Verify user is redirected back to connection page

**Test 4: Error handling - Invalid credentials**
1. Enter incorrect Client ID
2. Save and try to connect
3. Verify appropriate error message

**Test 5: Redirect URI validation**
1. Temporarily change redirect URI in Google Cloud Console
2. Try OAuth flow
3. Verify redirect_uri_mismatch error
4. Fix redirect URI
5. Verify flow works again

**Test 6: State parameter validation**
1. Start OAuth flow
2. Modify state parameter in callback URL
3. Verify OAuth flow is rejected
4. Verify appropriate error message

**Test 7: Connection test**
1. Complete OAuth flow successfully
2. Click "Test" connection button
3. Verify connection test passes
4. Verify API request is successful

**Test 8: Token refresh**
1. Complete OAuth flow
2. Wait for access token to expire (typically 1 hour)
3. Use connection in an assistant
4. Verify token is refreshed automatically
5. Verify API calls continue to work

## Implementation Details

### Code Locations

**Gmail OAuth (Pro addon):**
- Authorization start: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php:1387`
- OAuth callback: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php:1448`
- Connection manager: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

**Google Drive OAuth (Pro addon):**
- Authorization start: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php:1605`
- OAuth callback: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php:1666`
- Connection manager: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

**Legacy Google Drive OAuth (Core):**
- Authorization start: `includes/integrations/class-wp-mcp-ai-google-drive-oauth-handler.php:29`
- OAuth callback: `includes/integrations/class-wp-mcp-ai-google-drive-oauth-handler.php:95`

### OAuth Flow Sequence

1. **User initiates connection**
   - Admin clicks "Connect" button
   - Nonce is verified
   - Connection credentials are validated

2. **Authorization request**
   - State parameter is generated and stored in transient
   - Authorization URL is constructed with all parameters
   - User is redirected to Google authorization endpoint

3. **User authorizes**
   - Google displays consent screen
   - User reviews and grants permissions
   - Google redirects back to WordPress with authorization code and state

4. **Token exchange**
   - State parameter is validated
   - Authorization code is exchanged for tokens
   - Refresh token is stored (encrypted)
   - User email is fetched and stored

5. **Success**
   - User is redirected back to connection page
   - Success message is displayed
   - Connection is ready to use

## Compliance Summary

This implementation has been verified to comply with:

✅ **Google OAuth 2.0 Best Practices**
- Exact redirect URI matching
- State parameter for CSRF protection
- Secure token storage
- Proper error handling
- HTTPS requirement
- Minimal scope requests

✅ **Apigee OAuth Guidelines**
- Authorization code grant flow
- Proper redirect URI configuration
- No wildcards in URIs
- Token endpoint security
- Proper grant_type usage

✅ **WordPress Security Standards**
- Capability checks
- Nonce verification for actions (not OAuth callbacks)
- State parameter for OAuth callbacks
- Input sanitization
- Output escaping
- Encrypted credential storage

## Support Resources

- [Google OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground/)
- [Apigee OAuth Documentation](https://docs.cloud.google.com/apigee/docs/api-platform/security/oauth/oauth-home)
- [WordPress OAuth Best Practices](https://developer.wordpress.org/apis/security/oauth/)
- [NV oOS Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs)

## Changelog

### Version 1.0.0 (2026-01-13)
- Initial OAuth verification checklist
- Documented Gmail and Google Drive OAuth flows
- Verified compliance with Google OAuth 2.0 best practices
- Verified compliance with Apigee guidelines
- Added comprehensive troubleshooting guide
- Added security best practices
- Added testing procedures

## License

This documentation is part of the Open Operator System (NV oOS) project and is licensed under GPLv3 or later.
