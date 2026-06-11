# Cloudflare System Prompt Investigation - Summary

## Date
January 10, 2026

## Issue
Assistant #331 using Cloudflare provider (@cf/qwen/qwen2.5-coder-32b-instruct) does not follow its system instructions. The LLM responds with generic tool descriptions instead of maintaining the YAAD-RELIEF persona defined in the system_prompt.

## User Report
- System prompt appears empty or mixed up with tools only
- LLM doesn't receive context about which assistant is responding  
- Breaks agentic workflow continuity

## Investigation Results

### What We Confirmed ✅

From the user's provided request payload, we verified:

1. **System prompt EXISTS in database** - 5KB+ of YAAD-RELIEF disaster relief instructions
2. **System prompt IS in the request** - Full content present in `options.system_prompt`
3. **Code flow is CORRECT** - System prompt properly flows through all layers:
   - Loaded from assistant config
   - Transferred to options via `sanitize_options()`
   - Converted to system message in Cloudflare client
   - Should be extracted to `system` field in payload

### What Remains Unknown ❓

**Critical Question**: Does the `system` field actually make it into the HTTP request sent to Cloudflare API?

The code SHOULD work:
- Line 209-214: Creates system message from `options['system_prompt']`
- Line 226: Prepends system message to messages array
- Line 396-409: Extracts system messages → `$system_content`
- Line 432: Adds `$system_content` to payload as `system` field
- Line 271: Encodes payload to JSON
- Line 281: Sends to Cloudflare

But we need log confirmation that the `system` field exists in the final payload.

## Diagnostic Logging Added

### 7 Checkpoints for Complete Trace

1. **`rest_chat_assistant_config_loaded`** (includes/class-wp-mcp-ai-rest.php)
   - Shows: assistant_id, system_prompt length/preview, provider, model

2. **`cloudflare_sanitize_options_system_prompt`** (includes/rest/class-wp-mcp-ai-rest-validator.php)
   - Shows: system_prompt in config vs options, lengths, previews

3. **`cloudflare_system_prompt_check`** (includes/class-wp-mcp-ai-cloudflare-client.php)
   - Shows: Whether system_prompt exists in options when client receives it

4. **`cloudflare_system_messages_added`** (includes/class-wp-mcp-ai-cloudflare-client.php)
   - Shows: System messages prepended to conversation

5. **`cloudflare_payload_build`** (includes/class-wp-mcp-ai-cloudflare-client.php)  
   - Shows: System content extracted from messages, will_include_system_field

6. **`cloudflare_system_field_added`** (includes/class-wp-mcp-ai-cloudflare-client.php)
   - Shows: System field added to payload with length/preview

7. **`cloudflare_request`** (includes/class-wp-mcp-ai-cloudflare-client.php) **← MOST IMPORTANT**
   - Shows: **Final payload sent to API** including:
     - `has_system_field: true/false`
     - `system_field_length: N`
     - `system_field_preview: "..."`
     - `message_count: N`
     - `has_tools: true/false`

## How to Test

### Enable Logging
WordPress Admin → Settings → NV oOS → ☑ Enable Logging → Save

### Send Test Message
Use chat widget or chat-client interface with assistant #331

### Retrieve Logs

**Option 1: WP-CLI (Recommended)**
```bash
# Get all Cloudflare-related logs
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("cloudflare"))'

# Get only the final request log
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event == "cloudflare_request")'
```

**Option 2: WordPress Admin**
If log viewer is available in admin, filter by "cloudflare"

## Expected Log Analysis

### Scenario A: System Field Present in Final Payload ✅
```json
{
  "event": "cloudflare_request",
  "data": {
    "has_system_field": true,
    "system_field_length": 5234,
    "system_field_preview": "# System Instructions\n\nYou are \"YAAD-RELIEF\", a calm, fast...",
    "message_count": 1,
    "has_tools": true
  }
}
```
**Diagnosis**: Our code works correctly! Issue is with:
- Cloudflare API not processing `system` field
- Model (@cf/qwen/qwen2.5-coder-32b-instruct) not following instructions
- Model doesn't support system prompts well

