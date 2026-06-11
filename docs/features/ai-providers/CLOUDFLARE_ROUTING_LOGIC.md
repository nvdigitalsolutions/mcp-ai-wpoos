# Cloudflare Function Calling - Routing Logic

## How It Works Now

The Cloudflare client now intelligently routes between embedded and traditional function calling based on the tools provided.

### Routing Decision Tree

```
┌─────────────────────────────────────────┐
│ create_chat_completion() called         │
└──────────────┬──────────────────────────┘
               │
               ▼
        ┌──────────────┐
        │ Tools provided?│
        └──────┬──────────┘
               │
         ┌─────┴─────┐
         │           │
        Yes          No
         │           │
         ▼           ▼
  ┌──────────────┐  ┌─────────────────────┐
  │Has executables?│  │ Traditional approach│
  └──────┬─────────┘  │ (single req/resp)  │
         │            └─────────────────────┘
    ┌────┴────┐
    │         │
   Yes        No
    │         │
    ▼         ▼
┌─────────┐ ┌─────────────────────┐
│EMBEDDED │ │  TRADITIONAL        │
│APPROACH │ │  APPROACH          │
│         │ │                    │
│run_with │ │  Chat service      │
│_tools() │ │  handles execution │
└─────────┘ └─────────────────────┘
```

### Embedded Function Calling (Preferred for Cloudflare)

**When Used:**
- Tools have `function` key with callable values
- Example: From `run_with_tools()` or embedded implementations

**How It Works:**
1. Call `run_with_tools($messages, $tools, $options)`
2. Internal loop: request → tool_calls → execute → results → repeat
3. Returns final response with completed answer

**Benefits:**
- ✅ Handles XML tool calls automatically
- ✅ Executes tools and gets results
- ✅ Returns final answer to user
- ✅ No external loop needed

**Example Tool Format:**
```php
$tools = array(
    array(
        'name'        => 'get_weather',
        'description' => 'Get weather for a city',
        'parameters'  => array(/* JSON schema */),
        'function'    => function( $args ) {
            // Executable function
            return get_weather_data( $args['city'] );
        }
    )
);
```

### Traditional Function Calling (Fallback)

**When Used:**
- Tools are definitions only (no `function` key)
- Chat service provides definitions, handles execution externally

**How It Works:**
1. Call `create_chat_completion($messages, $options)` 
2. Returns response with `tool_calls` array
3. Chat service executes tools externally
4. Chat service sends results back
5. Repeat until `finish_reason === 'stop'`

**Use Case:**
- Integration with WordPress tool registry
- Chat service's agentic loop
- External tool execution backends

**Example Tool Format:**
```php
$tools = array(
    array(
        'type'     => 'function',
        'function' => array(
            'name'        => 'get_weather',
            'description' => 'Get weather for a city',
            'parameters'  => array(/* JSON schema */)
            // NO executable function key
        )
    )
);
```

## Code Examples

### Example 1: Embedded (With Executables)

```php
$client = new WP_MCP_AI_Cloudflare_Client();

$messages = array(
    array(
        'role'    => 'user',
        'content' => 'What is 5 + 3?'
    )
);

$tools = array(
    array(
        'name'        => 'calculate',
        'description' => 'Perform math operations',
        'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
                'expression' => array( 'type' => 'string' )
            ),
            'required' => array( 'expression' )
        ),
        'function'    => function( $args ) {
            // This makes it use EMBEDDED approach
            eval( '$result = ' . $args['expression'] . ';' );
            return array( 'result' => $result );
        }
    )
);

$options = array(
    'tools' => $tools,
    'model' => '@cf/meta/llama-3.1-8b-instruct'
);

// This will automatically use run_with_tools() internally
$response = $client->create_chat_completion( $messages, $options );

// Response contains final answer: "5 + 3 equals 8"
echo $response['choices'][0]['message']['content'];
```

### Example 2: Traditional (No Executables)

