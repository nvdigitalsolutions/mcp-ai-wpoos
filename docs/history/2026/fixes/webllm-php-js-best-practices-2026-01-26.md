# WebLLM PHP-JS Best Practices for WordPress Plugins

**Date:** January 26, 2026  
**Plugin:** NV oOS (Open Operator System)  
**Version:** 1.2.0+  
**Context:** Embedded LLM provider with WebLLM integration

---

## Executive Summary

This document outlines the best practices implemented in the NV oOS plugin for integrating WebLLM (browser-based LLM inference) in a WordPress environment. It covers the PHP-JS architecture, data flow patterns, security considerations, and performance optimizations.

### Key Achievements
✅ Zero server load for embedded inference  
✅ Complete data privacy (no data leaves browser)  
✅ Multi-instance support (multiple chat widgets per page)  
✅ Conditional script loading (only when needed)  
✅ Proper separation of concerns (PHP config → JS execution)  
✅ Event-driven async coordination  
✅ Professional prompt integration (added 2026-01-26)

---

## Architecture Overview

### High-Level Flow

```
WordPress Backend (PHP)
    ↓
    1. Shortcode/Widget Rendering
    2. Config Generation (JSON)
    3. Script Enqueuing (Conditional)
    ↓
Browser (JavaScript)
    ↓
    4. WebLLM Library Loading (CDN)
    5. Client Initialization (Per Widget)
    6. Model Loading (Progressive)
    7. Inference Execution (Local)
```

### Component Layers

```
Layer 1: PHP Configuration Layer
├── WP_MCP_AI_Shortcode::render()
├── WP_MCP_AI_WebLLM_Enqueue::maybe_enqueue_scripts()
└── Assistant CPT (system prompt, tools, knowledge)

Layer 2: Script Loading Layer
├── webllm-loader.js (CDN import, events)
├── embedded-llm-client.js (core client, 27KB)
├── webllm-function-calling-client.js (tool support, 5KB)
└── webllm-tool-adapter.js (WordPress tool bridge, 2KB)

Layer 3: Chat UI Layer
├── chat.js (main chat interface)
├── State management
├── Message building
└── Embedded client coordination

Layer 4: WebLLM Runtime
├── Model download (CDN → IndexedDB)
├── WebGPU initialization
├── Streaming inference
└── Tool execution (via WordPress REST API)
```

---

## Best Practice #1: Conditional Script Loading

### ❌ Anti-Pattern: Always Load Everything
```php
// DON'T DO THIS - loads WebLLM on every page
function bad_enqueue() {
    wp_enqueue_script('webllm-library', 'https://cdn.../webllm.js');
    wp_enqueue_script('my-client', 'client.js', array('webllm-library'));
}
add_action('wp_enqueue_scripts', 'bad_enqueue');
```

**Problems:**
- Wastes bandwidth on pages without chat
- Increases page load time unnecessarily
- May cause conflicts with other scripts

### ✅ Best Practice: Conditional + Lazy Loading
```php
/**
 * Only enqueue when embedded provider is active AND page has chat widget
 */
class WP_MCP_AI_WebLLM_Enqueue {
    public static function maybe_enqueue_scripts() {
        // Check 1: Is embedded provider enabled globally?
        $embedded_enabled = get_option('wp_mcp_ai_enable_embedded_llm', false);
        if (!$embedded_enabled) {
            return;
        }
        
        // Check 2: Does this page have a chat interface?
        if (!self::is_chat_page()) {
            return;
        }
        
        // Check 3: Load base scripts
        wp_enqueue_script('webllm-loader');
        wp_enqueue_script('wp-mcp-ai-embedded-llm-client');
        
        // Check 4: Load enhancements only if needed
        if (get_option('wp_mcp_ai_enable_webllm_tools', false)) {
            wp_enqueue_script('wp-mcp-ai-webllm-tool-adapter');
            wp_enqueue_script('wp-mcp-ai-webllm-function-calling');
        }
    }
    
    private static function is_chat_page() {
        global $post;
        
        if (!$post) {
            return false;
        }
        
        // Check for shortcode
        if (has_shortcode($post->post_content, 'mcp_ai_chat')) {
            return true;
        }
        
        // Check for Elementor widget (if using Elementor)
        if (self::has_elementor_chat_widget()) {
            return true;
        }
        
        return false;
    }
}
```

**Benefits:**
- ✅ Loads scripts only when needed
- ✅ Reduces page weight on non-chat pages
- ✅ Better performance metrics
- ✅ Respects user preferences (feature flags)

