# Streaming Thinking Text Display

## Overview

The **Streaming Thinking Text** feature displays AI model thinking/reasoning text in real-time in the chat status section. This is particularly useful for Gemini 2.0 Flash Thinking mode, which provides thinking text as the model processes requests.

## Feature Description

When an AI provider (currently Gemini 2.0 Flash Thinking mode) sends thinking/reasoning text during streaming responses, it is displayed in the `wp-mcp-ai-chat__status` section with a special `--text-stream` modifier class.

## Visual Appearance

### Status Section with Thinking Text

```
┌─────────────────────────────────────────────────────┐
│  ≡  Analyzing the user's request and considering   │
│     multiple approaches to solve this problem...▋  │
│                                                     │
│  [Amber/yellow background with blinking cursor]    │
└─────────────────────────────────────────────────────┘
```

**CSS Theme:**
- Background: Amber/yellow (`#fef3c7`)
- Border: Orange (`#f59e0b`)
- Text: Dark brown (`#78350f`)
- Font: Monospace (Courier New)
- Cursor: Blinking block (`▋`)

## How It Works

### 1. Gemini Sends Thinking Parts

When Gemini 2.0 Flash Thinking mode is active, it sends streaming responses with `thought` parts:

```json
{
  "candidates": [{
    "content": {
      "parts": [
        {
          "thought": "Let me break down this problem step by step..."
        },
        {
          "text": "Here is my response..."
        }
      ]
    }
  }]
}
```

### 2. Backend Parsing

The `WP_MCP_AI_Gemini_Client` class parses the streaming response and extracts both `text` and `thought` parts:

```php
// In stream_generate_content_with_callback()
if ( isset( $part['thought'] ) ) {
    $accumulated['thinking'] .= $part['thought'];
    
    if ( is_callable( $callback ) ) {
        call_user_func( $callback, $part['thought'], 'thought' );
    }
}
```

### 3. Frontend Multi-Provider Detection

The JavaScript `processSSEStream()` function intelligently detects thinking text from multiple provider formats:

```javascript
// OpenAI format
if (delta.reasoning_content) {
    thinkingChunk = delta.reasoning_content;
}

// Gemini format
if (part.thought) {
    thinkingChunk = part.thought;
}

// Anthropic format
if (data.delta.type === 'thinking_delta') {
    thinkingChunk = data.delta.thinking;
}

// Generic fallback
if (data.thinking || data.reasoning) {
    thinkingChunk = data.thinking || data.reasoning;
}
```

**Supported Field Names:**
- `delta.reasoning_content` - OpenAI (if available)
- `delta.reasoning` - OpenAI alternative
- `part.thought` - Gemini Thinking mode
- `delta.thinking` - Anthropic (if available)
- `message.thinking` - Ollama/LM Studio
- `thinking` - Generic fallback
- `reasoning` - Generic fallback

This multi-provider approach ensures the feature works automatically when any provider adds thinking/reasoning output to their streaming API.

### 4. Frontend Display

The status section is updated with the accumulated thinking text:

```javascript
// Display in status section
if (thinkingChunk) {
    state.thinkingText += thinkingChunk;
    setStatus(state.container, {
        message: state.thinkingText,
        type: 'text-stream',
        showTime: false
    });
}
```

### 5. Visual Feedback

The status section shows:
- Progressive accumulation of thinking text
- Blinking cursor to indicate active streaming
- Monospace font for readability
- Amber theme to distinguish from other statuses

### 6. Cleanup

When streaming completes, the thinking text buffer is cleared:

```javascript
// Clear thinking text buffer
state.thinkingText = null;
```

## Supported Providers

### Gemini (✅ Full Support - Confirmed)
- **Gemini 2.0 Flash Thinking Mode**: Provides `thought` parts in streaming responses
- **Format**: `candidates[0].content.parts[].thought`
- **Display**: Real-time streaming of thinking text in status section
- **Status**: Fully implemented and tested

### OpenAI (🔄 Partial Support - Future Ready)
- **O1 Models**: Perform internal reasoning but currently don't expose it in streaming API
- **Potential Formats**: 
  - `choices[0].delta.reasoning_content` - If OpenAI exposes reasoning in future
  - `choices[0].delta.reasoning` - Alternative field name
- **Display**: Will automatically work if OpenAI adds thinking output
- **Status**: Code ready, waiting for API support

### Anthropic (🔄 Partial Support - Future Ready)
- **Claude Models**: Extended thinking feature may be available
- **Potential Formats**:
  - `delta.type === 'thinking_delta'` with `delta.thinking` field
  - Claude's streaming format with thinking blocks
