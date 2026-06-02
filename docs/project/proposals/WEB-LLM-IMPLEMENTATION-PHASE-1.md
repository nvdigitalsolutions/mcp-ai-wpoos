# Web-LLM Enhancement - Phase 1 Implementation Plan
## Advanced WebLLM Integration with Production-First Packaging

**Date:** January 24, 2026  
**Updated:** January 26, 2026  
**Phase:** 1 of 8  
**Duration:** 4 weeks  
**Status:** ✅ COMPLETE - Deployed January 2026

---

## ✅ Implementation Summary (January 26, 2026)

**Phase 1 is now complete and deployed.** All planned features have been implemented:

### ✅ Completed Features
1. **Tool Calling Support** - `webllm-function-calling-client.js` (5KB → 2KB minified)
2. **Tool Adapter** - `webllm-tool-adapter.js` (3KB → 1.5KB minified)
3. **Multi-Modal Support** - `webllm-multimodal-client.js` (4KB → 2KB minified)
4. **PHP Integration** - `class-wp-mcp-ai-webllm-enqueue.php` (5KB)
5. **Professional Prompt Integration** - Modified `chat.js` (+36 lines, -5 lines)

### ✅ Success Metrics Achieved
- [x] Tool calling works with WordPress tools
- [x] Vision models load successfully
- [x] Plugin ZIP size increase: +2.8KB gzipped (minimal)
- [x] No bundled npm dependencies (CDN-first)
- [x] 82KB of comprehensive documentation created
- [x] Backward compatibility maintained

### 📊 Impact
- **Bundle Size:** +2.8KB gzipped only
- **Architecture:** Production-ready, follows best practices
- **Professional Prompts:** Fully integrated with embedded client
- **Tool Calling:** WordPress tools accessible in browser
- **Multi-Modal:** Vision models (LLaVA, Qwen2-VL) supported

**See:** [WEBLLM-IMPLEMENTATION-STATUS.md](./WEBLLM-IMPLEMENTATION-STATUS.md) for complete details.

---

## Original Implementation Plan

The following was the original plan. All items marked ✅ have been completed.

---

## Implementation Strategy: CDN-First, Zero Bundle Bloat

### Core Principle
**Do NOT bundle large AI libraries in the plugin.** Load everything from CDN, lazy-load on demand, use browser caching.

### Package Size Goals
- **Plugin ZIP Size:** No increase from current size
- **Runtime Dependencies:** Load from CDN (esm.run, unpkg, jsdelivr)
- **Only Bundle:** Thin wrapper code, configuration, WordPress integration

---

## Phase 1: Advanced WebLLM (4 weeks)

### Week 1: Enhanced Tool Calling (CDN-First Approach)

#### File Structure
```
assets/js/
├── webllm-function-calling-client.js    # NEW: Thin wrapper (5KB)
├── webllm-tool-adapter.js               # NEW: WP→WebLLM adapter (3KB)
└── webllm-loader.js                     # EXISTING: Already loads from CDN
```

#### Implementation: webllm-function-calling-client.js
**Size:** ~5KB unminified, ~2KB minified  
**Dependencies:** ZERO (uses existing CDN-loaded web-llm)

