# LangChain.js Orchestration Guide
## Advanced Multi-Step Reasoning with WordPress Integration

**Phase:** 3 of 8 (WebLLM Enhancement Roadmap)  
**Status:** ✅ Complete - January 2026  
**Version:** 1.0.0

---

## Overview

Phase 3 adds sophisticated AI orchestration capabilities to NV oOS using LangChain.js, enabling:

- **Multi-step reasoning chains** - Break complex tasks into sequential steps
- **Agent-based workflows** - AI agents that can plan and use tools autonomously
- **Conversation memory** - Maintain context across multiple interactions
- **Self-reflection** - Agents can evaluate and correct their own outputs
- **Hybrid execution** - Mix client-side and server-side tool execution

---

## Architecture

### Core Components

```
Browser (Client-Side)
├── langchain-orchestration.js (5.9KB minified)
│   ├── LangChainOrchestrator class
│   ├── Chain creation & execution
│   ├── Agent with tool calling
│   └── Conversation memory management
│
├── langchain-tool-adapter.js (3.8KB minified)
│   ├── WordPress → LangChain tool conversion
│   ├── Client-side tool execution
│   └── Server-side tool proxy
│
└── LangChain libraries (CDN, lazy-loaded)
    ├── @langchain/core (~400KB)
    ├── langchain (~300KB)
    └── @langchain/community (~100KB)

WordPress (Server-Side)
└── class-wp-mcp-ai-langchain-enqueue.php
    ├── Conditional script loading
    ├── Feature flag management
    └── Admin settings integration
```

### Dependencies

**Required:**
- ✅ Phase 1: WebLLM tool calling must be enabled
- ✅ Phase 1: Embedded LLM provider active
- ✅ WebLLM engine loaded and initialized

**Optional (Enhanced):**
- Phase 2: Transformers.js (for browser-native tools)
- WordPress tools (398+ available in full version)

---

## Getting Started

### 1. Enable Feature Flag

Navigate to: **WordPress Admin → NV oOS Pro → WebLLM Features**

Enable these options:
- ☑ **Enable Tool Calling** (Phase 1 requirement)
- ☑ **Enable LangChain Orchestration** (Phase 3)

### 2. Initialize Orchestrator

```javascript
// Wait for WebLLM engine to be ready
const embeddedClient = window.WP_MCP_AI_EmbeddedLLM;
await embeddedClient.loadModel('Llama-3.2-1B-Instruct-q4f16_1-MLC');

// Create orchestrator
const orchestrator = new WP_MCP_AI_LangChain_Orchestrator(
    embeddedClient.currentEngine
);

// Initialize (sets up chat model and memory)
await orchestrator.initialize();
```

### 3. Load WordPress Tools

```javascript
// Fetch available tools
const toolAdapter = window.WP_MCP_AI_LangChain_Tool_Adapter;
const tools = await toolAdapter.fetchTools();

// Pass to orchestrator
orchestrator.setTools(tools);

console.log(`Loaded ${tools.length} tools for orchestration`);
```

---

## Features & Examples

### 1. Simple Chains

Execute a single prompt with template variables:

```javascript
const result = await orchestrator.createChain(
    "Summarize this text in {length} words: {text}",
    {
        length: "50",
        text: "Your long text here..."
    }
);

console.log(result);
```

**With Memory:**

Memory is automatically maintained across chain executions:

```javascript
// First interaction
await orchestrator.createChain(
    "My name is {name}",
    { name: "Alice" }
);

// Later interaction - orchestrator remembers
const response = await orchestrator.createChain(
    "What's my name?",
    {}
);
// Response: "Your name is Alice"
```

### 2. Sequential Chains

Break complex tasks into multiple steps:

```javascript
const results = await orchestrator.createSequentialChain([
    {
        template: "Extract the main topics from this article: {article}",
        variables: { article: articleText }
    },
    {
        template: "For each topic, create a 2-sentence summary. Topics: {previous_result}",
        variables: {}
    },
    {
        template: "Combine these summaries into a cohesive paragraph: {previous_result}",
        variables: {}
    }
]);

// results[0] = list of topics
// results[1] = summaries for each topic
// results[2] = final combined paragraph
```

### 3. Agent-Based Workflows

Agents can autonomously decide which tools to use:

