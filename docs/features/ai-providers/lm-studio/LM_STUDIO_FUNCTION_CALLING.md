# LM Studio Function Calling Support

## Overview

LM Studio provider now supports OpenAI-compatible function calling, enabling tools to be used with local AI models like qwen/qwen3-coder-30b.

## What Changed

### 1. Enhanced Message Handling

The `build_payload()` method now detects when tools are present and preserves the OpenAI-compatible message structure:

**Assistant Messages with Tool Calls:**
```php
[
    'role' => 'assistant',
    'content' => 'Let me check the weather...',
    'tool_calls' => [
        [
            'id' => 'call_123',
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'arguments' => '{"location":"San Francisco"}'
            ]
        ]
    ]
]
```

**Tool Response Messages:**
```php
[
    'role' => 'tool',
    'content' => 'Sunny, 72F',
    'tool_call_id' => 'call_123',
    'name' => 'get_weather'
]
```

### 2. Tool Normalization

Added `normalise_tools_for_payload()` method that:
- Accepts tools in various formats (function, slug, id)
- Normalizes to OpenAI-compatible structure
- Ensures each tool has a valid name

### 3. Streaming Disabled for Tools

Streaming is explicitly disabled (`stream: false`) when tools are present, ensuring:
- Tool calls are received in complete format
- No partial JSON parsing issues
- Reliable tool execution flow

## Usage Example

### PHP Code

```php
// Configure LM Studio
update_option( 'wp_mcp_ai_settings', [
    'lm_studio_endpoint_url' => 'http://localhost:1234',
    'lm_studio_model' => 'qwen/qwen3-coder-30b',
] );

// Define tools
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get current weather information',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city name'
                    ]
                ],
                'required' => ['location']
            ]
        ]
    ]
];

// Make chat completion with tools
$client = new WP_MCP_AI_LM_Studio_Client();
$response = $client->create_chat_completion(
    [
        [
            'role' => 'user',
            'content' => 'What is the weather in San Francisco?'
        ]
    ],
    [
        'tools' => $tools
    ]
);

// Response will include tool_calls if the model decides to use them
if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
    foreach ( $response['choices'][0]['message']['tool_calls'] as $tool_call ) {
        $function_name = $tool_call['function']['name'];
        $arguments = json_decode( $tool_call['function']['arguments'], true );
        
        // Execute tool and send response back
        // ...
    }
}
```

### REST API

```bash
# Chat with tools enabled
curl -X POST http://localhost/wp-json/mcp-ai/v1/chat \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "provider": "lm_studio",
    "messages": [
        {
            "role": "user",
            "content": "What is the weather in San Francisco?"
        }
    ],
    "tools": [
        {
            "type": "function",
            "function": {
                "name": "get_weather",
                "description": "Get current weather information",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "location": {
                            "type": "string",
                            "description": "The city name"
                        }
                    },
                    "required": ["location"]
                }
            }
        }
    ]
}'
```

## Compatible Models

### Tested
- ✅ qwen/qwen3-coder-30b

### Should Work
Any LM Studio model that supports OpenAI-compatible function calling:
- Qwen2.5 series
- DeepSeek Coder V2
- Llama 3.x (with function calling fine-tuning)
- Mistral models (with tool use support)

## Configuration

1. **Start LM Studio**
   ```bash
   # Load your preferred model (e.g., qwen/qwen3-coder-30b)
   # Start the server on default port 1234
   ```

2. **Configure WP oOS**
   ```
   WordPress Admin → Settings → WP oOS → Providers → LM Studio
   
   ✅ Enable LM Studio Provider
   📍 Endpoint URL: http://localhost:1234
   🤖 Model: qwen/qwen3-coder-30b
   ```

3. **Test Connection**
   - Click "Test Connection" button
   - Verify successful connection
   - Model should support function calling

## Backward Compatibility

### Without Tools
Messages work exactly as before:
```php
$response = $client->create_chat_completion(
    [
        ['role' => 'user', 'content' => 'Hello!']
    ]
);
// No change in behavior
```

### With Tools
New OpenAI-compatible structure is used:
```php
$response = $client->create_chat_completion(
    [
        ['role' => 'user', 'content' => 'Hello!']
    ],
    ['tools' => $tools]
);
// Tool-compatible message structure
```

## Implementation Details

### Files Modified
1. `includes/class-wp-mcp-ai-lm-studio-client.php`
   - Enhanced `build_payload()` method (+68 lines)
   - Added `normalise_tools_for_payload()` method (+69 lines)

2. `tests/test-lm-studio-client.php`
   - Added 4 comprehensive tests (+243 lines)

### Test Coverage
- ✅ Tool normalization from various formats
- ✅ Tools included in request payload
- ✅ Streaming disabled with tools
- ✅ Tool/assistant messages preserved correctly

### Design Principles
- **Minimal Changes**: Only modified payload building
- **SOC Compliance**: All logic in client class
- **OpenAI Compatible**: Follows exact OpenAI pattern
- **No Breaking Changes**: Backward compatible

## Streaming Behavior

### Important: Streaming is ALWAYS Disabled

For both scenarios (with and without tools), streaming is explicitly disabled:

```php
$payload = [
    'model' => $model,
    'messages' => $formatted_messages,
    'stream' => false,  // Always false
];
```

**Why?**
1. Tool calls need complete JSON responses
2. Prevents partial streaming issues
3. Ensures reliable tool execution
4. Matches OpenAI best practices

## Troubleshooting

### Issue: Model doesn't support function calling
**Solution**: Use a model that explicitly supports OpenAI function calling format (e.g., qwen/qwen3-coder-30b)

### Issue: Tool calls not being made
**Solution**: 
1. Verify model supports function calling
2. Check tool definitions are properly formatted
3. Ensure `stream: false` in payload
4. Review LM Studio logs for errors

### Issue: Invalid tool call format
**Solution**:
1. Verify model is loaded in LM Studio
2. Check LM Studio server is running
3. Ensure endpoint URL is correct
4. Test with simple tool first

## References

- [OpenAI Function Calling Documentation](https://platform.openai.com/docs/guides/function-calling)
- [LM Studio Documentation](https://lmstudio.ai/)
- [WP oOS Provider Documentation](docs/providers.md)
- [Tool Development Guide](docs/tools.md)

## Future Enhancements

Potential improvements:
- [ ] Parallel tool calling support
- [ ] Tool choice parameter (auto/required/none)
- [ ] Tool result formatting options
- [ ] Enhanced error handling for tool failures
- [ ] Tool execution retry logic

## Security Considerations

- ✅ All tool names are sanitized
- ✅ Tool call IDs are validated
- ✅ Content is sanitized with `wp_kses_post()`
- ✅ No user input directly in tool execution
- ✅ Capability checks enforced at REST layer

---

**Version**: 1.0.0  
**Issue**: #1360  
**Target Model**: qwen/qwen3-coder-30b  
**Compatibility**: OpenAI Function Calling API
