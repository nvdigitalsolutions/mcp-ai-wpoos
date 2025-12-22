# Test Assistant Interface - Visual Reference

## Before vs After Enhancements

### BEFORE (Limited Features)
```
┌─────────────────────────────────────────────────────────────┐
│ Test AI Assistants                                          │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────────────────────────────────────────┐ │
│ │ Assistant Name │ Provider │ Model    │ Tools │ Actions │ │
│ ├────────────────────────────────────────────────────────┤ │
│ │ GPT-4 Helper   │ OpenAI   │ gpt-4    │ 5     │ [Test] │ │
│ └────────────────────────────────────────────────────────┘ │
│                                                              │
└─────────────────────────────────────────────────────────────┘

When clicking [Test]:
┌─────────────────────────────────────────────────────────────┐
│ GPT-4 Helper                                        [Close]  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ [Chat Messages Area]                                        │
│                                                              │
│ ┌──────────────────────────────────────────────────────┐   │
│ │ Ask something...                                      │   │
│ └──────────────────────────────────────────────────────┘   │
│                                              [Send]          │
│                                                              │
└─────────────────────────────────────────────────────────────┘

CONFIG:
✗ allowSensitiveTools: false
✗ saveTranscript: false
✗ toolShortcuts: []
✗ fileAccept: ''
✗ allowedImageMimes: []
✗ allowedFileMimes: []
```

### AFTER (Full Features) ✅
```
┌─────────────────────────────────────────────────────────────┐
│ Test AI Assistants                                          │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────────────────────────────────────────┐ │
│ │ Assistant Name │ Provider │ Model    │ Tools │ Actions │ │
│ ├────────────────────────────────────────────────────────┤ │
│ │ GPT-4 Helper   │ OpenAI   │ gpt-4    │ 5     │ [Test] │ │
│ │ ↳ data-tool-shortcuts='[{...}]'                        │ │
│ └────────────────────────────────────────────────────────┘ │
│                                                              │
└─────────────────────────────────────────────────────────────┘

When clicking [Test]:
┌─────────────────────────────────────────────────────────────┐
│ GPT-4 Helper                                        [Close]  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────────────────────────────────────────┐ │
│ │ 🎯 Quick Tasks:                                        │ │
│ │ [Analyze Code] [Find Posts] [Generate Image]          │ │
│ │ ↑ Tool Shortcuts from Assistant Config                │ │
│ └────────────────────────────────────────────────────────┘ │
│                                                              │
│ [Chat Messages Area]                                        │
│ ↑ Transcripts auto-saved to localStorage & JetEngine CCT   │
│                                                              │
│ ┌──────────────────────────────────────────────────────┐   │
│ │ Ask something...                                      │   │
│ └──────────────────────────────────────────────────────┘   │
│ 📎 Attach File (JPEG, PNG, PDF, JSON, etc.)                │
│ ↑ File uploads fully configured                            │
│                                              [Send]          │
│                                                              │
└─────────────────────────────────────────────────────────────┘

CONFIG:
✅ allowSensitiveTools: true     ← Admin access to ALL tools
✅ saveTranscript: true          ← Auto-save for debugging
✅ toolShortcuts: [{...}]        ← Quick task buttons
✅ fileAccept: 'image/*,.pdf,...' ← Proper file types
✅ allowedImageMimes: [...]      ← Image support
✅ allowedFileMimes: [...]       ← Document support
```

## Feature Details

### 1. Sensitive Tools Access ✅
```javascript
// Before
allowSensitiveTools: false
// ❌ Tools like WPCode snippets, file operations blocked

// After
allowSensitiveTools: true
// ✅ Admin users can test ALL tools including:
//    - Code execution tools
//    - Database operations
//    - File system tools
//    - System configuration
```

