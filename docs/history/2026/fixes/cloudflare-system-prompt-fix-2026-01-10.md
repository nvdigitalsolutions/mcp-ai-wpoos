# Cloudflare Workers AI System Prompt Fix - January 10, 2026

## Issue Summary

The Cloudflare Workers AI client was not sending system instructions (system_prompt) to the LLM, causing the assistant to respond with generic answers instead of following the configured persona and instructions. This issue appeared after adding tool support to the Cloudflare client.

### Symptoms

- Assistant configured with detailed system instructions (e.g., "YAAD-RELIEF" disaster relief GPT)
- Responses were generic: "we can assist with content creation, AI research, web development..."
- Neither the assistant's base system instructions NOR the professional layer prompts were being respected
- Issue occurred when using Cloudflare provider with tools enabled

### User Report

> "the cloudflare worker ai client is responding in the chat client as well as the tool response but it does not look like the assistant default settings (system instructions) and maybe the professional layer is being included to what is sent to the llm"
>
> Clarification: "both should be and they are not"
>
> Additional context: "it was working before we started adding tool support"

## Root Cause

Cloudflare Workers AI API uses a different format for system prompts compared to OpenAI:

### OpenAI Format (What we were using)
```json
{
  "messages": [
    {
      "role": "system",
      "content": "You are a helpful assistant..."
    },
    {
      "role": "user",
      "content": "Hello"
    }
  ]
}
```

### Cloudflare/Ollama Format (What's required)
```json
{
  "system": "You are a helpful assistant...",
  "messages": [
    {
      "role": "user",
      "content": "Hello"
    }
  ]
}
```

**The bug**: Cloudflare Workers AI expects system prompts in a separate `system` field, similar to Ollama, not as system role messages in the messages array. When we added tool support, system role messages in the messages array were being ignored by the Cloudflare API.

## Solution

Modified `WP_MCP_AI_Cloudflare_Client::build_payload()` to:

1. **Extract system role messages** from the messages array
2. **Combine multiple system messages** into a single system prompt (handles professional layer)
3. **Add system prompt to payload** as a separate `system` field
4. **Remove system messages** from the messages array sent to Cloudflare

### Code Changes

**File**: `includes/class-wp-mcp-ai-cloudflare-client.php`

**Modified `build_payload()` method** (lines 370-438):

```php
protected function build_payload( array $messages, array $options ) {
    // Normalize messages to ensure content is in the correct format.
    $normalized_messages = $this->normalize_messages( $messages );

    // Cloudflare Workers AI uses a separate 'system' field for system prompts
    // rather than system role messages, similar to Ollama.
    // Extract system messages from the messages array.
    $system_content = '';
    $non_system_messages = array();

    foreach ( $normalized_messages as $msg ) {
        if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
            // Accumulate system message content.
            if ( ! empty( $msg['content'] ) ) {
                if ( ! empty( $system_content ) ) {
                    $system_content .= "\n\n" . $msg['content'];
                } else {
                    $system_content = $msg['content'];
                }
            }
        } else {
            // Keep non-system messages.
            $non_system_messages[] = $msg;
        }
    }

    $payload = array(
        'messages' => $non_system_messages,
    );

    // Add system prompt as a separate field (Cloudflare/Ollama style).
    if ( ! empty( $system_content ) ) {
        $payload['system'] = $system_content;
    }

    // ... rest of payload building (temperature, tools, etc.)
}
```

## Testing

Created comprehensive test suite in `tests/test-cloudflare-system-prompt.php` with 5 test cases:

1. **`test_system_prompt_added_as_system_field()`**  
   Verifies system_prompt is added as a `system` field in the payload, not as a system role message

2. **`test_system_messages_extracted_to_system_field()`**  
   Verifies system role messages are extracted from messages array and placed in `system` field

3. **`test_system_prompt_sanitization_preserves_content()`**  
   Verifies wp_kses_post doesn't strip meaningful content from system instructions

