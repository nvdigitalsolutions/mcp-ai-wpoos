# Client-Side WebLLM - Model Distribution

## Where Do Models Come From?

For **client-side WebLLM**, models are downloaded **directly from MLC AI's CDN** to the user's browser. The WordPress server is **NOT involved** in model distribution.

## Model Distribution Architecture

```
MLC AI CDN (Hugging Face)
    ↓
User's Browser (First Time)
    ↓
IndexedDB Cache
    ↓
WebGPU/WebAssembly Inference
```

### Step-by-Step Process

#### 1. User Opens Chat Interface

```javascript
// assets/js/embedded-llm-client.js is loaded
// WebLLM library is initialized
```

#### 2. WebLLM Detects Model Not Cached

```javascript
// First time user uses this model
const engine = await webLLM.CreateMLCEngine(
    'Llama-3.2-1B-Instruct-q4f16_1-MLC',
    {
        initProgressCallback: (progress) => {
            console.log(progress.text, progress.progress);
        }
    }
);
```

#### 3. Browser Downloads Model from CDN

**Models are hosted on Hugging Face under the MLC AI organization:**

```
https://huggingface.co/mlc-ai/Llama-3.2-1B-Instruct-q4f16_1-MLC
```

**Model files structure:**
```
mlc-ai-models/
├── Llama-3.2-1B-Instruct-q4f16_1-MLC/
│   ├── params_shard_*.bin (multiple shards)
│   ├── mlc-chat-config.json
│   ├── tokenizer.model
│   └── tokenizer_config.json
```

**Browser downloads these files via HTTPS:**
- Uses `fetch()` API
- Shows progress bar during download
- Typical download time: 1-5 minutes (depends on internet speed)

#### 4. Browser Stores Model in IndexedDB

```javascript
// Stored in browser's IndexedDB database
// Database name: typically "mlc-cache" or similar
// Persistent storage across browser sessions
```

**Storage locations by browser:**

