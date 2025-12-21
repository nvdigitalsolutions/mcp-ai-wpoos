# Chat Button Helper Functions

This document describes the helper functions available in the `chat-ui-utilities-service.js` for managing chat buttons (voice chat, transcribe, etc.).

## Prerequisites

### Transcribe and Voice Chat Buttons

The transcribe and voice chat buttons require the following:

1. **User Permission**: The current user must have the `upload_files` capability OR guest access must be enabled
   - This is checked server-side in `WP_MCP_AI_Shortcode::render_shortcode()`
   - The logic: `$can_upload_attachments = current_user_can( 'upload_files' ) || $allow_guests;`
   - If neither condition is met, buttons are rendered with `disabled` and `hidden` attributes
   - **Fixed**: Previously only checked `upload_files` capability, now also allows if guest access is enabled

2. **Browser Support**: The browser must support:
   - `navigator.mediaDevices.getUserMedia()` for microphone access
   - `MediaRecorder` API for audio recording
   - Modern browsers (Chrome 49+, Firefox 25+, Safari 14+) support these APIs

3. **Microphone Permission**: The user must grant microphone access when prompted
   - This is requested when the user first clicks the transcribe or voice chat button
   - If denied, the button will show an error message

### Tool-to-Tool Execution

**Question: What about if it's tool to tool?**

**Answer**: Tool-to-tool execution in the agentic workflow is designed to work seamlessly:

1. **Context Inheritance**: When tools call other tools during the agentic workflow, they inherit the execution context including:
   - `endpoint` - The original REST endpoint (e.g., `/chat-client`)
   - `allow_sensitive_tools` - Permission flag for sensitive operations
   - `agentic_loop` - Flag indicating execution within agentic workflow
   - `user_id`, `assistant_id`, `iteration`, etc.

2. **No Restrictions for Upload/Transcription Tools**: 
   - Upload and transcription tools do NOT use the `WP_MCP_AI_Tool_Restrict_From_Chat_Client` trait
   - They are freely available for tool-to-tool calls without restrictions
   - Only sensitive tools like `create_wpcode_snippet` use the restriction trait

3. **Sensitive Tools**: Tools that use the restriction trait check:
   ```php
   // From trait-wp-mcp-ai-tool-restrict-from-chat-client.php
   $allow_sensitive_tools = isset( $context['allow_sensitive_tools'] ) && $context['allow_sensitive_tools'] === true;
   ```
   - If `allow_sensitive_tools` is true in the shortcode, it passes through the entire agentic workflow
   - This means if the assistant is allowed to use sensitive tools, all tools in the chain can use them

4. **Example Workflow**:
   ```
   User (chat client) 
     → Assistant decides to create a video
       → Calls generate_veo_video tool (not restricted)
         → Internally may process audio/files (not restricted)
           → Returns video URL to assistant
             → Assistant responds to user with video
   ```

**Best Practice**: If your assistant needs to use tool chains that include sensitive operations, set `allow_sensitive_tools="true"` in the shortcode:
```php
[mcp_ai_chat assistant_id="123" allow_sensitive_tools="true"]
```

### Troubleshooting

If the transcribe button is not responding:

1. **Check User Capability**: 
   ```php
   // In WordPress, check if current user can upload files
   $can_upload = current_user_can( 'upload_files' );
   ```

2. **Check Browser Console**: 
   ```javascript
   // Use the diagnostic script to check button state
   // See docs/chat-button-diagnostic.js
   ```

3. **Verify Button State**:
   ```javascript
   const button = document.querySelector('.wp-mcp-ai-chat__transcribe');
   console.log('Disabled:', button.disabled);
   console.log('Hidden:', button.hidden);
   ```

4. **Check Service Loading**:
   ```javascript
   console.log('Audio Service:', typeof window.wpMcpAiChatAudio);
   console.log('UI Utils:', typeof window.wpMcpAiChatUIUtils);
   ```

## Overview

The chat UI utilities service provides a set of helper functions specifically designed for managing button states, classes, icons, and labels in a consistent and accessible manner.

## Available Helper Functions

### toggleButtonClass(button, className, force)

Toggle a CSS class on a button element.

**Parameters:**
- `button` (Element): Button element
- `className` (string): CSS class name to toggle
- `force` (boolean, optional): Force parameter (true=add, false=remove)

