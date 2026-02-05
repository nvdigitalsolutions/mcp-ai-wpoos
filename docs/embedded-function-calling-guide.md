# Function Calling with Embedded Chat Client

## Overview

Function calling (also known as tool calling) allows AI models to call external functions/tools during conversation. This enables the model to:

- Access real-time data (weather, stock prices, etc.)
- Interact with external APIs
- Perform calculations
- Query databases
- Execute WordPress operations

The NV oOS plugin provides comprehensive function calling support through its embedded WebLLM client.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     User Query                              │
│   "What is the weather in Pittsburgh and Tokyo?"           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────┐
│                  WebLLM Model                               │
│            (Hermes-2-Pro-Llama-3-8B)                       │
│                                                             │
│  Decides: Need to call get_current_weather()              │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓ tool_calls
┌─────────────────────────────────────────────────────────────┐
│              Tool Call Requests                             │
│                                                             │
│  [{                                                         │
│    id: "call_123",                                         │
│    function: "get_current_weather",                        │
│    arguments: { location: "Pittsburgh", unit: "celsius" }  │
│  }, {                                                       │
│    id: "call_124",                                         │
│    function: "get_current_weather",                        │
│    arguments: { location: "Tokyo", unit: "celsius" }       │
│  }]                                                         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓ Execute functions
┌─────────────────────────────────────────────────────────────┐
│              Function Execution                             │
│                                                             │
│  get_current_weather("Pittsburgh", "celsius")              │
│    → { temp: 15°C, condition: "Cloudy" }                   │
│                                                             │
│  get_current_weather("Tokyo", "celsius")                   │
│    → { temp: 22°C, condition: "Sunny" }                    │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓ tool results
┌─────────────────────────────────────────────────────────────┐
│              Back to Model                                  │
│                                                             │
│  Model receives results and generates final response        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────┐
│              Final Response                                 │
│                                                             │
│  "The current weather is 15°C and cloudy in Pittsburgh,    │
│   and 22°C and sunny in Tokyo."                            │
└─────────────────────────────────────────────────────────────┘
```

## Supported Models

Not all models support function calling. You must use instruction-tuned models specifically trained for tool use:

### Recommended Models

| Model | Size | Function Calling Support | Notes |
|-------|------|------------------------|-------|
| **Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC** | ~4.5GB | ✅ Excellent | **Recommended** - Best function calling |
| Qwen2.5-7B-Instruct-q4f16_1-MLC | ~4.5GB | ✅ Good | Good alternative |
| Phi-3.5-mini-instruct-q4f16_1-MLC | ~2.5GB | ✅ Fair | Smaller, less accurate |

### Models Without Function Calling

| Model | Reason |
|-------|--------|
| Llama-3.2-1B-Instruct-q4f16_1-MLC | Too small, no training |
| Llama-3.2-3B-Instruct-q4f16_1-MLC | Not trained for tools |
| Qwen2.5-0.5B-Instruct-q4f16_1-MLC | Too small |

## Tool Definition Format

Tools are defined using OpenAI function calling format:

```javascript
const tools = [
    {
        type: "function",
        function: {
            name: "get_current_weather",
            description: "Get the current weather in a given location",
            parameters: {
                type: "object",
                properties: {
                    location: {
                        type: "string",
                        description: "The city and state, e.g. San Francisco, CA"
                    },
                    unit: {
                        type: "string",
                        enum: ["celsius", "fahrenheit"]
                    }
                },
                required: ["location"]
            }
        }
    }
];
```

### Tool Schema Specification

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Must be "function" |
| `function.name` | string | Yes | Function name (alphanumeric + underscore) |
| `function.description` | string | Yes | Clear description of what the function does |
| `function.parameters` | object | Yes | JSON Schema for parameters |
| `parameters.type` | string | Yes | Must be "object" |
| `parameters.properties` | object | Yes | Parameter definitions |
| `parameters.required` | array | No | Required parameter names |

## Usage Examples

### Example 1: Basic Function Calling (Vanilla WebLLM)

```javascript
import * as webllm from "@mlc-ai/web-llm";

// Define tools
const tools = [{
    type: "function",
    function: {
        name: "get_current_weather",
        description: "Get the current weather",
        parameters: {
            type: "object",
            properties: {
                location: { type: "string" },
                unit: { type: "string", enum: ["celsius", "fahrenheit"] }
            },
            required: ["location"]
        }
    }
}];

// Initialize engine
const engine = await webllm.CreateMLCEngine(
    "Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC",
    { logLevel: "INFO" }
);

// Make request with tools
const request = {
    stream: true,
    messages: [
        {
            role: "user",
            content: "What's the weather in Pittsburgh?"
        }
    ],
    tools: tools,
    tool_choice: "auto"
};

