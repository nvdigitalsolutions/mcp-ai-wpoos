# Agentic Loop finish_reason Fix

## Issue
LM Studio (and potentially other providers) would continue generating predictions in an infinite loop instead of sending the final message back to the chat client after executing tool calls.

## Root Cause
The agentic loop in `WP_MCP_AI_Chat_Service::process_chat_request()` only checked if `tool_calls` were empty to determine if the loop should continue. It didn't check the `finish_reason` field in the response.

According to the OpenAI API specification (which LM Studio and other providers follow):
- `finish_reason: "tool_calls"` - Model wants to execute tool calls
- `finish_reason: "stop"` - Model has completed its response

Some models may return a response with both tool execution history AND `finish_reason: "stop"` to indicate "I've executed the tools and here's my final answer."

## Solution
Added a check for `finish_reason` in the agentic loop exit condition:

```php
if ( empty( $tool_calls ) || 'stop' === $finish_reason ) {
    break; // Final response ready.
}
```

## Implementation Details

### Files Modified
1. **includes/services/class-wp-mcp-ai-chat-service.php**
   - Added `extract_finish_reason_from_response()` helper method
   - Updated agentic loop to check `finish_reason` 
   - Enhanced logging to show exit reason

2. **tests/test-agentic-chat-workflow-comprehensive.php**
   - Added `test_agentic_loop_exits_on_finish_reason_stop()` test case
   - Updated test helper methods to include proper `finish_reason` values

### Separation of Concerns (SoC)
The fix maintains excellent SoC by:
- **Single location**: Fix is in the centralized Chat Service orchestration layer
- **All providers benefit**: OpenAI, Gemini, Anthropic, Ollama, and LM Studio all use Chat Service
- **Provider responsibility**: Each provider client normalizes its responses to include `finish_reason`
- **Service responsibility**: Chat Service orchestrates the agentic loop based on normalized responses

### Provider Compatibility
All providers already normalize responses to include `finish_reason`:

| Provider   | Implementation |
|-----------|----------------|
| **OpenAI** | Native OpenAI format includes `finish_reason` |
| **LM Studio** | OpenAI-compatible API, preserves `finish_reason` from response |
| **Ollama** | Normalizes `done_reason` to `finish_reason` |
| **Gemini** | Normalizes `finishReason` to `finish_reason` |
| **Anthropic** | Normalizes `stop_reason` to `finish_reason` with mapping:<br>- `end_turn` → `stop`<br>- `tool_use` → `tool_calls`<br>- `max_tokens` → `length` |

## Testing
Added comprehensive test case that verifies:
1. Agentic loop executes tool calls when `finish_reason: "tool_calls"`
2. Agentic loop exits immediately when `finish_reason: "stop"`
3. Loop doesn't run until `max_iterations` when model signals completion

## Backward Compatibility
The fix is backward compatible:
- If `finish_reason` is not present, it returns empty string
- Empty string doesn't match `'stop'`, so existing behavior is preserved
- Loop continues to work with the original `empty( $tool_calls )` check

## Example Scenario (LM Studio)

### Before Fix
```
User: "What's the weather in Jamaica?"
→ LM Studio generates tool_call: get_open_meteo_forecast()
→ Tool executes successfully
→ LM Studio generates another tool_call (or keeps generating)
→ Loop continues until max_iterations (15 for chat client)
→ User sees no response until iteration limit
```

### After Fix
```
User: "What's the weather in Jamaica?"
→ LM Studio generates tool_call: get_open_meteo_forecast(), finish_reason: "tool_calls"
→ Tool executes successfully  
→ LM Studio generates final response, finish_reason: "stop"
→ Loop exits immediately (iteration 1)
→ User sees: "The weather in Jamaica is sunny, 28°C..."
```

## Related Documentation
- OpenAI API Specification: https://platform.openai.com/docs/api-reference/chat/object
- LM Studio Function Calling: `LM_STUDIO_FUNCTION_CALLING.md`
- Chat Service: `docs/services/chat-service.md`
