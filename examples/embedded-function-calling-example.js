/**
 * Embedded Function Calling Example
 * 
 * This example demonstrates how to use function calling with WebLLM
 * in the NV oOS WordPress plugin.
 * 
 * Based on @mlc-ai/web-llm documentation:
 * https://github.com/mlc-ai/web-llm
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/* eslint-disable no-console */

// Note: This assumes @mlc-ai/web-llm is loaded via CDN or bundler
// For the HTML example, see embedded-function-calling-example.html

/**
 * Example: Function Calling with WebLLM
 * 
 * This demonstrates the complete flow of function calling:
 * 1. Define tools/functions
 * 2. Initialize model
 * 3. Send chat request with tools
 * 4. Handle tool calls in streaming response
 * 5. Execute tools
 * 6. Send results back to model
 * 7. Get final response
 */
async function functionCallingExample() {
    // Import WebLLM (if using ES modules)
    // const webllm = await import("@mlc-ai/web-llm");
    
    // Or use global if loaded via CDN
    const webllm = window.webLLM || window.webllm;
    
    if (!webllm) {
        throw new Error('WebLLM not loaded. Please load @mlc-ai/web-llm first.');
    }
    
    // ============================================
    // Step 1: Define Tools/Functions
    // ============================================
    
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
                            description: "The city and state, e.g. San Francisco, CA",
                        },
                        unit: {
                            type: "string",
                            enum: ["celsius", "fahrenheit"]
                        },
                    },
                    required: ["location"],
                },
            },
        },
    ];
    
    // ============================================
    // Step 2: Initialize Model
    // ============================================
    
    // Use a model that supports function calling (e.g., Hermes-2-Pro)
    const selectedModel = "Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC";
    
    console.log('[Function Calling] Initializing model:', selectedModel);
    
    const initProgressCallback = (report) => {
        console.log('[Progress]', report.text, report.progress);
    };
    
    const engine = await webllm.CreateMLCEngine(
        selectedModel,
        { 
            initProgressCallback: initProgressCallback,
            logLevel: 'INFO'
        }
    );
    
    console.log('[Function Calling] Model initialized successfully');
    
    // ============================================
    // Step 3: Send Chat Request with Tools
    // ============================================
    
    const request = {
        stream: true, // Streaming is recommended for better UX
        stream_options: { include_usage: true }, // Get token usage
        messages: [
            {
                role: "user",
                content: "What is the current weather in celsius in Pittsburgh and Tokyo?",
            },
        ],
        tool_choice: "auto", // Let model decide when to use tools
        tools: tools,
    };
    
    console.log('[Function Calling] Sending chat request with tools');
    
    // ============================================
    // Step 4: Handle Streaming Response
    // ============================================
    
    const asyncChunkGenerator = await engine.chat.completions.create(request);
    
    let message = "";
    const toolCalls = [];
    let usageChunk;
    
    // Process each chunk
    for await (const chunk of asyncChunkGenerator) {
        console.log('[Chunk]', chunk);
        
        // Accumulate text content
        message += chunk.choices[0]?.delta?.content || "";
        
        // Handle tool calls (they come incrementally in streaming mode)
        if (chunk.choices[0]?.delta?.tool_calls) {
            const toolCallDeltas = chunk.choices[0].delta.tool_calls;
            
            toolCallDeltas.forEach(tc => {
                const index = tc.index || 0;
                
                // Initialize tool call if new
                if (!toolCalls[index]) {
                    toolCalls[index] = {
                        id: tc.id || 'call_' + Date.now() + '_' + index,
                        type: 'function',
                        function: {
                            name: tc.function?.name || '',
                            arguments: tc.function?.arguments || ''
                        }
                    };
                } else {
                    // Append to existing tool call (streaming)
                    if (tc.function?.name) {
                        toolCalls[index].function.name += tc.function.name;
                    }
                    if (tc.function?.arguments) {
                        toolCalls[index].function.arguments += tc.function.arguments;
                    }
                }
            });
        }
        
        // Track usage chunk
        if (chunk.usage) {
            usageChunk = chunk;
        }
    }
    
    console.log('[Function Calling] Stream completed');
    console.log('[Function Calling] Tool calls detected:', toolCalls.length);
    console.log('[Function Calling] Usage:', usageChunk?.usage);
    
    // ============================================
    // Step 5: Execute Tool Calls
    // ============================================
    
    if (toolCalls.length === 0) {
        // No tool calls, just return the message
        console.log('[Function Calling] No tool calls, returning message');
        return {
            message: message,
            usage: usageChunk?.usage
        };
    }
    
    // Execute each tool call
    const toolMessages = [];
    
    for (const toolCall of toolCalls) {
        console.log('[Function Calling] Executing tool:', toolCall.function.name);
        console.log('[Function Calling] Arguments:', toolCall.function.arguments);
        
        // Parse arguments
        const args = JSON.parse(toolCall.function.arguments);
        
        // Execute tool (mock implementation)
        let result;
        if (toolCall.function.name === 'get_current_weather') {
            result = getCurrentWeather(args.location, args.unit);
        } else {
            result = { error: 'Unknown function: ' + toolCall.function.name };
        }
        
        console.log('[Function Calling] Tool result:', result);
        
        // Add tool result to messages
        toolMessages.push({
            role: "tool",
            tool_call_id: toolCall.id,
            name: toolCall.function.name,
            content: JSON.stringify(result)
        });
    }
    
    // ============================================
    // Step 6: Send Tool Results Back to Model
    // ============================================
    
    console.log('[Function Calling] Sending tool results to model');
    
    const followUpRequest = {
        stream: true,
        messages: [
            { role: "user", content: request.messages[0].content },
            { role: "assistant", content: message, tool_calls: toolCalls },
            ...toolMessages
        ],
        tools: tools
    };
    
    const followUpGenerator = await engine.chat.completions.create(followUpRequest);
    
    // ============================================
    // Step 7: Get Final Response
    // ============================================
    
    let finalMessage = "";
    let finalUsage;
    
    for await (const chunk of followUpGenerator) {
        finalMessage += chunk.choices[0]?.delta?.content || "";
        if (chunk.usage) {
            finalUsage = chunk.usage;
        }
    }
    
    console.log('[Function Calling] Final response:', finalMessage);
    console.log('[Function Calling] Final usage:', finalUsage);
    
    return {
        message: finalMessage,
        toolCalls: toolCalls,
        toolResults: toolMessages,
        usage: finalUsage
    };
}