```javascript
const agentResult = await orchestrator.createAgent(
    "Research the WordPress REST API and create a summary with key endpoints",
    {
        maxIterations: 10,
        verbose: true // Log agent's reasoning
    }
);

if (agentResult.success) {
    console.log("Agent completed task:");
    console.log(agentResult.result);
    
    // View execution log
    agentResult.executionLog.forEach(step => {
        console.log(`Step ${step.iteration}:`, step.type, step.content || step.tool);
    });
} else {
    console.error("Agent failed:", agentResult.error);
}
```

**Agent Decision Making:**

The agent will:
1. Analyze the task
2. Decide which tool(s) to call
3. Execute tools via `TOOL_CALL: tool_name({"arg": "value"})`
4. Use tool results to inform next steps
5. Continue until task is complete

### 4. Tool Execution Modes

#### Client-Side Tools (Transformers.js)

If Phase 2 is enabled, these tools run instantly in the browser:

```javascript
// Automatically uses client-side execution
await orchestrator.executeTool('client_summarize_text', {
    text: "Long text to summarize...",
    maxLength: 100
});
```

**Available Client-Side Tools:**
- `client_summarize_text` - Text summarization
- `client_analyze_sentiment` - Sentiment analysis
- `client_extract_entities` - Named entity recognition
- `client_translate_text` - Multi-language translation
- `client_question_answering` - Extract answers from context
- `client_semantic_search` - Generate embeddings

#### Server-Side Tools (WordPress REST API)

All other tools execute server-side with proper permissions:

```javascript
// Automatically uses server-side execution
await orchestrator.executeTool('create_post', {
    title: "New Post",
    content: "Post content...",
    status: "draft"
});
```

**398+ Server-Side Tools Available** (full version)

### 5. Memory Management

Control conversation memory:

```javascript
// Get current memory
const memory = orchestrator.getMemory();
console.log(memory.getMessages()); // Array of past messages

// Manually add to memory
memory.addMessage('user', 'Remember this: User ID is 42');

// Clear memory (start fresh)
orchestrator.clearMemory();

// Create custom memory with different buffer size
orchestrator.memory = orchestrator.createMemory(20); // Keep last 20 messages
```

---

## Advanced Patterns

### 1. Research Agent

Autonomous research with multiple tool calls:

```javascript
const research = await orchestrator.createAgent(
    `Research the latest WordPress security best practices and create a checklist.
    Use search tools to find current information.`,
    { maxIterations: 15, verbose: true }
);
```

### 2. Content Generation Pipeline

Sequential processing with quality checks:

```javascript
const pipeline = await orchestrator.createSequentialChain([
    {
        template: "Generate 5 blog post ideas about {topic}",
        variables: { topic: "AI in WordPress" }
    },
    {
        template: "Select the best idea from: {previous_result}. Explain why it's best.",
        variables: {}
    },
    {
        template: "Create a detailed outline for: {previous_result}",
        variables: {}
    },
    {
        template: "Write the introduction section based on: {previous_result}",
        variables: {}
    }
]);
```

### 3. Self-Correcting Workflow

Agent that validates and corrects its own output:

```javascript
const validated = await orchestrator.createSequentialChain([
    {
        template: "Create a JSON object with user profile data for: {userData}",
        variables: { userData: "Name: John, Age: 25, City: NYC" }
    },
    {
        template: "Validate this JSON: {previous_result}. If invalid, fix it and return valid JSON.",
        variables: {}
    }
]);
```

---

## Integration with Chat UI

### Add to Existing Chat Interface

```javascript
// In your chat.js or chat initialization
if (window.WP_MCP_AI_LangChain_Orchestrator) {
    const orchestrator = new WP_MCP_AI_LangChain_Orchestrator(
        embeddedClient.currentEngine
    );
    await orchestrator.initialize();
    
    // Load tools
    const tools = await window.WP_MCP_AI_LangChain_Tool_Adapter.fetchTools();
    orchestrator.setTools(tools);
    
    // Use in chat handler
    async function handleChatMessage(userMessage) {
        // Option 1: Simple chain
        const response = await orchestrator.createChain(userMessage, {});
        
        // Option 2: Agent (for complex requests)
        // const response = await orchestrator.createAgent(userMessage);
        
        return response;
    }
}
```

### Detect Multi-Step Requests

```javascript
function isComplexRequest(message) {
    const keywords = [
        'research', 'analyze', 'create', 'generate', 
        'compare', 'summarize and', 'then'
    ];
    return keywords.some(kw => message.toLowerCase().includes(kw));
}

async function handleMessage(message) {
    if (isComplexRequest(message)) {
        // Use agent for complex multi-step tasks
        return await orchestrator.createAgent(message, {
            maxIterations: 10,
            verbose: false
        });
    } else {
        // Use simple chain for straightforward requests
        return await orchestrator.createChain(message, {});
    }
}
```

