# Function Calling Support Across AI Providers - Research Summary

**Date**: January 2026  
**Purpose**: Evaluate which AI providers support function calling/tool use to determine if `run_with_tools()` should be added to other clients.

---

## Executive Summary

**All major AI providers in this plugin support function calling/tool use.** Adding `run_with_tools()` to all clients would provide:
- ✅ Consistent developer experience across providers
- ✅ Shared validation and recursion logic
- ✅ Auto-trim functionality for all providers
- ✅ Unified error handling and logging

---

## Provider Capabilities

### 1. OpenAI ✅ Full Support

**Status**: Production-ready, extensive documentation

**API Format**:
- Uses `tools` parameter (not deprecated `functions`)
- Standard JSON Schema for parameters
- Multi-turn tool calling supported
- Streaming supported

**Key Features**:
- Built-in tools: web search, file search, code execution
- Custom tools via function schemas
- Assistant API with automatic tool execution
- OpenAI Agents SDK for advanced workflows

**Documentation**:
- [Official Function Calling Guide](https://platform.openai.com/docs/guides/function-calling)
- [Using Tools API](https://platform.openai.com/docs/guides/tools)

**Current Implementation in Plugin**:
```php
// Already supports tools in create_chat_completion()
if ( ! empty( $options['tools'] ) ) {
    $payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
}
```

**Recommendation**: ✅ Add `run_with_tools()` wrapper - logic already exists, just needs recursive execution loop.

---

### 2. Google Gemini ✅ Full Support

**Status**: Production-ready, well-documented

**API Format**:
- Uses `tools` parameter with OpenAPI-compliant schemas
- Native function calling via Vertex AI and Gemini API
- Supports both Python SDK and REST API

**Key Features**:
- Built-in tools: Google Search, Code Execution (Python)
- User-defined custom functions
- Live API with real-time tool sessions
- Multi-turn and parallel tool calling
- Supports Gemini 2.0+, 2.5 Flash, 3 Pro Preview

**Documentation**:
- [Gemini Function Calling Guide](https://ai.google.dev/gemini-api/docs/function-calling)
- [Vertex AI Function Calling](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/function-calling)

**Current Implementation in Plugin**:
```php
// Already supports tools via translate_tools()
if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
    $translated_tools = $this->translate_tools( $options['tools'] );
    if ( ! empty( $translated_tools ) ) {
        $payload['tools'] = $translated_tools;
    }
}
```

**Recommendation**: ✅ Add `run_with_tools()` wrapper - translation logic exists, needs recursive loop.

---

### 3. Anthropic Claude ✅ Full Support

**Status**: Production-ready, called "tool use" instead of "function calling"

**API Format**:
- Uses `tools` parameter with JSON Schema
- Returns `stop_reason: "tool_use"` when tool needed
- Tool blocks in `content` array
- Supports tool_result messages

**Key Features**:
- Advanced tool use with Claude 4.5+
- Batch and multi-turn tool calls
- Programmatic tool calling (Claude can write code to orchestrate tools)
- Strict schema validation mode
- Available on AWS Bedrock and Google Vertex AI

**Documentation**:
- [Claude Tool Use Overview](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)
- [Advanced Tool Use](https://www.anthropic.com/engineering/advanced-tool-use)

**Current Implementation in Plugin**:
```php
// Has tool_use conversion but not full tools support
// Converts Anthropic tool_use blocks to OpenAI-style tool calls
protected function convert_anthropic_tool_use_to_tool_call( array $tool_use )
```

**Recommendation**: ⚠️ Add tools parameter support first, then `run_with_tools()` wrapper. Needs additional work for Anthropic's different format.

---

### 4. Ollama ✅ Full Support

**Status**: Production-ready for supported models

**API Format**:
- OpenAI-compatible API format
- Uses `tools` parameter with JSON Schema
- Returns `tool_calls` structure
- Supports single and parallel tool calling

**Key Features**:
- Runs locally (privacy, no cloud needed)
- Models with support: Llama 3.1, Mistral, Granite, etc.
- Python library with automatic schema generation
- JavaScript/Node.js support
- Can use OpenAI Python library with `base_url` override

**Documentation**:
- [Ollama Tool Calling Docs](https://docs.ollama.com/capabilities/tool-calling)
- [Ollama Blog: Tool Support](https://ollama.com/blog/tool-support)

**Current Implementation in Plugin**:
- Basic chat completion support
- No explicit tools parameter handling visible

**Recommendation**: ✅ Add tools parameter support and `run_with_tools()` wrapper. Check if current implementation passes through tools.

---

### 5. LM Studio ✅ Full Support

**Status**: Production-ready, OpenAI-compatible

**API Format**:
- OpenAI-compatible `/v1/chat/completions` endpoint
- Uses `tools` parameter with JSON Schema
- Supports Model Context Protocol (MCP) for external tool servers

**Key Features**:
- OpenAI-style function calling API
- MCP server support (v0.3.17+)
- Agent frameworks (smolagents)
- Local execution (privacy)
- Model-dependent support (need compatible models)

**Documentation**:
- [LM Studio Tool Use Docs](https://lmstudio.ai/docs/developer/openai-compat/tools)
- [MCP Servers in LM Studio](https://apidog.com/blog/lmstudio-mcp-server/)

**Current Implementation in Plugin**:
- Basic chat completion support
- Follows OpenAI-compatible format

**Recommendation**: ✅ Add tools parameter support and `run_with_tools()` wrapper. Should work with minimal changes due to OpenAI compatibility.

---

### 6. Hugging Face ✅ Partial Support

**Status**: Growing support, model-dependent

**API Format**:
- Uses `tools` parameter
- Supports `tool_choice` parameter
- Returns `tool_calls` in response
- OpenAI-compatible interface available

**Key Features**:
- Function calling via Inference API
- Not all models support it (need to check per-model)
- Works with llama, mixtral chat models
- Used by n8n for automation

**Documentation**:
- [HF Function Calling Guide](https://huggingface.co/docs/inference-providers/en/guides/function-calling)

**Current Implementation in Plugin**:
- Basic inference support
- No explicit tools handling visible

**Recommendation**: ⚠️ Add tools parameter support cautiously. Verify model compatibility. Then add `run_with_tools()` for compatible models.

---

### 7. Cloudflare Workers AI ✅ Full Support

**Status**: Implemented in this PR

**API Format**:
- Uses `tools` parameter with JSON Schema
- Returns `tool_calls` in response
- Supports multi-turn conversations

**Key Features**:
- Already has `run_with_tools()` method
- Includes validation, auto-trim, recursive execution
- 6 configuration options
- Full error handling and logging

**Current Implementation**:
✅ **COMPLETE** - This PR implements full support

---

## Implementation Priority

### High Priority (OpenAI-Compatible)
1. **OpenAI Client** - Ready to implement, just needs wrapper
2. **LM Studio Client** - OpenAI-compatible, minimal changes
3. **Ollama Client** - OpenAI-compatible, needs tools parameter pass-through

### Medium Priority (Custom Format)
4. **Gemini Client** - Has translation logic, needs wrapper
5. **Anthropic Client** - Different format, needs more work

### Lower Priority (Model-Dependent)
6. **Hugging Face Client** - Model support varies, needs careful implementation

---

## Shared Implementation Strategy

### Extract Common Logic into Trait/Base Class

```php
trait WP_MCP_AI_Tool_Execution_Trait {
    /**
     * Run with tools - recursive execution loop
     */
    public function run_with_tools( $messages, $tools, $options ) {
        // Common logic here
        // - Tool validation
        // - Auto-trim
        // - Recursive loop
        // - Error handling
    }
    
    /**
     * Validate tool arguments - shared validation
     */
    protected function validate_tool_arguments( $function_name, $arguments, $definitions ) {
        // JSON Schema validation
    }
    
    /**
     * Auto-trim tools - relevance scoring
     */
    protected function auto_trim_tools( $messages, $tools, $options ) {
        // Keyword matching and scoring
    }
    
    /**
     * Provider-specific tool format conversion (abstract)
     */
    abstract protected function format_tools_for_provider( $tools );
    
    /**
     * Provider-specific tool call extraction (abstract)
     */
    abstract protected function extract_tool_calls_from_response( $response );
}
```

### Benefits of Shared Implementation
- ✅ Consistent behavior across all providers
- ✅ Single source of truth for validation logic
- ✅ Easier to maintain and update
- ✅ Reduced code duplication
- ✅ Unified testing

---

## Configuration Consistency

All clients should support the same options:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `strictValidation` | bool | `true` | Validate arguments before execution |
| `maxRecursiveToolRuns` | int | `5` | Maximum recursion depth |
| `streamFinalResponse` | bool | `false` | Enable streaming (where supported) |
| `verbose` | bool | `false` | Detailed logging |
| `autoTrimTools` | bool | `false` | Context-based tool selection |
| `maxTools` | int | `10` | Max tools when trimming |

---

## Testing Strategy

### Shared Tests
- Tool validation (required params, type checking)
- Auto-trim algorithm
- Recursion limits
- Error handling
- Configuration options

### Provider-Specific Tests
- Tool format conversion
- API response parsing
- Tool call extraction
- Provider-specific error codes

---

## Migration Path

### Phase 1: OpenAI-Compatible Clients
1. Add tools parameter pass-through to Ollama and LM Studio
2. Implement `run_with_tools()` for OpenAI client
3. Test thoroughly
4. Deploy

### Phase 2: Extract Common Code
1. Create trait with shared logic
2. Refactor Cloudflare client to use trait
3. Refactor OpenAI client to use trait
4. Update tests

### Phase 3: Remaining Clients
1. Add `run_with_tools()` to Gemini (has translation)
2. Add tools support + `run_with_tools()` to Anthropic
3. Add tools support + `run_with_tools()` to Hugging Face (with model checks)

---

## Risks and Considerations

### Model Compatibility
- Not all models support function calling
- Need to handle gracefully when model doesn't support it
- Return clear error messages

### API Differences
- Each provider has slight format differences
- Need robust conversion/translation layers
- Test edge cases thoroughly

### Performance
- Recursive calls can be expensive
- Monitor token usage
- Implement timeouts and limits

### Breaking Changes
- Existing code using `create_chat_completion()` should not break
- `run_with_tools()` should be additive, not replacing existing methods

---

## Recommendations

### Immediate Next Steps
1. ✅ **Reply to comment** with research findings
2. Create new issue for implementing across all clients
3. Start with OpenAI client (easiest, most used)
4. Document patterns for contributors

### Long-Term Strategy
1. Extract common code into trait
2. Implement for all clients following priority order
3. Create comprehensive test suite
4. Update documentation with examples for each provider
5. Consider creating unified `AI_Client` interface

---

## Conclusion

**YES, it makes complete sense to add `run_with_tools()` to other clients.**

All major providers support function calling with similar patterns. A shared implementation approach would:
- Reduce development time
- Ensure consistency
- Improve maintainability
- Provide better developer experience
- Enable advanced agentic workflows across all providers

This should be pursued as a follow-up enhancement to this PR.

---

## References

### Official Documentation Links

**OpenAI**
- https://platform.openai.com/docs/guides/function-calling
- https://platform.openai.com/docs/guides/tools

**Google Gemini**
- https://ai.google.dev/gemini-api/docs/function-calling
- https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/function-calling

**Anthropic Claude**
- https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview
- https://www.anthropic.com/engineering/advanced-tool-use

**Ollama**
- https://docs.ollama.com/capabilities/tool-calling
- https://ollama.com/blog/tool-support

**LM Studio**
- https://lmstudio.ai/docs/developer/openai-compat/tools

**Hugging Face**
- https://huggingface.co/docs/inference-providers/en/guides/function-calling

**Cloudflare Workers AI**
- https://developers.cloudflare.com/workers-ai/features/function-calling/embedded/
- https://www.npmjs.com/package/@cloudflare/ai-utils