// Handle streaming response
const stream = await engine.chat.completions.create(request);
let toolCalls = [];

for await (const chunk of stream) {
    // Accumulate tool calls from delta
    if (chunk.choices[0]?.delta?.tool_calls) {
        // Buffer tool calls (see example code for details)
    }
}

// Execute tools
for (const toolCall of toolCalls) {
    const args = JSON.parse(toolCall.function.arguments);
    const result = get_current_weather(args.location, args.unit);
    
    // Send result back to model
    // (see full example for complete flow)
}
```

### Example 2: Using Plugin's Embedded Client

```javascript
// Create client instance
const client = new window.WP_MCP_AI_EmbeddedLLM('my-chat', {
    systemPrompt: 'You are a helpful assistant.',
    tools: [
        {
            slug: 'get_weather',
            description: 'Get weather info',
            parameters: {
                type: 'object',
                properties: {
                    location: { type: 'string' }
                }
            }
        }
    ]
});

// Load model
await client.loadModel('Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC');

// Generate with tools
const result = await client.generateStreamingCompletion(
    [
        { role: 'system', content: 'You are a helpful assistant.' },
        { role: 'user', content: 'Weather in Tokyo?' }
    ],
    { tools: client.tools },
    (chunk) => console.log(chunk.content)
);

// Check for tool calls
if (result.tool_calls) {
    console.log('Tools called:', result.tool_calls);
}
```

### Example 3: Enhanced Function Calling Client

```javascript
// Use enhanced client with automatic tool execution
const client = new window.WP_MCP_AI_WebLLM_FunctionCalling('enhanced-chat', {
    tools: [/* tool definitions */]
});

await client.loadModel('Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC');

// Chat with tools - automatic handling
const stream = await client.chatWithTools(
    [{ role: 'user', content: 'What is the weather?' }],
    client.tools,
    { onChunk: (chunk) => console.log(chunk) }
);

// Process stream
for await (const chunk of stream) {
    if (chunk.type === 'tool_call') {
        // Tool is being called
        console.log('Calling:', chunk.data);
    }
}

// Execute tool calls
if (result.tool_calls) {
    const toolResults = await client.executeToolCalls(result.tool_calls);
    console.log('Results:', toolResults);
}
```

## Tool Execution Flow

### 1. Model Requests Tool Call

The model responds with `tool_calls` in the streaming response:

```javascript
{
    choices: [{
        delta: {
            tool_calls: [{
                index: 0,
                id: "call_abc123",
                type: "function",
                function: {
                    name: "get_current_weather",
                    arguments: '{"location":"Pittsburgh","unit":"celsius"}'
                }
            }]
        }
    }]
}
```

### 2. Execute Tool

```javascript
const toolCall = toolCalls[0];
const args = JSON.parse(toolCall.function.arguments);

// Execute the function
const result = get_current_weather(args.location, args.unit);
// Returns: { temperature: 15, unit: "celsius", condition: "Cloudy" }
```

### 3. Send Result Back to Model

```javascript
const toolMessage = {
    role: "tool",
    tool_call_id: toolCall.id,
    name: toolCall.function.name,
    content: JSON.stringify(result)
};

// Send back to model with conversation history
const followUpRequest = {
    messages: [
        { role: "user", content: originalMessage },
        { role: "assistant", content: "", tool_calls: toolCalls },
        toolMessage
    ],
    tools: tools
};

const finalResponse = await engine.chat.completions.create(followUpRequest);
```

## Streaming and Tool Calls

Tool calls can appear in streaming responses. The function name and arguments come incrementally:

```javascript
// Chunk 1
{ tool_calls: [{ index: 0, function: { name: "get_", arguments: "" } }] }

// Chunk 2
{ tool_calls: [{ index: 0, function: { name: "current", arguments: "" } }] }

// Chunk 3
{ tool_calls: [{ index: 0, function: { name: "_weather", arguments: '{"lo' } }] }

// Chunk 4
{ tool_calls: [{ index: 0, function: { name: "", arguments: 'cation":"' } }] }

// ... more chunks ...
```

You must buffer these chunks:

```javascript
const toolCalls = [];

