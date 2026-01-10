# Cloudflare System Prompt Diagnostic Logging - January 10, 2026

## Issue Summary

User reports that when using Cloudflare provider via the chat-client endpoint:
- System prompt appears empty or only contains tool information
- LLM doesn't receive context about which assistant is responding
- This breaks agentic workflow continuity across messages

## Diagnostic Logging Added

We've added comprehensive logging at 3 critical checkpoints to trace the system_prompt flow:

### Checkpoint 1: Assistant Config Loading
**File**: `includes/class-wp-mcp-ai-rest.php` (line ~2288)  
**Event**: `rest_chat_assistant_config_loaded`  
**Purpose**: Verify system_prompt exists when loaded from database

**Logs**:
- `assistant_id` - Which assistant is being used
- `has_system_prompt` - Boolean: Does config have system_prompt?
- `system_prompt_length` - Character count
- `system_prompt_preview` - First 200 characters
- `provider` - Which AI provider (should be 'cloudflare')
- `model` - Which model
- `tools_count` - Number of tools enabled

### Checkpoint 2: Options Sanitization
**File**: `includes/rest/class-wp-mcp-ai-rest-validator.php` (line ~640)  
**Event**: `cloudflare_sanitize_options_system_prompt`  
**Purpose**: Verify system_prompt transfers correctly to options array

**Logs**:
- `assistant_id` - From config if available
- `assistant_title` - From config if available
- `has_system_prompt_in_options` - Was it passed in request?
- `system_prompt_empty` - Is the final options value empty?
- `system_prompt_length` - Final length in options
- `system_prompt_preview` - First 200 chars in options
- `has_system_prompt_in_config` - Was it in assistant config?
- `config_system_prompt_length` - Length from config
- `config_system_prompt_preview` - Preview from config

### Checkpoint 3: Cloudflare Client Usage
**File**: `includes/class-wp-mcp-ai-cloudflare-client.php` (existing)  
**Events**: 
- `cloudflare_system_prompt_check`
- `cloudflare_system_messages_added`
- `cloudflare_payload_build`
- `cloudflare_system_field_added` OR `cloudflare_no_system_field`

**Purpose**: Verify system_prompt is used when building Cloudflare payload

## How to Use the Logging

### Step 1: Enable Logging
In WordPress admin:
1. Go to **Settings → NV oOS**
2. Check **Enable Logging**
3. Save settings

### Step 2: Reproduce the Issue
1. Use the chat widget or chat-client interface
2. Send a message to assistant #331 (or affected assistant)
3. Note the assistant's response behavior

### Step 3: Retrieve Logs
Via WP-CLI:
```bash
# Get recent activity logs
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("cloudflare") or contains("rest_chat"))'

# Or get all recent logs
wp option get wp_mcp_ai_recent_activity --format=json > logs.json
```

Via WordPress admin:
1. Go to **Settings → NV oOS → View Logs** (if available)
2. Filter by events containing "cloudflare" or "rest_chat"

### Step 4: Analyze Log Flow

**Scenario A: System Prompt Empty from Start**
```json
{
  "event": "rest_chat_assistant_config_loaded",
  "data": {
    "assistant_id": 331,
    "has_system_prompt": false,  ← PROBLEM HERE
    "system_prompt_length": 0,
    "provider": "cloudflare"
  }
}
```
**Diagnosis**: Assistant #331 has no system_prompt in database  
**Fix**: Edit assistant in WordPress admin and add system instructions

**Scenario B: System Prompt Lost During Sanitization**
```json
{
  "event": "rest_chat_assistant_config_loaded",
  "data": {
    "has_system_prompt": true,
    "system_prompt_length": 1500,  ← Exists here
    "system_prompt_preview": "You are a helpful assistant..."
  }
}
{
  "event": "cloudflare_sanitize_options_system_prompt",
  "data": {
    "has_system_prompt_in_config": true,
    "config_system_prompt_length": 1500,
    "system_prompt_empty": true,  ← But gone here!
    "system_prompt_length": 0
  }
}
```
**Diagnosis**: `wp_kses_post()` may be stripping content or logic error in sanitize_options  
**Fix**: Investigate sanitization logic

**Scenario C: System Prompt Not Reaching Cloudflare Client**
```json
{
  "event": "cloudflare_sanitize_options_system_prompt",
  "data": {
    "system_prompt_empty": false,
    "system_prompt_length": 1500  ← Exists in options
  }
}
{
  "event": "cloudflare_system_prompt_check",
  "data": {
    "has_system_prompt": false,  ← But not in client!
    "is_empty": true
  }
}
```
**Diagnosis**: System prompt not being passed to Cloudflare client  
**Fix**: Check how options are passed to `create_chat_completion()`

**Scenario D: Everything Correct But Still Not Working**
```json
All logs show system_prompt present and correct, but LLM behavior is wrong
```
**Diagnosis**: System prompt may be correctly sent but:
1. Cloudflare API not processing it correctly
2. Model not following instructions
3. System prompt content itself is problematic

**Fix**: 
- Verify system field is in actual API request (add network logging)
- Test with simpler system prompt
- Try different Cloudflare model

## Expected Fix Scenarios

Based on logs, here are likely fixes:

### Fix 1: Empty System Prompt in Database
**Code Change**: None needed  
**Action**: User needs to add system instructions to assistant #331

### Fix 2: wp_kses_post Stripping Content
**Code Change**: Replace `wp_kses_post()` with less aggressive sanitization  
**File**: `includes/rest/class-wp-mcp-ai-rest-validator.php`

### Fix 3: System Prompt Not in Options
**Code Change**: Ensure system_prompt is copied from config  
**File**: `includes/rest/class-wp-mcp-ai-rest-validator.php`

### Fix 4: Options Not Passed to Client
**Code Change**: Ensure options array includes system_prompt  
**File**: `includes/class-wp-mcp-ai-rest.php`

### Fix 5: Assistant Identity Context Missing
**Code Change**: Add assistant ID/title to system prompt for Cloudflare  
**File**: `includes/class-wp-mcp-ai-cloudflare-client.php`

## Testing After Fix

1. Clear logs
2. Send test message
3. Verify new logs show system_prompt at all checkpoints
4. Verify LLM response follows assistant instructions
5. Verify agentic workflow maintains context

## Related Documentation

- `docs/fixes/cloudflare-system-prompt-fix-2026-01-10.md` - Previous system prompt fix
- `docs/fixes/CLOUDFLARE_COMPLETE_FIX_SUMMARY.md` - Complete Cloudflare fix history
- `docs/fixes/cloudflare-payload-field-ordering-fix-2026-01-10.md` - Payload ordering fix

## Status

**Diagnostic logging added** - Ready for testing  
**Waiting for**: Log analysis from real-world test

## Date

January 10, 2026
