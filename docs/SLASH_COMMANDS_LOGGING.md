# Slash Commands Logging Guide

## Overview

This guide explains how to enable and use comprehensive logging for slash commands in the chat client. The system now includes correlation IDs for request tracing, persistent audit logging, timeout handling, and accessibility features.

## New Features (v1.2.0+)

### ✨ Correlation IDs
Every slash command execution gets a unique correlation ID that's tracked from client → server → handler, enabling complete request tracing.

### ✨ Persistent Audit Logging
All command executions are logged to a database table (`wp_mcp_ai_slash_command_audit`) with user, status, duration, and correlation ID.

### ✨ Timeout Handling
Commands automatically timeout after 30 seconds with user-friendly error messages.

### ✨ Complete Token Validation
Assistant credentials (`cred_{id}.{secret}`) are now fully validated against hashed secrets.

### ✨ ARIA Announcements
Screen reader users receive announcements about command execution status.

### ✨ Chat Integration
Slash commands now dispatch events that chat.js can listen to for seamless integration.

## Enabling Logging

### Client-Side Logging (Browser Console)

#### Standard Logging (Always Enabled)
Standard logging is always enabled and includes:
- Initialization status
- Command execution
- Errors and warnings
- API responses

**To view client-side logs:**
1. Open your browser's Developer Tools (F12 or Ctrl+Shift+I)
2. Go to the **Console** tab
3. Type a slash command in the chat (e.g., `/help`)
4. Watch for log messages prefixed with `[SlashCommands]`

**Example standard logs:**
```
[SlashCommands] ✅ Initialized successfully {debugMode: false, hasAutocomplete: true, timestamp: "2026-02-04T20:47:34.540Z"}
[SlashCommands] 🚀 Slash command detected on submit: /help
[SlashCommands] ⚙️ Executing command: /help
[SlashCommands] ✅ Command executed successfully in 234ms
```

#### Debug Mode (Verbose Logging)
For more detailed logging, enable debug mode by adding this to your page:

```javascript
// In browser console or before loading the plugin
window.wpMcpAiDebug = true;
```

Or add to your theme's `functions.php`:

```php
add_action( 'wp_footer', function() {
    ?>
    <script>
        window.wpMcpAiDebug = true;
    </script>
    <?php
} );
```

**Debug mode adds verbose logs including:**
- REST API endpoint details
- Request/response payloads
- DOM element detection
- Autocomplete operations
- Timing information

**Example debug logs:**
```
[SlashCommands:DEBUG] Starting initialization... {readyState: "complete", timestamp: "2026-02-04T20:47:34.540Z"}
[SlashCommands:DEBUG] mcpAiData available: {hasRestUrl: true, hasNonce: true}
[SlashCommands:DEBUG] Chat input found: wp-mcp-ai-chat__input
[SlashCommands:DEBUG] REST API request: {endpoint: "http://example.com/wp-json/mcp-ai/v1/slash-command", hasNonce: true, command: "/help"}
[SlashCommands:DEBUG] Response status: {status: 200, statusText: "OK", ok: true}
```

### Server-Side Logging (PHP Error Log)

#### WordPress Debug Mode
Enable WordPress debugging in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

**Server-side logs are written to:**
- `wp-content/debug.log` (if `WP_DEBUG_LOG` is enabled)
- PHP error log (location depends on server configuration)

**To view server logs:**
```bash
# WordPress debug log
tail -f wp-content/debug.log | grep SlashCommands

# Standard PHP error log (location varies)
tail -f /var/log/php_errors.log | grep SlashCommands
```

**Example server logs:**
```
[04-Feb-2026 20:47:34 UTC] [SlashCommands:REST] execute_command | {"command":"/help","async":false,"user_id":1,"ip":"192.168.1.1","endpoint":"/mcp-ai/v1/slash-command"}
[04-Feb-2026 20:47:34 UTC] [SlashCommands:REST] ✅ command_executed | {"command":"/help","duration":"234ms","has_result":true}
```

#### Plugin Activity Logs
Slash commands are also logged in WordPress options:

