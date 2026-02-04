# Slash Commands Logging Guide

## Overview

This guide explains how to enable and use logging for slash commands in the chat client to diagnose issues and confirm that slash commands are working properly.

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
