# Embedded LLM Provider Comparison

## Overview

The plugin offers **TWO distinct "Embedded LLM" options** with very different architectures and requirements. This document clarifies the differences to avoid confusion.

---

## Quick Comparison

| Feature | **Client-Side WebLLM** ✨ NEW | **Server-Side Embedded** |
|---------|----------------------------|--------------------------|
| **Where It Runs** | User's browser (WebGPU/WebAssembly) | Your WordPress server (llama.cpp) |
| **Requires shell_exec** | ❌ **NO** | ✅ **YES** |
| **Requires Server Resources** | ❌ No CPU/RAM usage on server | ✅ Significant CPU/RAM required |
| **Installation Complexity** | ✅ Zero - works out of the box | ❌ Requires binary installation |
| **Privacy** | ✅ 100% private (never leaves browser) | ✅ Private (stays on your server) |
| **Model Storage** | Browser IndexedDB cache | Server filesystem |
| **GPU Acceleration** | WebGPU (user's GPU) | CPU-only (llama.cpp) |
| **Hosting Compatibility** | ✅ Works on ANY hosting | ⚠️ May be blocked on shared hosting |
| **Browser Requirements** | Chrome 113+, Edge 113+, Safari 18+ | Any browser |
| **Pro Feature** | ✅ Yes | ✅ Yes |

---

## 1. Client-Side WebLLM (Recommended) ✨

### What Is It?

Client-side WebLLM uses the `@mlc-ai/web-llm` JavaScript library to run language models **entirely in the user's browser** using WebGPU (GPU acceleration) or WebAssembly (CPU fallback).

### Key Characteristics

- **Zero server requirements**: No shell_exec, no binaries, no server CPU/RAM usage
- **Fully client-side**: All inference happens in the browser
- **Automatic model loading**: Models download to browser cache on first use
- **WebGPU acceleration**: Leverages user's GPU for fast inference
- **Perfect for shared hosting**: No server restrictions needed

### Architecture

```
User Browser
├── WebLLM JavaScript Library (@mlc-ai/web-llm)
├── Model Download (First use only)
│   └── Stores in IndexedDB (~400MB to 2.5GB)
├── WebGPU Inference (GPU-accelerated)
│   └── OR WebAssembly fallback (CPU)
└── Responses generated locally

WordPress Server
└── Just serves the JavaScript - no processing!
```

### Does It Need shell_exec? ❌ **NO**

**Absolutely not.** Client-side WebLLM runs entirely in the browser using JavaScript. The WordPress server:
- Serves the HTML/JavaScript/CSS files
- Provides the REST API for chat history (optional)
- **Never executes any inference or model operations**

### Browser Requirements

- **Chrome/Edge**: Version 113+ (WebGPU support)
- **Safari**: Version 18+ (macOS/iOS)
- **Firefox**: Not yet supported (WebGPU in development)

Fallback to WebAssembly (CPU) works on older browsers but is slower.

### Available Models

All models are **MLC-compiled** and optimized for browser inference:

| Model | Size | Speed | Quality |
|-------|------|-------|---------|
| Qwen2.5 0.5B Instruct | ~400MB | ⚡⚡⚡ Fastest | ⭐⭐⭐ Good |
| Llama 3.2 1B Instruct | ~800MB | ⚡⚡⚡ Fast | ⭐⭐⭐⭐ Great |
| Qwen2.5 1.5B Instruct | ~1GB | ⚡⚡ Moderate | ⭐⭐⭐⭐ Great |
| Llama 3.2 3B Instruct | ~2GB | ⚡ Slower | ⭐⭐⭐⭐⭐ Excellent |
| Phi-3.5 Mini Instruct | ~2.5GB | ⚡ Slower | ⭐⭐⭐⭐⭐ Excellent |

### Setup Instructions

1. Navigate to **Settings → NV oOS → Providers → Embedded LLM**
2. Check **"Enable client-side embedded language models (Pro)"**
3. Select a default model (Llama 3.2 1B recommended)
4. Save settings

**That's it!** Models will automatically download to the user's browser on first use.

### Use Cases

- ✅ Shared hosting environments (no shell_exec)
- ✅ High-traffic sites (offload inference to clients)
- ✅ Privacy-sensitive applications (data never leaves browser)
- ✅ Cloudways, WP Engine, Kinsta, etc. (works anywhere)
- ✅ Sites without binary installation permissions

---

## 2. Server-Side Embedded LLM (Legacy)

### What Is It?

Server-side embedded LLM uses **llama.cpp** (a C++ inference engine) to run language models **on your WordPress server's CPU**.

### Key Characteristics

- **Requires shell_exec**: Must execute binary commands
- **Server-side processing**: Uses your server's CPU and RAM
- **Binary installation required**: Must install llama.cpp binary
- **GGUF model files**: Downloads to server filesystem
- **May be blocked on shared hosting**: Many hosts disable shell_exec

### Architecture

```
WordPress Server
├── llama.cpp Binary (must be installed)
├── GGUF Model Files (stored in wp-content/uploads/)
├── shell_exec() Execution (must be enabled)
├── CPU Inference (uses server RAM/CPU)
└── Returns response to user

User Browser
└── Just receives the final response
```

### Does It Need shell_exec? ✅ **YES**

**Absolutely required.** The server-side embedded LLM executes shell commands like:

```bash
/path/to/llama-cli -m /path/to/model.gguf -p "User prompt" -n 512 --temp 0.7
```

If `shell_exec()` is disabled, you'll see this error:

> "shell_exec() function is not available. This is required for embedded model inference. Please contact your hosting provider to enable it."

See `class-wp-mcp-ai-embedded-client.php` lines 599-606 for the check.

### Why shell_exec Is Needed

1. **Binary Execution**: Runs the `llama-cli` binary
2. **Model Loading**: Passes model path and parameters
3. **Inference**: Processes the prompt and generates responses
4. **Output Capture**: Retrieves generated text from stdout

### Server Requirements

- **PHP**: `shell_exec()` function enabled (not in `disable_functions`)
- **Binary**: llama.cpp binary installed and executable
- **RAM**: 2GB - 8GB depending on model size
- **CPU**: Modern multi-core CPU (ARM64 or x64)
- **Storage**: 350MB - 2.3GB per model

### Available Models

All models are **GGUF format** (quantized for CPU inference):

| Model | Size | RAM Required |
|-------|------|--------------|
| Qwen2 0.5B Instruct | ~350MB | 2GB |
| IBM Granite 3.1 2B Instruct | ~1.2GB | 4GB |
| Microsoft Phi-3 Mini | ~2.3GB | 6GB |

### Setup Instructions

1. **Check shell_exec Availability**:
   ```php
   <?php
   // Add to a test file
   if (function_exists('shell_exec')) {
       $disabled = ini_get('disable_functions');
       if (strpos($disabled, 'shell_exec') === false) {
           echo "shell_exec is available ✅";
       } else {
           echo "shell_exec is disabled ❌";
       }
   } else {
       echo "shell_exec function does not exist ❌";
   }
   ```

2. **Install llama.cpp Binary** (if shell_exec is available):
   - Download from: https://github.com/ggerganov/llama.cpp/releases/latest
   - Upload to: `wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/llama-cli`
   - Make executable: `chmod +x llama-cli`

3. **Configure in WordPress**:
   - Navigate to **Settings → NV oOS → Providers → Embedded LLM**
   - Check **"Enable Embedded LLM Provider"**
   - Select a model (IBM Granite 3.1 2B recommended)
   - Click **Download Model**
   - Test the connection

### Use Cases

- ✅ Dedicated servers with full shell access
- ✅ VPS hosting with root/sudo access
- ✅ Local development (Docker, Local WP, etc.)
- ✅ Servers with powerful CPUs
- ⚠️ **NOT recommended** for shared hosting

---

## Which Should You Use?

### Use Client-Side WebLLM If:

- ✅ You have **shared hosting** (Cloudways, WP Engine, SiteGround, etc.)
- ✅ `shell_exec()` is **disabled** on your server
- ✅ You want **zero server load** (high-traffic sites)
- ✅ You need **maximum privacy** (no data sent to server)
- ✅ You want **easy setup** (no binaries to install)
- ✅ Your users have **modern browsers** (Chrome 113+, Safari 18+)

### Use Server-Side Embedded If:

- ✅ You have a **dedicated server or VPS** with shell access
- ✅ `shell_exec()` is **enabled** and you can't change it
- ✅ Your server has **powerful CPU and RAM**
- ✅ You need **consistent performance** regardless of client device
- ✅ You want **server-side inference** for logging/monitoring
- ✅ Your users may have **older browsers**

---

## Common Misconceptions

### ❌ "All embedded LLMs need shell_exec"

**FALSE.** Only the server-side embedded LLM needs shell_exec. Client-side WebLLM runs in the browser and requires **zero** server-side execution.

### ❌ "WebLLM is slower because it's in JavaScript"

**FALSE.** WebLLM uses WebGPU (GPU acceleration) and is often **faster** than server-side CPU inference. It compiles models to WebAssembly and GPU shaders.

### ❌ "Server-side is more private"

**PARTIALLY FALSE.** Both are private:
- **Client-side**: Data never leaves the user's browser (100% private)
- **Server-side**: Data stays on your server (private from third parties)

### ❌ "I need to enable shell_exec for WebLLM"

**FALSE.** WebLLM is **client-side JavaScript**. The WordPress server just serves static files. No shell_exec needed.

---

## Technical Implementation Details

### Client-Side WebLLM

**Files:**
- `assets/js/embedded-llm-client.js` - JavaScript client library
- `includes/admin/class-wp-mcp-ai-pro-settings.php` - Settings UI
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` - Provider config

**Key Functions:**
```javascript
// Initialize WebLLM
const engine = await webLLM.CreateMLCEngine(modelId);

// Generate response (runs in browser)
const response = await engine.chat.completions.create({
    messages: messages,
    temperature: 0.7,
    max_tokens: 512
});
```

**No PHP execution** for inference - only for serving the JavaScript.

### Server-Side Embedded

**Files:**
- `includes/class-wp-mcp-ai-embedded-client.php` - PHP client wrapper

**Key Functions:**
```php
// Check if shell_exec is available (REQUIRED)
if (!function_exists('shell_exec') || $this->is_shell_exec_disabled()) {
    return new WP_Error(
        'wp_mcp_ai_shell_exec_disabled',
        __('shell_exec() function is not available...'),
        array('status' => 500)
    );
}

// Execute binary (uses shell_exec)
$output = shell_exec($command);
```

---

## FAQ

### Q: I have Cloudways hosting. Which embedded LLM should I use?

**A:** Use **Client-Side WebLLM**. Cloudways (and most shared hosting) disables `shell_exec()` by default, making server-side embedded LLM impossible without contacting support.

### Q: Can I use both client-side and server-side at the same time?

**A:** Currently, no. Choose one based on your hosting environment and requirements.

### Q: Will client-side WebLLM work if JavaScript is disabled?

**A:** No. WebLLM requires JavaScript to run in the browser. However, you can configure fallback providers (OpenAI, Anthropic, etc.) for such cases.

### Q: How do I check if shell_exec is enabled?

**A:** Add this to a test PHP file:

```php
<?php
var_dump(function_exists('shell_exec'));
var_dump(ini_get('disable_functions'));
```

If `shell_exec` appears in the disabled functions list, it's not available.

### Q: Can I still use the plugin if shell_exec is disabled?

**A:** **Yes!** Use client-side WebLLM (no shell_exec needed) or cloud providers (OpenAI, Anthropic, Gemini, Cloudflare, etc.). You only need shell_exec for server-side embedded LLM.

---

## Support

- **Documentation**: See `docs/features/ai-providers/embedded/`
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Troubleshooting**: See `docs/getting-started/installation-setup/deployment-troubleshooting.md`

---

**Last Updated**: January 24, 2026  
**Plugin Version**: 1.1.0+  
**License**: Proprietary — © 2025-2026 NV Digital Solutions. All rights reserved.