---

## Best Practice #2: CDN vs. Bundling Strategy

### Decision Matrix

| Component | Strategy | Reason | Size |
|-----------|----------|--------|------|
| `@mlc-ai/web-llm` | **CDN** (`esm.run`) | Large library (>100KB), frequent updates | ~150KB |
| `webllm-loader.js` | **Bundle** | Tiny wrapper, rarely changes | 2.5KB |
| `embedded-llm-client.js` | **Bundle** | Plugin-specific logic | 27KB |
| `webllm-function-calling-client.js` | **Bundle** | Thin wrapper, extends client | 5KB |
| `webllm-tool-adapter.js` | **Bundle** | WordPress-specific | 2KB |

### ✅ Implemented Strategy
```javascript
// webllm-loader.js - Loads heavy library from CDN
import('https://esm.run/@mlc-ai/web-llm')
    .then(function(webLLM) {
        window.webLLM = webLLM;
        window.dispatchEvent(new Event('webllm-ready'));
    })
    .catch(function(error) {
        console.error('[NV oOS] WebLLM load failed:', error);
        window.dispatchEvent(new CustomEvent('webllm-error', {
            detail: { error: error }
        }));
    });
```

**Why This Works:**
- ✅ CDN handles caching and delivery optimization
- ✅ Reduces plugin bundle size by ~150KB
- ✅ Browser caches CDN assets across sites
- ✅ Easy to update library version (change URL)
- ✅ Thin wrappers remain versioned with plugin

### Bundle Size Comparison
```
Without CDN strategy:
├── Plugin bundle: 190KB (includes WebLLM)
└── Total download: 190KB per site

With CDN strategy:
├── Plugin bundle: 40KB (wrappers only)
├── WebLLM CDN: 150KB (cached across sites)
└── Total first visit: 190KB
└── Total repeat visit: 40KB ✅ 75% reduction
```

---

## Best Practice #3: Async Module Loading with Events

### ❌ Anti-Pattern: Synchronous Assumptions
```javascript
// DON'T DO THIS - assumes webLLM is already loaded
function createClient() {
    // CRASH! webLLM may not be loaded yet
    const engine = window.webLLM.CreateMLCEngine('model-id');
}
```

### ✅ Best Practice: Event-Driven Async
```javascript
// Step 1: Loader script dispatches events
// webllm-loader.js
import('https://esm.run/@mlc-ai/web-llm')
    .then(function(webLLM) {
        window.webLLM = webLLM;
        window.dispatchEvent(new Event('webllm-ready'));
    })
    .catch(function(error) {
        window.dispatchEvent(new CustomEvent('webllm-error', {
            detail: { error: error }
        }));
    });

// Step 2: Client waits for ready event
// embedded-llm-client.js
function waitForWebLLM() {
    return new Promise(function(resolve, reject) {
        // Already loaded?
        if (window.webLLM) {
            resolve(window.webLLM);
            return;
        }
        
        // Wait for ready event
        function onReady() {
            clearTimeout(timeoutId);
            window.removeEventListener('webllm-ready', onReady);
            window.removeEventListener('webllm-error', onError);
            resolve(window.webLLM);
        }
        
        function onError(event) {
            clearTimeout(timeoutId);
            window.removeEventListener('webllm-ready', onReady);
            window.removeEventListener('webllm-error', onError);
            reject(event.detail.error);
        }
        
        window.addEventListener('webllm-ready', onReady);
        window.addEventListener('webllm-error', onError);
        
        // Timeout after 30 seconds
        var timeoutId = setTimeout(function() {
            window.removeEventListener('webllm-ready', onReady);
            window.removeEventListener('webllm-error', onError);
            reject(new Error('Timeout waiting for WebLLM'));
        }, 30000);
    });
}

// Step 3: Use the promise
async function loadModel(modelId) {
    await waitForWebLLM(); // Waits for WebLLM to be ready
    const engine = await window.webLLM.CreateMLCEngine(modelId);
    return engine;
}
```

**Benefits:**
- ✅ No race conditions
- ✅ Graceful error handling
- ✅ Works with any load order
- ✅ Clear timeout boundaries

---

## Best Practice #4: PHP-to-JS Data Flow

### Configuration Transfer Pattern