**Solutions**:
1. Try a different Cloudflare model (e.g., @cf/meta/llama-3.2-3b-instruct)
2. Check Cloudflare Workers AI documentation for model limitations
3. Contact Cloudflare support about system field behavior

### Scenario B: System Field Missing ❌
```json
{
  "event": "cloudflare_request",
  "data": {
    "has_system_field": false,
    "system_field_length": 0,
    "system_field_preview": ""
  }
}
```
**Diagnosis**: Bug in our code - system field not being added to payload

**Investigation**:
- Check earlier logs (`cloudflare_system_field_added` event)
- If that event shows success, bug is between field addition and HTTP request
- If that event doesn't fire, bug is in `build_payload()` method

**Solution**: Debug `build_payload()` method in Cloudflare client

### Scenario C: System Field Empty ⚠️
```json
{
  "event": "cloudflare_request",
  "data": {
    "has_system_field": true,
    "system_field_length": 0,  ← Empty!
    "system_field_preview": ""
  }
}
```
**Diagnosis**: Content stripped during processing

**Investigation**:
- Check `cloudflare_payload_build` log - does `system_content_length` show proper value?
- If yes → Content stripped between build_payload and HTTP request
- If no → Content stripped earlier (possibly wp_kses_post at line 212)

**Solution**: Replace `wp_kses_post()` with less aggressive sanitization

## Potential Fixes

### Fix 1: Model Selection
If system field IS in payload but model doesn't follow it:
```php
// User should try different Cloudflare model
// In assistant settings, change model to:
@cf/meta/llama-3.2-3b-instruct
// or
@cf/qwen/qwen2.5-14b-instruct
```

### Fix 2: Remove wp_kses_post (if stripping content)
**File**: `includes/class-wp-mcp-ai-cloudflare-client.php` line 212

**Current**:
```php
'content' => wp_kses_post( (string) $options['system_prompt'] ),
```

**Replace with**:
```php
'content' => sanitize_textarea_field( (string) $options['system_prompt'] ),
```

Or even less aggressive:
```php
'content' => wp_kses_data( (string) $options['system_prompt'] ),
```

### Fix 3: Add Assistant Identity Context (Enhancement)
If everything works but LLM still needs more context:

**File**: `includes/class-wp-mcp-ai-cloudflare-client.php` (in `build_payload` method)

**Add after line 409**:
```php
// Add assistant identity for agentic workflow context
if ( ! empty( $system_content ) && isset( $options['assistant_id'] ) && isset( $options['assistant_title'] ) ) {
    $identity = sprintf(
        "# Assistant Identity\n\nYou are assistant \"%s\" (ID: %d).\n\n---\n\n",
        $options['assistant_title'],
        $options['assistant_id']
    );
    $system_content = $identity . $system_content;
}
```

## Status

**Diagnostic logging complete** ✅  
**Awaiting user test results** ⏳  
**Fix implementation pending** based on log analysis

## Next Steps

1. User runs test with logging enabled
2. User provides `cloudflare_request` event log  
3. We analyze log to determine root cause
4. We implement appropriate fix
5. User tests fix

## Related Documentation

- `docs/fixes/cloudflare-system-prompt-diagnostic-2026-01-10.md` - Detailed diagnostic guide
- `docs/fixes/cloudflare-system-prompt-fix-2026-01-10.md` - Previous system prompt fix
- `docs/fixes/CLOUDFLARE_COMPLETE_FIX_SUMMARY.md` - Complete Cloudflare fix history
- `docs/fixes/cloudflare-payload-field-ordering-fix-2026-01-10.md` - Payload ordering fix

## Files Modified

1. `includes/class-wp-mcp-ai-rest.php` - Added assistant config loading log
2. `includes/rest/class-wp-mcp-ai-rest-validator.php` - Added options sanitization log
3. `includes/class-wp-mcp-ai-cloudflare-client.php` - Enhanced HTTP request logging

All changes are **logging only** - no functional code changes yet. This is deliberate to gather diagnostic data before implementing a fix.
