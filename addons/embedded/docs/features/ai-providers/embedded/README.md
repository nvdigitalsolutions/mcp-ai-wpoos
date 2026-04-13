# Embedded LLM - Quick Start Guide

## The Simple Answer

**Use Client-Side WebLLM** ✅

It:
- ✅ Works on **ANY** hosting (even shared hosting with disabled shell_exec)
- ✅ Requires **NO** special permissions or configuration
- ✅ Uses **ZERO** server resources
- ✅ Provides **100%** privacy (data never leaves browser)
- ✅ Is **GPU-accelerated** (faster than server CPU)
- ✅ Has **NO** download interface (models auto-load)

## Quick Setup (3 Steps)

### 1. Enable in Settings

Navigate to: **Settings → NV oOS → Providers → Embedded LLM**

```
☑ Enable client-side embedded language models (Pro)
```

### 2. Select a Model

Choose from dropdown:
- **Llama 3.2 1B Instruct** (~800MB) ← **Recommended**
- Qwen2.5 0.5B Instruct (~400MB) ← Fastest
- Qwen2.5 1.5B Instruct (~1GB)
- Llama 3.2 3B Instruct (~2GB)
- Phi-3.5 Mini Instruct (~2.5GB)

### 3. Save and Use

Click **Save Settings**

Models automatically download to user's browser on first use.

**That's it!** No binaries, no shell_exec, no configuration needed.

## Common Questions

### Q: Does it need shell_exec?
**A: NO** ❌ - Runs in browser, not on server

### Q: Where do models come from?
**A:** MLC AI CDN (Hugging Face) - Browser downloads directly

### Q: Do I need to download models?
**A: NO** - Models auto-download to each user's browser cache

### Q: How much server storage needed?
**A: ZERO** - Models stored in browser IndexedDB, not on server

### Q: Does it use server CPU/RAM?
**A: NO** - Uses user's GPU/CPU

### Q: Will it work on my hosting?
**A: YES** - Works on ANY hosting (Cloudways, WP Engine, Kinsta, GoDaddy, etc.)

### Q: What about bundling binaries in the plugin?
**A: NOT NEEDED** - Client-side doesn't use binaries. Server-side would still need shell_exec even with bundled binaries.

### Q: Can I use the download interface?
**A: NOT APPLICABLE** - No admin download needed. Models download automatically to users' browsers.

## Browser Requirements

**Supported:**
- ✅ Chrome 113+ (Desktop/Android)
- ✅ Edge 113+ (Desktop)
- ✅ Safari 18+ (macOS/iOS)

**Not Yet:**
- ❌ Firefox (WebGPU in development)

Fallback to CPU (WebAssembly) works on all modern browsers.

## Performance

**First Use (Model Download):**
- Qwen2.5 0.5B: ~30-60 seconds
- Llama 3.2 1B: ~1-2 minutes
- Llama 3.2 3B: ~3-5 minutes

**Subsequent Uses:**
- Instant (< 1 second from cache)

**Inference Speed:**
- Qwen2.5 0.5B: ~50-100 tokens/second
- Llama 3.2 1B: ~30-60 tokens/second
- Llama 3.2 3B: ~15-30 tokens/second

## Architecture Diagram

```
┌─────────────────────────────────────────────┐
│   USER'S BROWSER (Client-Side)             │
│                                             │
│   ┌─────────────────┐                      │
│   │  1. Visit Chat  │                      │
│   └────────┬────────┘                      │
│            │                                │
│            v                                │
│   ┌─────────────────┐                      │
│   │ 2. Load WebLLM  │ ← From plugin JS     │
│   │    Library      │                      │
│   └────────┬────────┘                      │
│            │                                │
│            v                                │
│   ┌─────────────────┐                      │
│   │ 3. Download     │ ← From MLC AI CDN    │
│   │    Model        │   (Hugging Face)     │
│   └────────┬────────┘                      │
│            │                                │
│            v                                │
│   ┌─────────────────┐                      │
│   │ 4. Cache in     │                      │
│   │    IndexedDB    │                      │
│   └────────┬────────┘                      │
│            │                                │
│            v                                │
│   ┌─────────────────┐                      │
│   │ 5. WebGPU       │                      │
│   │    Inference    │                      │
│   └────────┬────────┘                      │
│            │                                │
│            v                                │
│   ┌─────────────────┐                      │
│   │ 6. Display      │                      │
│   │    Response     │                      │
│   └─────────────────┘                      │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│   WORDPRESS SERVER (Zero Involvement)       │
│                                             │
│   ┌─────────────────┐                      │
│   │ Just serves:    │                      │
│   │ - HTML          │                      │
│   │ - CSS           │                      │
│   │ - JavaScript    │                      │
│   └─────────────────┘                      │
│                                             │
│   NO model storage                          │
│   NO inference processing                   │
│   NO shell_exec needed                      │
└─────────────────────────────────────────────┘
```