for await (const chunk of stream) {
    if (chunk.choices[0]?.delta?.tool_calls) {
        chunk.choices[0].delta.tool_calls.forEach(tc => {
            const index = tc.index || 0;
            
            if (!toolCalls[index]) {
                toolCalls[index] = {
                    id: tc.id || 'call_' + Date.now(),
                    type: 'function',
                    function: { name: '', arguments: '' }
                };
            }
            
            if (tc.function?.name) {
                toolCalls[index].function.name += tc.function.name;
            }
            if (tc.function?.arguments) {
                toolCalls[index].function.arguments += tc.function.arguments;
            }
        });
    }
}
```

## WordPress Integration

### Using WordPress Tools

The plugin provides a tool adapter to use WordPress tools with the embedded client:

```javascript
// Load WordPress tools
const toolAdapter = window.WP_MCP_AI_ToolAdapter;
const wpTools = await toolAdapter.fetchTools();

// Convert to OpenAI format
const formattedTools = toolAdapter.convertTools(wpTools);

// Use with client
const result = await client.generateStreamingCompletion(
    messages,
    { tools: formattedTools }
);

// Execute WordPress tool
if (result.tool_calls) {
    for (const toolCall of result.tool_calls) {
        const args = JSON.parse(toolCall.function.arguments);
        const result = await toolAdapter.executeTool(
            toolCall.function.name,
            args
        );
    }
}
```

### Available WordPress Tools

The plugin provides 519 tools (165 base + 348 pro + 6 core/memory) including:

- **Content Management**: `create_post`, `update_post`, `delete_post`
- **User Management**: `create_user`, `update_user`
- **Media**: `upload_file`, `get_attachment_info`
- **E-commerce** (WooCommerce): `create_product`, `update_order`
- **SEO** (Rank Math): `analyze_seo`, `get_seo_score`
- **Custom Fields** (JetEngine): `get_cct_item`, `update_cct_item`

See `docs/tool-reference.md` for complete list.

## Best Practices

### 1. Clear Tool Descriptions

```javascript
// ❌ Bad
{
    name: "get_weather",
    description: "Gets weather"
}

// ✅ Good
{
    name: "get_current_weather",
    description: "Get the current weather conditions including temperature, humidity, and wind speed for a specific location"
}
```

### 2. Detailed Parameter Descriptions

```javascript
{
    parameters: {
        properties: {
            location: {
                type: "string",
                description: "The city and state, e.g. 'San Francisco, CA' or 'Tokyo, Japan'"
            }
        }
    }
}
```

### 3. Specify Required Parameters

```javascript
{
    parameters: {
        type: "object",
        properties: {
            location: { type: "string" },
            unit: { type: "string" }
        },
        required: ["location"] // unit is optional
    }
}
```

### 4. Use Enums for Limited Options

```javascript
{
    unit: {
        type: "string",
        enum: ["celsius", "fahrenheit"],
        description: "Temperature unit"
    }
}
```

### 5. Handle Tool Execution Errors

```javascript
try {
    const result = executeToolCall(toolCall);
    return {
        role: "tool",
        tool_call_id: toolCall.id,
        content: JSON.stringify(result)
    };
} catch (error) {
    return {
        role: "tool",
        tool_call_id: toolCall.id,
        content: JSON.stringify({ error: error.message })
    };
}
```

### 6. Validate Tool Arguments

```javascript
function get_current_weather(location, unit) {
    if (!location || typeof location !== 'string') {
        throw new Error('Invalid location parameter');
    }
    
    if (unit && !['celsius', 'fahrenheit'].includes(unit)) {
        throw new Error('Invalid unit. Must be celsius or fahrenheit');
    }
    
    // Execute tool...
}
```

## Troubleshooting

### Issue: Model Not Calling Tools

**Symptoms**: Model responds with text instead of tool calls

**Solutions**:

1. **Use a function-calling model**
   ```javascript
   // ✅ Use this
   "Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC"
   
   // ❌ Not this
   "Llama-3.2-1B-Instruct-q4f16_1-MLC"
   ```

2. **Check tool descriptions**
   - Make descriptions clear and specific
   - Include examples in descriptions

3. **Use better prompts**
   ```javascript
   // ❌ Vague
   "Tell me about the weather"
   
   // ✅ Specific
   "What is the current temperature in celsius in Pittsburgh?"
   ```

### Issue: Tool Arguments Malformed

**Symptoms**: `JSON.parse()` fails on tool arguments

**Solutions**:

1. **Buffer streaming tool calls completely**
   ```javascript
   // Wait for all chunks before parsing
   if (chunk.finish_reason === 'tool_calls') {
       const args = JSON.parse(toolCall.function.arguments);
   }
   ```

2. **Validate JSON before parsing**
   ```javascript
   try {
       const args = JSON.parse(toolCall.function.arguments);
   } catch (error) {
       console.error('Invalid tool arguments:', error);
       // Handle error
   }
   ```

### Issue: Tool Calls Not Streaming

**Symptoms**: No tool calls in streaming response

**Solutions**:

1. **Enable streaming properly**
   ```javascript
   const request = {
       stream: true, // Required
       stream_options: { include_usage: true },
       // ...
   };
   ```

2. **Check finish_reason**
   ```javascript
   if (lastChunk.choices[0].finish_reason === 'tool_calls') {
       // Tool calls are present
   }
   ```

### Issue: Model Ignores Tool Results

**Symptoms**: Model doesn't use tool results in response

**Solutions**:

1. **Include full conversation history**
   ```javascript
   const followUpRequest = {
       messages: [
           { role: "user", content: originalMessage },
           { role: "assistant", content: "", tool_calls: toolCalls },
           ...toolMessages // Tool results
       ]
   };
   ```

2. **Format tool messages correctly**
   ```javascript
   {
       role: "tool", // Must be "tool"
       tool_call_id: toolCall.id, // Match original call
       name: toolCall.function.name,
       content: JSON.stringify(result) // Must be string
   }
   ```

## Performance Considerations

### Model Size vs. Accuracy

Larger models provide better function calling:

| Model Size | Tool Calling Accuracy | Download Time | Memory Usage |
|-----------|----------------------|---------------|--------------|
| 1-3B | Poor (not recommended) | ~2-5 min | 1-2GB |
| 7-8B | Good | ~5-10 min | 4-6GB |
| 13B+ | Excellent | ~15+ min | 8-12GB |

### Optimization Tips

1. **Cache model loading**
   ```javascript
   // Load once, reuse
   const engine = await loadModelOnce();
   ```

2. **Batch tool calls**
   ```javascript
   // Execute multiple tools in parallel
   const results = await Promise.all(
       toolCalls.map(tc => executeToolCall(tc))
   );
   ```

3. **Limit tool descriptions**
   - Keep descriptions concise
   - Too many tools reduces accuracy

4. **Use tool_choice strategically**
   ```javascript
   tool_choice: "auto" // Let model decide
   tool_choice: "none" // Never use tools
   tool_choice: { type: "function", function: { name: "specific_tool" } }
   ```

## Security Considerations

### 1. Validate Tool Inputs

```javascript
function executeTool(name, args) {
    // Whitelist allowed tools
    const allowedTools = ['get_weather', 'calculate'];
    if (!allowedTools.includes(name)) {
        throw new Error('Tool not allowed: ' + name);
    }
    
    // Validate arguments
    validateArgs(name, args);
    
    // Execute
    return tools[name](args);
}
```

### 2. Sanitize Tool Outputs

```javascript
function executeToolCall(toolCall) {
    const result = rawExecute(toolCall);
    
    // Sanitize before sending to model
    return {
        role: "tool",
        content: JSON.stringify(sanitize(result))
    };
}
```

### 3. Rate Limit Tool Calls

```javascript
const toolCallCounts = new Map();

