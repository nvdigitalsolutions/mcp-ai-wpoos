# Chat Transcripts Debug Logging

## Overview

This document explains how to enable and use the debug logging added to the chat-transcripts REST endpoints to diagnose issues with saving and retrieving conversation history.

## Security Note

For security, session keys in debug logs are truncated to show only the first 8 characters (e.g., `abc-123...`). This prevents full session keys from being exposed in log files while still allowing session tracking and correlation between save and retrieve operations.

## Enabling Debug Logging

Debug logging can be enabled via the WordPress admin interface:

1. Log in to WordPress admin
2. Navigate to **Settings → WP oOS**
3. Check the **Enable Logging** checkbox
4. Click **Save Changes**

## Log Format

All debug logs are written to the PHP error log with the prefix `[WP oOS Debug]` and follow this format:

```
[WP oOS Debug] <METHOD> <ENDPOINT>: <details>
```

## Log Locations

### POST /chat-transcripts (Save Transcript)

**1. Incoming Save Request**
```
[WP oOS Debug] POST /chat-transcripts: session_key=abc-123e... assistant_id=14 user_id=1 message_count=5 url=/wp-json/mcp-ai/v1/chat-transcripts
```

**2. Save Success**
```
[WP oOS Debug] POST /chat-transcripts SUCCESS: session_key=abc-123e... assistant_id=14 user_id=1 saved=1 response=200
```

**3. Save Failure**
```
[WP oOS Debug] POST /chat-transcripts FAILED: session_key=abc-123e... assistant_id=14 user_id=1 saved=0 response=500
```

### GET /chat-transcripts/{session_key} (Retrieve Transcript)

**1. Incoming GET Request**
```
[WP oOS Debug] GET /chat-transcripts/{session_key}: session_key=abc-123e... assistant_id=14 user_id=1 url=/wp-json/mcp-ai/v1/chat-transcripts/abc-123e...?user_id=1&assistant_id=14
```

**2. Session Not Found (404)**
```
[WP oOS Debug] GET /chat-transcripts/{session_key} ERROR: session_key=abc-123e... found_session=0 found_messages=0 response=404 error_code=wp_mcp_ai_transcript_not_found
```

**3. Unauthorized Access (403)**
```
[WP oOS Debug] GET /chat-transcripts/{session_key} UNAUTHORIZED: session_key=abc-123e... found_session=1 found_messages=5 response=403
```

**4. Success (200)**
```
[WP oOS Debug] GET /chat-transcripts/{session_key} SUCCESS: session_key=abc-123e... found_session=1 found_messages=5 response=200
```

## Viewing Logs

The location of your PHP error log depends on your server configuration:

### Common Locations

- **Apache**: `/var/log/apache2/error.log` or `/var/log/httpd/error_log`
- **Nginx**: `/var/log/nginx/error.log`
- **XAMPP**: `C:\xampp\apache\logs\error.log`
- **MAMP**: `/Applications/MAMP/logs/php_error.log`
- **Custom**: Check `php.ini` for `error_log` directive

### Via SSH/Terminal

```bash
# Tail the error log (updates in real-time)
tail -f /path/to/php-error.log | grep "WP oOS Debug"

# View last 50 lines containing debug logs
grep "WP oOS Debug" /path/to/php-error.log | tail -50
```

### Via WordPress Admin

Some server management plugins may provide access to error logs through the WordPress admin interface.

## Troubleshooting Common Issues

### Issue: "The requested chat transcript could not be found" (404)

**Check the logs for:**
```
[WP oOS Debug] GET /chat-transcripts/{session_key} ERROR: ... found_session=0 found_messages=0 response=404
```

**Possible causes:**
1. **Session key mismatch** - The session_key in the request doesn't match any saved transcript
2. **JetEngine CCT not configured** - Transcript storage requires JetEngine Custom Content Types
3. **Database issue** - The CCT table may not be accessible or may be missing data

**Debugging steps:**
1. Compare the first 8 characters of session_key from the save log with the get log (they should match)
2. Verify JetEngine is active and CCT is configured for chat transcripts
3. Check database for chat transcript records

### Issue: Save succeeds but retrieve fails

**Check for this pattern:**
```
[WP oOS Debug] POST /chat-transcripts SUCCESS: session_key=abc-123e... ... saved=1 response=200
[WP oOS Debug] GET /chat-transcripts/{session_key} ERROR: session_key=abc-123e... found_session=0 found_messages=0 response=404
```

**Note:** Session keys are truncated to the first 8 characters in logs. If the first 8 characters match but you still get 404, the full session keys may differ.

**Possible causes:**
1. **Session key normalization** - The save and retrieve may be normalizing session keys differently
2. **Timing issue** - Database write may not be committed before retrieve
3. **User ID mismatch** - If filtering by user_id, the IDs may not match

**Debugging steps:**
1. Compare the first 8 characters of session_key from both logs - they should match exactly
2. Add a small delay between save and retrieve
3. Check user_id values in both requests

### Issue: Unauthorized access (403)

**Check the logs for:**
```
[WP oOS Debug] GET /chat-transcripts/{session_key} UNAUTHORIZED: ... found_session=1 found_messages=N response=403
```

**Possible causes:**
1. **User mismatch** - User trying to access another user's transcript
2. **Authentication failure** - Guest token or bearer token authentication failed
3. **Permission check** - User lacks required capabilities

**Debugging steps:**
1. Compare user_id in the save request with user_id in the get request
2. Verify authentication headers are being sent correctly
3. Check user capabilities and permissions

## Disabling Debug Logging

To disable debug logging:

1. Navigate to **Settings → WP oOS**
2. Uncheck the **Enable Logging** checkbox
3. Click **Save Changes**

Or via code (in `wp-config.php` or a plugin):
```php
// Get current settings
$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

// Disable logging
$settings['enable_logging'] = false;

// Update settings
update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
```

## Performance Considerations

- Debug logging uses `error_log()` which is generally lightweight
- Logs are only written when `enable_logging` is true
- Consider disabling logging in production once issues are resolved
- Monitor log file size and rotate logs regularly to prevent disk space issues

## Security Considerations

- Error logs may contain sensitive information (session keys, user IDs, assistant IDs)
- Ensure error logs are not publicly accessible via web
- Regularly clean up old error logs
- Never commit error logs to version control
- Restrict access to error logs to authorized administrators only
