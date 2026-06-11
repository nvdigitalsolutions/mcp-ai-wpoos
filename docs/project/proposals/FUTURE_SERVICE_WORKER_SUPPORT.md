# Future Enhancement: Service Worker Support for WebLLM

> **Status:** 📋 Future (v1.1.29) — No service worker registration code exists

## Overview
Web-LLM provides Service Worker support to enable model persistence across page visits and improved offline experience. This document outlines how this could be integrated into the WordPress plugin.

## Current Implementation
The plugin currently uses **standard WebLLM** with models loaded per-page:
- Model loads when chat widget initializes
- Model reloads on page refresh
- Each page visit requires model download (unless cached by browser)

## Service Worker Benefits

### 1. Model Persistence
- **Current**: Model reloads on every page visit
- **With Service Worker**: Model stays loaded in background
- **Benefit**: Instant chat availability across pages

### 2. Offline Experience
- Service Worker can handle requests even when offline
- Model and assets pre-cached
- Better Progressive Web App (PWA) support

### 3. Performance
- Faster initial response time
- No model reinitialization overhead
- Shared model across multiple tabs/widgets

## Implementation Approach

### Architecture Change Required

**Current Architecture:**
```javascript
// Each page creates its own engine
const engine = await CreateMLCEngine(modelId, config);
```

**Service Worker Architecture:**
```javascript
// Service Worker (sw.js)
import { ServiceWorkerMLCEngineHandler } from "@mlc-ai/web-llm";

let handler;
self.addEventListener("activate", function (event) {
  handler = new ServiceWorkerMLCEngineHandler();
  console.log("WebLLM Service Worker ready");
});

// Main thread (embedded-llm-client.js)
import { CreateServiceWorkerMLCEngine } from "@mlc-ai/web-llm";

// Register service worker
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register(
    new URL("./webllm-sw.js", import.meta.url),
    { type: "module" }
  );
}

// Create engine that uses service worker
const engine = await CreateServiceWorkerMLCEngine(
  selectedModel,
  { initProgressCallback }
);
```

### WordPress Plugin Considerations

#### 1. Service Worker Registration
```php
// In PHP - enqueue service worker
function wp_mcp_ai_register_service_worker() {
    if ( ! wp_mcp_ai_is_embedded_provider_enabled() ) {
        return;
    }
    
    wp_register_script(
        'wp-mcp-ai-webllm-sw-register',
        plugins_url( 'assets/js/webllm-sw-register.js', __FILE__ ),
        array(),
        WP_MCP_AI_VERSION,
        true
    );
    
    wp_enqueue_script( 'wp-mcp-ai-webllm-sw-register' );
}
add_action( 'wp_enqueue_scripts', 'wp_mcp_ai_register_service_worker' );
```

#### 2. Service Worker File
```javascript
// assets/js/webllm-sw.js
import { ServiceWorkerMLCEngineHandler } from "@mlc-ai/web-llm";

let handler;

self.addEventListener("activate", function (event) {
    handler = new ServiceWorkerMLCEngineHandler();
    console.log("[NV oOS WebLLM] Service Worker activated");
});

// Handle service worker lifecycle
self.addEventListener("install", function (event) {
    console.log("[NV oOS WebLLM] Service Worker installing");
    self.skipWaiting(); // Activate immediately
});
```

#### 3. Update EmbeddedLLMClient
```javascript
// In embedded-llm-client.js
async loadModel(modelId, progressCallback) {
    // Detect if Service Worker support enabled
    const useServiceWorker = this.config.useServiceWorker && 
                             'serviceWorker' in navigator;
    
    if (useServiceWorker) {
        // Use Service Worker engine
        this.currentEngine = await webLLM.CreateServiceWorkerMLCEngine(
            modelId,
            {
                initProgressCallback: progressCallback,
                logLevel: 'INFO'
            }
        );
    } else {
        // Use standard engine (current implementation)
        this.currentEngine = await webLLM.CreateMLCEngine(
            modelId,
            {
                initProgressCallback: progressCallback,
                logLevel: 'INFO'
            }
        );
    }
}
```

### Challenges & Considerations

#### 1. Service Worker Lifecycle
- **Issue**: Browser can kill service worker anytime
- **Solution**: Implement heartbeat mechanism
- **Web-LLM provides**: `keepAliveMs` and `missedHeatbeat` options