---

## Configuration

### Feature Flags

Controlled via `wp_mcp_ai_webllm_settings` option:

```php
// Enable LangChain orchestration
update_option('wp_mcp_ai_enable_langchain_orchestration', true);

// Or via filter
add_filter('wp_mcp_ai_enable_langchain_orchestration', '__return_true');
```

### JavaScript Configuration

Passed to client via `wpMcpAiLangChain` global:

```javascript
// Available in JavaScript
wpMcpAiLangChain = {
    enabled: true,
    maxIterations: 10,  // Max agent iterations
    verbose: false,     // Console logging
    cdnUrls: {
        core: 'https://cdn.jsdelivr.net/npm/@langchain/core@1.1.20/+esm',
        langchain: 'https://cdn.jsdelivr.net/npm/langchain@1.2.19/+esm',
        community: 'https://cdn.jsdelivr.net/npm/@langchain/community@1.1.13/+esm'
    }
};
```

### PHP Filters

```php
// Change max agent iterations
add_filter('wp_mcp_ai_langchain_max_iterations', function() {
    return 20; // Default is 10
});

// Determine if page should load LangChain
add_filter('wp_mcp_ai_is_chat_page', function($is_chat_page) {
    // Your custom logic
    return $is_chat_page || is_page('custom-chat');
});
```

---

## Performance

### Bundle Size Impact

| Component | Size | Load Method |
|-----------|------|-------------|
| langchain-orchestration.js | 5.9KB | Bundled (minified) |
| langchain-tool-adapter.js | 3.8KB | Bundled (minified) |
| @langchain/core | ~400KB | CDN (lazy-loaded) |
| langchain | ~300KB | CDN (lazy-loaded) |
| @langchain/community | ~100KB | CDN (lazy-loaded) |
| **Total Plugin Impact** | **9.7KB** | Minified & gzipped |
| **Total Runtime (first load)** | **~810KB** | Cached after first load |

### Optimization Strategies

1. **Lazy Loading**: LangChain libraries only load when feature is enabled and used
2. **CDN Caching**: Libraries cached in browser after first load
3. **Code Splitting**: Orchestration code separate from main chat bundle
4. **Conditional Enqueue**: Only loads on pages with chat interface

### Performance Benchmarks

| Operation | Time | Description |
|-----------|------|-------------|
| Initialization | 100-500ms | One-time setup |
| Simple Chain | 1-3s | Single LLM call |
| Sequential Chain (3 steps) | 3-9s | Multiple LLM calls |
| Agent (5 iterations) | 5-15s | Multiple tool calls |
| Memory Access | <10ms | Read/write conversation |

---

## Browser Compatibility

| Browser | Status | Notes |
|---------|--------|-------|
| Chrome 113+ | ✅ Full support | Recommended |
| Edge 113+ | ✅ Full support | Recommended |
| Safari 18+ | ✅ Full support | macOS/iOS |
| Firefox | ⚠️ Partial | WebGPU in development, CPU fallback works |

**Minimum Requirements:**
- ES6+ JavaScript support
- LocalStorage enabled
- 4GB+ RAM recommended for agent workflows

---

## Troubleshooting

### Issue: "LangChain libraries not loaded"

**Cause:** Feature flag disabled or CDN blocked

**Solution:**
1. Verify **Enable LangChain Orchestration** is checked in settings
2. Check browser console for CDN loading errors
3. Verify network allows access to `cdn.jsdelivr.net`

### Issue: "WebLLM engine not provided"

**Cause:** Orchestrator initialized before WebLLM engine ready

**Solution:**
```javascript
// Ensure model is loaded first
await embeddedClient.loadModel('Llama-3.2-1B-Instruct-q4f16_1-MLC');

// Then create orchestrator
const orchestrator = new WP_MCP_AI_LangChain_Orchestrator(
    embeddedClient.currentEngine
);
```

### Issue: "Tool execution failed"

**Cause:** Tool not found or permission denied

**Solution:**
1. Verify tool exists: `orchestrator.tools` array
2. Check user has required capability for tool
3. For client-side tools, ensure Phase 2 (Transformers.js) is enabled
4. Check browser console for detailed error messages

### Issue: "Agent reaches max iterations"

**Cause:** Task too complex or agent stuck in loop

