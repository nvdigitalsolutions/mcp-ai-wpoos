# Embedded Provider Tool Support - Implementation Plan

## Executive Summary

This document outlines a practical plan to add tool/function calling support to the embedded LLM provider (WebLLM) in the WordPress plugin. The implementation uses a **hybrid architecture**: LLM inference runs client-side (browser) while tool execution runs server-side (WordPress REST API).

## Current State

### What Works ✅
- System prompts propagate to embedded provider
- Temperature settings apply correctly
- Model selection and streaming responses
- 100% client-side LLM inference for privacy

### What's Missing ❌
- Tool/function calling capabilities
- Tool definitions not passed to WebLLM
- No tool response handling
- No iteration for multi-step tool usage

## Proposed Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Browser (Client-Side)                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. User Message → Chat Widget                              │
│         ↓                                                    │
│  2. Format messages + system prompt + TOOLS                  │
│         ↓                                                    │
│  3. WebLLM.chat.completions.create({                        │
│       messages: [...],                                       │
│       tools: [tool_definitions],  ← NEW                      │
│       tool_choice: "auto"         ← NEW                      │
│     })                                                       │
│         ↓                                                    │
│  4. LLM Response (may include tool_calls)                   │
│         ↓                                                    │
│  5. IF tool_calls present:                                   │
│       → Execute tools via WordPress REST API ────────┐      │
│       ← Receive tool results                         │      │
│       → Add tool results to conversation             │      │
│       → Call WebLLM again with tool results          │      │
│       → Repeat until no more tool_calls              │      │
│                                                       │      │
└───────────────────────────────────────────────────────┼──────┘
                                                        │
                                                        │ AJAX
┌───────────────────────────────────────────────────────┼──────┐
│                WordPress Server (Server-Side)         │      │
├───────────────────────────────────────────────────────┼──────┤
│                                                       ↓      │
│  REST API: /wp-json/mcp-ai/v1/tools                         │
│                                                              │
│  • Validate tool call                                       │
│  • Check user permissions                                   │
│  • Execute tool (search, create_post, etc.)                 │
│  • Return structured result                                 │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Implementation Phases

### Phase 1: Pass Tool Definitions to Client (Estimated: 2-4 hours)

**Objective:** Make assistant's tool configurations available to embedded client.

**Changes Required:**

**1.1 PHP: Pass Tools via Config (`includes/class-wp-mcp-ai-shortcode.php`)**

```php
// Around line 885 - after adding systemPrompt and temperature
if ( ! empty( $assistant_config_for_provider['tools'] ) && is_array( $assistant_config_for_provider['tools'] ) ) {
    // Get tool definitions in OpenAI format for embedded provider
    $tool_definitions = array();
    
    if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
        $registry = WP_MCP_AI_Tool_Registry::instance();
        
        foreach ( $assistant_config_for_provider['tools'] as $tool_slug ) {
            $tool = $registry->get_tool( $tool_slug );
            if ( $tool && method_exists( $tool, 'get_definition' ) ) {
                $definition = $tool->get_definition();
                if ( $definition ) {
                    $tool_definitions[] = $definition;
                }
            }
        }
    }
    
    if ( ! empty( $tool_definitions ) ) {
        $config['tools'] = $tool_definitions;
    }
}
```

**1.2 JavaScript: Store Tools in State (`assets/js/chat.js`)**

Tools are already available via `state.config.tools` if passed in config. No changes needed here.

**Testing Phase 1:**
- Verify tool definitions appear in browser console
- Check format matches OpenAI tool schema
- Confirm no errors when tools are passed

---

### Phase 2: WebLLM Tool Calling Support (Estimated: 4-6 hours)

**Objective:** Pass tools to WebLLM and handle tool_calls in response.

**Changes Required:**

**2.1 Update `generateEmbeddedCompletion()` to Pass Tools (`assets/js/chat.js`)**