function checkRateLimit(userId, toolName) {
    const key = `${userId}:${toolName}`;
    const count = toolCallCounts.get(key) || 0;
    
    if (count > 10) {
        throw new Error('Rate limit exceeded');
    }
    
    toolCallCounts.set(key, count + 1);
}
```

### 4. Audit Tool Usage

```javascript
function executeToolCall(toolCall, context) {
    // Log tool execution
    logToolCall({
        user: context.userId,
        tool: toolCall.function.name,
        args: toolCall.function.arguments,
        timestamp: Date.now()
    });
    
    return execute(toolCall);
}
```

## Resources

### Examples

- **HTML Example**: `examples/embedded-function-calling-example.html`
- **JavaScript Example**: `examples/embedded-function-calling-example.js`
- **Live Demo**: Load the HTML example in a modern browser

### Documentation

- **Tool Reference**: `docs/tool-reference.md`
- **REST API**: `docs/rest-api.md`
- **WebLLM Docs**: https://github.com/mlc-ai/web-llm

### Plugin Classes

- **Base Client**: `assets/js/embedded-llm-client.js`
- **Function Calling**: `assets/js/webllm-function-calling-client.js`
- **Tool Adapter**: `assets/js/webllm-tool-adapter.js`
- **Multi-Modal**: `assets/js/webllm-multimodal-client.js`

## Summary

Function calling with the embedded chat client enables powerful AI interactions:

✅ **Real-time data access** - Weather, stock prices, etc.  
✅ **WordPress integration** - Use 519 built-in tools  
✅ **Custom tools** - Define your own functions  
✅ **Streaming support** - Responsive UX with streaming  
✅ **OpenAI compatible** - Standard API format  
✅ **Type-safe** - JSON Schema validation  

The plugin's embedded client makes function calling easy while running entirely in the browser with no server costs.
