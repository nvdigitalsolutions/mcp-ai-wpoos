# Google OAuth Setup Guide for Gmail Integration

This guide explains how to configure OAuth 2.0 credentials in the Google Cloud Console for Gmail integration in WP oOS (Open Operator System).

## Prerequisites

1. A Google Cloud account
2. Access to the Google Cloud Console (https://console.cloud.google.com/)
3. Admin access to your WordPress site

## Step-by-Step Setup

### 1. Create or Select a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Either:
   - Create a new project, or
   - Select an existing project from the dropdown at the top

### 2. Enable Gmail API

1. In the Google Cloud Console, go to **APIs & Services** > **Library**
2. Search for "Gmail API"
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
2. Enter a name for your OAuth client (e.g., "WP oOS Gmail Integration")

#### Required Configuration for Your Site

Based on your site URL (e.g., `https://bots.nvdigital.solutions`), configure the following:

##### **Authorized JavaScript origins**
Add your site's base URL without any path:
```
https://bots.nvdigital.solutions
```

**Note:** Do NOT include trailing slashes or paths like `/wp-admin/`

##### **Authorized redirect URIs**
Add the exact callback URL that Google will redirect to after authentication:
```
https://bots.nvdigital.solutions/wp-admin/admin-post.php?action=wp_mcp_ai_gmail_oauth_callback
```

**Important:** This URL must be exact, including the query parameter `action=wp_mcp_ai_gmail_oauth_callback`

### 5. Save and Copy Credentials

1. Click **CREATE**
2. A dialog will appear with your:
   - **Client ID** (looks like: `123456789-abcdef.apps.googleusercontent.com`)
   - **Client Secret** (looks like: `GOCSPX-abcd1234...`)
3. **Copy both values** - you'll need them in the next step

### 6. Configure WP oOS

1. In your WordPress admin panel, go to **WP oOS Dashboard** > **Tools** > **Connections**
2. Click on the **Gmail** tab
3. Enter your:
   - **Gmail OAuth Client ID**: Paste the Client ID from step 5
   - **Gmail OAuth Client Secret**: Paste the Client Secret from step 5
4. Click **Save Settings**
5. After saving, you'll see a **Connect Gmail Account** button
6. Click it to authorize WP oOS to access your Gmail account

## Troubleshooting

### 400 Bad Request Error

If you encounter a "400 Bad Request" error when clicking the Connect button:

1. **Verify the redirect URI** in Google Cloud Console exactly matches:
   ```
   https://YOUR-SITE-URL/wp-admin/admin-post.php?action=wp_mcp_ai_gmail_oauth_callback
   ```
2. Make sure there are no extra spaces or characters
3. Ensure the OAuth client type is set to "Web application"
4. Clear your browser cache and try again

### "redirect_uri_mismatch" Error

This error occurs when the redirect URI doesn't match what's configured in Google Cloud Console:

1. Check the error message for the exact redirect URI Google received
2. Add that exact URI to the "Authorized redirect URIs" list in Google Cloud Console
3. Wait a few minutes for changes to propagate
4. Try connecting again

### Missing Refresh Token

If you see "Google did not return a refresh token":

1. Go to https://myaccount.google.com/permissions
2. Find your app in the list
3. Remove access to it
4. Go back to WP oOS and click "Connect Gmail Account" again
5. Make sure to grant all requested permissions

## Security Best Practices

1. **Never share your Client Secret** - treat it like a password
2. **Use HTTPS** - OAuth requires secure connections in production
3. **Limit scopes** - only request the Gmail permissions you need
4. **Rotate credentials** - periodically generate new credentials
5. **Monitor usage** - check Google Cloud Console for unusual API activity

## Support

For issues specific to WP oOS:
- Check the plugin documentation
- Contact support at https://nvdigitalsolutions.com

For Google OAuth issues:
- Visit [Google OAuth 2.0 documentation](https://developers.google.com/identity/protocols/oauth2)
- Check [Gmail API documentation](https://developers.google.com/gmail/api)