### 2. File Upload Configuration ✅
```javascript
// Before
fileAccept: ''
allowedImageMimes: []
allowedFileMimes: []
// ❌ File upload button disabled or non-functional

// After
fileAccept: 'image/jpeg,.jpg,.png,application/pdf,.pdf,application/json,.json'
allowedImageMimes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
allowedFileMimes: ['application/pdf', 'application/json', 'text/plain']
// ✅ Full file upload support for:
//    - Images (for vision tools)
//    - Documents (for analysis)
//    - Data files (for processing)
```

### 3. Transcript Saving ✅
```javascript
// Before
saveTranscript: false
// ❌ Conversations lost after page refresh

// After
saveTranscript: true
// ✅ Conversations saved to:
//    - localStorage (24 hour retention)
//    - JetEngine CCT (permanent storage if available)
// ✅ Benefits:
//    - Debug tool execution flows
//    - Review AI decisions
//    - Reproduce issues
//    - Train assistants
```

### 4. Tool Shortcuts ✅
```javascript
// Before
toolShortcuts: []
// ❌ No quick access to common tasks

// After
toolShortcuts: [
  {
    "tool": "custom",
    "label": "Analyze Code",
    "payload": "Please analyze the following code for security issues..."
  },
  {
    "tool": "search_posts",
    "label": "Find Recent Posts",
    "payload": "Find posts published in the last 7 days"
  }
]
// ✅ Quick task buttons appear above chat
// ✅ One-click common operations
// ✅ Loaded from assistant configuration
```

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                   USER CLICKS "TEST" BUTTON                  │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│              JAVASCRIPT (admin-test-assistant.js)            │
├─────────────────────────────────────────────────────────────┤
│ 1. Read button data attributes:                             │
│    - data-assistant-id="123"                                 │
│    - data-assistant-title="GPT-4 Helper"                     │
│    - data-tool-shortcuts='[{...}]'                           │
│                                                              │
│ 2. Parse tool shortcuts from JSON                           │
│                                                              │
│ 3. Get file config from window.wpMcpAiChat:                 │
│    - fileAccept                                              │
│    - allowedImageMimes                                       │
│    - allowedFileMimes                                        │
│    - allowedExtensions                                       │
│                                                              │
│ 4. Build configuration object:                              │
│    {                                                         │
│      assistantId: 123,                                       │
│      allowSensitiveTools: true,  ← Enable all tools         │
│      saveTranscript: true,       ← Auto-save                │
│      toolShortcuts: [...],       ← From button data         │
│      fileAccept: '...',          ← From PHP config          │
│      ...                                                     │
│    }                                                         │
│                                                              │
│ 5. Store in window.wpMcpAiChatInstances[instanceId]        │
│                                                              │
│ 6. Open modal and initialize chat.js                        │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│                   CHAT.JS INITIALIZATION                     │
├─────────────────────────────────────────────────────────────┤
│ 1. Read config from wpMcpAiChatInstances[instanceId]       │
│                                                              │
│ 2. Apply configuration:                                     │
│    - Enable tool shortcuts UI                               │
│    - Configure file input accept attribute                  │
│    - Enable transcript saving                               │
│    - Set allowSensitiveTools in requests                    │
│                                                              │
│ 3. Initialize chat interface with full features             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   PHP BACKEND (Preparation)                  │
├─────────────────────────────────────────────────────────────┤
│ In enqueue_assets():                                        │
│   1. Call get_file_upload_config()                          │
│      ↓                                                       │
│   2. Load WP_MCP_AI_Message_Attachments::get_allowed_...    │
│      ↓                                                       │
│   3. Call get_allowed_extensions_for_mimes()                │
│      ↓                                                       │
│   4. Call build_file_accept_tokens()                        │
│      ↓                                                       │
│   5. Merge into wpMcpAiChat localized script                │
│                                                              │
│ In render_page():                                           │
│   1. For each assistant:                                    │
│      - Load configuration                                   │
│      - Call get_assistant_tool_shortcuts()                  │
│      - Add to button data-tool-shortcuts attribute          │
└─────────────────────────────────────────────────────────────┘
```

## Security Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      SECURITY LAYERS                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  LAYER 1: PHP Access Control                               │
│  ┌────────────────────────────────────────────────────┐    │
│  │ • require: current_user_can('manage_options')      │    │
│  │ • Admin menu: 'manage_options' capability          │    │
│  │ • Page render: capability check + wp_die() if fail │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  LAYER 2: REST API Authentication                          │
│  ┌────────────────────────────────────────────────────┐    │
│  │ • WordPress nonce verification (X-WP-Nonce)        │    │
│  │ • Standard WP REST API permissions_check           │    │
│  │ • Assistant-specific capability checks             │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  LAYER 3: Data Sanitization                                │
│  ┌────────────────────────────────────────────────────┐    │
│  │ • Input: sanitize_text_field(), absint(), etc.     │    │
│  │ • Output: esc_html(), esc_attr(), esc_url()        │    │
│  │ • JSON: wp_json_encode() with escaping             │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  LAYER 4: File Upload Validation                           │
│  ┌────────────────────────────────────────────────────┐    │
│  │ • Capability: current_user_can('upload_files')     │    │
│  │ • MIME type whitelist validation                   │    │
│  │ • File extension verification                      │    │
│  │ • WordPress media upload security                  │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  RESULT: allowSensitiveTools is SAFE because:              │
│  • Only admins can access (manage_options)                 │
│  • Admins already have equivalent permissions              │
│  • All requests still validated and sanitized              │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Usage Example

### Admin Testing Workflow

1. **Navigate to Test Assistant**
   ```
   WordPress Admin → AI Assistants → Test Assistant
   ```

2. **Select Assistant to Test**
   ```
   Click [Test] button next to any assistant
   ```

3. **Modal Opens with Full Features**
   ```
   ✅ Tool shortcuts displayed (if configured)
   ✅ File upload button active
   ✅ Transcripts auto-saving
   ✅ All tools accessible (including sensitive ones)
   ```

4. **Test Scenarios**
   ```
   A. Test file upload:
      - Click 📎 Attach File
      - Select image/document
      - Verify upload and processing

   B. Test sensitive tool:
      - Request code execution
      - Tool executes (not blocked)
      - Response shows results

   C. Test tool shortcut:
      - Click quick task button
      - Payload auto-inserted
      - Execute and verify

   D. Review transcript:
      - Refresh page
      - Conversation history preserved
      - Debug tool execution flow
   ```

## Developer Integration

### Accessing Configuration

```javascript
// Browser console
console.log(window.wpMcpAiChatInstances);
// Shows all active chat instances with full config

