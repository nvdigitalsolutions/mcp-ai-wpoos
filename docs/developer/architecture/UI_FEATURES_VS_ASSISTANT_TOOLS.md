# UI Features vs Assistant Tools Architecture

## Overview

The NV oOS plugin distinguishes between **UI-level features** and **assistant-controlled tools**. This document explains this architectural pattern and its implications.

## Auto-Enabled Utility Tools

Some tools are **automatically enabled** for all assistants, regardless of their configured tools list. These are defined in `WP_MCP_AI_REST::AUTO_ENABLED_UTILITY_TOOLS`:

```php
// File: includes/rest/class-wp-mcp-ai-rest-tools-controller.php (lines 488-499)
$utility_tools = array( 
    'generate_openai_speech',      // Text-to-speech for chat UI
    'transcribe_openai_audio'      // Speech-to-text for chat UI
);
```

These tools are **always added** to the allowed tools list during REST API execution, even if they're not in the assistant's configuration.

## Why This Pattern Exists

### UI Features (Auto-Enabled)
These tools provide **essential chat interface functionality** that should work consistently across all assistants:

1. **Speech Synthesis (`generate_openai_speech`)**
   - Powers the "play audio" button on assistant messages
   - Allows users to hear responses read aloud
   - UI feature, not an assistant capability

2. **Audio Transcription (`transcribe_openai_audio`)**
   - Powers the transcription button in the chat input area
   - Allows users to record voice input
   - UI feature, not an assistant capability

### Assistant Tools (Explicitly Configured)
These tools represent **AI capabilities** that should be controlled per-assistant:
- `save_post` - Content creation
- `web_search` - Information retrieval
- `generate_openai_image` - Image generation
- Custom tools for specific use cases

## Implementation Details

### REST API Tool Execution
When a tool is executed via POST `/wp-json/mcp-ai/v1/tools`:

```php
// 1. Get assistant's configured tools
$allowed_tools = $assistant_config['tools'];

// 2. Auto-enable utility tools
if (in_array($tool_slug, $utility_tools, true) && !in_array($tool_slug, $allowed_tools, true)) {
    $allowed_tools[] = $tool_slug;
}

// 3. Check if tool is allowed
if (!in_array($tool_slug, $allowed_tools, true)) {
    return error('Tool forbidden');
}
```

### Frontend Behavior
The chat UI assumes these buttons are always available:
- Transcription button is rendered based on browser capability, not tool availability
- Speech buttons are attached to all assistant messages automatically
- No need to check if tools are in the assistant's configuration

## Best Practices

### When to Use Auto-Enabled Tools
Use this pattern for:
- ✅ Core UI features all users expect
- ✅ Features that don't change assistant behavior
- ✅ Client-side capabilities (browser APIs + server processing)

### When to Use Assistant-Configured Tools
Use explicit configuration for:
- ✅ AI capabilities that affect responses
- ✅ Tools with cost/rate limit implications
- ✅ Features specific to certain use cases
- ✅ Tools requiring special permissions/credentials

## Common Pitfalls

### ❌ Don't Add UI Feature Files to attachmentLibrary
```javascript
// WRONG - causes file reuse issues
if (state.attachmentLibrary && record.fileId) {
    state.attachmentLibrary[record.fileId] = record;
}
```

**Why?** Transcription audio files are temporary recordings, not conversation attachments. Adding them to the library causes old recordings to be reused incorrectly.

### ✅ Only Add Conversation Attachments to attachmentLibrary
```javascript
// CORRECT - only for user-uploaded files
if (state.attachmentLibrary && record.fileId) {
    state.attachmentLibrary[record.fileId] = record;  // Only for explicit uploads
}
```

## Related Files

- `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` - Auto-enable logic
- `assets/js/chat-transcription-service.js` - Transcription UI
- `assets/js/chat-audio-service.js` - Speech synthesis UI
- `assets/js/chat.js` - Main chat UI logic

## See Also

- [REST API Documentation](../reference/rest-api.md)
- [Tool Reference](../reference/tools/tool-reference.md)
- [Chat UI Features](../features/chat/)
