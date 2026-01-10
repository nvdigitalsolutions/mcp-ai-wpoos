# Cloudflare Tool Auto-Triggering Fix - Complete Summary

**Date**: January 10, 2026  
**Issue**: Chat-client auto-triggering tools without user request  
**Status**: ✅ **RESOLVED**

---

## The Problem

User reported that when using Cloudflare Workers AI with tools enabled:

1. **Auto-triggering issue**: Simple questions like "what can you do?" would trigger `web_search` tool
2. **Persona ignored**: Assistant's system prompt (YAAD-RELIEF disaster relief persona) was being ignored
3. **Poor UX**: Tools called on first instinct instead of when explicitly needed

### Example of Bad Behavior

```json
{
  "assistant_id": 331,
  "messages": [
    {
      "role": "user",
      "content": "what are some things you can do"
    },
    {
      "role": "assistant",
      "tool_calls": [
        {
          "function": {
            "name": "web_search",
            "arguments": "{\"query\":\"things you can do\"}"
          }
        }
      ]
    }
  ]
}
```

❌ **Wrong**: Tool triggered immediately, assistant didn't describe its capabilities

---

## The Solution

### Three-Part Fix

#### 1. **tool_choice Parameter Support**
Added OpenAI-compatible `tool_choice` parameter:
- `"none"` - Exclude tools from payload
- `"auto"` - Let LLM decide (previous default)
- `"required"` / `"any"` - Force tool use
- Specific tool object - Force particular tool

#### 2. **response_format Parameter Support**
Added JSON mode support:
- `{type: "json_object"}` - Simple JSON
- `{type: "json_schema", json_schema: {...}}` - Structured with schema

#### 3. **Smart Chat-Client Default** 🎯
**CRITICAL**: Chat-client now defaults to `tool_choice="none"` for Cloudflare:
- Prevents auto-triggering
- Respects assistant persona
- User can override per-request

---

## How It Works Now

### Default Behavior (Chat-Client)

```javascript
// User asks about capabilities
POST /wp-json/mcp-ai/v1/chat-client
{
  "assistant_id": 331,
  "messages": [
    {"role": "user", "content": "what can you do"}
  ]
  // No options needed
}

// Backend automatically sets tool_choice="none" for Cloudflare
// Tools excluded from API payload
// Assistant responds from system prompt ✅
```

### When Tools Are Needed

```javascript
// User explicitly requests search
POST /wp-json/mcp-ai/v1/chat-client
{
  "assistant_id": 331,
  "messages": [
    {"role": "user", "content": "search for hurricane updates"}
  ],
  "options": {
    "tool_choice": "auto" // Enable tools for this request
  }
}

// LLM decides to use web_search appropriately ✅
```

---

## Implementation Details

### Code Changes

#### 1. Cloudflare Client (`includes/class-wp-mcp-ai-cloudflare-client.php`)

**In `build_payload()` method:**
```php
// Check tool_choice parameter
$tool_choice = isset( $options['tool_choice'] ) ? $options['tool_choice'] : 'auto';

// Exclude tools if tool_choice is "none"
if ( 'none' !== $tool_choice && ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
    $payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
    
    // Add tool_choice to payload if not "auto"
    if ( 'auto' !== $tool_choice ) {
        $payload['tool_choice'] = $tool_choice;
    }
}

// Auto-JSON mode for tools
if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
    $payload['response_format'] = $options['response_format'];
} elseif ( $has_tools && ! isset( $options['disable_auto_json'] ) ) {
    $payload['response_format'] = array( 'type' => 'json_object' );
}
```

#### 2. REST Chat Controller (`includes/rest/class-wp-mcp-ai-rest-chat-controller.php`)

**New method `set_chat_client_tool_choice_default()`:**
```php
public function set_chat_client_tool_choice_default( $options, $assistant_config, $request_params ) {
    $provider = isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '';
    
    // Only for Cloudflare, when tool_choice not already set
    if ( 'cloudflare' === $provider && ! isset( $options['tool_choice'] ) && ! empty( $options['tools'] ) ) {
        $options['tool_choice'] = 'none'; // Default to "none"
    }
    
    return $options;
}
```

**Applied via filter in `handle_chat_client_request()`:**
```php
add_filter( 'wp_mcp_ai_chat_options', array( $this, 'set_chat_client_tool_choice_default' ), 5, 3 );
```

---

## Test Coverage

### 26 Unit Tests Created

1. **test-cloudflare-tool-choice.php** (8 tests)
   - tool_choice="none" excludes tools
   - tool_choice="auto" includes tools
   - tool_choice="required" forces tool use
   - Specific tool selection
   - Default behavior
   - Empty tools handling
   - Sanitization

2. **test-cloudflare-response-format.php** (11 tests)
   - response_format with json_object
   - response_format with json_schema
   - Not added when absent
   - Invalid type handling
   - Works with tools
   - Works with tool_choice
   - Auto-JSON mode activation
   - Auto-JSON can be disabled
   - Explicit format overrides auto-JSON

3. **test-chat-client-tool-choice-default.php** (7 tests)
   - Cloudflare defaults to "none"
   - Explicit choice not overridden
   - Non-Cloudflare not affected
   - No default when no tools
   - User can override
   - Specific tool not overridden
   - Missing provider handled

---

## Backward Compatibility

