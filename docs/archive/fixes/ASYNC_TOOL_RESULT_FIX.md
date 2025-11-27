# Async Tool Result Handling Fix

## Problem Statement

When async tools like `generate_veo_video` complete, the final SSE message with tool results was not being processed, causing the video/media result to not appear in the chat interface.

### Error Observed

```javascript
[WP oOS] SSE message event received: {
  hasChoices: false,
  hasDelta: false,
  hasContent: false,
  hasData: true,
  dataKeys: ['assistant_id', 'data', 'cost', 'sessionKey', 'tool_results'],
  fullData: {
    assistant_id: 14,
    data: { /* OpenAI response structure without choices */ },
    cost: { cost_usd: 0.010691, ... },
    sessionKey: "9b87e411-54ff-459c-af0a-44e1d907b75f",
    tool_results: [
      {
        name: "generate_veo_video",
        content: "{\"async\":true,\"status\":\"pending\",...}",
        role: "tool",
        tool_call_id: "call_370dBuyfhnOyaUCbXIKnmQbh"
      }
    ]
  }
}
```

The media file was being created successfully on the backend, but the frontend was not displaying it.

## Root Cause

In `handleChatResponse()` function (assets/js/chat.js), when an SSE message arrived with:
- `data.data` (final response structure)
- `data.tool_results` array (with completed async job)
- BUT **no `choices` array** (no new assistant message)

The function would exit early at line 9917 with "No message found in response" error, never processing the `tool_results`.

## Solution

Modified `handleChatResponse()` to dynamically create an assistant message with the tool results when no message is present, similar to how image generation results are displayed.

### Key Changes (assets/js/chat.js)

1. **Check for tool_results before rejecting** (lines 9917-9926):
   ```javascript
   const hasToolResults = data && Array.isArray(data.tool_results) && data.tool_results.length > 0;
   
   if (!message && !hasToolResults) {
       // Only error if we have neither message nor tool_results
       ...
   }
   ```

2. **Handle tool_results without message** (lines 9928-10013):
   ```javascript
   if (!message && hasToolResults) {
       // Dynamically create an assistant message with the tool results
       const assistantDisplay = {
           text: '',
           attachments: []
       };
       
       // Process each tool result
       data.tool_results.forEach(function (toolResult) {
           // Parse content, normalize for display, extract attachments
           const normalized = normaliseToolResultForDisplay(toolName, parsedContent);
           
           // Add text and attachments to assistantDisplay
           assistantDisplay.text += normalized.text;
           assistantDisplay.attachments.concat(normalized.attachments);
       });
       
       // Display assistant message with attachments
       appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {...});
   }
   ```

3. **Maintain agentic flow continuity**:
   - Add tool_results to conversation array
   - Add dynamic assistant message to conversation array
   - Save conversation to storage
   - Ensure CCT persistence

## How It Works

### Flow for Async Tool Completion

1. **Initial Request**: User asks to generate a video
2. **Tool Execution**: Backend executes `generate_veo_video`, returns `{async: true, status: "pending", job_id: "veo_xxx"}`
3. **Frontend Polling**: `waitForAsyncToolResult()` polls job status via SSE
4. **Job Completes**: Backend processes video, fires `wp_mcp_ai_job_completed` hook
5. **SSE Message Sent**: Backend sends final message with:
   - `data.data` (completion data)
   - `data.tool_results` (array with video result)
   - `data.sessionKey` (session tracking)
   - **NO `choices`** (no new LLM message)
6. **Frontend Handling**:
   - `handleChatResponse()` receives message
   - Detects `hasToolResults = true` and `message = null`
   - Parses tool result content (JSON string → object)
   - Calls `normaliseToolResultForDisplay()` to extract:
     - `video_url.url` → attachment
     - `text` → display text
   - Creates dynamic assistant message with video attachment
   - Displays video player in chat interface

### Result Structure

The tool result content is a JSON string that gets parsed:
```javascript
{
  success: true,
  job_id: "veo_6926100bb2f8e3_59706124",
  attachment_id: 12345,
  url: "https://site.com/wp-content/uploads/veo-video-xxx.mp4",
  video_url: {
    url: "https://site.com/wp-content/uploads/veo-video-xxx.mp4"
  },
  text: "Successfully generated video...",
  message: "Video generated successfully",
  provider: "gemini",
  model: "veo-3.1-generate-preview"
}
```

The `normaliseToolResultForDisplay()` function extracts:
- `video_url.url` for video attachments (displays video player)
- `image_url.url` for image attachments (displays image)
- `url` for generic file attachments (displays download link)
- `text` for display message

## Variables Clarified

### sessionKey vs session_key

- **`sessionKey`** (camelCase): Used in **response payloads** from backend to frontend
  - Backend: `$payload['sessionKey'] = $recorded_session_key;`
  - Frontend: `data.sessionKey` received in SSE messages
  - Storage: `state.config.sessionKey`

- **`session_key`** (snake_case): Used in **request payloads** from frontend to backend
  - Frontend: `payload.session_key = state.config.sessionKey;`
  - Backend: `$request->get_param('session_key')`

**Correct usage in error log**: `sessionKey: "9b87e411-54ff-459c-af0a-44e1d907b75f"` is correct for response payload.

## Testing

Created test file: `/tmp/test-async-tool-result-handling.js`

Test validates:
- ✓ Tool result structure parsing
- ✓ video_url extraction
- ✓ Attachment creation logic
- ✓ Conversation continuity
- ✓ Storage persistence

All tests pass.

## Benefits

1. **No additional API calls**: Creates response dynamically on frontend
2. **Consistent UX**: Similar to image generation display
3. **Agentic flow maintained**: Tool results and assistant message added to conversation
4. **Persistent**: Saved to localStorage and CCT
5. **Extensible**: Works for any async tool (video, audio, files, etc.)

## Files Modified

- `assets/js/chat.js`: Lines 9917-10013 in `handleChatResponse()` function

## Linting

- ✓ JavaScript linting passed (eslint)
- ✓ No syntax errors
- ✓ Follows WordPress coding standards

## Related Documentation

- See `VEO_NOTIFICATION_FLOW.md` for complete async video generation flow
- See `normaliseToolResultForDisplay()` for attachment extraction logic
- See `displayAsyncToolResult()` for alternative display method