```javascript
// Around line 11687 - in generateEmbeddedCompletion()
function generateEmbeddedCompletion(state, embeddedClient, messages, finalize, submissionContext) {
    // ... existing code for formatting messages ...
    
    // Build request options
    const requestOptions = {
        temperature: temperature,
        max_tokens: maxTokens
    };
    
    // Add tools if available (NEW)
    if (state.config.tools && Array.isArray(state.config.tools) && state.config.tools.length > 0) {
        requestOptions.tools = state.config.tools;
        requestOptions.tool_choice = 'auto'; // Let model decide when to use tools
        
        console.log('[NV oOS] Passing tools to WebLLM:', {
            toolCount: state.config.tools.length,
            toolNames: state.config.tools.map(function(t) { 
                return t.function ? t.function.name : 'unknown'; 
            })
        });
    }
    
    return embeddedClient.generateStreamingCompletion(
        formattedMessages,
        requestOptions,
        function(chunk) {
            // ... existing chunk handling ...
        }
    )
    .then(function(result) {
        // Check if response includes tool_calls (NEW)
        if (result.tool_calls && Array.isArray(result.tool_calls) && result.tool_calls.length > 0) {
            console.log('[NV oOS] LLM requested tool calls:', result.tool_calls);
            
            // Execute tools and continue conversation
            return handleEmbeddedToolCalls(state, embeddedClient, messages, result, finalize, submissionContext);
        }
        
        // No tool calls - conversation complete
        // ... existing finalization code ...
    });
}
```

**2.2 Update WebLLM Client to Support Tools (`assets/js/embedded-llm-client.js`)**

```javascript
// Around line 370 - in generateStreamingCompletion()
async generateStreamingCompletion(messages, options = {}, onChunk) {
    if (!this.modelLoaded || !this.currentEngine) {
        throw new Error('No model is currently loaded. Please load a model first.');
    }

    try {
        const requestPayload = {
            messages: messages,
            temperature: options.temperature || 0.7,
            max_tokens: options.max_tokens || 512,
            top_p: options.top_p || 0.9,
            stream: true
        };
        
        // Add tools if provided (NEW)
        if (options.tools && Array.isArray(options.tools)) {
            requestPayload.tools = options.tools;
            
            if (options.tool_choice) {
                requestPayload.tool_choice = options.tool_choice;
            }
        }
        
        const asyncChunkGenerator = await this.currentEngine.chat.completions.create(requestPayload);
        
        let fullContent = '';
        let toolCalls = []; // NEW: Collect tool calls
        let lastChunk = null;
        let chunkCount = 0;

        for await (const chunk of asyncChunkGenerator) {
            lastChunk = chunk;
            const delta = chunk.choices[0]?.delta?.content || '';
            
            // Handle tool calls (NEW)
            if (chunk.choices[0]?.delta?.tool_calls) {
                const toolCallDelta = chunk.choices[0].delta.tool_calls;
                
                // WebLLM may stream tool calls incrementally
                toolCallDelta.forEach(function(tc) {
                    const index = tc.index || 0;
                    
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
                        // Append to existing tool call
                        if (tc.function?.name) {
                            toolCalls[index].function.name += tc.function.name;
                        }
                        if (tc.function?.arguments) {
                            toolCalls[index].function.arguments += tc.function.arguments;
                        }
                    }
                });
            }
            
            // Handle text content as before
            if (delta) {
                chunkCount++;
                fullContent += delta;
                // ... existing chunk callback ...
            }
        }

        return {
            success: true,
            content: fullContent,
            tool_calls: toolCalls.length > 0 ? toolCalls : undefined, // NEW
            usage: lastChunk?.usage || {},
            done: true
        };

    } catch (error) {
        throw new Error('Generation failed: ' + error.message);
    }
}
```

**Testing Phase 2:**
- Verify tools are passed to WebLLM without errors
- Check that WebLLM returns tool_calls when appropriate
- Confirm tool_calls format matches OpenAI schema

---

### Phase 3: Tool Execution via REST API (Estimated: 3-5 hours)

**Objective:** Execute tool calls via WordPress REST API and handle results.

**Changes Required:**

**3.1 Create Tool Execution Handler (`assets/js/chat.js`)**

