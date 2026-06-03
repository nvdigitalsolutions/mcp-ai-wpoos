# Test Assistant Feature Enhancements

## Overview

The Admin Test Assistant page now has full feature parity with the chat-client (shortcode/widget) implementation, making it a comprehensive tool for backend testing with access to all available tools and capabilities.

## New Features

### 1. Sensitive Tools Access (`allowSensitiveTools: true`)

**What it does:** Admin users now have access to ALL tools, including those marked as "sensitive" that are normally restricted from frontend chat interfaces.

**Why it matters:** Backend testing requires full access to verify tool behavior, including:
- Code execution tools
- System modification tools
- Database manipulation tools
- File management tools

**Implementation:**
```javascript
// In admin-test-assistant.js
allowSensitiveTools: true, // Admin users can access all tools
```

This flag bypasses the restriction trait `WP_MCP_AI_Tool_Restrict_From_Chat_Client` that blocks certain tools from the `/chat-client` endpoint.

### 2. File Upload Configuration

**What it does:** Proper MIME type, file extension, and accept attribute configuration for file uploads.

**Supported file types:**
- Image files (JPEG, PNG, GIF, WebP, etc.)
- Document files (PDF, DOC, DOCX, etc.)
- Data files (JSON, JSONL, NDJSON, CSV, etc.)
- All types supported by `WP_MCP_AI_Message_Attachments` class

**Implementation:**
- `fileAccept`: Comma-separated list of MIME types and extensions (e.g., "image/jpeg,.jpg,.png")
- `allowedImageMimes`: Array of allowed image MIME types
- `allowedFileMimes`: Array of allowed file MIME types  
- `allowedExtensions`: Array of allowed file extensions (without dots)

**PHP Methods:**
```php
// In class-wp-mcp-ai-admin-test-assistant.php
private function get_file_upload_config();
private function get_allowed_extensions_for_mimes( array $allowed_mimes );
private function build_file_accept_tokens( array $image_mimes, array $file_mimes, array $extensions );
```

### 3. Transcript Saving (`saveTranscript: true`)

**What it does:** Automatically saves chat transcripts for debugging and analysis.

**Why it matters:** Backend testing often requires reviewing conversation history to:
- Debug tool execution sequences
- Analyze AI decision-making
- Reproduce issues
- Train and improve assistants

**Implementation:**
```javascript
// In admin-test-assistant.js
saveTranscript: true, // Enable transcript saving for admin testing
```

Transcripts are saved to:
- Browser localStorage (24-hour retention)
- JetEngine CCT (if enabled, permanent retention)

### 4. Tool Shortcuts

**What it does:** Displays pre-configured task shortcuts for the selected assistant.

**Implementation:**
- Tool shortcuts are loaded from assistant configuration
- Passed via data attribute on "Test" button
- Parsed and applied to chat instance on modal open

**Example shortcuts:**
```json
[
  {
    "tool": "custom",
    "label": "Analyze Code",
    "payload": "Please analyze the following code for security issues..."
  },
  {
    "tool": "search_posts", 
    "label": "Find Recent Posts",
    "payload": "Find posts from the last 7 days"
  }
]
```

**PHP Method:**
```php
// In class-wp-mcp-ai-admin-test-assistant.php
private function get_assistant_tool_shortcuts( $assistant_id );
```

## Configuration Flow

### Backend (PHP)

1. **`enqueue_assets()`** - Prepares configuration for JavaScript
   - Calls `get_file_upload_config()` to load file upload settings
   - Merges config into `wpMcpAiChat` localized script

2. **`render_page()`** - Displays assistant list with test buttons
   - Loads tool shortcuts for each assistant
   - Adds `data-tool-shortcuts` attribute to buttons

### Frontend (JavaScript)

1. **Button Click Handler** - Captures assistant data
   - Reads `data-assistant-id`, `data-assistant-title`, `data-tool-shortcuts`
   - Parses JSON tool shortcuts