```php
$client = new WP_MCP_AI_Cloudflare_Client();

$messages = array(
    array(
        'role'    => 'user',
        'content' => 'What is 5 + 3?'
    )
);

$tools = array(
    array(
        'type'     => 'function',
        'function' => array(
            'name'        => 'calculate',
            'description' => 'Perform math operations',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'expression' => array( 'type' => 'string' )
                ),
                'required' => array( 'expression' )
            )
            // NO 'function' key with callable - uses TRADITIONAL
        )
    )
);

$options = array(
    'tools' => $tools,
    'model' => '@cf/meta/llama-3.1-8b-instruct'
);

// First call - returns tool_calls
$response = $client->create_chat_completion( $messages, $options );

if ( ! empty( $response['choices'][0]['message']['tool_calls'] ) ) {
    $tool_call = $response['choices'][0]['message']['tool_calls'][0];
    
    // YOU execute the tool externally
    $result = array( 'result' => 8 );
    
    // Add to conversation
    $messages[] = $response['choices'][0]['message'];
    $messages[] = array(
        'role'         => 'tool',
        'tool_call_id' => $tool_call['id'],
        'name'         => 'calculate',
        'content'      => wp_json_encode( $result )
    );
    
    // Second call - get final answer
    $response = $client->create_chat_completion( $messages, $options );
}

// Response contains final answer
echo $response['choices'][0]['message']['content'];
```

## Debugging

### Check Which Approach Is Used

Enable logging to see routing decisions:

```php
define( 'WP_MCP_AI_DEBUG', true );
```

Then check logs:

```bash
wp option get wp_mcp_ai_recent_activity --format=json | \
  jq '.[] | select(.event | contains("cloudflare_routing"))'
```

### Log Events

**Embedded Routing:**
```json
{
  "event": "cloudflare_routing_to_embedded",
  "message": "Routing to embedded function calling (run_with_tools) because tools with executables are provided",
  "data": {
    "tool_count": 3,
    "reason": "Cloudflare models work better with embedded approach for tool execution"
  }
}
```

**Traditional Routing:**
```json
{
  "event": "cloudflare_using_traditional",
  "message": "Using traditional function calling because tools lack executable functions",
  "data": {
    "tool_count": 3,
    "reason": "Chat service will handle tool execution externally"
  }
}
```

## Benefits of This Approach

### For Users
- ✅ **It just works** - No need to understand the difference
- ✅ **Correct results** - Tools are executed and final answer is returned
- ✅ **No XML output** - Users see answers, not raw XML

### For Developers
- ✅ **Flexibility** - Both approaches available
- ✅ **WordPress integration** - Traditional works with tool registry
- ✅ **Debugging** - Clear logging of routing decisions

### For Cloudflare Models
- ✅ **XML handling** - Embedded approach handles XML tool calls correctly
- ✅ **Complete execution** - Loop continues until final answer
- ✅ **No manual orchestration** - Automatic tool execution

## Migration Guide

### If You Were Using Traditional Before

No changes needed! If your code passes tools without executables, it will continue to work exactly as before.

### If You Want to Use Embedded

Add executable functions to your tools:

**Before:**
```php
$tools = array(
    array(
        'type'     => 'function',
        'function' => array(
            'name'       => 'my_tool',
            'parameters' => array(/* schema */)
        )
    )
);
```

**After:**
```php
$tools = array(
    array(
        'name'        => 'my_tool',
        'parameters'  => array(/* schema */),
        'function'    => function( $args ) {
            // Your executable code here
            return $result;
        }
    )
);
```

## Technical Notes

### Why Prioritize Embedded for Cloudflare?

1. **XML Tool Calls**: Some Cloudflare models output tool calls as XML text instead of proper JSON
2. **Complete Loop**: Embedded ensures the full loop (request → tool_calls → execute → results → final) completes
3. **User Experience**: Returns final answer, not intermediate XML

### Performance

- **Embedded**: Slightly slower (multiple API calls in loop) but guaranteed to complete
- **Traditional**: Faster for single tool call, but requires external orchestration

### Backwards Compatibility

✅ **Fully backwards compatible**
- Traditional approach still works
- Chat service continues to function normally
- No breaking changes to existing code

## Conclusion

The Cloudflare client now intelligently routes to the best approach for your use case:

- **Have executables?** → Embedded (automatic, complete)
- **Definitions only?** → Traditional (manual, external execution)

This ensures Cloudflare Workers AI function calling works reliably while maintaining flexibility for different integration patterns.