```php
// Retrieve last 100 command executions
$logs = get_option( 'wp_mcp_ai_slash_command_logs', array() );

// Each log entry contains:
// - command: Command name (e.g., "help")
// - status: "started", "completed", or "failed"
// - user_id: User who executed the command
// - timestamp: Execution time
// - input: Raw command input
// - context: Execution context
// - error: Error message (if failed)
```

**Via WP-CLI:**
```bash
wp option get wp_mcp_ai_slash_command_logs --format=json
```

## Log Message Reference

### Client-Side Log Prefixes

| Prefix | Type | Description |
|--------|------|-------------|
| `[SlashCommands]` | Standard | Normal operations and status |
| `[SlashCommands:DEBUG]` | Debug | Verbose debugging information |
| `[SlashCommands] ✅` | Success | Successful operations |
| `[SlashCommands] 🚀` | Action | Command execution started |
| `[SlashCommands] ⚙️` | Processing | Command being processed |
| `[SlashCommands] ❌` | Error | Errors and failures |
| `[SlashCommands] ⚠️` | Warning | Warnings and potential issues |

### Server-Side Log Prefixes

| Prefix | Type | Description |
|--------|------|-------------|
| `[SlashCommands:REST]` | Request | REST API requests and operations |
| `[SlashCommands:REST] ✅` | Success | Successful operations |
| `[SlashCommands:REST] ❌` | Error | Errors (always logged, even without WP_DEBUG) |

## Confirming Slash Commands Are Working

### Quick Test

1. **Open browser console** (F12)
2. **Type `/help` in the chat** and press Enter
3. **Check for these logs:**
   ```
   [SlashCommands] 🚀 Slash command detected on submit: /help
   [SlashCommands] ⚙️ Executing command: /help
   [SlashCommands] ✅ Command executed successfully in XXXms
   ```
4. **Verify the result appears in chat** (should show help text)

### Troubleshooting

#### Problem: No logs appear at all

**Possible causes:**
- Slash commands script not loaded
- Chat interface not properly initialized
- JavaScript errors blocking execution

**Check:**
```javascript
// In browser console
console.log('Handler:', window.slashCommands);
console.log('Initialized:', window.slashCommands?.initialized);
```

**Expected output:**
```
Handler: SlashCommandsHandler {initialized: true, ...}
Initialized: true
```

#### Problem: "Chat input not found" warning

**Cause:** Slash commands can't find the chat textarea

**Check:**
```javascript
// In browser console
document.querySelector('.wp-mcp-ai-chat__input');
```

**Should return:** The chat textarea element (not `null`)

#### Problem: "REST API configuration missing" error

**Cause:** `mcpAiData` global object not available

**Check:**
```javascript
// In browser console
console.log(window.mcpAiData);
```

**Expected output:**
```javascript
{
  restUrl: "http://your-site.com/wp-json",
  nonce: "abc123..."
}
```

**Fix:** Ensure the slash-commands script is properly enqueued via the chat shortcode.

#### Problem: API request fails (401/403)

**Cause:** Authentication or permission issues

**Check server logs for:**
```
[SlashCommands:REST] ❌ not_authenticated: No authentication provided
[SlashCommands:REST] ❌ insufficient_permission: User lacks read capability
```

**Fix:**
- Ensure user is logged in
- Check user has at least `read` capability
- Verify nonce is valid

#### Problem: Command not recognized

**Cause:** Command handler not registered or typo in command name

**Check available commands:**
```javascript
// In browser console
fetch(window.mcpAiData.restUrl + '/mcp-ai/v1/slash-command/list', {
  headers: { 'X-WP-Nonce': window.mcpAiData.nonce }
})
.then(r => r.json())
.then(d => console.table(d.commands));
```

## Provider-Specific Notes

### OpenAI Provider

Slash commands work identically with **all providers** (OpenAI, Gemini, Ollama) because they:
1. **Bypass the AI provider** - handled entirely by WordPress
2. **Execute server-side** via the slash command handler
3. **Return results directly** without AI processing

**The AI provider is only used for normal chat messages, not slash commands.**

### Testing with Different Providers