- **Chrome/Edge:**
  - Windows: `%LOCALAPPDATA%\Google\Chrome\User Data\Default\IndexedDB\`
  - macOS: `~/Library/Application Support/Google/Chrome/Default/IndexedDB/`
  - Linux: `~/.config/google-chrome/Default/IndexedDB/`

- **Safari:**
  - macOS: `~/Library/Safari/LocalStorage/`

- **Firefox:**
  - Windows: `%APPDATA%\Mozilla\Firefox\Profiles\*.default\storage\default\`
  - macOS: `~/Library/Application Support/Firefox/Profiles/*.default/storage/default/`
  - Linux: `~/.mozilla/firefox/*.default/storage/default/`

#### 5. Subsequent Uses Are Instant

```javascript
// Model already in cache
// Loads from IndexedDB instantly (< 1 second)
const engine = await webLLM.CreateMLCEngine(
    'Llama-3.2-1B-Instruct-q4f16_1-MLC'
);
// No download, immediate availability
```

## WordPress Server's Role

### What the WordPress Server DOES:

✅ **Serves JavaScript files:**
```php
// Enqueues embedded-llm-client.js
wp_enqueue_script('wp-mcp-ai-embedded-llm-client');
```

✅ **Provides settings:**
```php
// Returns selected model ID
$settings = get_option('wp_mcp_ai_settings');
$model = $settings['embedded_model'];
// e.g., 'Llama-3.2-1B-Instruct-q4f16_1-MLC'
```

✅ **Stores chat history (optional):**
```php
// Saves chat transcripts to database
// Only if user enabled this feature
```

### What the WordPress Server DOES NOT Do:

❌ **Download models** - Browser downloads directly from MLC CDN  
❌ **Store models** - Models stored in browser IndexedDB  
❌ **Execute models** - Inference runs in browser WebGPU/WebAssembly  
❌ **Process requests** - All AI processing happens client-side  

## Model CDN Sources

### Primary CDN: Hugging Face

**MLC AI hosts all web-optimized models on Hugging Face:**

```
https://huggingface.co/mlc-ai/
├── Llama-3.2-1B-Instruct-q4f16_1-MLC
├── Llama-3.2-3B-Instruct-q4f16_1-MLC
├── Phi-3.5-mini-instruct-q4f16_1-MLC
├── Qwen2.5-0.5B-Instruct-q4f16_1-MLC
└── Qwen2.5-1.5B-Instruct-q4f16_1-MLC
```

**These are special MLC-compiled versions:**
- Pre-compiled for WebGPU
- Optimized weight formats
- Include tokenizer files
- Include configuration files

**You CANNOT use standard Hugging Face models** - they must be MLC-compiled.

### Model Download URLs

**Example for Llama 3.2 1B:**

```
Base URL: https://huggingface.co/mlc-ai/Llama-3.2-1B-Instruct-q4f16_1-MLC/resolve/main/

Files:
- params_shard_0.bin (200MB)
- params_shard_1.bin (200MB)
- params_shard_2.bin (200MB)
- params_shard_3.bin (180MB)
- mlc-chat-config.json (5KB)
- tokenizer.model (500KB)
- tokenizer_config.json (1KB)

Total: ~800MB
```

**WebLLM handles all downloads automatically** - you don't need to construct URLs manually.

## Browser Requirements

### Required APIs

**WebLLM needs modern browser APIs:**

1. **WebGPU** (preferred - GPU acceleration)
   - Chrome 113+ ✅
   - Edge 113+ ✅
   - Safari 18+ (macOS) ✅
   - Firefox: Not yet ❌

2. **WebAssembly** (fallback - CPU only)
   - All modern browsers ✅
   - Slower than WebGPU
   - Still functional

3. **IndexedDB** (required - model storage)
   - All modern browsers ✅
   - Persistent storage API

4. **Service Workers** (optional - better caching)
   - Most modern browsers ✅
   - Not strictly required

### Checking Browser Support

```javascript
// Check WebGPU support
async function checkWebGPUSupport() {
    if (!navigator.gpu) {
        return {
            supported: false,
            message: 'WebGPU not supported'
        };
    }

    try {
        const adapter = await navigator.gpu.requestAdapter();
        if (!adapter) {
            return {
                supported: false,
                message: 'No GPU adapter available'
            };
        }

        return {
            supported: true,
            adapter: adapter.name
        };
    } catch (error) {
        return {
            supported: false,
            message: error.message
        };
    }
}
```

## Network Requirements

### Bandwidth

**First-time model download:**
- Qwen2.5 0.5B: ~400MB download
- Llama 3.2 1B: ~800MB download
- Qwen2.5 1.5B: ~1GB download
- Llama 3.2 3B: ~2GB download
- Phi-3.5 Mini: ~2.5GB download

**User experience:**
- 10 Mbps: 5-15 minutes
- 50 Mbps: 1-3 minutes
- 100 Mbps: 30 seconds - 1 minute

**After initial download:**
- 0MB (loads from cache)
- Instant availability

### Firewall/Proxy Considerations

**Organizations may block:**
- WebGPU API (security policies)
- Large downloads (>100MB)
- Hugging Face domain (huggingface.co)

**Workarounds:**
- Use OpenAI/Anthropic as fallback
- Allow huggingface.co in firewall
- Use VPN/different network for initial download

## Storage Requirements

### Browser Storage Limits

**IndexedDB quotas by browser:**

- **Chrome/Edge:** ~60% of available disk space per origin
  - Typical: 10-50GB available
  - Large models fit easily

- **Firefox:** ~50% of available disk space per origin
  - Typical: 10-50GB available

- **Safari:** 1GB default, can request more
  - May prompt user for permission
  - Need to handle quota exceeded errors

### Managing Storage

**Users can clear models:**

```javascript
// Clear all cached models
indexedDB.deleteDatabase('mlc-cache');

// Or clear via browser settings:
// Chrome: Settings > Privacy > Clear browsing data > Cached images and files
```

**Plugin doesn't control browser storage** - Users manage it themselves.

## Privacy & Security

### Data Flow

```
User Input
    ↓
WordPress Server (HTTPS)
    ↓
Browser JavaScript
    ↓
WebLLM Inference (Local)
    ↓
Browser Display
    ↑
WordPress Server (Save History - Optional)
```

### What Leaves the Browser

**WITHOUT chat history saving:**
- ❌ Nothing - 100% private
- User input never touches WordPress server
- Responses never touch WordPress server
- Complete offline operation (after model cached)

**WITH chat history saving:**
- ✅ Chat transcript (if user enabled)
- Saved to WordPress database
- Can be disabled per-user
- Encrypted at rest (if configured)

### What NEVER Leaves the Browser

- ❌ Model weights (stored in IndexedDB)
- ❌ Inference computation (runs in WebGPU/WASM)
- ❌ Embeddings
- ❌ Internal states

**Even with malicious WordPress admin:**
- Cannot access model weights
- Cannot intercept inference
- Cannot read WebGPU memory
- Can only see saved chat history (if enabled)

## Performance Characteristics

### Download Times (Typical Home Internet)

| Model | Size | 50 Mbps | 100 Mbps | 200 Mbps |
|-------|------|---------|----------|----------|
| Qwen2.5 0.5B | 400MB | 90s | 45s | 22s |
| Llama 3.2 1B | 800MB | 180s | 90s | 45s |
| Qwen2.5 1.5B | 1GB | 225s | 112s | 56s |
| Llama 3.2 3B | 2GB | 450s | 225s | 112s |
| Phi-3.5 Mini | 2.5GB | 562s | 281s | 140s |

### Cache Load Times

**From IndexedDB to WebGPU:**
- < 1 second for all models
- No network required
- Instant availability

### Inference Speed

**With WebGPU (GPU):**
- Qwen2.5 0.5B: ~50-100 tokens/second
- Llama 3.2 1B: ~30-60 tokens/second
- Llama 3.2 3B: ~15-30 tokens/second

**With WebAssembly (CPU):**
- ~10x slower than WebGPU
- Still functional
- Depends on CPU

## Troubleshooting

### Model Won't Download

**Symptoms:**
- Stuck at "Downloading model..."
- Progress bar frozen
- Error: "Failed to fetch"

**Solutions:**
1. Check internet connection
2. Disable VPN/proxy temporarily
3. Check Hugging Face status (status.huggingface.co)
4. Clear browser cache
5. Try different browser
6. Check firewall/corporate network

### Model Won't Load from Cache

**Symptoms:**
- Re-downloads every time
- "Model not found in cache"

**Solutions:**
1. Check available disk space
2. Check browser storage settings
3. Ensure cookies/storage not cleared automatically
4. Check "Private Browsing" not enabled
5. Grant storage permission (Safari)

### Out of Memory Error

**Symptoms:**
- "Out of memory"
- Browser tab crashes
- Slow/frozen browser

**Solutions:**
1. Close other tabs
2. Restart browser
3. Use smaller model (Qwen2.5 0.5B)
4. Upgrade device RAM
5. Use desktop instead of mobile

## Comparison with Server-Side

| Aspect | Client-Side (WebLLM) | Server-Side (llama.cpp) |
|--------|---------------------|------------------------|
| **Model Source** | MLC AI CDN (Hugging Face) | Hugging Face (GGUF) |
| **Model Storage** | Browser IndexedDB (~1-2GB) | Server filesystem (~1-2GB) |
| **Download By** | Each user's browser | Server admin once |
| **Network Usage** | Per-user (first time) | Server once, then LAN |
| **WordPress Bandwidth** | Zero | Zero (after download) |
| **User Privacy** | 100% (never leaves browser) | High (stays on server) |
| **Works Offline** | ✅ Yes (after cache) | ✅ Yes |
| **Requires shell_exec** | ❌ No | ✅ Yes |

## Summary

### Model Distribution for Client-Side WebLLM:

1. ✅ **Models hosted on MLC AI's Hugging Face**
2. ✅ **Browser downloads directly from CDN**
3. ✅ **WordPress server not involved in distribution**
4. ✅ **Cached in browser IndexedDB**
5. ✅ **Inference runs locally in browser**
6. ✅ **No server resources used**

### Key Takeaways:

- 📦 **Models come from**: MLC AI CDN (Hugging Face)
- 💾 **Stored in**: Browser IndexedDB
- 🔒 **Privacy**: 100% (data never leaves browser)
- ⚡ **Performance**: GPU-accelerated (WebGPU)
- 🌐 **Network**: One-time download per user per model
- 🖥️ **Server load**: Zero (just serves JavaScript)

---

**See Also:**
- [Shell Exec Requirements](./SHELL_EXEC_REQUIREMENTS.md)
- [Embedded LLM FAQ](./EMBEDDED_LLM_FAQ.md)
- [WebLLM Documentation](https://webllm.mlc.ai/)
- [MLC AI on Hugging Face](https://huggingface.co/mlc-ai)

---

**Last Updated:** January 24, 2026  
**Plugin Version:** 1.1.0+
