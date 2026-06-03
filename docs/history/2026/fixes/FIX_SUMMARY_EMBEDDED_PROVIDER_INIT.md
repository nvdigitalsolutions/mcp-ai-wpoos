# Fix: Embedded Provider Assistant Initialization

## Problem Statement

The embedded LLM client was not receiving assistant configuration (system prompt, tools, knowledge) when being initialized, causing it to skip context initialization with the log message:

```
[NV oOS Embedded Client] Skipping context initialization - no system prompt or knowledge: chat-1704-1769423121460-bwyg7apf4
```

This resulted in the embedded client creating a basic instance instead of an enhanced instance with full assistant capabilities.

## Root Cause

In `assets/js/chat.js` at lines 11524-11527, the code was checking `state.config` values to determine whether to use the enhanced WebLLM client, but it should have been checking the prepared `assistantConfig` object instead.

The specific issue:
- The code correctly builds a `completeSystemPrompt` by combining `state.config.systemPrompt` and `state.config.professionalPrompt` (lines 11482-11496)
- This combined prompt is stored in `assistantConfig.systemPrompt` (line 11501)
- **BUT** the capability flags were checking the original separate values in `state.config` instead of the combined value in `assistantConfig`

This meant:
- If an assistant had only a `professionalPrompt` and no `systemPrompt`, the check would see the professional prompt
- However, it wouldn't properly evaluate the COMBINED prompt that was actually being passed to the embedded client
- The flags could evaluate incorrectly, causing the wrong client type to be instantiated

## Solution

Changed lines 11524-11528 in `assets/js/chat.js` to check `assistantConfig` values instead of `state.config` values:

### Before (Incorrect)
```javascript
const hasTools = state.config.tools && Array.isArray(state.config.tools) && state.config.tools.length > 0;
const hasKnowledge = (state.config.memoryFiles && Array.isArray(state.config.memoryFiles) && state.config.memoryFiles.length > 0) || 
                     state.config.vectorStoreId;
const hasSystemPrompt = state.config.systemPrompt || state.config.professionalPrompt;
```

### After (Correct)
```javascript
// Check assistantConfig values (which include combined system prompt) instead of state.config
const hasTools = assistantConfig.tools && Array.isArray(assistantConfig.tools) && assistantConfig.tools.length > 0;
const hasKnowledge = (assistantConfig.memoryFiles && Array.isArray(assistantConfig.memoryFiles) && assistantConfig.memoryFiles.length > 0) || 
                     assistantConfig.vectorStoreId;
const hasSystemPrompt = !!(assistantConfig.systemPrompt && assistantConfig.systemPrompt.trim());
```

### Key Changes
1. **Check `assistantConfig` instead of `state.config`**: This ensures we're evaluating the actual configuration that will be passed to the embedded client
2. **Use `.trim()` for system prompt check**: This matches the logic in `embedded-llm-client.js` constructor and correctly handles whitespace-only prompts
3. **Added `!!` for boolean coercion**: Ensures the value is explicitly converted to boolean

## Impact

With this fix, the embedded client will now:
- Properly receive the combined system prompt (assistant + professional prompts)
- Initialize its context with the system instructions
- Use the enhanced WebLLM client when appropriate (when tools, knowledge, or system prompt exist)
- Correctly display logs showing the client has system prompt and knowledge:
  ```
  [NV oOS] Created enhanced WebLLM client with tools/knowledge support
  [NV oOS Embedded Client] Initializing model context for instance...
  ```

## Files Changed

1. **assets/js/chat.js** (lines 11524-11528)
   - Changed capability flag checks to use `assistantConfig` values
   - Added proper trim() validation for system prompt
   
2. **tests/js/embedded-client-config-flags.test.js** (new file)
   - Created comprehensive test suite with 14 test cases
   - Tests system prompt combination scenarios
   - Tests tools and knowledge detection
   - Tests client type selection logic
   - Tests edge cases (null, undefined, whitespace-only)

## Testing

Created a comprehensive test suite (`tests/js/embedded-client-config-flags.test.js`) with the following coverage:

### Test Scenarios
1. **System Prompt Combination**
   - Combined assistant + professional prompts
   - Both prompts present
   - Both prompts empty
   
2. **Tools Configuration**
   - Valid tools array
   - Empty tools array
   
3. **Knowledge Configuration**
   - Memory files detection
   - Vector store detection
   - Both present
   
4. **Client Type Selection**
   - Enhanced client when system prompt exists
   - Enhanced client when tools exist
   - Enhanced client when knowledge exists
   - Basic client when no capabilities exist
   
5. **Edge Cases**
   - Whitespace-only prompts
   - Null values
   - Undefined values

All tests pass and validate both the old (incorrect) and new (correct) behavior.

## Security Considerations

This change has minimal security impact as it:
- Only modifies which object properties are checked (from `state.config` to `assistantConfig`)
- Does not introduce any new user input handling
- Does not change security-sensitive code paths
- Only affects client initialization logic on the client side

The change improves security posture by:
- Ensuring system prompts are properly applied to embedded LLMs
- Maintaining consistency between what is passed to the client and what is checked

## Backward Compatibility

This fix is fully backward compatible:
- Assistants without professional prompts continue to work as before
- Assistants with only system prompts continue to work as before
- The fix only corrects the case where both prompts exist or only professional prompt exists

## Related Code

The fix aligns with the logic in `embedded-llm-client.js` constructor (lines 210-237) which:
- Expects `config.systemPrompt` to contain the complete system prompt
- Uses `.trim()` to check if the prompt is non-empty
- Sets capability flags based on these values

## Verification

To verify the fix works:
1. Create an assistant with a professional prompt but no system prompt
2. Open the chat widget with embedded provider
3. Check browser console logs
4. Should see: `[NV oOS] Created enhanced WebLLM client with tools/knowledge support`
5. Should NOT see: `[NV oOS Embedded Client] Skipping context initialization`

## References

- Issue Log: Shows "Skipping context initialization - no system prompt or knowledge"
- chat.js lines 11429-11550: sendChatEmbedded function
- embedded-llm-client.js lines 194-255: EmbeddedLLMClient constructor
- embedded-llm-client.js lines 389-430: initializeModelContext method