/**
 * Mock weather function
 * In a real application, this would call a weather API
 * 
 * @param {string} location - Location to get weather for
 * @param {string} unit - Temperature unit (celsius or fahrenheit)
 * @returns {Object} Weather data
 */
function getCurrentWeather(location, unit = 'celsius') {
    console.log('[Weather API] Getting weather for', location, 'in', unit);
    
    // Mock response
    return {
        location: location,
        temperature: unit === 'celsius' ? 22 : 72,
        unit: unit,
        condition: 'Partly cloudy',
        humidity: 65,
        wind_speed: 10,
        timestamp: new Date().toISOString()
    };
}

/**
 * Example: Using with WordPress NV oOS Plugin
 * 
 * The plugin provides WP_MCP_AI_EmbeddedLLM class that handles
 * function calling automatically when tools are configured.
 */
async function wordpressPluginExample() {
    // Check if plugin's embedded client is available
    if (!window.WP_MCP_AI_EmbeddedLLM) {
        throw new Error('WP_MCP_AI_EmbeddedLLM not loaded');
    }
    
    // Create instance
    const instanceId = 'example-' + Date.now();
    const client = new window.WP_MCP_AI_EmbeddedLLM(instanceId, {
        systemPrompt: 'You are a helpful weather assistant.',
        tools: [
            {
                slug: 'get_weather',
                description: 'Get current weather',
                parameters: {
                    type: 'object',
                    properties: {
                        location: {
                            type: 'string',
                            description: 'City name'
                        }
                    }
                }
            }
        ]
    });
    
    // Load model
    await client.loadModel('Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC', (progress) => {
        console.log('Loading:', progress.text, progress.progress);
    });
    
    // Send message with streaming
    const messages = [
        { role: 'system', content: 'You are a helpful weather assistant.' },
        { role: 'user', content: 'What is the weather in Tokyo?' }
    ];
    
    const result = await client.generateStreamingCompletion(
        messages,
        {
            temperature: 0.7,
            max_tokens: 512,
            tools: client.tools // Use configured tools
        },
        (chunk) => {
            console.log('Chunk:', chunk.content);
        }
    );
    
    console.log('Final result:', result);
    
    // Check if there are tool calls
    if (result.tool_calls && result.tool_calls.length > 0) {
        console.log('Tool calls detected:', result.tool_calls);
        // Handle tool execution here
    }
    
    return result;
}

/**
 * Example: Using Enhanced Function Calling Client
 * 
 * The plugin provides WP_MCP_AI_WebLLM_FunctionCalling class
 * which extends the base client with tool execution capabilities.
 */
async function enhancedFunctionCallingExample() {
    // Check if enhanced client is available
    if (!window.WP_MCP_AI_WebLLM_FunctionCalling) {
        console.warn('Enhanced function calling client not available');
        return;
    }
    
    // Create instance with tools
    const instanceId = 'enhanced-' + Date.now();
    const client = new window.WP_MCP_AI_WebLLM_FunctionCalling(instanceId, {
        systemPrompt: 'You are a helpful assistant with access to tools.',
        tools: [
            {
                slug: 'get_current_weather',
                description: 'Get weather information',
                parameters: {
                    type: 'object',
                    properties: {
                        location: { type: 'string' },
                        unit: { type: 'string', enum: ['celsius', 'fahrenheit'] }
                    },
                    required: ['location']
                }
            }
        ]
    });
    
    // Load model
    await client.loadModel('Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC', (progress) => {
        console.log('Loading:', progress.text);
    });
    
    // Chat with tools - the client handles tool calling automatically
    const messages = [
        { role: 'user', content: 'What is the weather in Pittsburgh?' }
    ];
    
    // Use chatWithTools method
    const stream = await client.chatWithTools(messages, client.tools, {
        temperature: 0.7,
        max_tokens: 512,
        onChunk: (chunk) => {
            console.log('Stream chunk:', chunk);
        }
    });
    
    // Process stream
    for await (const chunk of stream) {
        if (chunk.type === 'content') {
            console.log('Content:', chunk.data);
        } else if (chunk.type === 'tool_call') {
            console.log('Tool call:', chunk.data);
        } else if (chunk.type === 'done') {
            console.log('Done:', chunk);
        }
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        functionCallingExample,
        wordpressPluginExample,
        enhancedFunctionCallingExample,
        getCurrentWeather
    };
}

// Make available globally for HTML examples
if (typeof window !== 'undefined') {
    window.WP_MCP_AI_FunctionCallingExamples = {
        functionCallingExample,
        wordpressPluginExample,
        enhancedFunctionCallingExample,
        getCurrentWeather
    };
}

console.log('[Examples] Function calling examples loaded');
