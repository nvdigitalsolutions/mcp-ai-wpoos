# Fix Summary: Embedded Chat Client OpenAI Compatibility

## Issue
The embedded chat client (WebLLM) was incorrectly treating the API as stateful, causing the assistant to lose awareness of its system prompt, tools, and base knowledge after the initial interaction.

### Symptoms
- System prompt only used during "initialization"
- System messages filtered out from subsequent requests
- Assistant behavior inconsistent across multiple messages
- Tools and knowledge context not maintained

### Root Cause
The implementation assumed WebLLM maintained stateful chat history, so it:
1. Sent system prompt once during `initializeModelContext()`
2. Filtered out system messages from all subsequent requests
3. Assumed the model "remembered" its instructions

This is **incorrect** - WebLLM follows the OpenAI API pattern where the API is **stateless**.

## Solution

### Key Changes

#### 1. Removed Stateful Initialization (`embedded-llm-client.js`)
**Before:**
```javascript
async initializeModelContext() {
    // Send system prompt once to "prime" the model
    const initMessages = [
        { role: 'system', content: this.systemPrompt },
        { role: 'user', content: 'Understood. I am ready to assist.' }
    ];
    await this.currentEngine.chat.completions.create({
        messages: initMessages,
        // ...
    });
}
```

**After:**
```javascript
async initializeModelContext() {
    // No-op: System prompts are sent with each request, not initialized once
    console.log('[NV oOS Embedded Client] Model loaded - no initialization needed (OpenAI-compatible API)');
}
```

#### 2. Stopped Filtering System Messages (`embedded-llm-client.js`)
**Before:**
```javascript
// INCORRECT: Filter out system messages
const filteredMessages = messages.filter(function(msg) {
    return msg.role !== 'system';
});

const requestPayload = {
    messages: filteredMessages, // Missing system context!
    // ...
};
```

**After:**
```javascript
// CORRECT: Include ALL messages (OpenAI-compatible)
const requestPayload = {
    messages: messages, // Includes system prompt
    temperature: options.temperature || 0.7,
    max_tokens: options.max_tokens || 512,
    stream: true
};
```

#### 3. Updated Documentation and Comments
- Added references to Web-LLM OpenAI compatibility documentation
- Updated inline comments to explain stateless pattern
- Deprecated old initialization config constants

### OpenAI API Pattern (Stateless)

According to OpenAI and Web-LLM documentation:

1. **System prompts must be included in EVERY request**
   - Not just the first one
   - Not "initialized" once and remembered

2. **Full conversation history must be sent**
   - Each request should include the complete message thread
   - The API doesn't maintain session state

3. **Tools and options are per-request**
   - Specified with each `chat.completions.create()` call
   - Not configured once at initialization

### Reference
- [Web-LLM Full OpenAI Compatibility](https://github.com/mlc-ai/web-llm?tab=readme-ov-file#full-openai-compatibility)
- [OpenAI Chat Completions API](https://platform.openai.com/docs/api-reference/chat/create)

## Files Changed

### `assets/js/embedded-llm-client.js`
- **Line 380-401**: Converted `initializeModelContext()` to no-op with documentation
- **Line 491-521**: Removed system message filtering, include all messages
- **Line 557-558**: Changed `toolCalls` from `let` to `const` (ESLint fix)
- **Line 68-88**: Changed `var` to `const` for timeoutId and error (ESLint fix)
- **Line 363**: Changed `var` to `let` for knowledgeContext (ESLint fix)
- **Removed**: MODEL_INIT_CONFIG constant (no longer needed)

### `tests/js/embedded-llm-system-message-filtering.test.js`
- Renamed test suite to "OpenAI Compatibility"
- Updated tests to verify system messages are **included**, not filtered
- Added tests for full conversation context maintenance
- Updated integration tests to reflect correct OpenAI pattern

## Testing

### Unit Tests
```bash
npm test -- tests/js/embedded-llm-system-message-filtering.test.js
```

All 16 tests pass:
- ✓ System messages included in every request
- ✓ Message order preserved
- ✓ Tools properly selected from instance or options
- ✓ Full conversation context maintained

### Expected Behavior
Now when a user sends multiple messages:

**Request 1:**
```javascript
[
  { role: 'system', content: 'You are a helpful assistant...' },
  { role: 'user', content: 'Hello!' }
]
```

**Request 2:**
```javascript
[
  { role: 'system', content: 'You are a helpful assistant...' }, // ✓ Still included!
  { role: 'user', content: 'Hello!' },
  { role: 'assistant', content: 'Hi there!' },
  { role: 'user', content: 'What can you do?' }
]
```

The system prompt is **always present**, ensuring the assistant maintains awareness of its instructions, tools, and knowledge.

## Migration Notes

### For Developers
- The `initializeModelContext()` method is now a no-op but kept for backward compatibility
- System prompts are managed by `chat.js` which adds them to the messages array
- No changes needed to existing assistant configurations

### For Users
- No user-facing changes required
- Existing chat widgets will work correctly after update
- Assistant behavior should be more consistent across conversations

## Security Considerations
- No security implications
- System prompts are already being prepared by `chat.js` (lines 11915-11949)
- This fix just ensures they're not incorrectly filtered out

## Performance Impact
- Minimal: System prompts were already being prepared, just filtered incorrectly
- Slightly increased token usage per request (includes system prompt)
- No additional API calls (removed unnecessary initialization call)

## Related Issues
- Issue: "embedded chat client still has no sense of assistant info (system prompt, tools, base knowledge)"
- Requirement: "enhance embedded chat-client to work more like openai client"

## Verification Steps

1. Open a chat widget using embedded provider (WebLLM)
2. Send first message: "Hello"
3. Verify assistant responds according to system prompt
4. Send second message: "What are some things you can do?"
5. Verify assistant still aware of tools and capabilities (doesn't say "I don't know")
6. Check browser console logs for confirmation:
   - "System prompt included in request (OpenAI-compatible)"
   - No "Filtered out system message" messages

## References
- [Web-LLM Documentation](https://webllm.mlc.ai/docs/)
- [Web-LLM GitHub](https://github.com/mlc-ai/web-llm)
- [OpenAI API Reference](https://platform.openai.com/docs/api-reference/chat)
