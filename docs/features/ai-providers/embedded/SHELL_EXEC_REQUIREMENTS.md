# Shell Exec Requirements for Embedded LLM

## Quick Answer

**Does the new embedded LLM (client-side WebLLM) need shell_exec enabled on the server to work?**

## ❌ **NO** - Client-Side WebLLM Does NOT Need shell_exec

The **client-side WebLLM** feature is a **browser-based** implementation that runs entirely on the user's device using JavaScript, WebGPU, and WebAssembly. It requires:

- ✅ **NO** `shell_exec()` function
- ✅ **NO** binary installation
- ✅ **NO** server-side processing
- ✅ **NO** server CPU or RAM usage
- ✅ **NO** special hosting permissions

### Why Not?

Client-side WebLLM works like this:

1. **Your WordPress server** serves HTML, CSS, and JavaScript files (just like any webpage)
2. **User's browser** downloads the JavaScript library (`@mlc-ai/web-llm`)
3. **User's browser** downloads the AI model to IndexedDB cache (first time only)
4. **User's browser** runs inference using WebGPU or WebAssembly
5. **User's browser** displays the response

**The WordPress server never executes any model inference.** It's just serving static files.

### Where Is shell_exec Used?

The `shell_exec()` function is **ONLY required** for the **server-side embedded LLM** (the older implementation using llama.cpp), which:

- Runs on your WordPress server (not in the browser)
- Executes a binary called `llama-cli`
- Uses server CPU and RAM for inference
- Is **NOT** the same as client-side WebLLM

---

## Detailed Comparison

### Client-Side WebLLM (NO shell_exec needed)

```javascript
// This runs in the browser - NO PHP, NO shell_exec
const engine = await webLLM.CreateMLCEngine('Llama-3.2-1B-Instruct-q4f16_1-MLC');
const response = await engine.chat.completions.create({
    messages: [{role: 'user', content: 'Hello!'}]
});
```

**Server involvement:** Zero. The server just serves the JavaScript file.

### Server-Side Embedded LLM (YES shell_exec needed)

```php
// This runs on the server - REQUIRES shell_exec
$command = '/path/to/llama-cli -m /path/to/model.gguf -p "Hello!"';
$output = shell_exec($command); // ← REQUIRES shell_exec()
```

**Server involvement:** 100%. The server executes the binary and processes the model.

---

## How to Identify Which Implementation You Have

### Client-Side WebLLM Indicators:

- Settings mention "**WebGPU/WebAssembly**"
- Models have "**MLC**" in the name (e.g., `Llama-3.2-1B-Instruct-q4f16_1-MLC`)
- Description says "**runs in user browser**"
- Settings UI shows "**Browser Requirements**"
- JavaScript file: `assets/js/embedded-llm-client.js`

### Server-Side Embedded Indicators:

- Requires "**llama.cpp binary**" installation
- Models are "**GGUF format**" (e.g., `granite-3.1-2b-instruct.Q4_K_M.gguf`)
- Description says "**runs on server**"
- Settings UI shows "**Binary Installation**" instructions
- PHP file: `includes/class-wp-mcp-ai-embedded-client.php`

---

## Code Reference

### Client-Side WebLLM - No shell_exec Check

**File:** `assets/js/embedded-llm-client.js`

```javascript
/**
 * Generate chat completion
 * Runs entirely in the browser - NO SERVER EXECUTION
 */
async function generateCompletion(messages, options = {}) {
    if (!modelLoaded || !currentEngine) {
        throw new Error('No model is currently loaded.');
    }

    // This uses WebGPU/WebAssembly in the browser
    const response = await currentEngine.chat.completions.create({
        messages: messages,
        temperature: options.temperature || 0.7,
        max_tokens: options.max_tokens || 512
    });

    return {
        success: true,
        content: response.choices[0].message.content
    };
}
```

**No PHP code. No shell_exec. Pure JavaScript.**

### Server-Side Embedded - Requires shell_exec

**File:** `includes/class-wp-mcp-ai-embedded-client.php`

**Lines 599-606:**
```php
// Check if shell_exec is available.
if ( ! function_exists( 'shell_exec' ) || $this->is_shell_exec_disabled() ) {
    return new WP_Error(
        'wp_mcp_ai_shell_exec_disabled',
        __( 'shell_exec() function is not available. This is required for embedded model inference. Please contact your hosting provider to enable it.', 'mcp-ai-wpoos' ),
        array( 'status' => 500 )
    );
}

// Execute inference.
$start_time = microtime( true );
$output     = shell_exec( $command );  // ← HERE: Executes llama-cli binary
$end_time   = microtime( true );
```

**This code REQUIRES shell_exec to be enabled.**

---

## Common Questions

### Q: My hosting provider disabled shell_exec. Can I still use embedded LLM?

**A: YES!** Use the **client-side WebLLM** option. It doesn't need shell_exec because it runs in the browser.

### Q: How do I enable client-side WebLLM?

**A:**
1. Go to **Settings → NV oOS → Providers → Embedded LLM**
2. Enable "**Enable client-side embedded language models (Pro)**"
3. Select a model (Llama 3.2 1B recommended)
4. Save

Models will download automatically to users' browsers on first use.