```php
// PHP Side: Generate configuration
public static function render($atts = array()) {
    // Build complete configuration object
    $config = array(
        'assistantId' => $assistant_id,
        'provider' => 'embedded',
        'model' => $assistant_model,
        
        // CRITICAL: System prompt composition
        'systemPrompt' => $assistant_config['system_prompt'],
        'professionalPrompt' => $professional_prompt, // NEW: Role-based prompt
        
        // Knowledge base
        'memoryFiles' => $assistant_config['memory_files'] ?? array(),
        'vectorStoreId' => $assistant_config['vector_store_id'] ?? null,
        
        // Tools
        'tools' => $assistant_tools ?? array(),
        
        // Endpoints
        'restUrl' => rest_url(WP_MCP_AI_REST::REST_NAMESPACE),
        'toolsEndpoint' => rest_url(WP_MCP_AI_REST::REST_NAMESPACE . '/tools'),
        
        // Auth
        'restNonce' => wp_create_nonce('wp_rest'),
    );
    
    // Pass config to JavaScript
    wp_localize_script('wp-mcp-ai-chat', 'wpMcpAiChat' . $instance_id, $config);
}
```

```javascript
// JavaScript Side: Consume configuration
function initEmbeddedClient(state) {
    // Combine system prompt + professional prompt
    var completeSystemPrompt = state.config.systemPrompt || '';
    if (state.config.professionalPrompt) {
        if (completeSystemPrompt) {
            completeSystemPrompt = completeSystemPrompt + '\n\n' + state.config.professionalPrompt;
        } else {
            completeSystemPrompt = state.config.professionalPrompt;
        }
    }
    
    // Create client with complete configuration
    const assistantConfig = {
        systemPrompt: completeSystemPrompt,
        tools: state.config.tools || [],
        memoryFiles: state.config.memoryFiles || [],
        vectorStoreId: state.config.vectorStoreId
    };
    
    state.embeddedClient = new window.WP_MCP_AI_EmbeddedLLM(instanceId, assistantConfig);
}
```

### Key Principles

1. **PHP Builds Complete Config**: All data fetched and validated in PHP
2. **JS Receives Ready-to-Use Data**: No additional API calls needed for config
3. **Separation of Concerns**: PHP handles WordPress data access, JS handles UI/inference
4. **Type Safety**: Use TypeScript-style JSDoc for config structure

```javascript
/**
 * @typedef {Object} AssistantConfig
 * @property {string} systemPrompt - Combined system + professional prompt
 * @property {Array<Object>} tools - Available tools in OpenAI format
 * @property {Array<string>} memoryFiles - Knowledge base file IDs
 * @property {string|null} vectorStoreId - Vector store identifier
 */

/**
 * @param {string} instanceId
 * @param {AssistantConfig} config
 */
function EmbeddedLLMClient(instanceId, config) {
    this.systemPrompt = config.systemPrompt;
    this.tools = config.tools || [];
    // ...
}
```

---

## Best Practice #5: Instance-Based Architecture

### Why Multi-Instance Support Matters

Users may have multiple chat widgets on the same page:
- Different assistants in tabs
- Sidebar assistant + main content assistant
- Multiple specialized assistants for different topics

### ❌ Anti-Pattern: Singleton/Global State
```javascript
// DON'T DO THIS - breaks with multiple widgets
var globalEngine = null;

function loadModel(modelId) {
    globalEngine = await webLLM.CreateMLCEngine(modelId);
}

function chat(message) {
    // Which widget is this for? Can't tell!
    globalEngine.chat.completions.create({messages: [message]});
}
```

### ✅ Best Practice: Instance-Based Design
```javascript
class EmbeddedLLMClient {
    constructor(instanceId, config) {
        this.instanceId = instanceId; // Unique per widget
        this.currentEngine = null;
        this.systemPrompt = config.systemPrompt;
        this.tools = config.tools || [];
        this.memoryFiles = config.memoryFiles || [];
        
        console.log('[NV oOS] Created client instance:', this.instanceId);
    }
    
    async loadModel(modelId, progressCallback) {
        console.log('[NV oOS] Loading model for instance:', this.instanceId);
        
        // Create engine specific to this instance
        this.currentEngine = await webLLM.CreateMLCEngine(modelId, {
            initProgressCallback: progressCallback
        });
        
        // Initialize with this instance's configuration
        await this.initializeModelContext();
    }
    
    async generateCompletion(messages) {
        if (!this.currentEngine) {
            throw new Error('No model loaded for instance: ' + this.instanceId);
        }
        
        return this.currentEngine.chat.completions.create({
            messages: messages,
            stream: true
        });
    }
}

// Usage: Create separate instances for each widget
const chatInstance1 = new EmbeddedLLMClient('chat-assistant-123', config1);
const chatInstance2 = new EmbeddedLLMClient('chat-assistant-456', config2);

// Each operates independently
await chatInstance1.loadModel('Llama-3.2-1B-Instruct-q4f16_1-MLC');
await chatInstance2.loadModel('Qwen2.5-1.5B-Instruct-q4f16_1-MLC');
```