2. **`openTestModal()`** - Initializes chat instance
   - Creates chat HTML structure
   - Builds configuration object with all features:
     - `allowSensitiveTools: true`
     - `saveTranscript: true`
     - `toolShortcuts: [...]`
     - File upload configuration
   - Stores config in `window.wpMcpAiChatInstances[instanceId]`

3. **`initializeChatInstance()`** - Activates chat.js
   - Triggers DOMContentLoaded event
   - chat.js reads config from `wpMcpAiChatInstances`
   - Initializes with full feature set

## Usage

### For Administrators

1. Navigate to **AI Assistants → Test Assistant**
2. Click **Test** button next to any assistant
3. Modal opens with full-featured chat interface
4. Features available:
   - All tools (including sensitive ones)
   - File uploads (if you have `upload_files` capability)
   - Tool shortcuts (if configured for assistant)
   - Transcript saving (automatic)
   - Streaming responses

### For Developers

**Accessing saved transcripts:**
```javascript
// Browser console
const transcripts = localStorage.getItem('wp_mcp_ai_transcripts');
console.log(JSON.parse(transcripts));
```

**Checking instance configuration:**
```javascript
// Browser console (after opening test modal)
console.log(window.wpMcpAiChatInstances);
```

## Differences from Frontend Chat Client

| Feature | Frontend Chat | Test Assistant |
|---------|--------------|----------------|
| Sensitive Tools | Configurable (default: off) | Always enabled |
| Transcript Saving | Configurable (default: on) | Always enabled |
| User Context | Any user with capability | Admin users only |
| Access Control | Per-assistant capabilities | `manage_options` required |
| File Uploads | Based on `upload_files` cap | Based on `upload_files` cap |
| Tool Shortcuts | From assistant config | From assistant config |
| Streaming | Configurable | Always enabled |

## Security Considerations

### Why Sensitive Tools Are Enabled

The test assistant is restricted to admin users (`manage_options` capability), who already have:
- Full WordPress admin access
- Ability to install/edit plugins
- Database access
- File system access

Therefore, allowing sensitive tools in this context:
- ✅ Doesn't increase security risk (admins already have these permissions)
- ✅ Provides necessary testing capabilities
- ✅ Helps identify tool issues before public deployment

### Access Control

1. **PHP Level:** `current_user_can( 'manage_options' )` check in `render_page()`
2. **Menu Level:** Admin menu restricted to `manage_options`
3. **REST API:** Standard WordPress nonce verification
4. **Modal:** Only accessible within WordPress admin

## Testing

Tests are located in `tests/test-admin-test-assistant-features.php`:

- `test_file_upload_config_is_set()` - Verifies file upload configuration
- `test_get_allowed_extensions_for_mimes()` - Tests MIME to extension mapping
- `test_build_file_accept_tokens()` - Tests accept attribute generation
- `test_get_assistant_tool_shortcuts()` - Tests shortcut loading
- `test_admin_page_registers()` - Verifies submenu registration
- `test_assets_enqueued_on_page()` - Checks asset enqueueing
- `test_admin_has_upload_capability()` - Validates admin permissions

**Run tests:**
```bash
composer run test -- tests/test-admin-test-assistant-features.php
```

## Future Enhancements

Potential improvements for future releases:

- [ ] Add tool usage statistics/analytics in test interface
- [ ] Show available tools list in modal sidebar
- [ ] Add "Copy as shortcode" button to save configurations
- [ ] Export/import test conversations
- [ ] A/B testing between different assistant configurations
- [ ] Performance metrics (token usage, response time)

## Related Files

- `includes/admin/class-wp-mcp-ai-admin-test-assistant.php` - PHP backend
- `assets/js/admin-test-assistant.js` - JavaScript frontend
- `assets/css/admin-test-assistant.css` - Styling
- `tests/test-admin-test-assistant-features.php` - Tests
- `includes/tools/trait-wp-mcp-ai-tool-restrict-from-chat-client.php` - Tool restriction trait

## Changelog

### Version 1.1.0 (Current)
- Added `allowSensitiveTools` flag for admin users
- Added file upload configuration (MIME types, extensions)
- Enabled transcript saving by default
- Added tool shortcuts support
- Added comprehensive test coverage