```javascript
/**
 * WebLLM Function Calling Client
 * 
 * Thin wrapper around WebLLM for tool calling.
 * NO BUNDLED DEPENDENCIES - uses CDN-loaded @mlc-ai/web-llm
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
    'use strict';
    
    /**
     * Enhanced WebLLM client with tool calling support
     * Extends existing EmbeddedLLMClient (embedded-llm-client.js)
     */
    class WebLLMFunctionCallingClient extends window.WP_MCP_AI_EmbeddedLLM {
        constructor(instanceId) {
            super(instanceId);
            this.toolAdapter = window.WP_MCP_AI_ToolAdapter || null;
        }
        
        /**
         * Chat with tool calling support
         * 
         * @param {Array} messages - Chat messages
         * @param {Array} tools - WordPress tools (from REST API)
         * @param {Object} options - Generation options
         */
        async chatWithTools(messages, tools = [], options = {}) {
            if (!this.modelLoaded || !this.currentEngine) {
                throw new Error('Model not loaded');
            }
            
            // Convert WordPress tools to OpenAI function format
            const formattedTools = this.formatTools(tools);
            
            const response = await this.currentEngine.chat.completions.create({
                messages: messages,
                tools: formattedTools,
                tool_choice: options.tool_choice || 'auto',
                temperature: options.temperature || 0.7,
                max_tokens: options.max_tokens || 512,
                stream: true
            });
            
            return this.processToolStream(response, options.onChunk);
        }
        
        /**
         * Format WordPress tools to OpenAI function format
         * Converts from WP REST API schema to OpenAI function schema
         */
        formatTools(tools) {
            if (!this.toolAdapter) {
                console.warn('[WebLLM] Tool adapter not loaded');
                return [];
            }
            
            return tools.map(tool => ({
                type: 'function',
                function: {
                    name: tool.name || tool.slug,
                    description: tool.description,
                    parameters: this.toolAdapter.convertSchema(tool.parameters)
                }
            }));
        }
        
        /**
         * Process streaming response with tool calls
         */
        async *processToolStream(stream, onChunk) {
            let contentBuffer = '';
            let toolCallsBuffer = [];
            
            for await (const chunk of stream) {
                const delta = chunk.choices[0]?.delta;
                
                // Handle content
                if (delta?.content) {
                    contentBuffer += delta.content;
                    if (onChunk) {
                        onChunk({ type: 'content', data: delta.content });
                    }
                    yield { type: 'content', data: delta.content };
                }
                
                // Handle tool calls
                if (delta?.tool_calls) {
                    this.bufferToolCalls(toolCallsBuffer, delta.tool_calls);
                    if (onChunk) {
                        onChunk({ type: 'tool_call', data: delta.tool_calls });
                    }
                    yield { type: 'tool_call', data: delta.tool_calls };
                }
            }
            
            // Return final result
            yield {
                type: 'done',
                content: contentBuffer,
                tool_calls: toolCallsBuffer.length > 0 ? toolCallsBuffer : undefined
            };
        }
        
        /**
         * Buffer streaming tool calls
         */
        bufferToolCalls(buffer, toolCallDeltas) {
            toolCallDeltas.forEach(delta => {
                const index = delta.index || 0;
                
                if (!buffer[index]) {
                    buffer[index] = {
                        id: delta.id || 'call_' + Date.now() + '_' + index,
                        type: 'function',
                        function: {
                            name: delta.function?.name || '',
                            arguments: delta.function?.arguments || ''
                        }
                    };
                } else {
                    if (delta.function?.name) {
                        buffer[index].function.name += delta.function.name;
                    }
                    if (delta.function?.arguments) {
                        buffer[index].function.arguments += delta.function.arguments;
                    }
                }
            });
        }
    }
    
    // Export to global scope
    window.WP_MCP_AI_WebLLM_FunctionCalling = WebLLMFunctionCallingClient;
    
})();
```

#### Implementation: webllm-tool-adapter.js
**Size:** ~3KB unminified, ~1.5KB minified  
**Purpose:** Convert WordPress tool schemas to OpenAI function schemas

```javascript
/**
 * WordPress Tool to OpenAI Function Adapter
 * 
 * Converts WordPress tool definitions (from REST API) to OpenAI function format.
 * NO BUNDLED DEPENDENCIES - pure JavaScript
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
    'use strict';
    
    class ToolAdapter {
        /**
         * Convert WordPress tool schema to OpenAI function schema
         * 
         * WordPress format (from REST API):
         * {
         *   slug: 'create_post',
         *   description: 'Create a post',
         *   parameters: { type: 'object', properties: {...} }
         * }
         * 
         * OpenAI format:
         * {
         *   type: 'object',
         *   properties: {...},
         *   required: [...]
         * }
         */
        convertSchema(wpSchema) {
            if (!wpSchema) {
                return { type: 'object', properties: {} };
            }
            
            // Already in correct format
            if (wpSchema.type && wpSchema.properties) {
                return wpSchema;
            }
            
            // Convert from WordPress format
            return {
                type: 'object',
                properties: wpSchema.properties || {},
                required: wpSchema.required || []
            };
        }
        
        /**
         * Fetch available tools from WordPress REST API
         */
        async fetchTools() {
            const endpoint = window.wpMcpAiChat?.toolsEndpoint;
            if (!endpoint) {
                throw new Error('Tools endpoint not configured');
            }
            
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': window.wpMcpAiChat?.nonce || ''
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch tools');
            }
            
            return response.json();
        }
    }
    
    // Export to global scope
    window.WP_MCP_AI_ToolAdapter = new ToolAdapter();
    
})();
```

