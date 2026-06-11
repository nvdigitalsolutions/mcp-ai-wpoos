# Cloudflare Workers AI - Traditional Function Calling

## Overview

This document describes how Cloudflare Workers AI supports **traditional function calling** in the NV oOS WordPress plugin. Traditional function calling is the **primary and recommended approach** for using tools with Cloudflare Workers AI.

## Traditional vs Embedded Function Calling

### Traditional Function Calling (Recommended - Default)

**How it works:**
1. Your application sends a request to the AI with a list of available tools
2. The AI responds with `tool_calls` when it needs to use a tool
3. Your application executes the tool functions
4. Your application sends the tool results back to the AI
5. The AI uses the results to formulate a final response
6. This loop continues until the AI finishes without requesting more tools

**Benefits:**
- ✅ **Full control** - You control when and how tools are executed
- ✅ **Security** - You can validate, sanitize, and authorize tool calls before execution
- ✅ **Flexibility** - Works with any tool execution backend (WordPress, external APIs, databases)
- ✅ **Debugging** - Each step is visible and can be logged/monitored
- ✅ **Already implemented** - The plugin's chat service uses this approach
- ✅ **Compatible with WordPress architecture** - Integrates seamlessly with WordPress hooks, capabilities, and data

**Status:** ✅ **FULLY IMPLEMENTED AND WORKING**

### Embedded Function Calling (Alternative)

**How it works:**
1. You pass both messages and executable functions to `run_with_tools()`
2. The method handles the entire loop automatically
3. Tools are executed inline as the AI requests them
4. The final response is returned after all tool calls complete

**Benefits:**
- ✅ **Convenience** - Less code to write for simple use cases
- ✅ **Automatic looping** - No need to manage the conversation state

**Limitations:**
- ❌ **Less control** - Tool execution happens automatically
- ❌ **Limited to PHP callables** - Can't easily integrate with WordPress action hooks
- ❌ **Harder to debug** - The loop is internal to the method
- ❌ **Not the plugin's standard pattern** - The rest of the plugin uses traditional approach

**Status:** ✅ Available in `WP_MCP_AI_Cloudflare_Client::run_with_tools()` but **NOT USED** by default

---

## How Traditional Function Calling Works in This Plugin

### Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        REST API Request                              │
│                 /wp-json/mcp-ai/v1/chat                             │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│              WP_MCP_AI_REST_Chat_Controller                          │
│              • Authenticates request                                 │
│              • Loads assistant configuration                         │
│              • Passes to chat service                                │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 WP_MCP_AI_Chat_Service                               │
│              • process_chat_request()                                │
│              • Agentic tool execution loop (traditional)             │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
         ┌───────────────────┴───────────────────┐
         │                                       │
         ▼                                       ▼
┌────────────────────┐              ┌────────────────────────┐
│ Iteration 1        │              │ Iteration 2            │
│                    │              │                        │
│ 1. Send request    │              │ 1. Send request        │
│    with tools      │              │    with tools          │
│                    │              │    + tool results      │
│ 2. Receive         │              │                        │
│    tool_calls      │              │ 2. Receive final       │
│                    │              │    response            │
│ 3. Execute tools   │              │    (finish_reason:     │
│                    │              │     'stop')            │
│ 4. Format results  │              │                        │
└────────────────────┘              └────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────┐
│            WP_MCP_AI_Cloudflare_Client                               │
│            • create_chat_completion($messages, $options)             │
│            • Traditional function calling                            │
│            • Returns tool_calls in response                          │
└─────────────────────────────────────────────────────────────────────┘
```

### Code Flow

#### 1. Initial Request (Iteration 1)

```php
// Chat service prepares the request
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'What is the weather in London?'
    )
);

$options = array(
    'tools'  => $tools,  // Array of available tools
    'model'  => '@cf/meta/llama-3.1-8b-instruct',
    'tool_choice' => 'auto' // Let model decide when to use tools
);

// Call Cloudflare via traditional function calling
$response = $client->create_chat_completion( $messages, $options );

