# Fix Summary: Issue #1424 - OpenAI "Invalid parameter(s): messages" Error

## Problem
When tools return large content (e.g., base64-encoded images), the chat-client receives raw tool results and adds them to the conversation array. When this conversation is sent back to the API in subsequent requests, OpenAI rejects it with "Invalid parameter(s): messages" errors because:
1. Base64 image data violates OpenAI's message schema
2. Extra fields like `data`, `data_url`, `raw` aren't allowed in tool messages
3. Large binary content exceeds message size limits

## Root Cause Analysis
1. **REST Controller** (`includes/class-wp-mcp-ai-rest.php` lines 2293, 2682):
   - `$tool_result_messages[]` stored RAW tool results without sanitization
   - These were sent to chat-client in the response payload

2. **Chat Client** (`assets/js/chat.js` line 8086):
   - Pushed tool results into `state.conversation` array
   - This conversation is sent back to the API in the next chat request

3. **API Rejection**:
   - OpenAI receives tool messages with invalid/extra fields
   - Returns "Invalid parameter(s): messages" error
   - Chat breaks, user experience is disrupted

## Solution: Dual Sanitization Strategy
Implemented two separate sanitization paths to serve different purposes:

### 1. LLM Sanitization (Already Existed)
- **Purpose**: Reduce token usage, improve efficiency
- **Method**: `sanitize_for_llm()`
- **Strips**: Base64 data, raw API responses, verbose metadata
- **Keeps**: IDs, URLs, status messages for LLM reasoning
- **Applied**: Before adding tool results to LLM message context

### 2. Chat Sanitization (NEW)
- **Purpose**: Ensure schema compliance, prevent API errors
- **Method**: `sanitize_for_chat()`
- **Strips**: Base64 data, data URLs, raw responses, extra fields
- **Keeps**: URLs, attachment IDs, display-friendly metadata
- **Applied**: Before sending tool results to chat-client
- **Fallback**: Uses LLM sanitization if tool doesn't implement chat-specific sanitization

## Implementation Details

### New Files Created
1. **`includes/tools/class-wp-mcp-ai-tool-chat-sanitizer-interface.php`**
   - Optional interface for tools to implement custom chat sanitization
   - Mirrors `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` design pattern
   - Allows tools to define what data is safe for chat-client

### Modified Files

2. **`includes/rest/class-wp-mcp-ai-rest-validator.php`** (+178 lines)
   - Added `sanitize_tool_result_for_chat()` - Main entry point for chat sanitization
   - Added `sanitize_complex_data_for_chat()` - Recursive sanitization for arrays/objects
   - Added `is_large_binary_data()` - Detects base64/data URLs that should be stripped
   - Added `looks_like_base64()` - Heuristic to identify base64-encoded strings
   - Added `sanitize_scalar_for_chat()` - Sanitizes primitive values
   - Added comprehensive logging for sanitization decisions (SoC principle)

3. **`includes/class-wp-mcp-ai-rest.php`** (Modified ~55 lines)
   - Required new chat sanitizer interface
   - Updated non-streaming code path (line ~2270):
     - Get tool instance BEFORE creating tool messages
     - Apply `sanitize_tool_result_for_chat()` to tool results
     - Store sanitized results in `$tool_result_messages[]`
   - Updated streaming code path (line ~2655):
     - Same changes as non-streaming
     - Ensures consistency across both execution paths

### Test Files Created
4. **`tests/test-chat-sanitization-dual-path.php`** (217 lines, 7 test cases)
   - Tests base64 data stripping
   - Tests raw response stripping  
   - Tests fallback to LLM sanitization
   - Tests scalar value preservation
   - Tests nested array sanitization
   - Tests filter application
   - Comprehensive coverage of sanitization logic

5. **`tests/test-issue-1424-integration.php`** (205 lines, 3 integration tests)
   - Tests realistic image generation scenario
   - Tests dual sanitization (LLM vs Chat) works together
   - Tests full conversation flow doesn't trigger API errors
   - Validates the actual bug is fixed

## Key Design Decisions

### 1. Separation of Concerns (SoC)
- Sanitization logic lives in `WP_MCP_AI_REST_Validator` class
- REST controller just calls validator methods
- Each method has a single, clear responsibility
- Logging is centralized and consistent

### 2. Interface-Based Design
- Tools can optionally implement `WP_MCP_AI_Tool_Chat_Sanitizer_Interface`
- Provides fine-grained control for tool-specific needs
- Falls back to LLM sanitization if no chat sanitization defined
- Follows existing pattern from `WP_MCP_AI_Tool_LLM_Sanitizer_Interface`

### 3. Logging Strategy
- Logs when custom chat sanitization is used
- Logs when falling back to LLM sanitization
- Logs when stripping large binary fields
- Provides visibility for debugging and monitoring
- Uses `WP_MCP_AI_Logger::log_event()` for consistency

### 4. Backward Compatibility
- Existing tools continue to work without changes
- Tools with LLM sanitization automatically get chat sanitization
- No breaking changes to existing APIs
- New interface is optional

## Data Flow After Fix

```
Tool Execution
    ↓
Raw Tool Result (with base64 images)
    ↓
    ├─→ sanitize_for_llm() → LLM Messages (token-optimized)
    │
    └─→ sanitize_for_chat() → Chat Client (schema-compliant)
            ↓
        tool_result_messages[] (sanitized)
            ↓
        Sent to chat-client in response
            ↓
        Chat client adds to conversation
            ↓
        Next API request (no errors!)
```

## Affected Code Paths
1. Non-streaming chat requests (`/chat`, `/chat-client`)
2. Streaming chat requests (SSE)
3. All tools that return complex data structures
4. Specifically fixes image generation tools (Gemini, OpenAI)

## Testing Strategy
- Unit tests for individual sanitization methods
- Integration tests for full request/response cycle
- Tests validate actual bug scenario is fixed
- Tests ensure backward compatibility maintained

## Expected Behavior After Fix
1. ✅ Tools can return base64 images without breaking chat
2. ✅ Chat-client receives safe, schema-compliant tool results
3. ✅ Subsequent API requests don't trigger parameter errors
4. ✅ Large files don't bloat conversation context
5. ✅ LLM still gets token-efficient messages
6. ✅ Chat UI can still display images via URLs

## Verification Checklist
- [x] Syntax validation passes (php -l)
- [x] Code follows WordPress coding standards
- [x] SoC principles maintained
- [x] Logging is comprehensive
- [x] Tests created for new functionality
- [ ] Tests pass successfully
- [ ] No regressions in existing tests
- [ ] Manual testing with image generation tools

## Migration Notes
No migration needed. The fix is transparent to end users and tool developers. Existing tools benefit automatically from the fallback to LLM sanitization.

## Performance Considerations
- Sanitization adds minimal overhead (O(n) for array traversal)
- Base64 detection is optimized with early returns
- Logging is conditional and uses existing logger infrastructure
- No impact on tools that return simple results

## Security Considerations
- Sanitization ensures only safe data goes to chat-client
- Prevents injection of malicious data through tool results
- UTF-8 validation ensures data integrity
- Follows WordPress sanitization best practices

## Related Issues
- Fixes #1424: OpenAI "Invalid parameter(s): messages" error
- Prevents similar issues with Gemini and other providers
- Addresses "this broke the chat" user feedback
- Ensures large files don't get passed to LLM

## Future Enhancements
1. Consider adding max message size limits
2. Add metrics for sanitization effectiveness
3. Create admin UI to view sanitization logs
4. Add more granular control over which fields to strip
