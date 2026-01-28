# Embedded LLM Chat Client renderMessage Fix

**Date**: 2026-01-24  
**Issue**: Embedded chat client failing with `ReferenceError: renderMessage is not defined`  
**Severity**: Critical - Blocking embedded LLM functionality  
**Files Modified**: `assets/js/chat.js`

## Problem

When using the embedded LLM provider (WebLLM), the chat client threw a JavaScript error:

```
Uncaught (in promise) ReferenceError: renderMessage is not defined
    at sr (chat.js:11601:9)
```

This occurred when the embedded LLM tried to create an initial message bubble for streaming responses.

### Root Cause

The `generateEmbeddedCompletion` function at line 11601 called a non-existent function `renderMessage()`:

```javascript
// BEFORE (broken code)
const assistantMessage = {
    role: 'assistant',
    content: [{
        type: 'text',
        text: ''
    }],
    id: assistantMessageId
};
state.conversation.push(assistantMessage);
renderMessage(state, assistantMessage);  // ❌ Function doesn't exist
```

The function `renderMessage` was never defined in the codebase. The correct function to use is `appendMessage`, which creates and appends message bubbles to the DOM.

## Solution

### Changes Made

1. **Replaced `renderMessage` with `appendMessage`** (lines 11602-11606):
   ```javascript
   // Create empty message bubble for progressive updates
   const bubble = appendMessage(state.messagesEl, 'assistant', { text: '' }, true, { state: state });
   if (bubble) {
       bubble.setAttribute('data-message-id', assistantMessageId);
   }
   ```

2. **Updated streaming logic** to update bubble directly (lines 11627-11635):
   ```javascript
   // BEFORE: Looking for non-existent nested text container
   const textContainer = bubble.querySelector('.wp-mcp-ai-chat__message-text');
   if (textContainer && markdownService && markdownService.renderMarkdown) {
       textContainer.innerHTML = markdownService.renderMarkdown(fullContent);
   }
   
   // AFTER: Update bubble directly
   if (bubble) {
       if (markdownService && markdownService.renderMarkdown) {
           bubble.innerHTML = markdownService.renderMarkdown(fullContent);
       } else {
           bubble.textContent = fullContent;
       }
       scrollToBottom(state);
   }
   ```

### Why This Fix Works

1. **`appendMessage` is the standard message rendering function** used throughout the codebase
2. **Sets proper `data-message-id` attribute** so streaming updates can find the bubble via selector
3. **Updates bubble content directly** instead of looking for a nested `.wp-mcp-ai-chat__message-text` element that doesn't exist
4. **Maintains consistency** with how other message types are rendered

## Testing

### Verification Steps

1. Clear browser cache to ensure new JavaScript is loaded
2. Open chat interface with embedded LLM provider configured
3. Send a message to the embedded assistant
4. Verify:
   - No `renderMessage is not defined` error in console
   - Message bubble is created and visible
   - Streaming updates appear progressively
   - Final message content renders correctly with markdown

### Expected Behavior

- Message bubble appears immediately (empty)
- Content fills progressively as tokens stream in
- Markdown rendering works correctly
- No JavaScript errors in console

## Files Changed

- `assets/js/chat.js` (lines 11602-11635)
- `assets/js/chat.min.js` (rebuilt via `npm run build:js`)
- `assets/js/chat-bundle.min.js` (rebuilt via `npm run build:js`)

## Build Process

After fixing the source file, the minified bundles were rebuilt:

```bash
npm run build:js
```

This ensures the fix is present in:
- Development version: `chat.js`
- Production version: `chat.min.js`
- Bundled version: `chat-bundle.min.js`

## Related Code

The `appendMessage` function signature:
```javascript
function appendMessage(listEl, role, payload, allowMarkdown, options)
```

Parameters used for embedded LLM:
- `listEl`: `state.messagesEl` - The messages container
- `role`: `'assistant'` - Message role
- `payload`: `{ text: '' }` - Initial empty content
- `allowMarkdown`: `true` - Enable markdown rendering
- `options`: `{ state: state }` - Pass state for additional features

## Impact

This fix restores full functionality to the embedded LLM chat feature, allowing users to:
- Use client-side AI models (WebLLM)
- See streaming responses
- Have fully private, offline AI chat
- Benefit from GPU-accelerated inference in the browser

## Prevention

To prevent similar issues in the future:
1. Always use existing helper functions (`appendMessage`, etc.)
2. Test embedded provider functionality before commits
3. Ensure JavaScript is built after source changes
4. Add type checking or linting to catch undefined function calls