```javascript
const engine = await CreateServiceWorkerMLCEngine(
    selectedModel,
    { 
        initProgressCallback,
        keepAliveMs: 10000,        // Heartbeat every 10s
        missedHeatbeat: 3          // Restart after 3 missed
    }
);
```

#### 2. WordPress Multi-Site
- Service worker scope per site
- Need to handle different domain configurations
- Cache management per site

#### 3. Browser Compatibility
- Service Workers require HTTPS (except localhost)
- Not all browsers support module service workers
- Need fallback to standard implementation

#### 4. Cache Management
- Model files can be 1-4GB
- Need cache cleanup strategy
- WordPress admin interface for cache control

#### 5. Security
- Service worker has full control over requests
- Need proper scope restrictions
- Validate model sources

### Implementation Steps

#### Phase 1: Basic Service Worker Support
1. Create service worker file (`webllm-sw.js`)
2. Add registration script (`webllm-sw-register.js`)
3. Update `EmbeddedLLMClient` to detect and use service worker
4. Add admin setting to enable/disable service worker

#### Phase 2: Lifecycle Management
1. Implement heartbeat monitoring
2. Add error recovery for killed workers
3. Add reconnection logic
4. User notifications for worker status

#### Phase 3: Cache Management
1. Add cache size monitoring
2. Implement cache cleanup
3. Admin UI for cache management
4. Pre-loading strategy for common models

#### Phase 4: Optimization
1. Multi-tab model sharing
2. Offline mode detection
3. Background model updates
4. Performance analytics

### Configuration Options

Add to assistant settings:

```javascript
{
    "embedded_provider_options": {
        "use_service_worker": false,        // Enable/disable
        "service_worker_keepalive_ms": 10000,
        "service_worker_missed_heartbeat": 3,
        "cache_max_size_gb": 5,
        "preload_models": ["Llama-3.2-1B-Instruct-q4f16_1-MLC"]
    }
}
```

### WordPress Admin Settings

```php
// Add settings section
add_settings_section(
    'wp_mcp_ai_service_worker_settings',
    __( 'Service Worker Settings', 'mcp-ai-wpoos' ),
    'wp_mcp_ai_service_worker_settings_callback',
    'wp-mcp-ai-settings'
);

add_settings_field(
    'wp_mcp_ai_enable_service_worker',
    __( 'Enable Service Worker', 'mcp-ai-wpoos' ),
    'wp_mcp_ai_enable_service_worker_callback',
    'wp-mcp-ai-settings',
    'wp_mcp_ai_service_worker_settings'
);
```

## Benefits vs. Complexity

### Benefits
✅ Faster page loads (model persists)
✅ Better offline experience
✅ Shared model across tabs
✅ Reduced bandwidth usage
✅ PWA capabilities

### Complexity
⚠️ Service worker lifecycle management
⚠️ Cache management complexity
⚠️ HTTPS requirement
⚠️ Browser compatibility issues
⚠️ Debugging challenges
⚠️ WordPress multisite considerations

## Recommendation

**For Current Release**: 
- ❌ **Do NOT implement** in this PR
- ✅ **Reason**: Current OpenAI compatibility fix is complete and working
- ✅ **Focus**: Get basic functionality stable first

**For Future Release (v2.0+)**:
- ✅ **Implement** as optional feature
- ✅ **Default**: Disabled (opt-in for advanced users)
- ✅ **Requirements**: HTTPS, modern browser
- ✅ **Admin UI**: Toggle + cache management

## Testing Requirements

If implemented, must test:
1. Service worker registration across different WordPress setups
2. Model persistence across page navigation
3. Multi-tab scenarios
4. Service worker restart/recovery
5. Cache size limits
6. Offline mode
7. HTTPS vs HTTP behavior
8. Browser compatibility (Chrome, Edge, Safari)

## References

- [Web-LLM Service Worker Example](https://github.com/mlc-ai/web-llm/tree/main/examples/service-worker)
- [Service Worker API](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [WordPress and Service Workers](https://make.wordpress.org/core/2019/05/14/service-workers-in-wordpress/)

## Conclusion

Service Worker support is a **valuable future enhancement** but should be implemented as a **separate feature** after the current OpenAI compatibility fix is stable and deployed.

**Current PR Status**: ✅ Complete - OpenAI compatibility implemented
**Service Worker Status**: 📋 Documented for future implementation

---

*This document serves as a roadmap for implementing Service Worker support in a future version of the plugin.*