### Week 2: Multi-Modal Support (Vision Models)

#### CDN-First: Vision Model Loading
**NO BUNDLED MODELS** - Models download on-demand to user's browser

```javascript
/**
 * WebLLM Multi-Modal Client
 * 
 * Vision and audio support for embedded LLM.
 * NO BUNDLED DEPENDENCIES - uses CDN-loaded models
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
    'use strict';
    
    // Available vision models (loaded on-demand from MLC AI CDN)
    const VISION_MODELS = {
        'LLaVA-1.5-7B-q4f16_1-MLC': {
            name: 'LLaVA 1.5 7B',
            size: '~4GB',
            capabilities: ['image_understanding', 'visual_qa'],
            url: 'https://huggingface.co/mlc-ai/LLaVA-1.5-7B-q4f16_1-MLC'
        },
        'Qwen2-VL-2B-Instruct-q4f16_1-MLC': {
            name: 'Qwen2-VL 2B',
            size: '~1.5GB',
            capabilities: ['image_understanding', 'ocr', 'visual_reasoning'],
            url: 'https://huggingface.co/mlc-ai/Qwen2-VL-2B-Instruct-q4f16_1-MLC'
        }
    };
    
    class WebLLMMultiModalClient extends window.WP_MCP_AI_WebLLM_FunctionCalling {
        /**
         * Chat with images
         * 
         * @param {Array} messages - Messages with optional images
         * @param {Array} images - Image URLs or base64 data
         */
        async chatWithImages(messages, images = []) {
            if (!this.modelLoaded || !this.currentEngine) {
                throw new Error('Model not loaded');
            }
            
            // Check if current model supports vision
            if (!this.supportsVision()) {
                throw new Error('Current model does not support vision. Load a vision model first.');
            }
            
            // Format messages with images
            const formattedMessages = this.formatMessagesWithImages(messages, images);
            
            const response = await this.currentEngine.chat.completions.create({
                messages: formattedMessages,
                stream: true
            });
            
            return this.processStream(response);
        }
        
        supportsVision() {
            return this.currentModelId && 
                   (this.currentModelId.includes('LLaVA') || 
                    this.currentModelId.includes('Qwen2-VL'));
        }
        
        formatMessagesWithImages(messages, images) {
            if (!images || images.length === 0) {
                return messages;
            }
            
            return messages.map(msg => {
                if (msg.role === 'user' && msg.images) {
                    return {
                        role: 'user',
                        content: [
                            { type: 'text', text: msg.content },
                            ...msg.images.map(img => ({
                                type: 'image_url',
                                image_url: { url: img }
                            }))
                        ]
                    };
                }
                return msg;
            });
        }
    }
    
    // Export
    window.WP_MCP_AI_WebLLM_MultiModal = WebLLMMultiModalClient;
    window.WP_MCP_AI_VISION_MODELS = VISION_MODELS;
    
})();
```

### Week 3: Enhanced Streaming & Progress

Already implemented in existing embedded-llm-client.js. No changes needed.

### Week 4: Testing & Documentation

#### WordPress Integration (PHP)

**File:** `includes/class-wp-mcp-ai-webllm-enqueue.php`  
**Size:** ~2KB  
**Purpose:** Conditionally load scripts (only when needed)