### Q: Will enabling client-side WebLLM slow down my server?

**A: NO!** Client-side WebLLM uses **zero** server resources. All processing happens in the user's browser.

### Q: Can I use client-side WebLLM on Cloudways/WP Engine/Kinsta?

**A: YES!** Client-side WebLLM works on **any hosting** because it doesn't require any special server permissions.

### Q: What if my users have old browsers?

**A:** WebLLM requires modern browsers (Chrome 113+, Safari 18+). You can:
- Configure fallback providers (OpenAI, Anthropic, etc.)
- Display a browser upgrade message
- Use server-side providers instead

### Q: Can I test if shell_exec is enabled?

**A:** Create a test PHP file:

```php
<?php
if (!function_exists('shell_exec')) {
    echo "❌ shell_exec function does not exist\n";
} else {
    $disabled = ini_get('disable_functions');
    $disabled_array = array_map('trim', explode(',', $disabled));
    
    if (in_array('shell_exec', $disabled_array)) {
        echo "❌ shell_exec is in disable_functions list\n";
    } else {
        echo "✅ shell_exec is available\n";
        
        // Test execution
        $test = shell_exec('echo "test"');
        if ($test) {
            echo "✅ shell_exec execution successful\n";
        } else {
            echo "⚠️ shell_exec exists but execution failed\n";
        }
    }
}
```

### Q: Which embedded LLM should I choose?

| Your Situation | Recommended Option |
|----------------|-------------------|
| Shared hosting (Cloudways, WP Engine, etc.) | Client-Side WebLLM |
| shell_exec is disabled | Client-Side WebLLM |
| Want zero server load | Client-Side WebLLM |
| Maximum privacy needed | Client-Side WebLLM |
| Users have modern browsers | Client-Side WebLLM |
| Dedicated server with shell access | Either (your choice) |
| Need consistent performance | Server-Side Embedded |
| Users may have old browsers | Server-Side Embedded |

**For most users: Choose Client-Side WebLLM** ✨

---

## Technical Deep Dive

### Why Doesn't Client-Side WebLLM Need shell_exec?

**Traditional approach (server-side):**
```
User Request
    ↓
WordPress Server
    ↓
PHP Code (shell_exec)
    ↓
Binary Execution (llama-cli)
    ↓
Model Inference
    ↓
Response to User
```

**Client-side WebLLM approach:**
```
User Request
    ↓
Browser JavaScript
    ↓
WebLLM Library (already in browser)
    ↓
WebGPU/WebAssembly Inference (in browser)
    ↓
Response (never leaves browser)
```

The WordPress server is **completely bypassed** for inference. It just serves the initial HTML/JS files like any other webpage.

### What About Model Downloads?

Client-side models are downloaded **directly from CDN to the browser** via HTTPS. The WordPress server is never involved:

```
User Browser → CDN (Hugging Face) → IndexedDB Cache
```

The first time a user loads a model, they download it from the CDN (like downloading any large JavaScript library). Subsequent uses load instantly from browser cache.

### WebGPU vs shell_exec

**WebGPU** is a modern browser API that allows JavaScript to access the GPU for computation:

```javascript
// This runs on the user's GPU - NO server involvement
const adapter = await navigator.gpu.requestAdapter();
const device = await adapter.requestDevice();
// ... inference code runs on GPU
```

**shell_exec** is a PHP function that executes system commands:

```php
// This runs on the server
shell_exec('/usr/bin/some-binary --option value');
```

These are **completely different technologies** operating in **completely different environments**.

---

## Summary

### The Answer: NO shell_exec Needed for Client-Side WebLLM ✅

- ✅ Client-side WebLLM is **browser-based JavaScript**
- ✅ Runs on **user's device** (GPU/CPU)
- ✅ WordPress server **just serves static files**
- ✅ Works on **any hosting** (no special permissions)
- ✅ **Zero server load** (perfect for high traffic)

### When shell_exec IS Needed ⚠️

- ⚠️ Only for **server-side embedded LLM** (llama.cpp)
- ⚠️ This is a **different feature** from client-side WebLLM
- ⚠️ Uses a **binary on the server**
- ⚠️ Most shared hosting **disables** shell_exec

### Recommendation 💡

**For the vast majority of WordPress users:**

Use **Client-Side WebLLM** because:
- Works on any hosting (including shared)
- No shell_exec requirement
- Easy setup (zero configuration)
- Zero server load
- Maximum privacy

---

## Additional Resources

- [Embedded LLM Comparison](./EMBEDDED_LLM_COMPARISON.md) - Full comparison guide
- [Implementation Summary](./IMPLEMENTATION_SUMMARY.md) - Technical details
- [Cloudways Setup](./CLOUDWAYS_SETUP.md) - Hosting-specific guide
- [Deployment Troubleshooting](../../../getting-started/installation-setup/deployment-troubleshooting.md)

---

**TL;DR:** Client-side WebLLM runs in the browser using JavaScript. It does **NOT** need shell_exec. The server just serves files like any webpage.

---

**Last Updated**: January 24, 2026  
**Plugin Version**: 1.1.0+  
**Related Issue**: "Does the new embedded LLM (client-side WebLLM) need shell_exec enabled on the server to work?"  
**Answer**: ❌ **NO**