**Solution:**
```javascript
// Increase max iterations
const result = await orchestrator.createAgent(task, {
    maxIterations: 20, // Increase from default 10
    verbose: true      // See what agent is doing
});

// Or simplify the task
// Instead of: "Research, analyze, and create report"
// Try: Use sequential chains for each step
```

---

## Best Practices

### 1. Progressive Enhancement

```javascript
// Check if LangChain is available
if (window.WP_MCP_AI_LangChain_Orchestrator) {
    // Use orchestration features
    useOrchestration();
} else {
    // Fallback to simple chat
    useSimpleChat();
}
```

### 2. Error Handling

```javascript
try {
    const result = await orchestrator.createAgent(task);
    if (!result.success) {
        console.error('Agent failed:', result.error);
        // Fallback to simple chain
        const fallback = await orchestrator.createChain(task, {});
        return fallback;
    }
    return result.result;
} catch (error) {
    console.error('Orchestration error:', error);
    // Handle gracefully
}
```

### 3. Memory Management

```javascript
// Clear memory periodically to prevent context overflow
if (orchestrator.memory.messages.length > 50) {
    orchestrator.clearMemory();
}

// Or keep only recent context
orchestrator.memory.messages = orchestrator.memory.messages.slice(-20);
```

### 4. Tool Selection

```javascript
// Filter tools for specific use case
const toolAdapter = window.WP_MCP_AI_LangChain_Tool_Adapter;

// Only client-side tools (faster)
const clientTools = toolAdapter.filterByExecutionType('client');

// Only post-related tools
const postTools = tools.filter(t => t.name.includes('post'));

orchestrator.setTools(clientTools);
```

---

## API Reference

### WP_MCP_AI_LangChain_Orchestrator

#### Constructor

```javascript
new WP_MCP_AI_LangChain_Orchestrator(webllmEngine)
```

**Parameters:**
- `webllmEngine` (Object) - WebLLM engine instance from embedded-llm-client

#### Methods

##### initialize()

Initialize the orchestrator (must be called before use).

```javascript
await orchestrator.initialize();
```

**Returns:** `Promise<void>`

##### createChain(template, variables)

Create and execute a simple chain.

```javascript
await orchestrator.createChain(template, variables);
```

**Parameters:**
- `template` (String) - Prompt template with `{variables}`
- `variables` (Object) - Variables to fill in template

**Returns:** `Promise<string>` - Chain result

##### createSequentialChain(steps)

Execute multiple chains in sequence.

```javascript
await orchestrator.createSequentialChain(steps);
```

**Parameters:**
- `steps` (Array<Object>) - Array of `{template, variables}` objects

**Returns:** `Promise<Array<string>>` - Array of results

##### createAgent(task, options)

Create and execute an agent with tool calling.

```javascript
await orchestrator.createAgent(task, options);
```

**Parameters:**
- `task` (String) - Task description
- `options` (Object) - Options:
  - `maxIterations` (Number) - Max iterations (default: 10)
  - `verbose` (Boolean) - Log reasoning (default: false)

**Returns:** `Promise<Object>` - `{success, result, iterations, executionLog}`

##### setTools(wpTools)

Set tools available for agent.

```javascript
orchestrator.setTools(wpTools);
```

**Parameters:**
- `wpTools` (Array) - WordPress tools from REST API

##### executeTool(toolName, args)

Execute a WordPress tool (client or server-side).

```javascript
await orchestrator.executeTool(toolName, args);
```

**Parameters:**
- `toolName` (String) - Tool name/slug
- `args` (Object) - Tool arguments

**Returns:** `Promise<any>` - Tool result

##### getMemory()

Get conversation memory instance.

```javascript
orchestrator.getMemory();
```

**Returns:** `Object` - Memory instance with `messages` array

##### clearMemory()

Clear conversation memory.

```javascript
orchestrator.clearMemory();
```

##### isReady()

Check if orchestrator is initialized and ready.

```javascript
orchestrator.isReady();
```

**Returns:** `Boolean` - Ready state

---

## Related Documentation

- [Phase 1: WebLLM Tool Calling Guide](./TOOL_CALLING_GUIDE.md)
- [Phase 2: Transformers.js Guide](./TRANSFORMERS_BROWSER_AI.md)
- [Embedded Provider Implementation](./IMPLEMENTATION_COMPLETE.md)
- [WebLLM Implementation Status](../../proposals/WEBLLM-IMPLEMENTATION-STATUS.md)

---

## Support

**Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues  
**Documentation:** See `docs/` directory  
**Version:** Phase 3 v1.0.0  
**Last Updated:** January 2026