4. **`test_empty_system_prompt_no_system_field()`**  
   Verifies no `system` field is added when system_prompt is empty

5. **`test_multiple_system_messages_combined()`**  
   Verifies multiple system messages (base + professional layer) are combined correctly

## Comparison with Other Providers

| Provider    | System Prompt Format | Implementation |
|-------------|---------------------|----------------|
| OpenAI      | System role in messages array | `messages: [{role: 'system', content: '...'}]` |
| Anthropic   | System role in messages array | `messages: [{role: 'system', content: '...'}]` |
| Gemini      | System role in messages array | `messages: [{role: 'system', content: '...'}]` |
| **Ollama**  | **Separate `system` field** | `system: '...', messages: [...]` |
| **Cloudflare** | **Separate `system` field** (FIXED) | `system: '...', messages: [...]` |

## Professional Layer Support

The fix properly handles professional layer prompts that are added as additional system messages:

**Before (Broken)**:
```json
{
  "messages": [
    {"role": "system", "content": "Base instructions"},
    {"role": "system", "content": "Professional layer"},
    {"role": "user", "content": "Hello"}
  ]
}
```
↓ Cloudflare ignores system role messages ↓  
❌ **Result**: Generic responses

**After (Fixed)**:
```json
{
  "system": "Base instructions\n\nProfessional layer",
  "messages": [
    {"role": "user", "content": "Hello"}
  ]
}
```
↓ Cloudflare respects system field ↓  
✅ **Result**: Persona-aware responses

## Debug Logging

Added comprehensive logging to trace system_prompt through the entire flow:

1. **`cloudflare_system_prompt_check`**: Logs when checking system_prompt in options
2. **`cloudflare_system_messages_added`**: Logs when system messages are prepended
3. **`cloudflare_payload_build`**: Logs payload construction with system content length

Enable logging: **Settings → NV oOS → Enable Logging**

Retrieve logs via WP-CLI:
```bash
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("cloudflare"))'
```

## Verification Steps

### Manual Testing Checklist

- [x] Test with Cloudflare provider + system_prompt only (no tools)
- [x] Test with Cloudflare provider + system_prompt + tools
- [x] Test with Cloudflare provider + system_prompt + professional layer
- [x] Test with Cloudflare provider + system_prompt + professional layer + tools
- [x] Verify assistant responds according to configured persona
- [x] Verify system instructions are respected in all scenarios
- [x] Verify professional layer is included and respected

### Example Test Case

**Assistant Configuration**:
- Provider: cloudflare  
- Model: @cf/meta/llama-3.2-3b-instruct
- System Prompt: "You are YAAD-RELIEF, a disaster relief GPT for Jamaica..."
- Tools: list_jetengine_rest_routes, web_search

**Expected Result**: Assistant responds with disaster relief persona and Jamaica-specific guidance

**Before Fix**: ❌ "we can assist with content creation, AI research, web development..."  
**After Fix**: ✅ "As YAAD-RELIEF, I can help you prepare for hurricanes in Jamaica..."

## Impact

✅ **Fixes**: Cloudflare Workers AI now respects system instructions  
✅ **Fixes**: Professional layer prompts are properly included  
✅ **Fixes**: Works correctly with AND without tools enabled  
✅ **Maintains Compatibility**: All existing Cloudflare functionality preserved  
✅ **Aligns with Ollama**: Uses same system prompt pattern as Ollama client  
✅ **Security**: Proper sanitization applied to all content  

## Related Issues

- **Previous Fix**: cloudflare-tool-normalization-fix-2026-01-10.md - Tool format normalization
- **Previous Fix**: CLOUDFLARE_MESSAGE_FORMAT_FIX_2026.md - Message content format
- **Related**: Ollama client uses same `system` field pattern (line 232)

## Breaking Changes

None. This is a bug fix that restores expected behavior.

## Date

January 10, 2026

## Commit

`3ee920b` - Fix Cloudflare Workers AI system prompt not being sent to LLM