```javascript
// Slash commands work the same regardless of provider
// They do NOT interact with OpenAI, Gemini, or Ollama

// Test command (provider-agnostic):
/help

// This executes:
// 1. Client detects "/" prefix
// 2. Sends to WordPress REST API
// 3. WordPress executes command
// 4. Result displayed in chat
// 5. AI provider never contacted
```

## Advanced Debugging

### Network Tab (Browser DevTools)

1. Open DevTools → **Network** tab
2. Filter by `slash-command`
3. Type `/help` and submit
4. Click the request to see:
   - Request headers (should include `X-WP-Nonce`)
   - Request payload (`{"command":"/help"}`)
   - Response status (should be `200 OK`)
   - Response body (should include `{"success":true,...}`)

### Custom Logging Hook

Add custom logging to your theme/plugin:

```php
add_action( 'wp_mcp_ai_slash_command_logged', function( $log_entry ) {
    error_log( 'Custom slash command log: ' . print_r( $log_entry, true ) );
}, 10, 1 );
```

### Performance Monitoring

Track command execution times:

```javascript
// Add to browser console
window.slashCommands._originalExecute = window.slashCommands.executeCommand;
window.slashCommands.executeCommand = async function(command) {
    const start = performance.now();
    const result = await this._originalExecute.call(this, command);
    console.log(`⏱️ ${command} took ${(performance.now() - start).toFixed(2)}ms`);
    return result;
};
```

## FAQ

### Q: Do I need to enable logging to use slash commands?
**A:** No. Logging is for diagnostics. Slash commands work without any logging enabled.

### Q: Are logs stored permanently?
**A:** Client logs are temporary (browser console only). Server logs in `debug.log` persist but may rotate. The `wp_mcp_ai_slash_command_logs` option keeps the last 100 executions.

