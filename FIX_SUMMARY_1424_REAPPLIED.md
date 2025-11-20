# Fix Summary: Issue #1424 - Images Not Surfacing to Chat-Client UI

## Problem
After PR #1426 was reverted (in PR #1428), the fix from PR #1424 was lost. This caused:
1. Tool results with images were not displaying their descriptive text in the chat UI
2. Only attachments were extracted, text was ignored
3. Empty assistant message content with tool_calls caused OpenAI "Invalid parameter(s): messages" errors

## Root Cause
The chat.js code at line 8111 only checked for `normalized.attachments`, completely ignoring the `normalized.text` field that contains descriptive messages from tools like `generate_gemini_image`.

## Solution
This PR restores the fix from PR #1424, which extracts BOTH text and attachments from normalized tool results.

### Changes Made

#### 1. Extract Tool Result Text (lines 8120-8136)
**Before:**
```javascript
if (normalized && normalized.attachments && normalized.attachments.length > 0) {
    assistantDisplay.attachments = (assistantDisplay.attachments || []).concat(normalized.attachments);
}
```

**After:**
```javascript
if (normalized) {
    // Add attachments to the assistant display.
    if (normalized.attachments && normalized.attachments.length > 0) {
        assistantDisplay.attachments = (assistantDisplay.attachments || []).concat(normalized.attachments);
    }
    
    // Add text from tool result to assistant display if present.
    if (normalized.text && normalized.text.trim()) {
        if (!assistantDisplay.text) {
            assistantDisplay.text = normalized.text.trim();
        } else {
            assistantDisplay.text += '\n\n' + normalized.text.trim();
        }
    }
}
```

#### 2. Update Conversation Content (lines 8140-8159)
Added logic to check if we now have text from tool results and update the assistant message content accordingly:

```javascript
const hasTextFromTools = assistantDisplay.text && assistantDisplay.text.trim() !== '';

if (hasDisplayContent || hasTextFromTools) {
    // Update existing assistant message with attachments and/or text from tools
    // ...
    
    // Update conversation content with text from tool results
    if (hasTextFromTools && !hasDisplayContent) {
        assistantMessage.content = assistantDisplay.text;
    }
}
```

#### 3. Null Fallback for Empty Content (lines 8075-8089)
OpenAI rejects assistant messages with tool_calls when content is an empty string. Changed to use `null` instead:

**Before:**
```javascript
if (!assistantMessage.hasOwnProperty('content')) {
    assistantMessage.content = '';
}
```

**After:**
```javascript
if (!assistantMessage.hasOwnProperty('content')) {
    // Use null instead of empty string for tool_calls without content
    assistantMessage.content = hasToolCalls ? null : '';
} else if (assistantMessage.content === '' && hasToolCalls) {
    // Convert empty string to null for tool_calls messages
    assistantMessage.content = null;
}
```

## Files Changed
- `assets/js/chat.js` - 44 insertions, 14 deletions
- `tests/test-issue-1424-tool-result-text-extraction.php` - New comprehensive test file

## Data Flow After Fix

```
Tool Execution (e.g., generate_gemini_image)
    ↓
Returns: { url: "...", text: "Gemini image saved...", ... }
    ↓
normaliseToolResultForDisplay() extracts:
    ↓
    ├─→ attachments: [{ url: "...", label: "...", ... }]
    └─→ text: "Gemini image saved to the Media Library."
    ↓
Chat.js now extracts BOTH:
    ├─→ assistantDisplay.attachments (for image display)
    └─→ assistantDisplay.text (for descriptive message)
    ↓
UI displays: Image with descriptive text
Conversation contains: Non-empty assistant message
Next API request: ✅ No "Invalid parameter(s)" errors
```

## Expected Behavior After Fix
1. ✅ Tool results with images display both the image AND descriptive text
2. ✅ Assistant messages with tool_calls have proper content (null or text)
3. ✅ No "Invalid parameter(s): messages" errors from OpenAI
4. ✅ Conversation flow continues smoothly after image generation
5. ✅ Multiple tool calls all display properly

## Testing
- ✅ ESLint passes with no errors
- ✅ CodeQL security scan passes (0 alerts)
- ✅ Comprehensive integration tests created
- Manual testing scenarios:
  - [ ] Generate image with `generate_openai_image` tool
  - [ ] Generate image with `generate_gemini_image` tool
  - [ ] Verify image displays with descriptive text
  - [ ] Send follow-up message after image generation
  - [ ] Confirm no API errors in console
  - [ ] Test with multiple tool calls in sequence

## Backward Compatibility
- No breaking changes
- Existing tool results continue to work
- Added functionality only enhances display, doesn't change core behavior
- Works with all existing tools that return normalized results

## Why This Approach vs PR #1426
PR #1426 attempted to solve the problem by adding server-side sanitization to strip base64 data before sending to chat-client. However, this broke the chat because it was too aggressive in stripping content.

This PR (based on PR #1424) takes a different approach:
- Keeps the server-side tool results intact
- Fixes the frontend to properly extract and display all available data
- Uses `null` for empty content to satisfy OpenAI's API requirements
- Simpler and less invasive solution

## Security Considerations
- No new security vulnerabilities introduced (CodeQL scan passed)
- No changes to data sanitization or validation
- Only changes how existing data is extracted and displayed
- All changes are in frontend JavaScript display logic

## Performance Impact
- Negligible - only adds a few string concatenations
- No additional API calls or database queries
- Same number of messages sent to LLM
- UI rendering impact is minimal (just text append)