```javascript
/**
 * Handle tool calls from embedded LLM response
 * Executes tools via WordPress REST API and continues conversation
 * 
 * @param {Object} state Chat state
 * @param {Object} embeddedClient WebLLM client instance
 * @param {Array} conversationMessages Current conversation
 * @param {Object} llmResult LLM response with tool_calls
 * @param {Function} finalize Cleanup function
 * @param {Object} submissionContext Submission context
 */
function handleEmbeddedToolCalls(state, embeddedClient, conversationMessages, llmResult, finalize, submissionContext) {
    console.log('[NV oOS] Executing tools for embedded provider:', llmResult.tool_calls);
    
    // Display assistant's tool-calling message
    const assistantMessage = {
        role: 'assistant',
        content: llmResult.content || '',
        tool_calls: llmResult.tool_calls
    };
    
    // Add to conversation
    conversationMessages.push(assistantMessage);
    
    // Show assistant message with tool indicators
    if (llmResult.content) {
        appendMessage(state, 'assistant', llmResult.content, {});
    }
    
    // Execute each tool call
    const toolExecutionPromises = llmResult.tool_calls.map(function(toolCall) {
        return executeToolForEmbedded(state, toolCall);
    });
    
    return Promise.all(toolExecutionPromises)
        .then(function(toolResults) {
            // Add tool results to conversation
            toolResults.forEach(function(result, index) {
                const toolMessage = {
                    role: 'tool',
                    tool_call_id: llmResult.tool_calls[index].id,
                    name: llmResult.tool_calls[index].function.name,
                    content: JSON.stringify(result)
                };
                
                conversationMessages.push(toolMessage);
                
                // Display tool result (use existing tool display logic)
                displayToolResult(state, llmResult.tool_calls[index].function.name, result);
            });
            
            // Continue conversation with tool results
            return generateEmbeddedCompletion(
                state, 
                embeddedClient, 
                conversationMessages, 
                finalize, 
                submissionContext
            );
        })
        .catch(function(error) {
            console.error('[NV oOS] Tool execution failed:', error);
            handleError(state, {
                message: 'Tool execution failed: ' + error.message
            });
            restoreSubmissionState(state, submissionContext);
            finalize();
        });
}

/**
 * Execute a single tool via WordPress REST API
 * 
 * @param {Object} state Chat state
 * @param {Object} toolCall Tool call object from LLM
 * @returns {Promise} Tool result
 */
function executeToolForEmbedded(state, toolCall) {
    console.log('[NV oOS] Executing tool:', toolCall.function.name);
    
    // Parse tool arguments
    let toolArgs = {};
    try {
        toolArgs = JSON.parse(toolCall.function.arguments);
    } catch (e) {
        console.error('[NV oOS] Failed to parse tool arguments:', e);
        return Promise.resolve({
            error: 'Failed to parse tool arguments',
            raw_arguments: toolCall.function.arguments
        });
    }
    
    // Call WordPress REST API to execute tool
    const payload = {
        tool: toolCall.function.name,
        arguments: toolArgs,
        assistant_id: state.config.assistantId
    };
    
    return postJson(
        state.config.toolsEndpoint,
        payload,
        buildJsonHeaders(state),
        { state: state }
    )
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        console.log('[NV oOS] Tool result:', {
            tool: toolCall.function.name,
            success: !data.error
        });
        return data;
    })
    .catch(function(error) {
        console.error('[NV oOS] Tool execution error:', error);
        return {
            error: 'Tool execution failed: ' + error.message
        };
    });
}

/**
 * Display tool execution result in chat
 * Reuses existing tool display logic
 */
function displayToolResult(state, toolName, result) {
    // Check if result has specific display format
    if (result && result.display) {
        // Use existing display logic from non-embedded tools
        const displayText = formatToolDisplay(toolName, result);
        appendMessage(state, 'tool', displayText, { toolName: toolName });
    } else {
        // Generic display
        const summary = 'Tool "' + toolName + '" completed';
        appendMessage(state, 'tool', summary, { toolName: toolName });
    }
}
```

**Testing Phase 3:**
- Verify tool calls execute via REST API
- Check tool results are properly formatted
- Confirm conversation continues after tool execution

---

### Phase 4: Iteration & Error Handling (Estimated: 2-3 hours)

**Objective:** Support multi-step tool usage and handle edge cases.

**Changes Required:**

**4.1 Add Iteration Limits**