**Benefits:**
- ✅ Multiple widgets work simultaneously
- ✅ Clear instance ownership
- ✅ Isolated state per widget
- ✅ Easy debugging (instance IDs in logs)

---

## Best Practice #6: Professional Prompt Integration

### The Problem
WordPress assistants can have multiple prompt components:
1. **System Prompt**: Base instructions from assistant settings
2. **Professional Prompt**: Role-specific instructions from profession taxonomy
3. **Knowledge Context**: Information about available memory files

These must be combined correctly for embedded clients.

### ✅ Implementation

#### PHP Side: Provide All Components
```php
// includes/class-wp-mcp-ai-shortcode.php
$config = array(
    'systemPrompt' => $assistant_config['system_prompt'],
    'professionalPrompt' => $professional_prompt, // From profession taxonomy
    'memoryFiles' => $assistant_config['memory_files'],
    'vectorStoreId' => $assistant_config['vector_store_id']
);
```

#### JavaScript Side: Combine Components
```javascript
// assets/js/chat.js

// Step 1: Combine for client initialization
function initEmbeddedClient(state) {
    var completeSystemPrompt = state.config.systemPrompt || '';
    
    // Add professional prompt
    if (state.config.professionalPrompt) {
        if (completeSystemPrompt) {
            completeSystemPrompt = completeSystemPrompt + '\n\n' + state.config.professionalPrompt;
        } else {
            completeSystemPrompt = state.config.professionalPrompt;
        }
        console.log('[NV oOS] Combined with professional prompt');
    }
    
    const assistantConfig = {
        systemPrompt: completeSystemPrompt,
        tools: state.config.tools || [],
        memoryFiles: state.config.memoryFiles || [],
        vectorStoreId: state.config.vectorStoreId
    };
    
    state.embeddedClient = new window.WP_MCP_AI_EmbeddedLLM(instanceId, assistantConfig);
}

// Step 2: Combine for each message (embedded-llm-client.js)
async initializeModelContext() {
    if (!this.systemPrompt) {
        return;
    }
    
    var systemPromptContent = this.systemPrompt; // Already includes professional prompt
    
    // Add knowledge context
    if (this.memoryFiles && this.memoryFiles.length > 0) {
        var knowledgeContext = '\n\n## Base Knowledge\n\n';
        knowledgeContext += 'You have access to ' + this.memoryFiles.length + ' files\n';
        systemPromptContent += knowledgeContext;
    }
    
    // Send initialization message
    await this.currentEngine.chat.completions.create({
        messages: [
            { role: 'system', content: systemPromptContent },
            { role: 'user', content: 'Understood. I am ready to assist.' }
        ],
        temperature: 0.3,
        max_tokens: 50,
        stream: false
    });
}
```

### Message Building Pattern
```javascript
function generateEmbeddedCompletion(state, embeddedClient, messages) {
    // Build complete system prompt for this conversation
    if ((state.config.systemPrompt || state.config.professionalPrompt) && 
        !messages.some(msg => msg.role === 'system')) {
        
        var systemPromptContent = state.config.systemPrompt || '';
        
        // Add professional prompt
        if (state.config.professionalPrompt) {
            systemPromptContent = systemPromptContent ? 
                systemPromptContent + '\n\n' + state.config.professionalPrompt :
                state.config.professionalPrompt;
        }
        
        // Add knowledge context
        if (state.config.memoryFiles && state.config.memoryFiles.length > 0) {
            systemPromptContent += '\n\n## Base Knowledge\n\n';
            systemPromptContent += 'You have access to ' + state.config.memoryFiles.length + ' files\n';
        }
        
        // Prepend to messages
        messages.unshift({
            role: 'system',
            content: systemPromptContent
        });
    }
    
    return embeddedClient.generateStreamingCompletion(messages, options, onChunk);
}
```

---

## Best Practice #7: Security Considerations

