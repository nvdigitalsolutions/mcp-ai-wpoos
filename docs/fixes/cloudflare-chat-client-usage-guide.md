# Chat-Client Usage Guide: Cloudflare Tool Choice

Quick reference for using the new `tool_choice`, `response_format`, and auto-JSON features in the WordPress chat-client.

---

## Problem Solved

**Before**: When Cloudflare assistants had tools enabled, they would auto-trigger tools even for simple questions like "what can you do?", ignoring the assistant's persona.

**After**: Full control over when and how tools are used, with **smart defaults** for chat-client.

---

## 🎯 Important: Chat-Client Default Behavior

**NEW**: For Cloudflare Workers AI, the chat-client (`/wp-json/mcp-ai/v1/chat-client`) now **defaults to `tool_choice="none"`**.

### Why This Matters

This prevents tools from being auto-triggered on the user's first instinct/question. Instead:

1. ✅ **Assistant responds from its persona first** (system prompt)
2. ✅ **Tools only used when explicitly needed** (user asks for specific action)
3. ✅ **User can still override** to enable tools per-request

### Example Flow

```
User: "what are some things you can do"
→ tool_choice="none" (default)
→ Assistant describes its capabilities from system prompt
→ NO web_search or other tools triggered ✅

User: "search for hurricane updates in Jamaica"
→ User can override with tool_choice="auto"
→ Assistant uses web_search tool as appropriate ✅
```

---

## Usage in Chat-Client (JavaScript)

### Example 1: Default Behavior (NEW) - Tools Disabled by Default

```javascript
// Default behavior for chat-client - tools NOT auto-triggered
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [
            {
                role: 'user',
                content: [{
                    type: 'text',
                    text: 'what are some things you can do'
                }]
            }
        ]
        // No options needed - defaults to tool_choice="none" for Cloudflare
    })
});

// Result: Assistant describes its capabilities from system prompt
// Does NOT call web_search or other tools automatically ✅
```

### Example 2: Enable Tools When Needed

```javascript
// User asks for something that requires a tool - explicitly enable
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [
            {
                role: 'user',
                content: [{
                    type: 'text',
                    text: 'search for current hurricane warnings in Jamaica'
                }]
            }
        ],
        options: {
            tool_choice: 'auto' // Override default to enable tools
        }
    })
});

// Result: AI intelligently decides to use web_search tool
```

### Example 3: Prevent Auto-Triggering (Explicit)

```javascript
// Explicitly prevent tools (same as default for Cloudflare)
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [...],
        options: {
            tool_choice: 'none' // Explicitly set (though this is now the default)
        }
    })
});

// Result: Assistant uses knowledge only, no tool calls
```

```javascript
// Force the AI to use at least one tool
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [...],
        options: {
            tool_choice: 'required' // or 'any'
        }
    })
});

// Result: At least one tool will be called
```

### Example 4: Enable Custom JSON Schema

```javascript
// Request structured JSON output
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [
            {
                role: 'user',
                content: [{
                    type: 'text',
                    text: 'Tell me about Jamaica'
                }]
            }
        ],
        options: {
            response_format: {
                type: 'json_schema',
                json_schema: {
                    type: 'object',
                    properties: {
                        country: { type: 'string' },
                        capital: { type: 'string' },
                        population: { type: 'number' }
                    },
                    required: ['country', 'capital']
                }
            }
        }
    })
});

// Result: Structured JSON response matching schema
```

### Example 5: Disable Auto-JSON Mode

```javascript
// Disable automatic JSON mode for tools (if it causes issues)
const response = await fetch('/wp-json/mcp-ai/v1/chat-client', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAiChat.nonce
    },
    body: JSON.stringify({
        assistant_id: 331,
        messages: [...],
        options: {
            disable_auto_json: true // Disable auto-JSON
        }
    })
});

// Result: Tools work but without automatic JSON mode
```

---

## Modifying chat.js Integration

If you want to add tool_choice control to the WordPress chat.js frontend:

```javascript
// In sendChat() function around line 10950
function sendChat(message, attachments) {
    // ... existing code ...
    
    const requestBody = {
        assistant_id: assistantId,
        messages: conversationMessages,
        session_key: sessionKey,
        options: {
            // Add tool_choice control
            tool_choice: chatConfig.toolChoice || 'auto', // 'none', 'auto', 'required'
            
            // Optionally add response_format
            response_format: chatConfig.responseFormat || undefined,
            
            // Optionally disable auto-JSON
            disable_auto_json: chatConfig.disableAutoJson || false
        }
    };
    
    // ... rest of sendChat ...
}
```

Then in your shortcode/widget configuration:

```php
// When rendering chat widget
wp_localize_script('wp-mcp-ai-chat', 'wpMcpAiChat', array(
    'assistantId' => $assistant_id,
    'toolChoice' => 'none', // or 'auto', 'required'
    'responseFormat' => array('type' => 'json_object'), // optional
    // ... other config ...
));
```

---

## Assistant Configuration (Backend)

You can also set tool_choice at the assistant level:

```php
// When creating/updating assistant
$assistant_meta = array(
    '_tool_choice' => 'none', // Default for this assistant
    // ... other meta ...
);

update_post_meta($assistant_id, '_tool_choice', 'none');
```

Then in REST controller, retrieve and pass it:

```php
// In handle_chat_client_request() or similar
$tool_choice = get_post_meta($assistant_id, '_tool_choice', true);
if (!empty($tool_choice)) {
    $options['tool_choice'] = $tool_choice;
}
```

---

## Recommendation for YAAD-RELIEF Assistant

Based on your issue with the disaster relief assistant:

```javascript
// For general capability questions
options: {
    tool_choice: 'none' // Let assistant describe itself without triggering tools
}

// For actual disaster info queries
options: {
    tool_choice: 'auto' // Let AI decide when tools are needed
}
```

Or better yet, set it at the assistant level:

```php
// In assistant configuration
update_post_meta(331, '_tool_choice', 'auto'); // Default to auto
// User can override per-request if needed
```

---

## Auto-JSON Mode Benefits

When tools are enabled, auto-JSON mode is automatically activated:

✅ **Ensures consistent tool call format**  
✅ **Prevents malformed JSON responses**  
✅ **Improves parsing reliability**  
✅ **More like OpenAI behavior**  

You typically don't need to disable it unless you're experiencing specific issues.

---

## Troubleshooting

### Issue: Tools still triggering when they shouldn't

**Solution**: Explicitly set `tool_choice: 'none'` in the request options.

### Issue: AI not using tools when it should

**Solution**: Check if `tool_choice` is set to `'none'`. Change to `'auto'` or `'required'`.

### Issue: Malformed tool responses

**Solution**: Auto-JSON should fix this automatically. If not, try explicit response_format.

### Issue: Need to force a specific tool

**Solution**: Use `tool_choice: {type: "function", function: {name: "tool_name"}}`.

---

## Testing Your Changes

```bash
# Test with tool_choice="none"
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/chat-client \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "assistant_id": 331,
    "messages": [{"role": "user", "content": [{"type": "text", "text": "what can you do"}]}],
    "options": {"tool_choice": "none"}
  }'

# Should return assistant describing capabilities, NOT calling web_search
```

---

## Summary

| Parameter | Values | Purpose |
|-----------|--------|---------|
| `tool_choice` | "none", "auto", "required", "any", specific tool | Control when tools are used |
| `response_format` | `{type: "json_object"}` or schema | Force JSON output format |
| `disable_auto_json` | true/false | Disable automatic JSON for tools |

**Key Takeaway**: Use `tool_choice: "none"` to prevent auto-triggering and let your assistant respond from its system prompt/persona.