✅ **100% Backward Compatible**

| Scenario | Behavior |
|----------|----------|
| MCP remote clients (`/chat`) | Unchanged - still uses "auto" |
| Chat-client with OpenAI | Unchanged - still uses "auto" |
| Chat-client with Gemini | Unchanged - still uses "auto" |
| Chat-client with Cloudflare | **NEW** - defaults to "none" |
| Explicit `tool_choice` in request | Always respected (any provider) |
| `run_with_tools()` method | Works with all new parameters |

---

## Benefits

### For Users
1. ✅ **Better UX**: Assistants respond from persona first
2. ✅ **No confusion**: Tools only used when appropriate
3. ✅ **Full control**: Can enable tools per-request
4. ✅ **Smart defaults**: Works great out of the box

### For Developers
1. ✅ **OpenAI compatibility**: Same parameters work
2. ✅ **Cloudflare parity**: Matches @cloudflare/ai-utils
3. ✅ **Flexible**: Fine-grained control over tool usage
4. ✅ **Well tested**: 26 unit tests

### For Cloudflare
1. ✅ **JSON mode**: Better parsing reliability
2. ✅ **Auto-JSON**: Activated when needed
3. ✅ **Tool choice**: Full API support
4. ✅ **Run with tools**: All parameters supported

---

## Usage Examples

### YAAD-RELIEF Disaster Assistant

**Before Fix:**
```
User: "what are some things you can do"
→ web_search("things you can do") called ❌
→ Generic search results returned
→ Persona completely ignored
```

**After Fix:**
```
User: "what are some things you can do"
→ tool_choice="none" (default)
→ Assistant responds: "I am YAAD-RELIEF, a disaster relief assistant for Jamaica..."
→ Describes specific capabilities from system prompt ✅

User: "search for current hurricane warnings"
→ Override with tool_choice="auto"
→ web_search appropriately used ✅
```

### Tool Selection by Name

```javascript
// Only use web_search when user explicitly asks
if (userMessage.includes('search') || userMessage.includes('find')) {
    options.tool_choice = 'auto'; // Enable tools
} else {
    // Use default (tool_choice="none" for Cloudflare)
    // Assistant responds from knowledge
}
```

---

## Configuration Options

### Per-Request (JavaScript)

```javascript
options: {
    tool_choice: "none" | "auto" | "required" | "any" | {type: "function", function: {name: "tool_name"}},
    response_format: {type: "json_object"} | {type: "json_schema", json_schema: {...}},
    disable_auto_json: true | false
}
```

### Per-Assistant (PHP)

```php
// Set default for assistant
update_post_meta($assistant_id, '_tool_choice', 'none');

// Or in REST controller
if (!empty($assistant_config['tool_choice'])) {
    $options['tool_choice'] = $assistant_config['tool_choice'];
}
```

---

## Monitoring & Debugging

### Log Events

When logging is enabled, watch for:

```
cloudflare_auto_json_enabled - Auto-JSON mode activated
cloudflare_payload_build - Payload construction details
chat_client_tool_choice_default - Default applied to chat-client
cloudflare_system_prompt_check - System prompt handling
```

### Verify Behavior

```bash
# Check logs
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("tool_choice"))'

# Test chat-client
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/chat-client \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"assistant_id": 331, "messages": [{"role": "user", "content": "what can you do"}]}'

# Should describe capabilities WITHOUT calling tools
```

---

## Documentation

### Created Documents

1. **cloudflare-tool-choice-implementation-2026-01-10.md** (12KB)
   - Complete technical implementation guide
   - API reference
   - Testing guide
   - Troubleshooting

2. **cloudflare-chat-client-usage-guide.md** (9KB)
   - Chat-client specific usage
   - JavaScript examples
   - Frontend integration
   - YAAD-RELIEF recommendations

3. **Updated CLOUDFLARE_AI_UTILS.md**
   - New parameters documented
   - Options table updated
   - run_with_tools() documentation

---

## Key Takeaways

1. 🎯 **Chat-client now defaults to `tool_choice="none"` for Cloudflare**
2. ✅ **Prevents auto-triggering of tools**
3. ✅ **Respects assistant persona/system prompt**
4. ✅ **User can override per-request**
5. ✅ **Fully backward compatible**
6. ✅ **OpenAI and Cloudflare API compatible**
7. ✅ **26 comprehensive unit tests**
8. ✅ **Well documented with examples**

---

## Result

**ISSUE RESOLVED**: Cloudflare Workers AI no longer auto-triggers tools without user request. Assistants respond from their persona first, and tools are only used when appropriate or explicitly enabled. ✅

---

## Files Modified

- `includes/class-wp-mcp-ai-cloudflare-client.php` - Core tool_choice logic
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` - Chat-client defaults
- `docs/fixes/cloudflare-tool-choice-implementation-2026-01-10.md` - Implementation guide
- `docs/fixes/cloudflare-chat-client-usage-guide.md` - Usage guide
- `docs/CLOUDFLARE_AI_UTILS.md` - API reference
- `tests/test-cloudflare-tool-choice.php` - 8 unit tests
- `tests/test-cloudflare-response-format.php` - 11 unit tests
- `tests/test-chat-client-tool-choice-default.php` - 7 unit tests

**Total: 6 files modified, 3 test files created, 2 documentation files created** ✅