- **Display**: Will automatically work if Anthropic exposes thinking
- **Status**: Code ready, waiting for API support

### Ollama (🔄 Partial Support - Model Dependent)
- **Support**: Depends on underlying model capabilities
- **Potential Formats**:
  - `message.thinking` - If model supports thinking output
  - `thinking` - Generic field in response
- **Display**: Will work if local model provides thinking field
- **Status**: Compatible, requires model with thinking support

### LM Studio (🔄 Partial Support - Model Dependent)
- **Support**: Depends on model and configuration
- **Formats**: Usually OpenAI-compatible, may not have thinking field
- **Display**: Will work if model provides thinking in compatible format
- **Status**: Compatible, requires model with thinking support

### Generic Fallback (✅ Supported)
- **Any Provider**: The code checks for common field names
- **Formats Supported**:
  - `data.thinking` - Generic thinking field
  - `data.reasoning` - Generic reasoning field
- **Display**: Will automatically detect and display
- **Status**: Broad compatibility built-in

## CSS Classes

### Base Status Class
```css
.wp-mcp-ai-chat__status {
    margin-bottom: 1rem;
    color: var(--wp-mcp-ai-color-status-text, #1d4ed8);
    font-size: 0.95rem;
    background: var(--wp-mcp-ai-color-status-background, #eef2ff);
    padding: 0.75rem 1rem;
    border-radius: 10px;
    border-left: 4px solid var(--wp-mcp-ai-color-status-border, #3b82f6);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
```

### Text Stream Modifier
```css
.wp-mcp-ai-chat__status--text-stream {
    background: var(--wp-mcp-ai-color-status-text-stream-bg, #fef3c7);
    border-left-color: var(--wp-mcp-ai-color-status-text-stream-border, #f59e0b);
    color: var(--wp-mcp-ai-color-status-text-stream-text, #78350f);
}

.wp-mcp-ai-chat__status--text-stream .wp-mcp-ai-chat__status-text {
    font-family: var(--wp-mcp-ai-font-mono, 'Courier New', monospace);
    font-size: 0.9rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wp-mcp-ai-chat__status--text-stream .wp-mcp-ai-chat__status-text::after {
    content: '▋';
    display: inline-block;
    margin-left: 2px;
    animation: wp-mcp-ai-cursor-blink 1s step-end infinite;
}
```

### Cursor Animation
```css
@keyframes wp-mcp-ai-cursor-blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}
```

## Status Types

The chat interface now supports these status types:

1. **`thinking`** - Blue spinner, for general thinking status
2. **`processing`** - Yellow spinner, for processing operations
3. **`streaming`** - Green icon, for content streaming
4. **`text-stream`** - Orange with cursor, for thinking text streaming ✨ NEW
5. **`tool`** - Purple spinner, for tool execution

## User Experience

### Before (Without Thinking Text)
```
┌─────────────────────────────────────────────┐
│  🔄  Analyzing tool results...  2s          │
└─────────────────────────────────────────────┘
```
User sees generic status message, no insight into what the AI is thinking.

### After (With Thinking Text)
```
┌─────────────────────────────────────────────────────────────────┐
│  ≡  I need to first check the database for existing records,  │
│     then compare them with the new data to identify changes,   │
│     and finally generate a summary of the differences...▋      │
└─────────────────────────────────────────────────────────────────┘
```
User sees exactly what the AI is thinking, providing transparency and engagement.

## Configuration

### No Configuration Required
The feature is automatically enabled for all assistants when:
1. The AI provider supports thinking output (Gemini 2.0 Flash Thinking mode)
2. Streaming is enabled (`enable_streaming="true"`)

### Customization via CSS Variables

You can customize the appearance using CSS variables:

```css
:root {
    /* Text stream status colors */
    --wp-mcp-ai-color-status-text-stream-bg: #fef3c7;        /* Background */
    --wp-mcp-ai-color-status-text-stream-border: #f59e0b;    /* Border */
    --wp-mcp-ai-color-status-text-stream-text: #78350f;      /* Text */
    
    /* Monospace font */
    --wp-mcp-ai-font-mono: 'Courier New', monospace;
}
```

## Performance Considerations

### Minimal Overhead
- **DOM Updates**: Only updates status text, not message bubbles
- **Animation**: CSS-based cursor blink (GPU-accelerated)
- **Memory**: Thinking text is cleared after streaming completes
- **Network**: No additional requests, uses existing SSE stream

### Optimization
- Uses existing `setStatus()` function infrastructure
- Reuses same status DOM element throughout streaming
- Text accumulation happens in memory before DOM update
- Cursor animation uses `opacity` for GPU optimization

