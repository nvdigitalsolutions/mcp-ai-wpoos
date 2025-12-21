# Display Metadata Persistence in Chat Conversations

## Overview

This document describes the display metadata persistence feature that ensures chat messages are properly restored when conversations are loaded from localStorage or CCT (Custom Content Type) storage.

## Problem Statement

Previously, chat messages were saved with minimal structure:
```javascript
{
  role: 'user',
  content: 'message text'
}
```

When reloaded, important display information was lost:
- Attachments (images, files)
- Bubble type modifiers (JSON, truncated)
- Display text (when different from API content)

This caused messages to not display correctly after reload.

## Solution

### Enhanced Message Structure

Messages now include an optional `display` metadata object:

```javascript
{
  role: 'user' | 'assistant' | 'tool' | 'system',
  content: <string or array>,  // API format (sent to backend)
  display: {                    // UI reconstruction metadata
    text: <string>,             // Display text
    attachments: [...],         // Display attachments
    bubbleType: 'json' | 'truncated' | null  // Bubble CSS modifier
  },
  tool_calls: [...]             // Optional, for assistant messages
}
```

### Helper Functions

Two SOC-compliant helper functions manage display metadata:

#### extractDisplayMetadata(messageElement, displayPayload)

Extracts display metadata from a rendered message element.

**Parameters:**
- `messageElement` - The rendered DOM element containing the message
- `displayPayload` - The original payload used to render the message

**Returns:**
- Object with `{text, attachments, bubbleType}` or `null` if no metadata

**Example:**
```javascript
const messageElement = appendMessage(state.messagesEl, 'user', {
  text: 'Check this out',
  attachments: [{url: 'image.jpg', label: 'Photo'}]
});

const metadata = extractDisplayMetadata(messageElement, {
  text: 'Check this out',
  attachments: [{url: 'image.jpg', label: 'Photo'}]
});

// Result:
// {
//   text: 'Check this out',
//   attachments: [{url: 'image.jpg', label: 'Photo'}]
// }
```

#### createConversationMessage(role, content, displayMetadata, additionalFields)

Creates a properly structured conversation message with optional display metadata.

**Parameters:**
- `role` - Message role ('user', 'assistant', 'tool', 'system')
- `content` - Message content (API format)
- `displayMetadata` - Display metadata from `extractDisplayMetadata()`
- `additionalFields` - Additional fields like `tool_calls`

**Returns:**
- Structured message object

**Example:**
```javascript
const message = createConversationMessage(
  'assistant',
  '{"result": "success"}',
  { text: '{"result": "success"}', bubbleType: 'json' },
  { tool_calls: [...] }
);

// Result:
// {
//   role: 'assistant',
//   content: '{"result": "success"}',
//   display: {
//     text: '{"result": "success"}',
//     bubbleType: 'json'
//   },
//   tool_calls: [...]
// }
```

## Bubble Types

The following bubble type modifiers are preserved:

### JSON Bubble (`bubbleType: 'json'`)

Applied when content is valid JSON and should be displayed in a collapsible JSON viewer.

**CSS Class:** `wp-mcp-ai-chat__bubble--json`

**Example:**
```javascript
{
  role: 'assistant',
  content: '{"status": "ok", "count": 42}',
  display: {
    text: '{"status": "ok", "count": 42}',
    bubbleType: 'json'
  }
}
```

### Truncated Bubble (`bubbleType: 'truncated'`)

Applied when content contains the orchestration truncation marker.

**CSS Class:** `wp-mcp-ai-chat__bubble--truncated`

**Example:**
```javascript
{
  role: 'assistant',
  content: 'Long text... [... Result truncated by orchestration layer to fit within budget constraints ...]',
  display: {
    text: 'Long text... [... Result truncated ...]',
    bubbleType: 'truncated'
  }
}
```

## Message Saving Pathways

All message saving pathways now preserve display metadata:

### 1. User Messages

