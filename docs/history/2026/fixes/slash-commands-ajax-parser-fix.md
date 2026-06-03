# Slash Commands Dashboard AJAX & Parser Fix

## Issues Fixed

### 1. Command Parser Not Handling Hyphens (CRITICAL)
**Problem**: Commands with hyphens were being incorrectly parsed.
- `/optimize-perf` was parsed as `/optimize` (command) with `-perf` (as arguments)
- This caused "Command not found" errors for all hyphenated commands

**Root Cause**: The regex pattern in `class-wp-mcp-ai-slash-command-parser.php` only matched word characters `\w` (alphanumeric + underscore) but not hyphens.

**Fix**: Updated regex from `/^(\w+)(.*)$/s` to `/^([a-zA-Z0-9_-]+)(.*)$/s` to include hyphens.

**Affected Commands** (now working):
- `/optimize-perf`
- `/clean-content`
- `/sync-docs`
- `/next-task`
- Any other hyphenated command

### 2. Missing Console Logging
**Problem**: No debug output in browser console made it impossible to diagnose AJAX issues.

**Fix**: Added comprehensive console logging throughout the JavaScript:
- Dashboard initialization logs
- Button click and event logs
- AJAX request logs (URL, nonce, data)
- AJAX response logs (success and error)
- Detailed XHR error information

### 3. Missing Server-Side Logging
**Problem**: No server-side logs for command/workflow execution made debugging difficult.

**Fix**: Added detailed PHP logging to AJAX handlers:
- Permission denial logs
- Command/workflow execution attempts
- Handler initialization failures
- Execution errors with full context
- Successful executions

## How to Use the Logging

### Browser Console
1. Open browser DevTools (F12)
2. Go to Console tab
3. Navigate to the Slash Commands dashboard
4. Execute commands or workflows
5. View detailed logs:

```
[SlashCommandsDashboard] Initializing...
[SlashCommandsDashboard] wpMcpAiSlashCommands: {ajaxUrl: "...", nonce: "..."}
[SlashCommandsDashboard] Setting up tabs...
[Test Tab] Test tab initialized
[Test Tab] Execute button clicked
[Test Tab] Command input value: /optimize-perf
[AJAX] Sending command request: /optimize-perf
[AJAX] URL: https://example.com/wp-admin/admin-ajax.php
[AJAX] Nonce: Present
[AJAX] Success response: {success: true, data: {...}}
[Test Tab] Command executed successfully
```

### Server Logs
Enable logging in WordPress admin:
1. Go to **Settings → NV oOS**
2. Enable **Logging**
3. Execute commands/workflows
4. View logs in **Settings → NV oOS → View Logs**

Log events to look for:
- `command_execution_attempt` - When a command is executed
- `command_execution_error` - When a command fails
- `command_execution_success` - When a command succeeds
- `workflow_execution_attempt` - When a workflow is executed
- `workflow_execution_error` - When a workflow fails
- `workflow_execution_success` - When a workflow succeeds
- `command_permission_denied` - When user lacks permissions
- `workflow_permission_denied` - When user lacks permissions

## Testing

### Manual Test
Run the included test script to verify the parser fix:

```bash
cd /path/to/plugin
php tests/manual/test-command-parser-hyphen.php
```

Expected output:
```
=== Command Parser Hyphen Fix Test ===

✅ PASS: /optimize-perf => optimize-perf
✅ PASS: /optimize-perf --dry-run => optimize-perf
✅ PASS: /clean-content --post-id=123 => clean-content
✅ PASS: /sync-docs => sync-docs
✅ PASS: /next-task --filter=drafts => next-task
✅ PASS: /help => help
✅ PASS: /test_command => test_command
✅ PASS: /my-multi-word-command => my-multi-word-command
✅ PASS: /test-123-command => test-123-command

=== Test Results ===
Passed: 9
Failed: 0
Total:  9

✅ All tests passed!
```

### Unit Tests
Run PHPUnit tests (if WordPress test framework is installed):

```bash
phpunit tests/test-command-parser-hyphen-fix.php
```

## Files Changed

1. **assets/js/admin-slash-commands-dashboard.js**
   - Added console logging to all methods
   - Added initialization logging
   - Added AJAX request/response logging

2. **includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php**
   - Added server-side logging to `ajax_execute_command()`
   - Added server-side logging to `ajax_execute_workflow()`
   - Logs permission checks, execution attempts, errors, and successes

3. **includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php**
   - **Line 51**: Fixed regex to allow hyphens in command names
   - Changed from `/^(\w+)(.*)$/s` to `/^([a-zA-Z0-9_-]+)(.*)$/s`

4. **tests/test-command-parser-hyphen-fix.php** (NEW)
   - Comprehensive PHPUnit test suite for the parser fix
   - 13 test methods covering various scenarios

5. **tests/manual/test-command-parser-hyphen.php** (NEW)
   - Standalone manual test script
   - Can run without WordPress test framework

## Troubleshooting Guide

### Common Issues After This Fix

#### Issue: "HTTP 403 error" when executing workflows
This is typically a **permission issue**, not a parser issue. The 403 error occurs when:
- The workflow requires higher privileges than the user has
- Example: `site-health` workflow requires `manage_options` (Administrator only) because it uses `/optimize-perf`

**Solution**: 
- Check the error message in the console - it now shows which tasks require higher privileges
- Use an account with the required capability
- Or choose a different workflow that matches your permissions

Example error message:
```
Error: You do not have sufficient permissions to execute this workflow. 
The following tasks require higher privileges: optimize-perf (requires manage_options)
```

#### Issue: Console shows "wpMcpAiSlashCommands is not defined"
**Solution**: The page may not have properly loaded the JavaScript. Check:
1. Clear browser cache
2. Ensure you're on the Slash Commands dashboard page
3. Check browser console for script loading errors

#### Issue: Nonce verification fails
**Solution**: 
1. Check console - it will show "Nonce: MISSING" if nonce isn't present
2. Try refreshing the page
3. Check if any security plugins are interfering

## Security Considerations

The logging added:
- ✅ Does NOT log sensitive data (passwords, API keys)
- ✅ Logs user IDs but not user credentials
- ✅ Only shows nonce presence (true/false), not actual nonce value
- ✅ Requires appropriate WordPress capabilities to access logs
- ✅ Follows WordPress coding standards

## Performance Impact

The changes have **minimal performance impact**:
- Console logging only runs in browser (no server overhead)
- Server-side logging is conditional (checks if logger class exists)
- Regex change is a minor modification (no performance difference)
- No new database queries added

## Related Documentation

- [Workflow 403 Error Fix](../fixes/workflow-403-error-fix.md) - Details on workflow capability validation
- [FlowHub 403 Troubleshooting](../troubleshooting/FLOWHUB_403_TROUBLESHOOTING.md) - For FlowHub API 403 errors
- [Slash Commands Documentation](../slash-commands.md) - General slash commands documentation

## Support

If you continue to experience issues:
1. Enable logging in WordPress admin
2. Open browser console
3. Try to reproduce the issue
4. Copy console logs and server logs
5. Create an issue with the logs attached