```javascript
// In handleEmbeddedToolCalls()
function handleEmbeddedToolCalls(state, embeddedClient, conversationMessages, llmResult, finalize, submissionContext, iterationCount) {
    iterationCount = iterationCount || 0;
    const MAX_ITERATIONS = 5; // Prevent infinite loops
    
    if (iterationCount >= MAX_ITERATIONS) {
        console.warn('[NV oOS] Max tool iterations reached:', MAX_ITERATIONS);
        
        // Add warning message
        appendMessage(state, 'system', 
            'Maximum tool iterations reached. Conversation may be incomplete.',
            { isWarning: true }
        );
        
        finalize();
        return Promise.resolve();
    }
    
    // ... rest of implementation with iterationCount + 1 passed to next call ...
}
```

**4.2 Add Permission Checks**

```javascript
// Before executing tools, check if user has permission
function canExecuteTool(state, toolName) {
    // Check if tool is in assistant's allowed tools
    if (!state.config.tools || !Array.isArray(state.config.tools)) {
        return false;
    }
    
    const toolDef = state.config.tools.find(function(t) {
        return t.function && t.function.name === toolName;
    });
    
    if (!toolDef) {
        console.warn('[NV oOS] Tool not in assistant config:', toolName);
        return false;
    }
    
    // Check sensitive tools restriction
    if (state.config.allowSensitiveTools === false) {
        // List of sensitive tools that should be restricted
        const sensitiveTool = ['delete_post', 'update_user', 'delete_file'];
        if (sensitiveTool.indexOf(toolName) !== -1) {
            console.warn('[NV oOS] Sensitive tool blocked:', toolName);
            return false;
        }
    }
    
    return true;
}
```

**4.3 Add Loading Indicators**

```javascript
// Show visual indicator while tools are executing
function executeToolForEmbedded(state, toolCall) {
    // Add loading indicator
    const loadingMsg = appendMessage(state, 'system', 
        'Executing tool: ' + toolCall.function.name + '...',
        { isLoading: true, toolName: toolCall.function.name }
    );
    
    return postJson(/* ... */)
        .then(function(result) {
            // Remove loading indicator
            if (loadingMsg && loadingMsg.parentNode) {
                loadingMsg.parentNode.removeChild(loadingMsg);
            }
            return result;
        })
        .catch(function(error) {
            // Remove loading indicator on error too
            if (loadingMsg && loadingMsg.parentNode) {
                loadingMsg.parentNode.removeChild(loadingMsg);
            }
            throw error;
        });
}
```

**Testing Phase 4:**
- Test multi-step workflows (tool → LLM → tool → LLM)
- Verify iteration limits work
- Check permission restrictions
- Confirm loading indicators appear/disappear

---

### Phase 5: Testing & Documentation (Estimated: 3-4 hours)

**Objective:** Comprehensive testing and user documentation.

**5.1 Test Cases**

1. **Single Tool Call**
   - LLM calls one tool
   - Tool executes successfully
   - LLM continues with result

2. **Multiple Tool Calls**
   - LLM calls multiple tools in parallel
   - All execute successfully
   - LLM synthesizes results

3. **Multi-Step Workflow**
   - LLM → Tool A → LLM → Tool B → LLM → Final response
   - Verify context maintained throughout

4. **Error Handling**
   - Tool execution fails
   - Invalid tool arguments
   - Permission denied
   - Network errors

5. **Edge Cases**
   - Max iterations reached
   - Tool returns no data
   - Malformed JSON arguments
   - Browser tab closed during execution

**5.2 Update Documentation**

Add to `docs/system-prompt-propagation.md`:

```markdown
### Embedded Provider Tool Support

**Status:** ✅ Supported (as of v1.x.x)

The embedded provider now supports tool/function calling using a hybrid architecture:
- **LLM Inference:** Client-side (browser) using WebLLM
- **Tool Execution:** Server-side (WordPress REST API)

**How It Works:**
1. User sends message
2. WebLLM processes with tool definitions
3. If LLM requests tools, execute via AJAX
4. Add tool results to conversation
5. WebLLM continues with tool results
6. Repeat until complete

**Limitations:**
- Maximum 5 iterations per conversation turn
- Tools must be in assistant configuration
- Requires internet connection for tool execution (LLM still runs offline)
- Some tools may have permission restrictions

**Supported Tools:**
All WordPress plugin tools are supported:
- Content operations (search, create, update)
- Media operations (upload, resize, crop)
- WooCommerce operations (if installed)
- And 60+ more...
```