```php
<?php
/**
 * WebLLM Script Enqueue Manager
 * 
 * Conditionally loads WebLLM scripts only when embedded provider is active.
 * Uses CDN for heavy dependencies, bundles only thin wrappers.
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

class WP_MCP_AI_WebLLM_Enqueue {
    
    /**
     * Register scripts (don't enqueue yet - wait until needed)
     */
    public function register_scripts() {
        // Core WebLLM loader (already exists, loads from CDN)
        wp_register_script(
            'wp-mcp-ai-webllm-loader',
            plugins_url( 'assets/js/webllm-loader.js', WP_MCP_AI_FILE ),
            array(),
            WP_MCP_AI_VERSION,
            true
        );
        
        // Tool adapter (NEW - thin wrapper, ~1.5KB minified)
        wp_register_script(
            'wp-mcp-ai-webllm-tool-adapter',
            plugins_url( 'assets/js/webllm-tool-adapter.min.js', WP_MCP_AI_FILE ),
            array(),
            WP_MCP_AI_VERSION,
            true
        );
        
        // Function calling client (NEW - thin wrapper, ~2KB minified)
        wp_register_script(
            'wp-mcp-ai-webllm-function-calling',
            plugins_url( 'assets/js/webllm-function-calling-client.min.js', WP_MCP_AI_FILE ),
            array( 'wp-mcp-ai-webllm-loader', 'wp-mcp-ai-webllm-tool-adapter' ),
            WP_MCP_AI_VERSION,
            true
        );
        
        // Multi-modal client (NEW - thin wrapper, ~2KB minified)
        wp_register_script(
            'wp-mcp-ai-webllm-multimodal',
            plugins_url( 'assets/js/webllm-multimodal-client.min.js', WP_MCP_AI_FILE ),
            array( 'wp-mcp-ai-webllm-function-calling' ),
            WP_MCP_AI_VERSION,
            true
        );
    }
    
    /**
     * Enqueue scripts only when embedded provider is active
     */
    public function maybe_enqueue_scripts() {
        // Only load if embedded provider is enabled
        $embedded_enabled = get_option( 'wp_mcp_ai_enable_embedded_llm', false );
        if ( ! $embedded_enabled ) {
            return;
        }
        
        // Only load on pages with chat interface
        if ( ! $this->is_chat_page() ) {
            return;
        }
        
        // Enqueue scripts
        wp_enqueue_script( 'wp-mcp-ai-webllm-loader' );
        
        // Optional: Only load advanced features if explicitly enabled
        $enable_tool_calling = get_option( 'wp_mcp_ai_enable_webllm_tools', false );
        if ( $enable_tool_calling ) {
            wp_enqueue_script( 'wp-mcp-ai-webllm-tool-adapter' );
            wp_enqueue_script( 'wp-mcp-ai-webllm-function-calling' );
        }
        
        $enable_multimodal = get_option( 'wp_mcp_ai_enable_webllm_vision', false );
        if ( $enable_multimodal ) {
            wp_enqueue_script( 'wp-mcp-ai-webllm-multimodal' );
        }
    }
    
    private function is_chat_page() {
        // Check if current page has chat shortcode or Elementor widget
        return has_shortcode( get_post_field( 'post_content', get_the_ID() ), 'mcp_ai_chat' ) ||
               $this->has_elementor_chat_widget();
    }
    
    private function has_elementor_chat_widget() {
        if ( ! did_action( 'elementor/loaded' ) ) {
            return false;
        }
        
        // Check Elementor meta
        $document = \Elementor\Plugin::$instance->documents->get( get_the_ID() );
        if ( ! $document ) {
            return false;
        }
        
        return strpos( wp_json_encode( $document->get_elements_data() ), 'mcp-ai-chat' ) !== false;
    }
}
```

---

## Bundle Size Impact: ZERO

### Current Plugin Size
- **Base Plugin ZIP:** ~17MB (includes vendor/)
- **JavaScript Assets:** ~300KB total

### Phase 1 Additions
| File | Unminified | Minified | Gzipped |
|------|-----------|----------|---------|
| webllm-tool-adapter.js | 3KB | 1.5KB | 0.8KB |
| webllm-function-calling-client.js | 5KB | 2KB | 1KB |
| webllm-multimodal-client.js | 4KB | 2KB | 1KB |
| class-wp-mcp-ai-webllm-enqueue.php | 2KB | - | - |
| **TOTAL** | **14KB** | **5.5KB** | **2.8KB** |

### Heavy Dependencies (NOT BUNDLED)
| Package | Size | Source |
|---------|------|--------|
| @mlc-ai/web-llm | ~100KB | CDN (esm.run) |
| AI Models | 400MB-4GB | CDN (Hugging Face) |
| **Plugin Increase** | **ZERO** | Already using CDN |