**Example:**
```javascript
const button = document.querySelector('.wp-mcp-ai-chat__voice-chat');
const uiUtils = window.wpMcpAiChatUIUtils;

// Toggle recording class
uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--recording');

// Force add class
uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--recording', true);

// Force remove class
uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--recording', false);
```

### setButtonState(button, options)

Set button state (enabled/disabled) with optional class toggling.

**Parameters:**
- `button` (Element): Button element
- `options` (Object): State options
  - `disabled` (boolean): Whether button should be disabled
  - `hidden` (boolean): Whether button should be hidden
  - `addClass` (string): CSS class to add
  - `removeClass` (string): CSS class to remove

**Example:**
```javascript
const button = document.querySelector('.wp-mcp-ai-chat__transcribe');
const uiUtils = window.wpMcpAiChatUIUtils;

// Disable button and add processing class
uiUtils.setButtonState(button, {
    disabled: true,
    addClass: 'wp-mcp-ai-chat__transcribe--recording'
});

// Enable button and remove processing class
uiUtils.setButtonState(button, {
    disabled: false,
    removeClass: 'wp-mcp-ai-chat__transcribe--recording'
});

// Hide button
uiUtils.setButtonState(button, {
    hidden: true
});
```

### setButtonIcon(button, iconHTML, selector)

Update button icon/content.

**Parameters:**
- `button` (Element): Button element
- `iconHTML` (string): HTML content for the icon
- `selector` (string, optional): Selector for icon element within button (defaults to first child)

**Example:**
```javascript
const button = document.querySelector('.wp-mcp-ai-chat__voice-chat');
const uiUtils = window.wpMcpAiChatUIUtils;

const recordingIcon = '<svg class="wp-mcp-ai-chat__voice-chat-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="currentColor"/></svg>';

// Update icon (using default first child selector)
uiUtils.setButtonIcon(button, recordingIcon);

// Update specific icon element
uiUtils.setButtonIcon(button, recordingIcon, '.wp-mcp-ai-chat__voice-chat-icon');
```

### updateButtonLabel(button, label)

Update button accessibility labels (aria-label and title).

**Parameters:**
- `button` (Element): Button element
- `label` (string): Label text for aria-label and title

**Example:**
```javascript
const button = document.querySelector('.wp-mcp-ai-chat__transcribe');
const uiUtils = window.wpMcpAiChatUIUtils;

// Update label when recording starts
uiUtils.updateButtonLabel(button, 'Stop recording');

// Update label when recording stops
uiUtils.updateButtonLabel(button, 'Transcribe audio');
```

## Cross-Chat Communication

The helper functions also support communication between multiple chat instances on the same page.

### broadcastMessage(eventType, data)

Broadcast a message to all chat instances on the page.

**Parameters:**
- `eventType` (string): Event type (will be prefixed with 'chat:')
- `data` (*): Event data to broadcast

**Example:**
```javascript
const uiUtils = window.wpMcpAiChatUIUtils;

// Broadcast attachment upload event
uiUtils.broadcastMessage('attachment:uploaded', {
    fileId: 'file_123',
    fileName: 'document.pdf',
    url: 'https://example.com/file.pdf'
});
```

### listenToChatEvents(eventType, handler)

Listen for messages from other chat instances.

**Parameters:**
- `eventType` (string): Event type to listen for
- `handler` (Function): Event handler function
- **Returns**: Cleanup function to remove the listener

**Example:**
```javascript
const uiUtils = window.wpMcpAiChatUIUtils;

// Listen for attachment uploads
const cleanup = uiUtils.listenToChatEvents('attachment:uploaded', function(data) {
    console.log('File uploaded:', data.fileName);
    // Add to local attachment library
    state.attachmentLibrary[data.fileId] = data;
});

// Later, remove listener
cleanup();
```

### getOtherChatInstances(currentInstanceId)

Get all other chat instances on the page (excluding the current one).

**Parameters:**
- `currentInstanceId` (string): Current chat instance ID to exclude
- **Returns**: Array of chat instance objects

**Example:**
```javascript
const uiUtils = window.wpMcpAiChatUIUtils;

const otherChats = uiUtils.getOtherChatInstances(state.config.id);

otherChats.forEach(function(chat) {
    console.log('Chat ID:', chat.id);
    console.log('Assistant:', chat.config.assistantId);
    console.log('Messages:', chat.state.conversation.length);
});
```

