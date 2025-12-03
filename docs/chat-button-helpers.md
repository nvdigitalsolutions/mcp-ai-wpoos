# Chat Button Helper Functions

This document describes the helper functions available in the `chat-ui-utilities-service.js` for managing chat buttons (voice chat, transcribe, etc.).

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

- [Chat UI Utilities Service](../assets/js/chat-ui-utilities-service.js)
- [Chat Audio Service](../assets/js/chat-audio-service.js)
- [Chat Client Main](../assets/js/chat.js)