### Q: Can I disable console logging?
**A:** Standard logs cannot be disabled (they're essential for diagnostics). Debug logs are opt-in via `wpMcpAiDebug`.

### Q: Do logs contain sensitive information?
**A:** Logs may contain command text and user IDs but never passwords or API keys. Review logs before sharing publicly.

### Q: How do I clear old logs?
**A:** Client logs clear when you refresh. Server logs can be cleared with:
```php
delete_option( 'wp_mcp_ai_slash_command_logs' );
```

## Support

If slash commands still aren't working after enabling logging:

1. **Collect logs:**
   - Browser console output (export as text)
   - Server error log entries
   - Network tab screenshot

2. **Check system status:**
   - WordPress version
   - PHP version
   - Plugin version
   - Active provider (OpenAI/Gemini/Ollama)
   - Browser and version

3. **Report issue:** Include logs and system information in your bug report.

## Correlation ID Tracing

### What is a Correlation ID?

A correlation ID is a unique identifier assigned to each slash command execution. It allows you to trace a request through the entire system:

```
Client (browser) → REST API → Handler → Database
    slash_1738707654_abc123xyz
```

### Finding Correlation IDs

**In Browser Console:**
```javascript
[SlashCommands] 🚀 Executing command: /help | ID: slash_1738707654_abc123xyz
[SlashCommands] ✅ Command executed successfully in 234ms | ID: slash_1738707654_abc123xyz
```

**In Server Logs:**
```
[SlashCommands:REST] execute_command | {"command":"/help","correlation_id":"slash_1738707654_abc123xyz",...}
[SlashCommands:AUDIT] /help | User: 1 | Status: completed | Duration: 234ms | ID: slash_1738707654_abc123xyz
```

### Querying by Correlation ID

**Via PHP:**
```php
// Get audit entry by correlation ID
$audit = new WP_MCP_AI_Slash_Command_Audit();
$entry = $audit->get_by_correlation_id( 'slash_1738707654_abc123xyz' );
print_r( $entry );
```

**Via WP-CLI:**
```bash
wp eval "
\$audit = new WP_MCP_AI_Slash_Command_Audit();
print_r(\$audit->get_by_correlation_id('slash_1738707654_abc123xyz'));
"
```

**Via SQL:**
```sql
SELECT * FROM wp_mcp_ai_slash_command_audit 
WHERE correlation_id = 'slash_1738707654_abc123xyz';
```

## Persistent Audit Logging

### Database Table

All command executions are logged to `wp_mcp_ai_slash_command_audit` with the following columns:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Auto-incrementing primary key |
| command | varchar(255) | The slash command executed |
| user_id | bigint | WordPress user ID |
| status | varchar(20) | completed, failed, or timeout |
| duration_ms | float | Execution time in milliseconds |
| correlation_id | varchar(100) | Unique request identifier |
| result | text | Error message (if failed) or "success" |
| timestamp | datetime | When command was executed |
| ip_address | varchar(45) | IP address of requester |

### Querying Audit Logs

**Get recent executions:**
```php
$audit = new WP_MCP_AI_Slash_Command_Audit();

// Last 50 commands
$logs = $audit->get_logs( array( 'limit' => 50 ) );

// Filter by user
$logs = $audit->get_logs( array( 'user_id' => 1 ) );

// Filter by status
$logs = $audit->get_logs( array( 'status' => 'failed' ) );

// Filter by command
$logs = $audit->get_logs( array( 'command' => '/help' ) );

// Date range
$logs = $audit->get_logs( array(
    'date_from' => '2026-02-01 00:00:00',
    'date_to'   => '2026-02-04 23:59:59',
) );
```

**Get statistics:**
```php
$audit = new WP_MCP_AI_Slash_Command_Audit();
$stats = $audit->get_statistics();

echo "Total executions: " . $stats['total_executions'] . "\n";
echo "Success rate: " . ($stats['completed_count'] / $stats['total_executions'] * 100) . "%\n";
echo "Average duration: " . round($stats['avg_duration'], 2) . "ms\n";
```

### Audit Log Retention

By default, audit logs older than **90 days** are automatically deleted daily. To customize:

```php
// Keep audit logs for 180 days
add_filter( 'wp_mcp_ai_slash_audit_retention_days', function() {
    return 180;
} );
```

### Manual Cleanup

```php
// Delete logs older than 30 days
$audit = new WP_MCP_AI_Slash_Command_Audit();
$deleted = $audit->clean_old_logs( 30 );
echo "Deleted {$deleted} old entries";
```

## Timeout Handling

Commands automatically timeout after **30 seconds** (configurable).

### Changing Timeout

```javascript
// In browser console or custom script
window.slashCommands.executionTimeout = 60000; // 60 seconds
```

### Timeout Logs

**Client-side:**
```javascript
[SlashCommands] ❌ Error after 30000ms: Command execution timeout after 30 seconds | ID: slash_xxx
```

**Server-side:**
The server continues execution (no timeout on PHP side), but the client stops waiting and shows an error.

### Handling Long-Running Commands

For commands that legitimately take > 30 seconds, use **async execution**:

```javascript
// Request async execution
fetch(mcpAiData.restUrl + '/mcp-ai/v1/slash-command', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': mcpAiData.nonce
    },
    body: JSON.stringify({ 
        command: '/long-running-task',
        async: true 
    })
})
.then(r => r.json())
.then(d => {
    console.log('Job ID:', d.job_id);
    // Poll for results...
});
```

## Token Validation

### Assistant Credentials

Assistant credentials follow the format: `cred_{credential_id}.{secret}`

**Example:**
```
Authorization: Bearer cred_abc123.xyz789secretkey
```

### Validation Process

1. **Extract components** from token
2. **Query assistant** with matching `_mcp_ai_credential_id`
3. **Get stored hash** from `_mcp_ai_credential_hash` meta
4. **Compare** using constant-time hash_equals()
5. **Return user ID** from assistant meta

### Validation Logs

**Successful validation:**
```
[SlashCommands:REST] ✅ credential_validated | {"credential_id":"abc123","user_id":5,"assistant_id":42}
```

**Failed validation:**
```
[SlashCommands:REST] ❌ credential_not_found: No assistant found with credential ID | {"credential_id":"abc123"}
[SlashCommands:REST] ❌ credential_invalid_secret: Invalid credential secret | {"credential_id":"abc123"}
```

### Creating Assistant Credentials

```php
// Generate credential ID and secret
$credential_id = 'cred_' . wp_generate_password( 12, false );
$secret        = wp_generate_password( 32, false );
$hash          = hash( 'sha256', $secret );

// Store in assistant post meta
update_post_meta( $assistant_id, '_mcp_ai_credential_id', $credential_id );
update_post_meta( $assistant_id, '_mcp_ai_credential_hash', $hash );
update_post_meta( $assistant_id, '_mcp_ai_user_id', $user_id );

// Full token to provide to client
$token = $credential_id . '.' . $secret;
echo "Token: {$token}";
```

## ARIA Announcements

Screen reader users receive live announcements about command execution:

### Announcement Types

1. **Executing:** "Executing command: /help"
2. **Completed:** "Command completed successfully"
3. **Failed:** "Command failed: {error message}"
4. **Timeout:** "Command error: Command timed out..."

### Testing ARIA

Enable screen reader emulation or check the hidden announcer element:

```javascript
// View current announcement
document.getElementById('wp-mcp-ai-slash-announcer').textContent
```

The announcer is positioned off-screen but accessible to assistive technology.

## Chat Integration

Slash commands now notify chat.js via custom events and global state.

### Listening to Events

```javascript
// In chat.js or custom code
window.addEventListener('slash-command-event', function(e) {
    console.log('Slash command event:', e.detail);
    
    if (e.detail.type === 'command-executed') {
        // Update chat UI
        // e.detail.data contains { command, result, correlationId }
    }
});
```

### Global State

```javascript
// Access last execution
window.wpMcpAiSlashCommandState.lastExecution
// { type: 'command-executed', data: {...}, timestamp: '...' }

// View history (last 50 events)
window.wpMcpAiSlashCommandState.history
// Array of execution events
```

### Event Types

| Event Type | When Fired | Data |
|------------|------------|------|
| `command-executed` | Command completed successfully | `{ command, result, correlationId }` |
| `command-failed` | Command execution failed | `{ command, error, correlationId }` |
| `command-timeout` | Command timed out | `{ command, correlationId }` |

## Testing All Features

### Full Test Checklist

1. **Basic Execution:**
   ```
   /help
   ```
   - ✅ Command executes
   - ✅ Result displays in chat
   - ✅ Correlation ID in console
   - ✅ Audit log created

2. **Timeout:**
   ```javascript
   window.slashCommands.executionTimeout = 1000; // 1 second
   // Then run a slow command
   ```
   - ✅ Timeout after 1 second
   - ✅ Error message shown
   - ✅ Correlation ID in logs

3. **ARIA:**
   - ✅ Screen reader announces execution
   - ✅ Hidden announcer element exists
   - ✅ Announcement updates

4. **Audit:**
   ```php
   $audit = new WP_MCP_AI_Slash_Command_Audit();
   print_r($audit->get_logs(['limit' => 5]));
   ```
   - ✅ Logs appear in database
   - ✅ Correlation IDs match
   - ✅ Duration recorded

5. **Token Validation:**
   ```bash
   curl -X POST https://example.com/wp-json/mcp-ai/v1/slash-command \
     -H "Authorization: Bearer cred_xxx.yyy" \
     -H "Content-Type: application/json" \
     -d '{"command":"/help"}'
   ```
   - ✅ Valid token accepted
   - ✅ Invalid token rejected
   - ✅ Validation logged

## Performance Impact

The new features have minimal performance overhead:

| Feature | Overhead |
|---------|----------|
| Correlation IDs | ~1-2ms (string generation) |
| Audit logging | ~5-10ms (database insert) |
| Timeout handling | 0ms (Promise.race) |
| Token validation | ~10-20ms (database query + hash) |
| ARIA announcements | ~1ms (DOM manipulation) |
| Chat integration | ~1ms (event dispatch) |

**Total:** ~18-34ms per command execution

To disable audit logging (not recommended):

```php
// In wp-config.php or functions.php
add_filter( 'wp_mcp_ai_slash_audit_enabled', '__return_false' );
```

