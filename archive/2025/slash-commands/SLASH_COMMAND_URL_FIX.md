# Slash Command REST API URL Fix

## Issue
The REST API endpoints for slash commands had a double slash causing 404 errors:
- Error: `/wp-json//mcp-ai/v1/slash-command/list` 
- Expected: `/wp-json/mcp-ai/v1/slash-command/list`

This affected all slash command requests in the chat client when using OpenAI or any other provider.

## Root Cause
The problem was in how the REST API URL was constructed:

1. **PHP Side** (`includes/slash-commands/slash-commands-init.php`):
   ```php
   'restUrl' => esc_url_raw( rest_url() )
   ```
   This returned `/wp-json/` with a trailing slash.

2. **JavaScript Side** (`assets/js/slash-commands.js` and `command-autocomplete.js`):
   ```javascript
   const endpoint = window.mcpAiData?.restUrl + '/mcp-ai/v1/slash-command';
   ```
   This added a leading slash, creating: `/wp-json/` + `/mcp-ai/v1/...` = `/wp-json//mcp-ai/v1/...`

## Solution
Updated the code to follow the pattern used elsewhere in the codebase:

1. **PHP Side**: Set `restUrl` to include the full namespace with trailing slash:
   ```php
   'restUrl' => esc_url_raw( trailingslashit( rest_url( 'mcp-ai/v1' ) ) )
   ```
   Result: `/wp-json/mcp-ai/v1/`

2. **JavaScript Side**: Remove the namespace prefix and leading slash from paths:
   ```javascript
   const endpoint = window.mcpAiData?.restUrl + 'slash-command';
   ```
   Result: `/wp-json/mcp-ai/v1/` + `slash-command` = `/wp-json/mcp-ai/v1/slash-command`

## Files Changed
- `includes/slash-commands/slash-commands-init.php` - Updated restUrl construction
- `assets/js/slash-commands.js` - Updated 2 endpoint constructions
- `assets/js/command-autocomplete.js` - Updated 1 endpoint construction
- `docs/SLASH_COMMANDS_LOGGING.md` - Updated documentation examples

## Testing
Created validation script that confirms:
- ✅ New URLs construct correctly: `/wp-json/mcp-ai/v1/slash-command`
- ✅ Old URLs were broken: `/wp-json//mcp-ai/v1/slash-command`
- ✅ All endpoint paths now work properly

## Impact
- **Minimal change**: Only 4 files modified, 6 lines changed
- **Consistent pattern**: Aligns with how other parts of the codebase construct REST URLs
- **No breaking changes**: Only fixes broken functionality
- **All providers affected**: The fix applies to OpenAI, Gemini, Ollama, and all other providers

## Related Files
The following files use the same correct pattern:
- `includes/class-wp-mcp-ai-shortcode.php`
- `includes/admin/class-wp-mcp-ai-build-assistant-page.php`
- `includes/admin/class-wp-mcp-ai-admin-test-page-base.php`
- `includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-chats-widget.php`

## Verification
To verify the fix works in a WordPress environment:
1. Open browser console on a page with the chat widget
2. Run: `console.log(window.mcpAiData.restUrl)`
3. Expected output: `/wp-json/mcp-ai/v1/` (not `/wp-json/`)
4. Try using a slash command (e.g., `/help`)
5. Check Network tab - URL should be `/wp-json/mcp-ai/v1/slash-command` (no double slash)
