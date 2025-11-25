# Agentic Workflow Architecture

**Version:** 1.0.0  
**Last Updated:** November 9, 2024

This document provides a comprehensive overview of the agentic workflow implementation in WP Open Operator System (WP oOS), including architecture, optimizations, and best practices.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Workflow Flow](#workflow-flow)
4. [Configuration](#configuration)
5. [Optimizations](#optimizations)
6. [Testing](#testing)
7. [Troubleshooting](#troubleshooting)

---

## Overview

The agentic workflow enables AI assistants to autonomously execute tools in a loop until they have all the information needed to respond to user queries. This creates a more intelligent, self-sufficient assistant that can:

- Execute multiple tools in sequence
- Make decisions about which tools to use
- Gather information from various sources
- Provide comprehensive answers

**Key Features:**
- Automatic tool execution (no manual intervention)
- Configurable iteration limits
- Progress feedback to users
- Error recovery and handling
- Performance optimizations

---

## Architecture

### Component Overview

```
┌─────────────────────────────────────────────────────────────┐
│                         Frontend                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  chat.js                                              │  │
│  │  - Message bundling (800ms)                          │  │
│  │  - SSE streaming support                             │  │
│  │  - Tool execution feedback (⚙️ ✓ ⚠️)               │  │
│  │  - Result normalization                              │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                            ↓ HTTP POST
┌─────────────────────────────────────────────────────────────┐
│                   REST API Endpoints                         │
│  ┌─────────────────────┐  ┌──────────────────────────────┐ │
│  │  /chat-client       │  │  /chat                        │ │
│  │  Max: 15 iterations │  │  Max: 5 iterations            │ │
│  │  (Browser UI)       │  │  (MCP Protocol)               │ │
│  └─────────────────────┘  └──────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    Chat Service                              │
│  - Agentic loop execution                                    │
│  - Tool execution orchestration                              │
│  - Error handling                                            │
│  - Transcript recording                                      │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    Tool Registry                             │
│  - 65+ built-in tools                                        │
│  - Tool validation                                           │
│  - Capability checking                                       │
│  - Result formatting                                         │
└─────────────────────────────────────────────────────────────┘
```

### Request Flow

1. **User Input** → Frontend (chat.js)
2. **Message Bundling** → 800ms delay for rapid inputs
3. **HTTP POST** → `/chat-client` endpoint
4. **Authentication** → Nonce, bearer token, or Auth0
5. **Agentic Loop Execution**:
   ```
   iteration = 0
   while iteration < max_iterations:
       response = LLM(messages)
       if no tool_calls:
           break
       
       # Add assistant message with tool_calls
       messages.append(assistant_message)
       
       # Execute tools
       for tool_call in response.tool_calls:
           result = execute_tool(tool_call)
           messages.append(tool_result)
       
       iteration++
   ```
6. **Response** → Include tool_results array
7. **Frontend Rendering** → Normalize and display results

---

## Workflow Flow

### Detailed Execution Steps

#### Step 1: Message Preparation
```javascript
// Frontend: chat.js
- User types message
- 800ms delay for bundling
- Attach any files
- Send to /chat-client endpoint
```

#### Step 2: Server Processing
```php
// Backend: WP_MCP_AI_REST::handle_chat_client_request()
- Validate authentication
- Apply max_agentic_iterations filter
- Delegate to handle_chat_request()
```

#### Step 3: Agentic Loop
```php
// Backend: WP_MCP_AI_Chat_Service::process_chat_request()
iteration = 0
max_iterations = 15 (or configured value)

while iteration < max_iterations:
    // Send to LLM
    response = client->create_chat_completion(messages, options)
    
    // Check for tool calls
    tool_calls = extract_tool_calls(response)
    
    if empty(tool_calls):
        break  // Final response ready
    
    // Add assistant message
    messages[] = {
        role: 'assistant',
        content: response.content,
        tool_calls: tool_calls
    }
    
    // Execute each tool
    foreach tool_calls as tool_call:
        result = tool_registry->execute_tool(
            tool_call.function.name,
            tool_call.function.arguments
        )
        
        messages[] = {
            role: 'tool',
            tool_call_id: tool_call.id,
            content: json_encode(result)
        }
        
        tool_result_messages[] = messages[last]
    
    iteration++

// Add tool_results and agentic_tool_messages to response for frontend
response.tool_results = tool_result_messages
response.agentic_tool_messages = agentic_tool_messages  // Intermediate assistant messages with tool_calls
```

#### Step 4: Response Delivery
```php
// Backend: Return response
{
    "id": "chatcmpl-xxx",
    "choices": [{
        "message": {
            "role": "assistant",
            "content": "Final response"
        }
    }],
    "tool_results": [{
        "role": "tool",
        "tool_call_id": "call_xxx",
        "content": "{\"result\": \"data\"}"
    }],
    "agentic_tool_messages": [{
        "role": "assistant",
        "content": "Let me check that for you...",
        "tool_calls": [{"id": "call_xxx", "function": {...}}]
    }]
}
```

#### Step 5: Frontend Rendering
```javascript
// Frontend: chat.js handleChatResponse()
- Add agentic_tool_messages to conversation (preserves intermediate assistant messages)
- Add tool_results to conversation
- Normalize tool results for display
- Extract attachments (images, files)
- Re-render assistant message with attachments
- Show tool execution feedback
```

---

## Configuration

### Max Iterations Configuration Hierarchy

Configuration is applied in this priority order:

1. **Per-Assistant Override** (Highest Priority)
   ```php
   // In assistant post meta
   $assistant_config['max_agentic_iterations'] = 20;
   ```

2. **Admin Setting**
   - Navigate to: **Settings → WP oOS → General Settings → Custom AI Settings (Filters)**
   - Field: **Max Agentic Iterations**
   - Range: 1-50
   - Default: 5

3. **Programmatic Filter**
   ```php
   add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $iterations, $config ) {
       return 10;
   }, 10, 2 );
   ```

4. **Endpoint Default**
   - `/chat-client`: 15 iterations
   - `/chat`: 5 iterations

5. **Safety Bounds** (Enforced)
   - Minimum: 1
   - Maximum: 50

### Endpoint Selection

```php
// Browser chat UI uses /chat-client
'messagesEndpoint' => rest_url( 'mcp-ai/v1/chat-client' )

// MCP protocol clients use /chat
'messagesEndpoint' => rest_url( 'mcp-ai/v1/chat' )
```

### Disable Optimizations

```php
// In wp-config.php
define( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS', true );
```

---

## Optimizations

### 1. Message Bundling (Frontend)

**Purpose:** Reduce API calls for rapid user inputs

**Implementation:**
```javascript
// chat.js lines 4986-5035
const MESSAGE_BUNDLE_DELAY_MS = 800;

function queueMessageForBundling(state, submissionContext) {
    clearTimeout(state.messageBundleTimer);
    state.pendingMessageBundle.push(submissionContext);
    
    state.messageBundleTimer = setTimeout(function() {
        sendBundledMessages(state);
    }, MESSAGE_BUNDLE_DELAY_MS);
}
```

**Benefits:**
- Reduces redundant API calls
- Allows users to correct/extend input
- Lower server load

### 2. Tool Result Caching

**Purpose:** Cache read-only tool results

**Implementation:**
```php
// WP_MCP_AI_Agentic_Workflow_Optimizer
- Cache idempotent tools (get_site_summary, search_content, etc.)
- 5-minute cache expiration
- MD5-based cache keys
```

**Cacheable Tools:**
- `get_site_summary`
- `search_content`
- `get_recent_posts`
- `search_attachments`
- `get_elementor_templates`
- `get_jetengine_items`
- `get_woo_products`
- `get_rankmath_seo`

### 3. Result Compression

**Purpose:** Reduce payload size for large tool results

**Implementation:**
```php
// Compress results > 10KB
if (strlen($content) > 10240) {
    $compressed = gzencode($content, 6);
    // Only use if saves >20%
}
```

**Benefits:**
- Faster network transfer
- Reduced bandwidth usage
- Lower memory footprint

### 4. Performance Metrics

**Purpose:** Track and optimize workflow performance

**Metrics Collected:**
- Execution duration
- Memory usage
- Iteration count
- Tool execution count
- Cache hit/miss ratio
- Compression savings

**Access Metrics:**
```php
$optimizer = new WP_MCP_AI_Agentic_Workflow_Optimizer();
$metrics = $optimizer->get_metrics();
```

### 5. Admin Setting Respect

**Purpose:** Allow admin setting to override chat-client default

**Implementation:**
```php
// WP_MCP_AI_REST::get_chat_client_max_iterations()
if ( $default_max > 5 ) {
    // Admin setting applied, use it
    return $default_max;
}
return 15; // Chat client default
```

**Priority Flow:**
```
Per-Assistant Config (highest)
    ↓
Admin Setting (filter_max_agentic_iterations)
    ↓
Chat Client Default (15) or Chat Default (5)
    ↓
Safety Bounds (1-50)
```

---

## Testing

### Test Coverage

**Unit Tests:**
- `test-agentic-chat-workflow-comprehensive.php`
  - Basic chat flow
  - Single/multiple tool execution
  - Multi-iteration loops
  - Max iteration enforcement
  - Error handling
  - Response surfacing

- `test-agentic-workflow-tool-types.php`
  - Image generation tools
  - Data retrieval tools
  - Content creation tools
  - Mixed tool types
  - Result structure consistency

### Running Tests

```bash
# Run all agentic workflow tests
composer test -- --filter Agentic

# Run comprehensive tests
vendor/bin/phpunit tests/test-agentic-chat-workflow-comprehensive.php

# Run tool type tests
vendor/bin/phpunit tests/test-agentic-workflow-tool-types.php
```

### Manual Testing

1. **Basic Flow:**
   ```
   User: "What's the weather in Paris?"
   Expected: Tool call to weather API, then response
   ```

2. **Multi-Tool:**
   ```
   User: "Get recent posts and current time"
   Expected: 2 tool calls, combined response
   ```

3. **Complex Workflow:**
   ```
   User: "Create a blog post about the weather in London"
   Expected: Weather lookup → Content generation → Post creation
   ```

---

## Troubleshooting

### Common Issues

#### 1. Infinite Loop / Max Iterations Hit

**Symptoms:**
- Workflow stops abruptly
- "Maximum iterations reached" in logs

**Solutions:**
- Increase max_agentic_iterations in admin settings
- Check tool implementations for infinite loops
- Verify LLM is not repeatedly requesting same tool

#### 2. Tool Results Not Displaying

**Symptoms:**
- Tools execute but no results shown
- Missing ✓ or ⚠️ indicators

**Solutions:**
- Check `tool_results` array in response
- Verify `normaliseToolResultForDisplay()` handles tool type
- Check browser console for errors

#### 3. Slow Performance

**Symptoms:**
- Long wait times
- Timeouts

**Solutions:**
- Enable tool result caching
- Reduce max_iterations if not needed
- Check tool execution times in metrics
- Enable SSE streaming for better UX

#### 4. Cache Issues

**Symptoms:**
- Stale data returned
- Unexpected results

**Solutions:**
```php
// Clear all tool caches
WP_MCP_AI_Agentic_Workflow_Optimizer::clear_cache();
```

### Debug Mode

```php
// Enable debug mode to disable optimizations
define( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS', true );

// Enable verbose logging
update_option( 'wp_mcp_ai_settings', array_merge(
    get_option( 'wp_mcp_ai_settings' ),
    array( 'enable_logging' => true )
) );
```

### Performance Monitoring

```php
// View metrics in logs
add_action( 'wp_mcp_ai_agentic_metrics', function( $metrics ) {
    error_log( 'Agentic Metrics: ' . print_r( $metrics, true ) );
} );
```

---

## Best Practices

### For Administrators

1. **Set Appropriate Limits:**
   - Start with default 15 for chat-client
   - Monitor actual usage in metrics
   - Adjust based on complexity of tasks

2. **Monitor Performance:**
   - Enable logging
   - Check metrics regularly
   - Identify slow tools

3. **Configure Caching:**
   - Use default cacheable tools
   - Add custom cacheable tools if read-only

### For Developers

1. **Tool Implementation:**
   - Make tools idempotent where possible
   - Return structured, predictable results
   - Handle errors gracefully

2. **Custom Filters:**
   ```php
   // Adjust iterations based on assistant type
   add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $iterations, $config ) {
       if ( isset( $config['assistant_type'] ) && 'complex' === $config['assistant_type'] ) {
           return 25;
       }
       return $iterations;
   }, 10, 2 );
   ```

3. **Performance Testing:**
   - Test with max iterations
   - Measure tool execution times
   - Profile memory usage

---

## Additional Resources

- [Tool Reference](tool-reference.md) - Complete list of 65+ tools
- [REST API Documentation](rest-api.md) - API endpoints and authentication
- [Testing Guide](TESTING-CHAT-OPTIMIZATIONS.md) - Performance testing
- [Quick Reference](QUICK_REFERENCE.md) - Common tasks

---

## Changelog

### Version 1.0.0 (November 9, 2024)
- Initial documentation
- Comprehensive architecture overview
- Optimization implementations
- Testing guidelines
- Admin setting respect fix
