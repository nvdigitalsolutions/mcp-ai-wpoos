# Google Drive Remote Connection Setup Guide

This guide explains how to set up a Google Drive remote connection in the Open Operator System (NV oOS) plugin.

## Overview

The Google Drive connection type allows your WordPress AI assistants to interact with Google Drive files and folders using OAuth2 authentication. This connection supports:

- **OAuth2 Authentication** - Secure authentication using Google's OAuth2 flow
- **Folder Scoping** - Optional limitation to specific folders
- **Read-Only Access** - Safe access to Drive files and metadata
- **Token Refresh** - Automatic token refresh for long-lived connections

## Prerequisites

Before setting up a Google Drive connection, you need:

1. **Google Cloud Project** - A project in Google Cloud Console
2. **OAuth 2.0 Credentials** - Client ID and Client Secret
3. **Google Drive API** - Enabled in your project
4. **WordPress Admin Access** - Permission to manage plugin settings

## Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Click **Create Project** or select an existing project
3. Enter a project name (e.g., "WordPress AI Assistant")
4. Click **Create**

## Step 2: Enable Google Drive API

1. In your Google Cloud Project, go to **APIs & Services → Library**
2. Search for "Google Drive API"
3. Click on **Google Drive API**
4. Click **Enable**

## Step 3: Create OAuth 2.0 Credentials

1. Go to **APIs & Services → Credentials**
2. Click **Create Credentials** → **OAuth client ID**
3. If prompted, configure the OAuth consent screen first:
   - User Type: **External** (for testing) or **Internal** (for G Suite organizations)
   - App name: Your app name
   - Support email: Your email
   - Scopes: Add `drive.readonly` and `drive.metadata.readonly`
   - Test users: Add your Gmail address for testing
4. Select **Application type**: **Web application**
5. Enter a name (e.g., "WordPress AI Google Drive")
6. Under **Authorized redirect URIs**, add:
   ```
   https://your-wordpress-site.com/wp-admin/admin-post.php?action=wp_mcp_ai_google_drive_oauth_callback
   ```
   Replace `your-wordpress-site.com` with your actual WordPress site URL.
7. Click **Create**
8. **Copy the Client ID and Client Secret** - you'll need these later

## Step 4: Create Connection in WordPress

1. Log in to your WordPress admin dashboard
2. Go to **WP-MCP-AI → Remote Sites**
3. Click **Add New Connection**
4. Fill in the form:

   - **Connection Name**: A descriptive name (e.g., "My Google Drive")
   - **Site URL**: Will be auto-set to `https://www.googleapis.com/drive/v3`
   - **Connection Type**: Select **Google Drive (Cloud Storage)**
   - **OAuth Client ID**: Paste your Client ID from Step 3
   - **OAuth Client Secret**: Paste your Client Secret from Step 3
   - **Refresh Token**: Leave blank initially (will be obtained via OAuth)
   - **Folder ID** (Optional): Enter a folder ID if you want to scope access to a specific folder
   - **Google User Email** (Optional): For reference purposes
   - **Status**: Check **Connection enabled**

5. Click **Save Connection**

## Step 5: Authorize Access (First Time Only)

After creating the connection, you need to authorize WordPress to access your Google Drive:

1. Go to **Settings → NV oOS → Tools → Connections** (or wherever you store Google OAuth settings)
2. Click the **Connect Google Drive** button
3. You'll be redirected to Google's authorization page
4. Sign in with your Google account
5. Review the permissions requested
6. Click **Allow**
7. You'll be redirected back to WordPress with a success message
8. The refresh token will be automatically saved to your connection

## Optional: Folder Scoping

If you want to limit access to a specific Google Drive folder:

1. Open Google Drive in your browser
2. Navigate to the folder you want to use
3. The URL will look like: `https://drive.google.com/drive/folders/1a2b3c4d5e6f7g8h9i0j`
4. Copy the folder ID: `1a2b3c4d5e6f7g8h9i0j`
5. Paste it into the **Folder ID** field when creating/editing the connection

When a folder ID is provided, API requests will be scoped to that folder and its subfolders.

## OAuth Scopes

The Google Drive connection requests the following OAuth scopes:

- `https://www.googleapis.com/auth/drive.readonly` - Read-only access to files
- `https://www.googleapis.com/auth/drive.metadata.readonly` - Read-only access to file metadata

These scopes ensure that the AI assistant can read files but cannot modify or delete them.

## Using the Connection

Once configured, you can use the Google Drive connection with AI assistants:

1. Go to **Assistants** and edit an assistant
2. In the **Remote Connections** metabox, enable the Google Drive connection
3. The assistant can now interact with Google Drive files within the granted scopes

## Troubleshooting

### "OAuth Client ID and client secret are required"
- Ensure you've entered both Client ID and Client Secret
- Make sure there are no extra spaces in the credentials

### "Google returned an error during authorisation"
- Check that the redirect URI in Google Cloud matches your WordPress URL exactly
- Ensure the Google Drive API is enabled in your project
- Verify that your Google account has access to the OAuth consent screen

### "Google did not return a refresh token"
- Revoke existing grants: Go to https://myaccount.google.com/permissions
- Find your app and click **Remove access**
- Try the OAuth flow again with `prompt=consent` to force re-authorization

### Connection Test Fails
- Verify the connection is enabled
- Check that the refresh token is present (after OAuth flow completion)
- Ensure the OAuth credentials haven't expired
- Check WordPress error logs for detailed error messages

## Security Best Practices

1. **Use HTTPS** - Always use HTTPS for your WordPress site to secure OAuth tokens
2. **Limit Scopes** - Only request the scopes you need (read-only by default)
3. **Folder Scoping** - Use folder IDs to limit access to specific folders
4. **Regular Audits** - Periodically review authorized applications in Google account settings
5. **Credential Security** - Keep Client Secret secure; never commit to version control
6. **Test Accounts** - Use test accounts during development

## API Reference

### Connection Type
- **Type**: `google_drive`
- **Authentication**: OAuth2 with authorization code grant
- **Base URL**: `https://www.googleapis.com/drive/v3`

### Connection Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `client_id` | string | Yes | OAuth 2.0 Client ID from Google Cloud Console |
| `client_secret` | string | Yes | OAuth 2.0 Client Secret (encrypted in storage) |
| `refresh_token` | string | No | OAuth refresh token (obtained via OAuth flow) |
| `folder_id` | string | No | Google Drive folder ID for scoping access |
| `user_email` | string | No | Google account email for reference |

### OAuth Endpoints

- **Authorization**: `https://accounts.google.com/o/oauth2/v2/auth`
- **Token**: `https://oauth2.googleapis.com/token`
- **API Base**: `https://www.googleapis.com/drive/v3`

### WordPress Hooks

#### Actions
- `admin_post_wp_mcp_ai_google_drive_oauth_start` - Initiates OAuth flow
- `admin_post_wp_mcp_ai_google_drive_oauth_callback` - Handles OAuth callback

#### Filters
- `wp_mcp_ai_google_drive_oauth_scope` - Modify requested OAuth scopes
- `wp_mcp_ai_google_drive_oauth_authorize_endpoint` - Customize authorization endpoint
- `wp_mcp_ai_google_drive_oauth_token_endpoint` - Customize token endpoint

## Related Documentation

- [Remote Connection Types](../REMOTE_CONNECTION_TYPES_IMPLEMENTATION.md)
- [Remote Site Manager](../features/tools/remote-wp-connection.md)
- [OAuth Manager](../features/oauth-integrations.md)
- [Google Drive API Documentation](https://developers.google.com/drive/api/v3/about-sdk)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs
