# Voice Chat Troubleshooting Guide

This document provides troubleshooting steps for voice chat issues in Open Operator System (WP oOS).

## Common Issue: Voice Chat Returns 404 Error

### Symptoms

- Voice recording completes successfully
- Audio file uploads to WordPress media library (visible in Console: `POST .../wp-json/wp/v2/media`)
- Transcription request fails with 404 error (Console: `POST .../wp-json/mcp-ai/v1/tools 404 (Not Found)`)
- User sees error message: "Voice chat service is temporarily unavailable"

### Root Causes

#### 1. WordPress Permalinks Not Flushed

The `/tools` REST API endpoint requires WordPress permalinks to be properly configured.

**Solution:**
1. Go to WordPress Admin → Settings → Permalinks
2. Click "Save Changes" (even without making changes)
3. This flushes the rewrite rules and re-registers all REST API routes

#### 2. Security Plugin or .htaccess Rules Blocking REST API

Some security plugins or server configurations may block custom REST API endpoints.

**Solution:**
1. Check your security plugin settings (WordFence, Sucuri, iThemes Security, etc.)
2. Ensure REST API is not blocked
3. Whitelist the `/wp-json/mcp-ai/v1/*` endpoint pattern
4. Check `.htaccess` for rules that might block REST requests

#### 3. Plugin Activation Issues

The Tools Controller might not be properly loaded if there was an error during plugin activation.

**Solution:**
1. Deactivate WP oOS plugin
2. Check PHP error logs for any errors
3. Re-activate the plugin
4. Flush permalinks (Settings → Permalinks → Save)

#### 4. PHP Version Compatibility

WP oOS requires PHP 7.4 or higher.

**Solution:**
1. Check your PHP version (WordPress Admin → Site Health → Info → Server)
2. Upgrade to PHP 7.4 or higher if needed
3. After upgrade, deactivate and re-activate the plugin

#### 5. Conflicting Plugins

Some plugins may interfere with REST API routes.

**Solution:**
1. Temporarily deactivate other plugins
2. Test voice chat
3. Re-activate plugins one by one to identify conflicts

### Diagnostic Information

When voice chat fails, the browser console now provides detailed diagnostic information:

#### Upload Stage Logs
```javascript
Voice chat: Uploading audio file {
  fileName: "voice-chat-1234567890.webm",
  fileSize: 123456,
  fileType: "audio/webm",
  endpoint: "https://example.com/wp-json/wp/v2/media"
}

Voice chat: Upload response received {
  status: 200,
  statusText: "OK",
  ok: true
}

Voice chat: Media file created successfully {
  id: 789,
  fileId: "wp-attachment-789",
  name: "voice-chat-1234567890.webm"
}
```

#### Transcription Stage Logs
```javascript
Voice chat: Requesting transcription {
  endpoint: "https://example.com/wp-json/mcp-ai/v1/tools",
  attachmentId: 789,
  tool: "transcribe_openai_audio"
}
```

#### Error Logs
```javascript
Voice chat: Transcription request failed {
  status: 404,
  statusText: "Not Found",
  url: "https://example.com/wp-json/mcp-ai/v1/tools",
  endpoint: "https://example.com/wp-json/mcp-ai/v1/tools",
  attachmentId: 789
}
```

### Testing the `/tools` Endpoint

You can test if the endpoint is accessible using your browser's developer tools or a REST API client.

#### Using Browser Console

```javascript
fetch('/wp-json/mcp-ai/v1/tools', {
  method: 'GET',
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce,
    'Content-Type': 'application/json'
  },
  credentials: 'same-origin'
})
.then(response => {
  console.log('Status:', response.status);
  return response.json();
})
.then(data => console.log('Response:', data))
.catch(error => console.error('Error:', error));
```

**Expected Response:**
- Status: 200
- Response: Array of available tools

**If you get 404:**
- The endpoint is not registered → Follow permalink flush steps above
- Check that the plugin is active
- Check PHP error logs

#### Using cURL

```bash
curl -X GET https://example.com/wp-json/mcp-ai/v1/tools \
  -H "X-WP-Nonce: YOUR_NONCE_HERE" \
  -H "Content-Type: application/json"
```

### Checking REST API Health

WordPress provides a built-in REST API test:

```
https://example.com/wp-json/
```

This should return a JSON response with available routes. Look for:

```json
{
  "routes": {
    "/mcp-ai/v1/tools": {
      "namespace": "mcp-ai/v1",
      "methods": ["GET", "POST"],
      ...
    }
  }
}
```

If `/mcp-ai/v1/tools` is missing from the routes list, the endpoint is not registered.

### Advanced Debugging

#### Enable WordPress Debug Mode

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check `wp-content/debug.log` for errors related to:
- REST API registration
- WP_MCP_AI_REST class
- WP_MCP_AI_REST_Tools_Controller class

#### Enable WP oOS Logging

In WordPress Admin:
1. Go to Settings → WP oOS
2. Enable "Debug Logging"
3. Use voice chat
4. Check the logs for detailed error information

#### Check Server Error Logs

- Apache: `/var/log/apache2/error.log`
- Nginx: `/var/log/nginx/error.log`
- Check for PHP errors, permission issues, or server-level blocks

### Workaround: Manual Transcription

If voice chat cannot be fixed immediately, users can:

1. Record audio separately using their device's voice recorder
2. Upload the audio file using the attachment button in the chat
3. Ask the AI to transcribe it: "Please transcribe the audio file I just uploaded"

### Still Having Issues?

If none of the above solutions work:

1. Check the GitHub repository for known issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
2. Open a new issue with:
   - WordPress version
   - PHP version
   - WP oOS version
   - Console error logs
   - Server error logs (if accessible)
   - Steps to reproduce

### Related Documentation

- [REST API Documentation](../../reference/api/rest-api.md)
- [Tool Reference](../../reference/tools/tool-reference.md)
- [Deployment Troubleshooting](../../getting-started/installation-setup/deployment-troubleshooting.md)