## Browser Compatibility

| Feature | Browser Support |
|---------|----------------|
| CSS Variables | All modern browsers |
| CSS Animations | All modern browsers |
| `::after` pseudo-element | All browsers |
| Monospace fonts | All browsers |

## Testing

### Manual Testing with Gemini

1. **Configure Gemini 2.0 Flash Thinking**:
   - Go to Settings → WP oOS
   - Set provider to "Gemini"
   - Select "gemini-2.0-flash-thinking-exp" model
   - Ensure streaming is enabled

2. **Start a Chat**:
   - Use `[mcp_ai_chat assistant="123"]` shortcode
   - Send a complex query that requires reasoning

3. **Observe**:
   - Status section should show amber-themed box
   - Thinking text should stream in progressively
   - Cursor should blink during streaming
   - Thinking text should clear when complete

### Expected Behavior

✅ **Success Indicators**:
- Amber status box appears during thinking
- Text streams in progressively with cursor
- Monospace font makes thinking readable
- Status clears when streaming completes
- Main response appears in message bubble

❌ **Failure Indicators**:
- No status box appears
- Generic "Analyzing..." message instead of thinking text
- Thinking text appears in message bubble instead of status
- Cursor doesn't blink
- Thinking text doesn't clear

## Troubleshooting

### Issue: Thinking text not appearing

**Possible Causes**:
1. Model doesn't support thinking output
2. Streaming is disabled
3. JavaScript error preventing parsing

**Solutions**:
1. Verify using Gemini 2.0 Flash Thinking mode
2. Check `enable_streaming="true"` in shortcode
3. Check browser console for JavaScript errors

### Issue: Thinking text in wrong place

**Possible Causes**:
1. CSS not loaded
2. Status element not found

**Solutions**:
1. Clear browser cache
2. Check for `.wp-mcp-ai-chat__status` element in DOM
3. Verify CSS file is loaded

### Issue: No cursor blinking

**Possible Causes**:
1. CSS animation not working
2. `::after` pseudo-element not rendering

**Solutions**:
1. Check browser DevTools for CSS errors
2. Verify `@keyframes` animation is defined
3. Test in different browser

## Future Enhancements

### Potential Improvements

1. **Configurable Display**:
   - Option to hide/show thinking text
   - User preference for thinking verbosity

2. **Advanced Styling**:
   - Different colors for different thinking phases
   - Progress indicators for multi-step reasoning

3. **Analytics**:
   - Track thinking text length
   - Measure thinking time vs response time

4. **Multi-Provider Support**:
   - Add support if OpenAI exposes reasoning
   - Support custom models with thinking output

5. **Accessibility**:
   - Screen reader announcements for thinking updates
   - Keyboard shortcuts to expand/collapse thinking

## Security Considerations

### XSS Prevention
- Thinking text is escaped using `escapeHtml()` function
- No HTML rendering in thinking text (plain text only)
- Same security model as other status messages

### Content Safety
- Thinking text goes through same content filters as responses
- No special handling needed for malicious content
- Backend validation applies to all streamed content

## Related Features

- **Streaming Text Display** (`STREAMING_TEXT_DISPLAY.md`): Main response streaming
- **Status Indicators** (`chat.css`): All status types and styling
- **SSE Handling** (`class-wp-mcp-ai-rest.php`): Server-Sent Events infrastructure
- **Gemini Client** (`class-wp-mcp-ai-gemini-client.php`): Gemini API integration

## References

- **Files Modified**:
  - `assets/css/chat.css` - Added text-stream status styles
  - `assets/js/chat.js` - Added thinking text parsing and display
  - `includes/class-wp-mcp-ai-gemini-client.php` - Added thought part handling

- **Gemini Documentation**:
  - [Gemini 2.0 Flash Thinking Mode](https://ai.google.dev/gemini-api/docs/thinking-mode)

- **OpenAI Documentation**:
  - [O1 Models](https://platform.openai.com/docs/models/o1) - Internal reasoning only

## Changelog

### Version 1.0.0 (2025-11-19)
- ✨ Initial implementation of streaming thinking text display
- ✨ Added `--text-stream` CSS modifier class
- ✨ Gemini `thought` part parsing support
- ✨ Real-time thinking text streaming in status section
- 📝 Created documentation

## Credits

- **Implementation**: GitHub Copilot
- **Feature Request**: User feedback for "streaming/thinking text in status section"
- **Inspiration**: Gemini 2.0 Flash Thinking mode transparency

## License

This feature is part of Open Operator System (WP oOS) and is licensed under GPLv3 or later.