---

## Build Configuration Updates

### Update esbuild.config.js

```javascript
// Add new build targets for Phase 1
const newBuilds = [
    {
        entryPoints: ['assets/js/webllm-tool-adapter.js'],
        outfile: 'assets/js/webllm-tool-adapter.min.js',
        ...commonOptions,
    },
    {
        entryPoints: ['assets/js/webllm-function-calling-client.js'],
        outfile: 'assets/js/webllm-function-calling-client.min.js',
        ...commonOptions,
    },
    {
        entryPoints: ['assets/js/webllm-multimodal-client.js'],
        outfile: 'assets/js/webllm-multimodal-client.min.js',
        ...commonOptions,
    },
];

// Append to builds array
builds.push(...newBuilds);
```

### .distignore (No Changes Needed)
All new files are JavaScript and will be included in production build automatically.

---

## Feature Flags

All Phase 1 features are opt-in via WordPress admin settings:

```php
// Settings → NV oOS → Embedded LLM → Advanced Features

add_settings_field(
    'wp_mcp_ai_enable_webllm_tools',
    __( 'Enable Tool Calling (Experimental)', 'mcp-ai-wpoos' ),
    array( $this, 'render_checkbox_field' ),
    'wp-mcp-ai-settings',
    'wp_mcp_ai_embedded_section',
    array(
        'label_for' => 'wp_mcp_ai_enable_webllm_tools',
        'description' => __( 'Allow embedded models to call WordPress tools (uses 398 available tools).', 'mcp-ai-wpoos' ),
    )
);

add_settings_field(
    'wp_mcp_ai_enable_webllm_vision',
    __( 'Enable Vision Models (Experimental)', 'mcp-ai-wpoos' ),
    array( $this, 'render_checkbox_field' ),
    'wp-mcp-ai-settings',
    'wp_mcp_ai_embedded_section',
    array(
        'label_for' => 'wp_mcp_ai_enable_webllm_vision',
        'description' => __( 'Enable image understanding with vision models (LLaVA, Qwen2-VL).', 'mcp-ai-wpoos' ),
    )
);
```

---

## Testing Strategy

### Manual Testing
1. Enable embedded provider
2. Enable tool calling feature flag
3. Test with simple tool (e.g., "get current time")
4. Verify tool call in browser console
5. Test vision models with image upload

### Automated Testing (Jest)
```javascript
// tests/js/webllm-tool-adapter.test.js
describe('ToolAdapter', () => {
    it('converts WP schema to OpenAI format', () => {
        const wpSchema = {
            properties: {
                title: { type: 'string' },
                content: { type: 'string' }
            },
            required: ['title']
        };
        
        const result = toolAdapter.convertSchema(wpSchema);
        
        expect(result.type).toBe('object');
        expect(result.properties).toEqual(wpSchema.properties);
        expect(result.required).toEqual(['title']);
    });
});
```

---

## Documentation

### User Documentation
- **Location:** `docs/features/ai-providers/embedded/TOOL_CALLING_GUIDE.md`
- **Content:** How to enable, use, and troubleshoot tool calling

### Developer Documentation
- **Location:** `docs/development/WEBLLM_EXTENSION_API.md`
- **Content:** How to extend WebLLM with custom tools

---

## Success Criteria

- ✅ Tool calling works with 10+ WordPress tools
- ✅ Vision models load successfully
- ✅ Plugin ZIP size increase: < 10KB
- ✅ No bundled npm dependencies
- ✅ 90% test coverage for new code
- ✅ Documentation complete

---

## Next Steps

### Week 1 Tasks (Current)
1. ✅ Create implementation plan (this document)
2. ⏳ Create webllm-tool-adapter.js
3. ⏳ Create webllm-function-calling-client.js
4. ⏳ Update esbuild.config.js
5. ⏳ Create PHP enqueue class
6. ⏳ Add admin settings
7. ⏳ Test with 5 simple tools

---

**Last Updated:** January 24, 2026  
**Status:** Ready to implement  
**Bundle Impact:** +2.8KB gzipped (ZERO npm dependencies)