```javascript
// In handleSubmit()
const displayPayload = {
  text: inputValue,
  attachments: displayAttachments,
};

const userMessageElement = appendMessage(state.messagesEl, 'user', displayPayload);
const displayMetadata = extractDisplayMetadata(userMessageElement, displayPayload);
const userMessage = createConversationMessage('user', payloadContent, displayMetadata);

state.conversation.push(userMessage);
```

### 2. Assistant Messages

```javascript
// In handleChatResponse()
const assistantMessageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {...});
const displayMetadata = extractDisplayMetadata(assistantMessageElement, assistantDisplay);

if (displayMetadata) {
  assistantMessage.display = displayMetadata;
}
```

### 3. Streaming Messages (Fallback)

```javascript
// In processStream() fallback
const displayPayload = { text: streamResult.content };
const displayMetadata = extractDisplayMetadata(streamingMessageElement, displayPayload);
const assistantMessage = createConversationMessage('assistant', streamResult.content, displayMetadata);

state.conversation.push(assistantMessage);
```

### 4. Re-rendered Messages (Tool Results)

```javascript
// When re-rendering with attachments
const updatedMessageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {...});
const displayMetadata = extractDisplayMetadata(updatedMessageElement, assistantDisplay);

if (displayMetadata) {
  assistantMessage.display = displayMetadata;
}
```

## Message Restoration

When loading conversations from storage:

```javascript
// In loadHistorySessionIntoChat()
messages.forEach(function (message) {
  // ... role and content extraction ...

  // Use display metadata if available
  let payload;
  if (message.display && typeof message.display === 'object') {
    payload = message.display;
  } else {
    payload = { text: trimmedContent };
  }
  
  appendMessage(state.messagesEl, role, payload, allowMarkdown);
  
  // Preserve original message structure including display metadata
  state.conversation.push(message);
});
```

## Backward Compatibility

The implementation maintains backward compatibility:

1. **Messages without display metadata** work as before:
   ```javascript
   {
     role: 'user',
     content: 'Simple message'
   }
   // Renders with default payload: { text: 'Simple message' }
   ```

2. **Reload logic** checks for `display` first, falls back to `content`:
   ```javascript
   const payload = message.display || { text: message.content };
   ```

3. **Existing saved conversations** continue to work without modification

## Testing

Comprehensive tests in `tests/js/display-metadata-persistence.test.js`:

- ✅ User messages with attachments
- ✅ Assistant messages with JSON bubble type
- ✅ Assistant messages with truncated bubble type
- ✅ Assistant messages with tool results and attachments
- ✅ Messages without display metadata (backward compatibility)
- ✅ Full conversations with mixed message types
- ✅ Message restoration logic

## Usage Example

```javascript
// Sending a user message with an image
const userMessage = {
  role: 'user',
  content: [
    { type: 'text', text: 'What do you see?' },
    { type: 'image_url', image_url: { url: 'data:image/png;base64,...' } }
  ],
  display: {
    text: 'What do you see?',
    attachments: [
      { url: 'data:image/png;base64,...', label: 'Screenshot.png', meta: '1.2 MB' }
    ]
  }
};

// Receiving a JSON response
const assistantMessage = {
  role: 'assistant',
  content: '{"objects": ["cat", "tree", "sky"], "confidence": 0.95}',
  display: {
    text: '{"objects": ["cat", "tree", "sky"], "confidence": 0.95}',
    bubbleType: 'json'
  }
};

// Both messages will be properly restored when conversation is reloaded
```

## Files Modified

- `assets/js/chat.js`:
  - Added `extractDisplayMetadata()` helper function
  - Added `createConversationMessage()` helper function
  - Updated `handleSubmit()` to save user message display metadata
  - Updated `handleChatResponse()` to save assistant message display metadata
  - Updated streaming fallback to save display metadata
  - Updated re-render logic to preserve display metadata
  - Enhanced `appendMessage()` to accept and use `bubbleType` hint

## Related Issues

- Fixes #1437 - Fix image_url persistence in chat client
- Addresses bubble type persistence across conversation reloads
- Ensures attachments are preserved when messages are restored

## Future Enhancements

- Consider adding display metadata for tool messages
- Add display metadata for system messages with special formatting
- Optimize storage size by compressing/deduplicating attachments