### 1. Nonce Validation for REST API
```php
// PHP: Generate nonce
$config['restNonce'] = wp_create_nonce('wp_rest');

// JavaScript: Send with requests
fetch(endpoint, {
    headers: {
        'X-WP-Nonce': config.restNonce
    }
});
```

### 2. Sanitize Configuration Data
```php
$config = array(
    'assistantId' => absint($assistant_id),
    'systemPrompt' => wp_kses_post($system_prompt), // Allow safe HTML
    'professionalPrompt' => wp_kses_post($professional_prompt),
    'restUrl' => esc_url_raw($rest_url),
);
```

### 3. Capability Checks
```php
// Only enqueue if user has permission
if (!current_user_can($required_capability)) {
    return;
}
```

### 4. Client-Side Validation
```javascript
// Validate configuration before use
function validateConfig(config) {
    if (!config.assistantId) {
        throw new Error('Missing assistant ID');
    }
    
    if (config.tools && !Array.isArray(config.tools)) {
        throw new Error('Tools must be an array');
    }
    
    return true;
}
```

---

## Best Practice #8: Error Handling

### Categorized Error Messages
```javascript
function categorizeError(error) {
    const errorMessage = error.message || error.toString();
    
    const errorCategories = {
        MEMORY_ERROR: {
            message: 'Low memory. Try a smaller model.',
            action: 'Switch Model',
            recoverable: true
        },
        GPU_UNSUPPORTED: {
            message: 'WebGPU not supported. Update browser.',
            action: 'Learn More',
            recoverable: false
        },
        NETWORK_ERROR: {
            message: 'Download failed. Check connection.',
            action: 'Retry',
            recoverable: true
        }
    };
    
    if (/memory|OOM/i.test(errorMessage)) {
        return errorCategories.MEMORY_ERROR;
    }
    
    if (/gpu|webgpu|adapter/i.test(errorMessage)) {
        return errorCategories.GPU_UNSUPPORTED;
    }
    
    if (/network|fetch|download/i.test(errorMessage)) {
        return errorCategories.NETWORK_ERROR;
    }
    
    return errorCategories.MODEL_LOAD_ERROR;
}
```

---

## Best Practice #9: Performance Optimization

### 1. Model Recommendation Based on Device
```javascript
function checkModelSuitability(modelId) {
    const model = AVAILABLE_MODELS[modelId];
    const deviceMemoryGB = navigator.deviceMemory || 4;
    const isMobile = /Mobi|Android/i.test(navigator.userAgent);
    
    const sizeInMB = parseModelSize(model.size);
    const threshold = isMobile ? 0.15 : 0.25; // Mobile = 15%, Desktop = 25%
    const maxRecommendedSizeMB = (deviceMemoryGB * 1024) * threshold;
    
    if (sizeInMB > maxRecommendedSizeMB) {
        return {
            suitable: false,
            warning: `Model too large for ${deviceMemoryGB}GB device`,
            suggestedModel: findSmallerModel(maxRecommendedSizeMB)
        };
    }
    
    return { suitable: true };
}
```

### 2. Lazy Model Loading
```javascript
// Don't load model until user sends first message
async function sendChatEmbedded(state, userMessage) {
    if (!state.embeddedClient.isModelLoaded()) {
        // Load model only when needed
        await state.embeddedClient.loadModel(modelId, showProgress);
    }
    
    return generateCompletion(messages);
}
```

### 3. Streaming for Perceived Performance
```javascript
async function generateStreamingCompletion(messages, options, onChunk) {
    const stream = await this.currentEngine.chat.completions.create({
        messages: messages,
        stream: true
    });
    
    for await (const chunk of stream) {
        const delta = chunk.choices[0]?.delta?.content || '';
        if (delta && onChunk) {
            // Show tokens immediately as they arrive
            onChunk({ content: delta, done: false });
        }
    }
}
```

---

## Best Practice #10: Logging & Debugging

### Structured Logging
```javascript
// Always include instance ID and context
console.log('[NV oOS Embedded Client] Model loaded:', {
    instanceId: this.instanceId,
    modelId: modelId,
    hasSystemPrompt: !!this.systemPrompt,
    hasTools: this.tools.length > 0,
    hasKnowledge: this.memoryFiles.length > 0
});

console.log('[NV oOS Embedded Client] Initializing model context:', {
    instanceId: this.instanceId,
    systemPromptLength: this.systemPrompt.length,
    professionalPromptIncluded: this.systemPrompt.includes('profession'),
    memoryFileCount: this.memoryFiles.length
});
```