---

## WordPress Plugin Considerations

### Performance Optimizations

1. **Tool Result Caching**
   - Cache identical tool calls within same session
   - Reduces redundant API calls

2. **Batch Tool Execution**
   - If LLM requests multiple independent tools
   - Execute in parallel using `Promise.all()`

3. **Progressive Display**
   - Show tool execution progress
   - Display partial results as they complete

### Security Considerations

1. **Tool Permission Checks**
   - Verify user has capability for each tool
   - Respect assistant's tool configuration
   - Block sensitive tools for guest users

2. **Rate Limiting**
   - Limit tool executions per minute
   - Prevent abuse of expensive operations

3. **Input Validation**
   - Sanitize tool arguments
   - Validate against tool schema
   - Prevent injection attacks

### User Experience

1. **Visual Feedback**
   - Show which tools are executing
   - Display tool results inline
   - Indicate when LLM is "thinking"

2. **Error Messages**
   - User-friendly error explanations
   - Suggest alternatives when tools fail
   - Option to retry failed operations

3. **Transparency**
   - Show users when tools are being used
   - Allow users to approve sensitive operations
   - Provide tool execution history

---

## Implementation Timeline

| Phase | Estimated Time | Dependencies |
|-------|----------------|--------------|
| Phase 1: Tool Definitions | 2-4 hours | None |
| Phase 2: WebLLM Integration | 4-6 hours | Phase 1 |
| Phase 3: Tool Execution | 3-5 hours | Phase 2 |
| Phase 4: Iteration & Error Handling | 2-3 hours | Phase 3 |
| Phase 5: Testing & Documentation | 3-4 hours | Phase 4 |
| **Total** | **14-22 hours** | |

### Recommended Approach

**Week 1:** Phases 1-2 (Foundation)
- Get tool definitions flowing
- Basic WebLLM integration
- Minimal viable prototype

**Week 2:** Phases 3-4 (Core Features)
- Tool execution working
- Iteration support
- Error handling

**Week 3:** Phase 5 (Polish)
- Comprehensive testing
- Documentation
- User feedback

---

## Risks & Mitigations

### Risk 1: WebLLM API Compatibility
**Description:** WebLLM may not support tools in expected format
**Mitigation:** Check WebLLM documentation, test with simple examples first
**Fallback:** Transform tool format to match WebLLM's expectations

### Risk 2: Performance Issues
**Description:** Tool execution may slow down conversations
**Mitigation:** Show loading indicators, optimize REST API calls
**Fallback:** Add option to disable tools for embedded provider

### Risk 3: Context Window Limits
**Description:** Tool results may exceed model's context window
**Mitigation:** Truncate large tool results, summarize when possible
**Fallback:** Limit tool result size, warn users

### Risk 4: Browser Compatibility
**Description:** Not all browsers support WebGPU
**Mitigation:** Already handled by existing WebLLM checks
**Fallback:** Gracefully degrade to server-side providers

---

## Success Criteria

✅ **Functional Requirements:**
- [ ] Tools execute successfully for embedded provider
- [ ] Multi-step workflows work correctly
- [ ] Error handling prevents crashes
- [ ] Performance is acceptable (< 5s per tool)

✅ **User Experience Requirements:**
- [ ] Clear visual feedback during tool execution
- [ ] Helpful error messages
- [ ] Tool results displayed properly
- [ ] No confusion about client vs server execution

✅ **Technical Requirements:**
- [ ] Code follows WordPress coding standards
- [ ] Comprehensive error logging
- [ ] Security best practices followed
- [ ] Documentation complete

---

## Conclusion

This plan provides a **practical, phased approach** to adding tool support for the embedded provider. The hybrid architecture leverages the best of both worlds:
- Client-side LLM for privacy and offline capability
- Server-side tools for WordPress integration

The implementation is **feasible within a WordPress plugin context** and can be completed in 2-3 weeks of focused development.

**Next Steps:**
1. Review and approve this plan
2. Create GitHub issues for each phase
3. Begin Phase 1 implementation
4. Iterate based on testing feedback