// Response structure:
// array(
//     'choices' => array(
//         array(
//             'message' => array(
//                 'role' => 'assistant',
//                 'content' => '',
//                 'tool_calls' => array(
//                     array(
//                         'id' => 'call_123',
//                         'type' => 'function',
//                         'function' => array(
//                             'name' => 'get_weather',
//                             'arguments' => '{"location":"London"}'
//                         )
//                     )
//                 )
//             ),
//             'finish_reason' => 'tool_calls'  // Not 'stop' yet!
//         )
//     )
// )
```

#### 2. Tool Execution

```php
// Chat service extracts tool_calls
$tool_calls = $response['choices'][0]['message']['tool_calls'];
$finish_reason = $response['choices'][0]['finish_reason'];

// Check if we need to execute tools
if ( !empty( $tool_calls ) && 'stop' !== $finish_reason ) {
    // Execute each tool
    foreach ( $tool_calls as $tool_call ) {
        $tool_name = $tool_call['function']['name'];
        $arguments = json_decode( $tool_call['function']['arguments'], true );
        
        // Execute via tool registry
        $result = $tool_registry->execute_tool( $tool_name, $arguments );
        
        // Add tool result to conversation
        $messages[] = $response['choices'][0]['message']; // Assistant message with tool_calls
        $messages[] = array(
            'role'         => 'tool',
            'tool_call_id' => $tool_call['id'],
            'name'         => $tool_name,
            'content'      => wp_json_encode( $result )
        );
    }
}
```

#### 3. Follow-up Request (Iteration 2)

```php
// Send updated conversation back to AI
$response = $client->create_chat_completion( $messages, $options );

// Final response:
// array(
//     'choices' => array(
//         array(
//             'message' => array(
//                 'role' => 'assistant',
//                 'content' => 'The weather in London is cloudy with a temperature of 15°C.'
//             ),
//             'finish_reason' => 'stop'  // Conversation complete!
//         )
//     )
// )
```

---

## Implementation Details

### Cloudflare Client Methods

#### `create_chat_completion( $messages, $options )`

**Purpose:** Traditional function calling - single request/response

**Parameters:**
- `$messages` (array) - Conversation history
- `$options` (array) - Request options:
  - `tools` (array) - Available tools in OpenAI format
  - `tool_choice` (string) - "auto", "none", "required", or specific tool
  - `model` (string) - Cloudflare model ID
  - `temperature` (float) - Temperature setting
  - `max_tokens` (int) - Maximum tokens to generate

**Returns:**
- Response array with `choices[0].message.tool_calls` if tools needed
- Response array with `choices[0].finish_reason = 'stop'` when complete

**Key Features:**
- ✅ Accepts `tools` parameter
- ✅ Respects `tool_choice` parameter
- ✅ Normalizes tool_calls to OpenAI format
- ✅ Handles both Cloudflare format and OpenAI format tool_calls
- ✅ Parses XML-formatted tool calls from models that output them as text
- ✅ Sets correct `finish_reason` based on tool_calls presence

### Tool Format

Tools must follow OpenAI's function calling format:

```php
$tools = array(
    array(
        'type'     => 'function',
        'function' => array(
            'name'        => 'get_weather',
            'description' => 'Get the current weather for a location',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'location' => array(
                        'type'        => 'string',
                        'description' => 'The city name'
                    ),
                    'units' => array(
                        'type'        => 'string',
                        'description' => 'Temperature units',
                        'enum'        => array( 'celsius', 'fahrenheit' )
                    )
                ),
                'required' => array( 'location' )
            )
        )
    )
);
```

### Tool Results Format

Tool results must be sent as tool role messages:

```php
$tool_result_message = array(
    'role'         => 'tool',
    'tool_call_id' => $tool_call['id'],  // Must match the tool_call ID
    'name'         => $tool_call['function']['name'],  // Tool name
    'content'      => wp_json_encode( $result )  // Tool result as JSON string
);
```

---

## Configuration

### Tool Choice Parameter

The `tool_choice` parameter controls when and how tools are used:

| Value | Behavior |
|-------|----------|
| `"auto"` (default) | Model decides when to use tools based on context |
| `"none"` | Tools are disabled for this request |
| `"required"` or `"any"` | Model must use at least one tool |
| `{"type": "function", "function": {"name": "tool_name"}}` | Force specific tool |

**Chat Client Default:**
For browser chat (`/chat-client`), the plugin sets `tool_choice: "auto"` to let the model intelligently decide when tools are appropriate.

```php
// Filter in WP_MCP_AI_REST_Chat_Controller
add_filter( 'wp_mcp_ai_chat_options', array( $this, 'set_chat_client_tool_choice_default' ), 5, 3 );
```

### Max Iterations

The agentic loop has iteration limits to prevent infinite loops:

| Endpoint | Max Iterations |
|----------|----------------|
| `/chat` (MCP remote) | 5 |
| `/chat-client` (browser) | 15 |

These limits can be adjusted via filters.

---

## Examples

### Example 1: Simple Weather Tool

```php
// Define the tool
$weather_tool = array(
    'type'     => 'function',
    'function' => array(
        'name'        => 'get_weather',
        'description' => 'Get current weather for a city',
        'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
                'city' => array(
                    'type'        => 'string',
                    'description' => 'City name'
                )
            ),
            'required' => array( 'city' )
        )
    )
);