## Comparison: Client-Side vs Alternatives

| Solution | shell_exec? | Shared Hosting? | Setup | Performance |
|----------|-------------|-----------------|-------|-------------|
| **Client-Side WebLLM** | ❌ NO | ✅ YES | Easy | Fast (GPU) |
| Server-Side + Binaries | ✅ YES | ❌ NO | Hard | Slow (CPU) |
| Ollama (Separate Server) | ❌ NO* | ✅ YES* | Medium | Fast |
| OpenAI API | ❌ NO | ✅ YES | Easy | Fast |
| Anthropic API | ❌ NO | ✅ YES | Easy | Fast |

*Requires separate server for Ollama

**Winner:** Client-Side WebLLM for most users

## Complete Documentation

### Core Docs:
1. **[Shell Exec Requirements](./SHELL_EXEC_REQUIREMENTS.md)** - Does WebLLM need shell_exec? (NO)
2. **[Model Distribution](./CLIENT_SIDE_MODEL_DISTRIBUTION.md)** - Where do models come from?
3. **[Bundling Analysis](./BUNDLING_BINARIES_ANALYSIS.md)** - Why bundling binaries doesn't help
4. **[Feature Comparison](./EMBEDDED_LLM_COMPARISON.md)** - Client-side vs server-side
5. **[Comprehensive FAQ](./EMBEDDED_LLM_FAQ.md)** - All questions answered

### External Resources:
- [WebLLM Documentation](https://webllm.mlc.ai/)
- [MLC AI on Hugging Face](https://huggingface.co/mlc-ai)
- [WebGPU Support](https://caniuse.com/webgpu)

## Troubleshooting

### "Model won't download"
1. Check internet connection
2. Check browser (Chrome 113+, Safari 18+)
3. Disable VPN temporarily
4. Clear browser cache

### "Out of memory"
1. Close other browser tabs
2. Use smaller model (Qwen2.5 0.5B)
3. Restart browser
4. Upgrade device RAM

### "Not supported browser"
1. Update browser to latest version
2. Use Chrome/Edge/Safari
3. Enable WebGPU in browser settings
4. Or use CPU fallback (slower)

### "Package shows 'Not Found'"
**For `@mlc-ai/web-llm`:**
- Should show "Installed" if `embedded-llm-client.js` exists
- This is normal - loaded from CDN, not bundled

**For `qrcode`:**
- Should check Pro vendor directory first
- Then node_modules (development)
- Shows "Installed" if Pro addon active

## Migration from Server-Side

If you were planning to use server-side embedded LLM:

### Don't:
- ❌ Don't bundle binaries
- ❌ Don't try to enable shell_exec
- ❌ Don't download server-side models
- ❌ Don't install llama.cpp

### Do:
- ✅ Use Client-Side WebLLM
- ✅ Enable in settings (3 clicks)
- ✅ Let users' browsers handle models
- ✅ Enjoy zero server load

## Support

**Issues:**
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

**Questions:**
- Read the [Comprehensive FAQ](./EMBEDDED_LLM_FAQ.md)
- Check [Model Distribution](./CLIENT_SIDE_MODEL_DISTRIBUTION.md)

## Summary

**Client-Side WebLLM is the solution to all these problems:**
- ✅ No shell_exec needed
- ✅ No binaries to bundle
- ✅ No models to download (auto-downloads to browser)
- ✅ No server resources used
- ✅ Works on any hosting
- ✅ Maximum privacy
- ✅ GPU-accelerated

**Just enable it in settings and it works.** 🎉

## Technical Implementation

**Chat Client Execution:**

When an assistant is configured with the `embedded` provider:
1. Chat client detects provider from assistant configuration
2. Bypasses server-side REST API completely
3. Uses `embedded-llm-client.js` for browser-based inference
4. Streams responses directly from WebLLM engine
5. No server-side API requests are made at all

This is different from other providers (OpenAI, Gemini, etc.) which make server-side API calls. The embedded provider runs **100% client-side** in the browser using WebGPU/WebAssembly.

**Code Flow:**
```javascript
// In chat.js sendChat() function:
if (state.config.provider === 'embedded') {
    return sendChatEmbedded(state, messages, finalize, submissionContext);
}
// Otherwise, use normal REST API
```

**Benefits:**
- Zero server load (no PHP execution for chat completion)
- Perfect for high-traffic sites
- No API rate limits or quotas
- Complete data privacy (never leaves browser)
- Works even with `shell_exec` disabled

---

**Last Updated:** January 24, 2026
**Plugin Version:** 1.1.0+  
**Recommended for:** Everyone (shared hosting, VPS, dedicated, all users)
