# Cloudflare Chat Client Investigation - January 10, 2026

## Issue Report

**User Report**: "I think there is an issue with the chat client for Cloudflare Worker ID where it might be getting the tool list before the system prompt from the chat client (maybe the frontend js)"

**Investigation Date**: January 10, 2026

## Investigation Summary

### Finding: ✅ Issue Already Resolved

The reported issue was **already fixed** in PR #2770 (commit 9bbe6c6), merged before this investigation began.

## Problem Analysis

### Original Issue
When tools were enabled for Cloudflare Workers AI assistants, the system prompt (assistant persona and instructions) was being ignored, resulting in generic responses instead of persona-specific responses.

### Root Cause
Cloudflare Workers AI requires a different format for system prompts compared to OpenAI:

**OpenAI Format** (what we were sending):
```json
{
  "messages": [
    {"role": "system", "content": "You are..."},
    {"role": "user", "content": "Hello"}
  ]
}
```

**Cloudflare/Ollama Format** (what's required):
```json
{
  "system": "You are...",
  "messages": [
    {"role": "user", "content": "Hello"}
  ]
}
```

## Solution Implemented

### Backend Fix (PHP)

**File**: `includes/class-wp-mcp-ai-cloudflare-client.php`

**Method**: `build_payload()` (lines 370-437)

1. **Extracts system messages** from the messages array
2. **Combines multiple system messages** (handles base + professional layer)
3. **Adds as separate `system` field** in the payload
4. **Removes system messages** from messages array
5. **Normalizes tools** before adding to payload

**Code Flow**:
```php
// 1. Extract system messages
foreach ( $normalized_messages as $msg ) {
    if ( 'system' === $msg['role'] ) {
        $system_content .= $msg['content'];
    } else {
        $non_system_messages[] = $msg;
    }
}

// 2. Build payload with correct ordering
$payload = array(
    'messages' => $non_system_messages,  // No system messages here
);

// 3. Add system as separate field
if ( ! empty( $system_content ) ) {
    $payload['system'] = $system_content;  // Cloudflare format
}

// 4. Add normalized tools
if ( ! empty( $options['tools'] ) ) {
    $payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
}
```

### Frontend Analysis (JavaScript)

**File**: `assets/js/chat.js`

**Method**: `sendChat()` (lines 10921-11027)

The frontend correctly:
- **Does NOT** send `system_prompt` or `tools` from JavaScript
- Only sends: `assistant_id`, `messages`, `session_key`, `professional_prompt`, `options` overrides
- Backend retrieves `system_prompt` from assistant configuration
- Backend builds `tools` payload from assistant configuration

**This is the correct architecture** - system prompt and tools should come from the assistant configuration on the backend, not from the frontend.

## Data Flow

### Complete Request Flow

```
1. Frontend (chat.js)
   ↓
   POST /wp-json/mcp-ai/v1/chat
   {
     assistant_id: 123,
     messages: [...],
     session_key: "abc",
     professional_prompt: "..." (optional)
   }

2. REST API (class-wp-mcp-ai-rest-chat-controller.php)
   ↓
   - Loads assistant configuration (includes system_prompt)
   - Merges professional_prompt if provided
   - Builds tools payload from assistant config
   - Creates options array with system_prompt and tools

3. Language Model Router (class-wp-mcp-ai-language-model-router.php)
   ↓
   - Routes to Cloudflare client

4. Cloudflare Client (class-wp-mcp-ai-cloudflare-client.php)
   ↓
   send_message():
   - Adds system_prompt as system message (lines 193-214)
   - Prepends system messages to conversation (lines 224-237)
   
   build_payload():
   - Extracts system messages (lines 381-395)
   - Combines into single system content
   - Builds payload with separate 'system' field (lines 409-416)
   - Normalizes and adds tools (line 434)

5. Cloudflare Workers AI
   ✅ Receives correct format:
   {
     "system": "Combined system prompt...",
     "messages": [{"role": "user", ...}],
     "tools": [...]
   }
```

## Test Coverage

### Unit Tests Created

1. **`tests/test-cloudflare-system-prompt.php`** - 5 tests
   - System prompt added as system field
   - System messages extracted correctly
   - Sanitization preserves content
   - Empty system prompt handled
   - Multiple system messages combined

2. **`tests/test-cloudflare-tool-normalization.php`** - 6 tests
   - OpenAI function format normalized
   - Slug-to-name conversion
   - ID-to-name conversion
   - Invalid tool filtering
   - Multiple tools handling
   - Empty array handling

## Verification

### Manual Testing Completed (from PR #2770)

- [x] Cloudflare + system_prompt only (no tools)
- [x] Cloudflare + system_prompt + tools
- [x] Cloudflare + system_prompt + professional layer
- [x] Cloudflare + system_prompt + professional layer + tools
- [x] Assistant responds according to configured persona
- [x] System instructions respected in all scenarios
- [x] Professional layer included and respected

### Example Test Case

**Configuration**:
- Provider: `cloudflare`
- Model: `@cf/meta/llama-3.2-3b-instruct`
- System Prompt: "You are YAAD-RELIEF, a disaster relief GPT for Jamaica..."
- Tools: `list_jetengine_rest_routes`, `web_search`

**Before Fix**: ❌ "we can assist with content creation, AI research, web development..."

**After Fix**: ✅ "As YAAD-RELIEF, I can help you prepare for hurricanes in Jamaica..."

## Investigation Conclusion

### Question: "Is there an issue with tool list getting sent before system prompt?"

**Answer**: ✅ **No, there is no issue**

1. **Frontend is correct**: Does not send system_prompt or tools (these come from backend)
2. **Backend is correct**: Retrieves system_prompt and builds tools from assistant config
3. **Payload ordering is correct**: System field is added before tools in the payload
4. **Format is correct**: Cloudflare receives separate `system` field (not in messages array)
5. **Fix was already applied**: PR #2770 addressed this exact issue

### Ordering in Final Payload

The payload sent to Cloudflare API has the correct structure:
```json
{
  "system": "Your system prompt here...",
  "messages": [...],
  "tools": [...]
}
```

Cloudflare processes the `system` field **before** processing messages and tools, ensuring the assistant's persona is established first.

## Related Documentation

- **Main Fix**: `docs/fixes/cloudflare-system-prompt-fix-2026-01-10.md`
- **Tool Normalization**: `docs/fixes/cloudflare-tool-normalization-fix-2026-01-10.md`
- **Visual Flow**: `docs/fixes/cloudflare-system-prompt-visual-flow.md`
- **PR**: #2770 - "Fix Cloudflare Workers AI ignoring system prompts when tools enabled"

## Recommendations

### For Users Experiencing Similar Issues

1. **Verify you're on the latest version** with PR #2770 merged
2. **Enable logging**: Settings → NV oOS → Enable Logging
3. **Check logs** for `cloudflare_system_prompt_check` events:
   ```bash
   wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("cloudflare"))'
   ```
4. **Verify assistant configuration**:
   - System Prompt is not empty
   - Tools are properly configured
   - Provider is set to "cloudflare"

### For Developers

The architecture is correct:
- ✅ Frontend sends minimal data (assistant_id, messages, session_key)
- ✅ Backend loads configuration (system_prompt, tools)
- ✅ Backend formats for specific provider (Cloudflare, OpenAI, etc.)
- ✅ Each provider client handles its own format requirements

**Do not modify the frontend to send system_prompt or tools** - this would break the security model and assistant configuration architecture.

## Date

January 10, 2026

## Investigation By

GitHub Copilot Coding Agent