### Debug Mode
```php
// PHP: Enable verbose logging
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[NV oOS] Embedded client config: ' . wp_json_encode($config));
}

// JavaScript: Conditional verbose logging
if (window.wpMcpAiDebug) {
    console.log('[NV oOS] Full message array:', messages);
    console.log('[NV oOS] Complete system prompt:', systemPromptContent);
}
```

---

## Implementation Checklist

### PHP Side
- [x] Conditional script enqueuing (only when needed)
- [x] Feature flags for optional enhancements
- [x] Complete configuration object generation
- [x] Professional prompt integration
- [x] Security: nonce, sanitization, capability checks
- [x] Shortcode detection for conditional loading
- [x] Elementor widget detection

### JavaScript Side
- [x] Event-driven async loading
- [x] Instance-based architecture
- [x] Proper error categorization
- [x] Device capability detection
- [x] Streaming response handling
- [x] Tool calling support
- [x] Professional prompt combination
- [x] Knowledge context integration
- [x] Comprehensive logging

### Documentation
- [x] Architecture overview
- [x] Best practices guide
- [x] Security considerations
- [x] Performance optimization notes
- [ ] User testing guide
- [ ] Troubleshooting guide

---

## Testing Scenarios

### 1. Single Widget, No Professional Prompt
```php
[mcp_ai_chat assistant="123"]
```
**Expected:** System prompt + knowledge context only

### 2. Single Widget, With Professional Prompt
```php
[mcp_ai_chat profession="456"]
```
**Expected:** Professional prompt + knowledge context

### 3. Single Widget, Both Prompts
```php
[mcp_ai_chat assistant="123" profession="456"]
```
**Expected:** System prompt + professional prompt + knowledge context

### 4. Multiple Widgets, Different Configs
```php
[mcp_ai_chat assistant="123"]
[mcp_ai_chat profession="456"]
```
**Expected:** Independent instances, different prompts, both work

### 5. Tool Calling
```php
[mcp_ai_chat assistant="789"] <!-- Has tools configured -->
```
**Expected:** Enhanced client, tools available, professional context preserved

---

## Common Issues & Solutions

### Issue: Professional Prompt Not Applied
**Symptom:** Assistant doesn't follow professional role  
**Cause:** Professional prompt not combined with system prompt  
**Fix:** Ensure both `initEmbeddedClient()` and `generateEmbeddedCompletion()` combine prompts

### Issue: Model Won't Load on Mobile
**Symptom:** Out of memory errors on phones  
**Cause:** Model too large for device  
**Fix:** Use `checkModelSuitability()` and recommend smaller model

### Issue: Multiple Widgets Conflict
**Symptom:** Second widget doesn't work  
**Cause:** Global state instead of instance-based  
**Fix:** Ensure all state is instance-specific with unique IDs

### Issue: Scripts Not Loading
**Symptom:** "WebLLM not found" error  
**Cause:** Conditional loading not detecting chat  
**Fix:** Verify `is_chat_page()` logic, check shortcode/widget detection

---

## Performance Metrics

### Target Metrics
- Model load time: 5-30 seconds (first time)
- Model load time: <1 second (cached)
- First token latency: 100-500ms
- Streaming speed: 5-20 tokens/second
- Script bundle size: <50KB (excluding WebLLM CDN)

### Actual Results (NV oOS)
- ✅ WebLLM CDN: 150KB (cached globally)
- ✅ Plugin scripts: ~40KB total
- ✅ Model caching: IndexedDB (offline capable)
- ✅ Multi-instance: Tested with 3 widgets
- ✅ Professional prompts: Working as of 2026-01-26

---

## Future Enhancements

1. **TypeScript Migration**: Type-safe configuration and API
2. **Service Worker**: Better offline support
3. **Performance Monitoring**: Track metrics client-side
4. **A/B Testing**: Test different initialization strategies
5. **Advanced Memory Management**: Auto-unload on long inactivity
6. **Progressive Web App**: Installable chat experience

---

## References

- [WebLLM Official Documentation](https://webllm.mlc.ai/)
- [WordPress Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/)
- [ES Modules in WordPress](https://yourwpweb.com/2025/09/26/how-to-enqueue-es-module-scripts-and-use-dynamic-import-in-wp-in-wordpress/)
- [WebGPU Compatibility](https://caniuse.com/webgpu)

---

**Document Version:** 1.0  
**Last Updated:** January 26, 2026  
**Maintainer:** NV Digital Solutions