// Initial conversation
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'What is the weather in Paris?'
    )
);

// First request
$response = $client->create_chat_completion(
    $messages,
    array(
        'tools' => array( $weather_tool ),
        'model' => '@cf/meta/llama-3.1-8b-instruct'
    )
);

// Check for tool calls
if ( !empty( $response['choices'][0]['message']['tool_calls'] ) ) {
    $tool_call = $response['choices'][0]['message']['tool_calls'][0];
    
    // Execute tool
    $weather_data = array(
        'temperature' => 18,
        'condition'   => 'Partly cloudy',
        'humidity'    => 65
    );
    
    // Add to conversation
    $messages[] = $response['choices'][0]['message'];
    $messages[] = array(
        'role'         => 'tool',
        'tool_call_id' => $tool_call['id'],
        'name'         => 'get_weather',
        'content'      => wp_json_encode( $weather_data )
    );
    
    // Second request with tool result
    $final_response = $client->create_chat_completion(
        $messages,
        array(
            'tools' => array( $weather_tool ),
            'model' => '@cf/meta/llama-3.1-8b-instruct'
        )
    );
    
    // $final_response['choices'][0]['message']['content'] contains final answer
}
```

### Example 2: Multiple Tools

```php
$tools = array(
    // Weather tool
    array(
        'type'     => 'function',
        'function' => array(
            'name'        => 'get_weather',
            'description' => 'Get weather for a location',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'location' => array( 'type' => 'string' )
                ),
                'required' => array( 'location' )
            )
        )
    ),
    // Database search tool
    array(
        'type'     => 'function',
        'function' => array(
            'name'        => 'search_posts',
            'description' => 'Search WordPress posts',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string' )
                ),
                'required' => array( 'query' )
            )
        )
    )
);

// The AI can choose which tool(s) to use based on the user's request
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'Search for posts about "WordPress development" and tell me the weather in the author\'s location'
    )
);

// The agentic loop will handle multiple tool calls across iterations
```

---

## Testing

### Running Tests

```bash
# Run all Cloudflare tests
vendor/bin/phpunit tests/test-cloudflare-*.php

# Run traditional function calling tests specifically
vendor/bin/phpunit tests/test-cloudflare-traditional-function-calling.php

# Run tool validation tests
vendor/bin/phpunit tests/test-cloudflare-tool-calls-validation.php
```

### Test Coverage

✅ Tool parameter acceptance  
✅ Tool normalization  
✅ Tool_choice parameter handling  
✅ Tool_calls extraction from responses  
✅ Finish_reason based on tool_calls  
✅ Multi-turn conversation with tool results  
✅ Multiple tools  
✅ OpenAI format compatibility  
✅ Cloudflare simpler format normalization  
✅ XML tool call parsing (for models that output XML)  

---

## Debugging

### Enable Verbose Logging

```php
// In wp-config.php or plugin settings
define( 'WP_MCP_AI_DEBUG', true );
```

### Check Logs

```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("cloudflare"))'

