# Slash Commands Logging - Quick Start

## 🚀 How to Confirm Slash Commands Are Working (1 Minute)

### Step 1: Enable Debug Mode
Open browser console (F12) and run:
```javascript
window.wpMcpAiDebug = true;
```

### Step 2: Test a Command
Type in chat and press Enter:
```
/help
```

### Step 3: Check Console Logs
You should see:
```
[SlashCommands] ✅ Initialized successfully
[SlashCommands] 🚀 Slash command detected: /help | ID: slash_1738707654_abc123
[SlashCommands] ✅ Command executed successfully in 234ms | ID: slash_1738707654_abc123
```

**If you see these logs → Slash commands are working! ✅**

---

## 🔍 Provider-Specific Answer

### Question: Do slash commands work with OpenAI as provider?

**Answer: YES! Slash commands work with ALL providers:**
- ✅ OpenAI
- ✅ Google Gemini  
- ✅ Ollama (local AI)

**Why?** Slash commands:
- Are handled entirely by WordPress
- Execute server-side (PHP)
- Do NOT interact with AI provider
- Only normal messages go to OpenAI/Gemini/Ollama

**Test it:**
```
/help      → Handled by WordPress (not OpenAI)
Hello AI   → Sent to OpenAI/Gemini/Ollama
```

---

## 📊 Logging Levels

### Standard Logging (Always On)
```javascript
[SlashCommands] ✅ Initialized successfully
[SlashCommands] 🚀 Slash command detected
[SlashCommands] ✅ Command executed successfully
[SlashCommands] ❌ Error: Command failed
```

### Debug Logging (Opt-In)
```javascript
[SlashCommands:DEBUG] Starting initialization...
[SlashCommands:DEBUG] REST API request: {...}
[SlashCommands:DEBUG] Response status: {...}
[SlashCommands:DEBUG] Response data: {...}
```

**Enable debug:** `window.wpMcpAiDebug = true;`

---

## 🔗 Correlation ID Tracing

Every command gets a unique ID:
```
Client: slash_1738707654_abc123
Server: slash_1738707654_abc123
Database: slash_1738707654_abc123
```

**Find it in logs:**
```javascript
// Browser console
[SlashCommands] 🚀 Executing: /help | ID: slash_1738707654_abc123

// Server log (wp-content/debug.log)
[SlashCommands:REST] execute_command | {"correlation_id":"slash_1738707654_abc123",...}

// Database
SELECT * FROM wp_mcp_ai_slash_command_audit 
WHERE correlation_id = 'slash_1738707654_abc123';
```

---

## 💾 Audit Logging

All command executions are logged to database:

**Query recent commands:**
```php
$audit = new WP_MCP_AI_Slash_Command_Audit();
$logs = $audit->get_logs( array( 'limit' => 10 ) );
foreach ( $logs as $log ) {
    echo "{$log['command']} by user {$log['user_id']} - {$log['status']}\n";
}
```

**Via WP-CLI:**
```bash
wp eval "
\$audit = new WP_MCP_AI_Slash_Command_Audit();
print_r(\$audit->get_logs(['limit' => 10]));
"
```

**Statistics:**
```php
$audit = new WP_MCP_AI_Slash_Command_Audit();
$stats = $audit->get_statistics();
echo "Total executions: {$stats['total_executions']}\n";
echo "Success rate: " . round($stats['completed_count'] / $stats['total_executions'] * 100, 2) . "%\n";
```

---

## ⏱️ Timeout Testing

**Default timeout:** 30 seconds

**Test timeout:**
```javascript
// Set 1-second timeout
window.slashCommands.executionTimeout = 1000;

// Run a slow command (will timeout)
// Type in chat: /slow-command
```

**Reset timeout:**
```javascript
window.slashCommands.executionTimeout = 30000; // 30 seconds
```

---

## 🔐 Token Validation

**Format:** `cred_{id}.{secret}`

**Test with curl:**
```bash
curl -X POST https://your-site.com/wp-json/mcp-ai/v1/slash-command \
  -H "Authorization: Bearer cred_abc123.xyz789secret" \
  -H "Content-Type: application/json" \
  -d '{"command":"/help"}'
```

**Check validation logs:**
```
[SlashCommands:REST] ✅ credential_validated | {"credential_id":"abc123","user_id":5}
```

---

## ♿ ARIA Testing

**Check announcer exists:**
```javascript
document.getElementById('wp-mcp-ai-slash-announcer')
// Should return a hidden div element
```

**View current announcement:**
```javascript
document.getElementById('wp-mcp-ai-slash-announcer').textContent
// "Command completed successfully"
```

**Test with screen reader:** Enable your screen reader and run `/help`

---

## 📡 Chat Integration

**Listen to events:**
```javascript
window.addEventListener('slash-command-event', function(e) {
    console.log('Event type:', e.detail.type);
    console.log('Data:', e.detail.data);
    console.log('Correlation ID:', e.detail.data.correlationId);
});
```

**Check global state:**
```javascript
// Last execution
window.wpMcpAiSlashCommandState.lastExecution

// History (last 50 events)
window.wpMcpAiSlashCommandState.history
```

---

## 🐛 Troubleshooting

### Problem: No logs appear

**Solution:**
1. Check handler initialized:
   ```javascript
   window.slashCommands?.initialized
   // Should be: true
   ```

2. Check mcpAiData exists:
   ```javascript
   window.mcpAiData
   // Should have: { restUrl: "...", nonce: "..." }
   ```

3. Enable debug mode:
   ```javascript
   window.wpMcpAiDebug = true;
   ```

### Problem: "Chat input not found"

**Solution:**
Check element exists:
```javascript
document.querySelector('.wp-mcp-ai-chat__input')
// Should return: <textarea> element
```

### Problem: "REST API configuration missing"

**Solution:**
Verify scripts loaded:
```javascript
window.mcpAiData?.restUrl  // Should be defined
window.mcpAiData?.nonce    // Should be defined
```

---

## 📚 Full Documentation

- **Main Guide:** `docs/SLASH_COMMANDS_LOGGING.md`
- **Test Suite:** `tests/manual/test-slash-commands-enhanced.html`
- **Slash Commands Guide:** `docs/slash-commands-guide.md`

---

## ✅ Success Checklist

Run through this checklist to verify everything works:

- [ ] Open browser console
- [ ] Enable debug mode: `window.wpMcpAiDebug = true`
- [ ] Type `/help` in chat
- [ ] See initialization logs
- [ ] See execution logs with correlation ID
- [ ] See success confirmation
- [ ] Result appears in chat
- [ ] Check audit logs (PHP or database)
- [ ] Verify correlation ID matches across logs
- [ ] Test ARIA announcer exists
- [ ] Test chat integration event fired

**All checked?** Slash commands are fully functional! 🎉

---

## 🆘 Still Having Issues?

1. **Collect logs:**
   - Browser console output (copy/paste)
   - Server error log entries
   - Network tab screenshot

2. **Check system:**
   - WordPress version
   - PHP version
   - Plugin version
   - Browser and version

3. **Report issue** with logs and system info

---

## 📖 Key Takeaways

1. **Slash commands are provider-agnostic** - They work the same with OpenAI, Gemini, and Ollama
2. **Debug mode is your friend** - Enable it for verbose logging
3. **Correlation IDs trace everything** - Follow them through logs
4. **Audit logs persist** - Query them for historical data
5. **Timeouts protect users** - 30 seconds default
6. **Security is built-in** - Token validation, audit trails, capability checks

---

Last updated: 2026-02-04
Plugin version: 1.2.0+