// Example output:
{
  "wp-mcp-ai-test-chat-123-1699810000000": {
    "assistantId": "123",
    "allowSensitiveTools": true,
    "saveTranscript": true,
    "toolShortcuts": [
      {
        "tool": "custom",
        "label": "Analyze Code",
        "payload": "..."
      }
    ],
    "fileAccept": "image/jpeg,.jpg,.png,...",
    "allowedImageMimes": ["image/jpeg", "image/png"],
    "allowedFileMimes": ["application/pdf"],
    "allowedExtensions": ["jpg", "png", "pdf"]
  }
}
```

### Accessing Transcripts

```javascript
// Browser console
const transcripts = localStorage.getItem('wp_mcp_ai_transcripts');
const parsed = JSON.parse(transcripts);
console.log(parsed);

// Shows saved conversations with:
// - Messages (user, assistant, tool)
// - Timestamps
// - Assistant ID
// - Session keys
```

## Summary

The test assistant now provides a **production-grade testing environment** for WordPress administrators to:

1. ✅ **Test all tools** without restrictions
2. ✅ **Upload files** of any supported type
3. ✅ **Review transcripts** for debugging
4. ✅ **Use shortcuts** for common tasks
5. ✅ **Stream responses** in real-time

Perfect for validating assistant configurations before deploying to frontend users!