### copyMessageToClipboard(message)

Copy a message to clipboard for pasting in another chat.

**Parameters:**
- `message` (Object): Message object to copy
- **Returns**: Promise that resolves when copy is complete

**Example:**
```javascript
const uiUtils = window.wpMcpAiChatUIUtils;

const message = state.conversation[5];

uiUtils.copyMessageToClipboard(message)
    .then(function() {
        uiUtils.setStatus(container, 'Message copied to clipboard');
    })
    .catch(function(err) {
        console.error('Copy failed:', err);
    });
```

## Common Usage Patterns

### Voice Chat Button State Management

```javascript
const voiceChatButton = document.querySelector('.wp-mcp-ai-chat__voice-chat');
const uiUtils = window.wpMcpAiChatUIUtils;

// Start recording
uiUtils.setButtonState(voiceChatButton, {
    disabled: false,
    addClass: 'wp-mcp-ai-chat__voice-chat--recording'
});
uiUtils.updateButtonLabel(voiceChatButton, 'Stop recording');

// Processing audio
uiUtils.setButtonState(voiceChatButton, {
    disabled: true,
    removeClass: 'wp-mcp-ai-chat__voice-chat--recording',
    addClass: 'wp-mcp-ai-chat__voice-chat--processing'
});
uiUtils.updateButtonLabel(voiceChatButton, 'Processing...');

// Back to idle state
uiUtils.setButtonState(voiceChatButton, {
    disabled: false,
    removeClass: 'wp-mcp-ai-chat__voice-chat--processing'
});
uiUtils.updateButtonLabel(voiceChatButton, 'Voice chat');
```

### Transcribe Button State Management

```javascript
const transcribeButton = document.querySelector('.wp-mcp-ai-chat__transcribe');
const uiUtils = window.wpMcpAiChatUIUtils;

// Start recording
uiUtils.toggleButtonClass(transcribeButton, 'wp-mcp-ai-chat__transcribe--recording', true);
uiUtils.updateButtonLabel(transcribeButton, 'Stop recording');

// Stop recording and disable while processing
uiUtils.setButtonState(transcribeButton, {
    disabled: true,
    removeClass: 'wp-mcp-ai-chat__transcribe--recording'
});

// Re-enable after processing
uiUtils.setButtonState(transcribeButton, {
    disabled: false
});
uiUtils.updateButtonLabel(transcribeButton, 'Transcribe audio');
```

## Integration with Chat State

The helper functions work seamlessly with the existing chat state management:

```javascript
function updateVoiceChatButtonState(state) {
    if (!state || !state.voiceChatButton) {
        return;
    }

    const uiUtils = window.wpMcpAiChatUIUtils;
    const button = state.voiceChatButton;
    
    const canUse = !!state.canUploadAttachments;
    const disabled = !canUse || state.busy || state.uploading > 0 || state.voiceChatProcessing;

    uiUtils.setButtonState(button, {
        disabled: disabled || state.isVoiceChatRecording ? false : disabled,
        hidden: !canUse
    });

    if (state.isVoiceChatRecording) {
        uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--recording', true);
    } else {
        uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--recording', false);
    }

    if (state.voiceChatProcessing) {
        uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--processing', true);
    } else {
        uiUtils.toggleButtonClass(button, 'wp-mcp-ai-chat__voice-chat--processing', false);
    }
}
```

## Accessibility Considerations

All helper functions maintain proper accessibility attributes:

1. **updateButtonLabel** - Updates both `aria-label` and `title` for consistent accessibility
2. **setButtonState** - Properly handles `disabled` and `hidden` attributes
3. **toggleButtonClass** - Ensures visual state matches functional state

## Browser Compatibility

These helper functions are compatible with:
- Modern browsers (Chrome, Firefox, Safari, Edge)
- Internet Explorer 11 (with polyfills)
- Mobile browsers (iOS Safari, Chrome for Android)

## Performance

All helper functions:
- Use DOM update batching when appropriate via `domUpdateBatcher`
- Avoid forced reflows
- Are optimized for frequent state changes

## See Also

- [Chat UI Utilities Service](../../../assets/js/chat-ui-utilities-service.js)
- [Chat Audio Service](../../../assets/js/chat-audio-service.js)
- [Chat Client Main](../../../assets/js/chat.js)