# Check for tool-related events
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event | contains("tool"))'
```

### Common Log Events

- `cloudflare_request` - Outgoing API request
- `cloudflare_response_structure` - Response structure details
- `cloudflare_tool_calls_detected` - Valid tool_calls found
- `cloudflare_tool_calls_filtered` - Invalid tool_calls filtered out
- `cloudflare_tool_call_normalized` - Format normalization
- `cloudflare_xml_tool_calls_parsed` - XML format detected and parsed

---

## Comparison: Traditional vs Embedded

| Feature | Traditional | Embedded |
|---------|-------------|----------|
| **Control** | Full control over execution | Automatic |
| **Security** | You validate each call | Validation built-in |
| **WordPress Integration** | Native | Via callables |
| **Debugging** | Each step visible | Internal loop |
| **Current Usage** | ✅ Used by default | ❌ Not used |
| **Status** | ✅ Fully implemented | ✅ Available but optional |
| **Recommendation** | ✅ **Use this** | Consider for simple scripts |

---

## When to Use Each Approach

### Use Traditional Function Calling When:
- ✅ Building WordPress plugins or themes
- ✅ Need to check user capabilities before tool execution
- ✅ Want to log or monitor each tool call
- ✅ Integrating with WordPress hooks and filters
- ✅ Need to sanitize/validate tool arguments before execution
- ✅ Building production systems
- ✅ **This is the default and recommended approach**

### Use Embedded Function Calling When:
- Writing quick scripts or prototypes
- All tools are safe to execute automatically
- Don't need WordPress integration
- Prefer simpler code for basic use cases

---

## Migration Guide

### If You're Currently Using `run_with_tools()`

The plugin doesn't use `run_with_tools()` by default, so no migration is needed. However, if you've built custom code using it, here's how to switch:

**Before (Embedded):**
```php
$response = $client->run_with_tools(
    $messages,
    $tools_with_executables,
    $options
);
```

**After (Traditional):**
```php
// Iteration loop
$max_iterations = 5;
for ( $i = 0; $i < $max_iterations; $i++ ) {
    $response = $client->create_chat_completion( $messages, $options );
    
    $tool_calls = $response['choices'][0]['message']['tool_calls'] ?? array();
    $finish_reason = $response['choices'][0]['finish_reason'];
    
    if ( empty( $tool_calls ) || 'stop' === $finish_reason ) {
        break; // Done!
    }
    
    // Add assistant message
    $messages[] = $response['choices'][0]['message'];
    
    // Execute tools
    foreach ( $tool_calls as $tool_call ) {
        $result = execute_your_tool( $tool_call );
        
        $messages[] = array(
            'role'         => 'tool',
            'tool_call_id' => $tool_call['id'],
            'name'         => $tool_call['function']['name'],
            'content'      => wp_json_encode( $result )
        );
    }
}
```

---

## References

- [Cloudflare Workers AI Function Calling Docs](https://developers.cloudflare.com/workers-ai/features/function-calling/)
- [OpenAI Function Calling Format](https://platform.openai.com/docs/guides/function-calling)
- [Plugin Chat Service Code](../includes/services/class-wp-mcp-ai-chat-service.php)
- [Cloudflare Client Code](../includes/class-wp-mcp-ai-cloudflare-client.php)
- [Tests](../tests/test-cloudflare-traditional-function-calling.php)

---

## Conclusion

**Traditional function calling is the primary, recommended, and fully-working approach** for using tools with Cloudflare Workers AI in this plugin. It provides:

- ✅ Full control and security
- ✅ WordPress integration
- ✅ Better debugging
- ✅ Already implemented and tested
- ✅ Used by default in the chat service

**Embedded function calling** (`run_with_tools()`) is available as an alternative for simpler use cases, but is **not the default** and **not used by the plugin's core functionality**.

For production use, always use **traditional function calling** via `create_chat_completion()`.
