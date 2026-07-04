# Local Voice + Embedded STT — Feature Proposal

**Status:** 📋 Proposed
**Created:** June 28, 2026
**Author:** AI Agent (NV oOS)
**PR:** TBD

---

## Executive Summary

NV oOS already has a mature cloud voice stack — PR #5479 delivered GPT-Realtime-2 WebRTC transport, realtime translation, streaming Whisper STT, push-to-talk, commentary phase, and waveform visualization. But every audio frame flows through OpenAI or Gemini cloud APIs. For the embedded addon (`addons/embedded/`), which already ships WebLLM, llama.cpp GGUF inference, and Transformers.js for browser-side LLM, there is **no voice path at all**.

This proposal adds three pluggable browser-side STT backends, reusable by both the cloud voice UI and the embedded addon, plus a server-assisted Gemma 4 audio pipeline. The result is local-first voice that costs $0 per minute, keeps audio on-device, and works offline — while remaining opt-in and fully backward-compatible with the existing cloud stack.

---

## Problem Statement

> What problem does this solve? Who experiences it? What is the current workaround?

| # | Problem | Impact | Current Workaround |
|---|---------|--------|-------------------|
| 1 | **No local STT** — all speech-to-text goes through OpenAI or Gemini cloud APIs | Users on embedded/WebLLM assistants have zero voice capability; cloud-only users pay per-minute STT costs | None for embedded; cloud users type instead |
| 2 | **Gemma 4 audio unrecognized** — E2B/E4B native audio input not leveraged as an STT source | The only open model at this size with native audio is treated as text-only LLM | Use OpenAI Whisper instead (cloud, cost) |
| 3 | **No PR #5479 parity for local mode** — push-to-talk, commentary phase, waveform visualization only work with cloud providers | Fragmented UX: cloud voice users get a rich interface; embedded users get nothing | Build separate voice UI from scratch (not done) |
| 4 | **Transformers.js underutilized** — already integrated for WebLLM task pipelines but not for Whisper | Existing `transformers-tasks-client.js` handles summarization, NER, embeddings, translation — but not STT | Skip voice, or use cloud APIs |
| 5 | **No voice UX for embedded** — embedded/WebLLM assistant instances have no voice toggle, no PTT button, no waveform | Embedded assistants are text-only, limiting their utility for accessibility and hands-free use cases | Use cloud assistant instead (defeats purpose of embedded) |

---

## Target Users

- **Privacy-conscious site owners** who want voice without sending audio to third-party APIs
- **Embedded/WebLLM users** running local LLMs (llama.cpp GGUF, WebLLM WebGPU) who need voice input
- **Developers** building voice-enabled WordPress applications with zero API-key requirements
- **Enterprise deployments** requiring GDPR/HIPAA-compliant on-device voice processing
- **Accessibility users** who need hands-free interaction with NV oOS assistants

---

## Industry Research

### whisper.cpp WASM (ggml-org/whisper.cpp)

| Attribute | Value |
|-----------|-------|
| Maturity | Production-ready, 50K+ GitHub stars |
| Browser support | Official WASM examples with real-time streaming and Web Worker threading |
| Model size | ~75 MB (`tiny.en`), up to ~1.5 GB (`large-v3`) |
| Speed | 2–3× real-time on modern CPU (WASM); ~5× real-time with SIMD WASM |
| License | MIT |
| Privacy | Audio never leaves the device |
| Worker support | Designed for Web Worker use; includes `stream.wasm` example |

### Transformers.js Whisper (huggingface/transformers.js v3)

| Attribute | Value |
|-----------|-------|
| Maturity | Stable (v3.8.1); already integrated into NV oOS via `transformers-tasks-client.js` |
| Browser support | WebGPU acceleration gives 3–5× speedup over WASM on supported hardware |
| Model range | `whisper-tiny` (~39 MB) through `whisper-large-v3` (~1.5 GB) |
| License | Apache 2.0 |
| Integration cost | Low — same CDN, same pipeline API, same Web Worker pattern |
| Fallback | Automatic WASM fallback when WebGPU unavailable |

### Gemma 4 Audio (Google, April 2025)

| Attribute | Value |
|-----------|-------|
| Variants | E2B and E4B with native audio input tokens |
| Capability | Speech recognition + reasoning + vision + function calling — single model |
| Browser availability | Not yet — requires WebGPU runtime that does not exist for Gemma 4 |
| Server availability | Ollama 0.5+, vLLM |
| License | Gemma license (open weights) |
| Paradigm | Single-model voice AI: audio in → reasoning + tools → text/speech out |

### AudioWorklet & Web Workers

| API | Role |
|-----|------|
| **AudioWorklet** | Low-latency audio capture in a dedicated real-time thread. Feeds raw PCM buffers into the STT pipeline without main-thread jank |
| **Web Workers** | Required for WASM/WebGPU inference to avoid blocking the UI. Both whisper.cpp WASM and Transformers.js are designed for Worker use |
| **SharedArrayBuffer** | Enables zero-copy buffer transfer between AudioWorklet and STT Worker (requires COOP/COEP headers) |

### Comparison Matrix

| Backend | Runtime | Model Size | Speed | License | Privacy | NV oOS Dependencies |
|---------|---------|-----------|-------|---------|---------|---------------------|
| whisper.cpp WASM | Browser (Worker) | 75 MB–1.5 GB | 2–3× RT | MIT | ✅ On-device | None (new CDN dep) |
| Transformers.js Whisper | Browser (Worker + WebGPU) | 39 MB–1.5 GB | 3–5× RT | Apache 2.0 | ✅ On-device | Existing `transformers-tasks-client.js` |
| Gemma 4 Audio | Server (Ollama/vLLM) | 2B–4B params | Realtime | Gemma | ✅ Self-hosted | Existing embedded client infra |

---

## Proposed Solution

### Three Pluggable STT Backends

```
┌──────────────────────────────────────────────────────────────────────┐
│                    Local STT Backend Registry                          │
│                                                                        │
│  ┌─────────────────┐  ┌───────────────────┐  ┌────────────────────┐  │
│  │ whisper.cpp      │  │ Transformers.js   │  │ Gemma 4 Unified    │  │
│  │ WASM (P0)        │  │ Whisper (P2)      │  │ (P1)               │  │
│  │                  │  │                   │  │                    │  │
│  │ • MIT license    │  │ • Apache 2.0      │  │ • Gemma license    │  │
│  │ • 2-3x realtime  │  │ • 3-5x (WebGPU)   │  │ • Audio+LLM+Vision │  │
│  │ • Web Worker     │  │ • Pipeline API     │  │ • Server-side      │  │
│  │ • All browsers   │  │ • Chrome/Edge      │  │ • Ollama / vLLM    │  │
│  └────────┬────────┘  └────────┬──────────┘  └────────┬───────────┘  │
│           │                    │                       │               │
│           └────────────────────┼───────────────────────┘               │
│                                ▼                                       │
│              ┌─────────────────────────────────────┐                   │
│              │     STT Provider Interface           │                   │
│              │  • initialize(model, options)        │                   │
│              │  • transcribe(audioBuffer) → text   │                   │
│              │  • streamStart() → AsyncIterator    │                   │
│              │  • isAvailable() → bool             │                   │
│              │  • getLatency() → ms                │                   │
│              └─────────────────┬───────────────────┘                   │
│                                │                                       │
│              ┌─────────────────┴───────────────────┐                   │
│              │         Consumers                    │                   │
│              │                                      │                   │
│              │  • Voice Mode UI (PR #5479 parity)   │                   │
│              │  • Embedded/WebLLM assistants        │                   │
│              │  • POST /embedded/transcribe REST    │                   │
│              └──────────────────────────────────────┘                   │
└──────────────────────────────────────────────────────────────────────┘
```

### P0 — whisper.cpp WASM Backend

**Why first:** Most mature browser WASM STT, MIT license, works in Firefox/Safari/Chrome, no WebGPU requirement, designed for Web Workers, ~75 MB model footprint, streaming support.

**Implementation:**
1. **`assets/js/workers/stt-whisper-wasm-worker.js`** — Web Worker that loads whisper.cpp WASM from CDN (jsDelivr), receives `Float32Array` audio chunks via `postMessage`, returns transcription text
2. **`assets/js/stt-provider-whisper-wasm.js`** — Thin browser-side wrapper implementing the STT Provider Interface, managing Worker lifecycle
3. **Admin settings:** Model selection (`tiny.en` / `base.en` / `small.en`), language code, VAD threshold

### P1 — Gemma 4 Unified Backend (Server-Assisted)

**Why second:** Paradigm shift — single model does audio understanding + reasoning + tool calling. Leverages existing embedded infrastructure (`WP_MCP_AI_Embedded_Client`, Ollama model management). No browser-side Gemma 4 runtime yet, so server-side only initially.

**Implementation:**
1. **`addons/embedded/includes/stt/class-nvoos-embedded-gemma4-stt.php`** — Server-side STT class using Ollama's multimodal API with Gemma 4 E2B/E4B
2. **`POST /mcp-ai/v1/embedded/transcribe`** — REST endpoint accepting audio blobs, returning transcription text. Capability-gated
3. **`assets/js/stt-provider-gemma4-server.js`** — Browser client that sends audio to the REST endpoint
4. **Admin settings:** Gemma 4 model variant (E2B/E4B), Ollama endpoint URL, timeout

### P2 — Transformers.js Whisper Backend

**Why third:** WebGPU accelerated (3–5× faster than WASM), already in dependency tree, but WebGPU support is Chrome/Edge-only and model loading is heavier than whisper.cpp.

**Implementation:**
1. **`assets/js/stt-provider-transformers-whisper.js`** — Uses existing `transformers-tasks-client.js` pattern, extends pipeline registry with `automatic-speech-recognition` task
2. **`assets/js/workers/stt-transformers-whisper-worker.js`** — Worker wrapper for non-blocking inference
3. **Admin settings:** Model selection, WebGPU/WASM device preference

### Voice UX — PR #5479 Parity (P2–P3)

The cloud voice features delivered in PR #5479 must work identically with local STT:
- **Push-to-Talk (PTT) button** — Hold to speak, release to transcribe; same CSS class conventions
- **Waveform visualization** — Canvas-based real-time audio level display during recording
- **Transcription display** — Live partial text overlay, same DOM structure as `chat-transcription-realtime-service.js`
- **Status bar** — Same state classes (`--listening`, `--processing`, `--speaking`) for CSS consistency
- **Commentary phase** — Brief post-transcription analysis (LLM call, works with both cloud and embedded LLM)

These components are built once and shared across all STT backends via the common `STT Provider Interface`.

### Architecture Integration

```
┌─────────────────────────────────────────────────────────────────┐
│                        BROWSER                                    │
│                                                                   │
│  ┌──────────┐  ┌───────────────────┐  ┌───────────────────────┐ │
│  │ Chat UI  │  │ Voice Mode        │  │ Embedded / WebLLM     │ │
│  │ (chat.js)│  │ Integration       │  │ Client Handler        │ │
│  └────┬─────┘  └────────┬──────────┘  └───────────┬───────────┘ │
│       │                 │                         │               │
│       │    ┌────────────┴────────────┐            │               │
│       │    │  STT Provider Interface │            │               │
│       │    │  (new JS abstraction)   │            │               │
│       │    └────────┬───┬────────────┘            │               │
│       │             │   │                         │               │
│       │    ┌────────┘   └────────┐                │               │
│       │    ▼                     ▼                ▼               │
│  ┌────┴───────────┐  ┌──────────────────┐  ┌──────────────────┐ │
│  │ whisper.cpp    │  │ Transformers.js  │  │ Gemma 4 Server   │ │
│  │ WASM Worker    │  │ Whisper Worker   │  │ AJAX Client      │ │
│  │ (P0, MIT)      │  │ (P2, Apache 2.0) │  │ (P1, REST-based) │ │
│  └────────────────┘  └──────────────────┘  └────────┬─────────┘ │
│                                                       │          │
└───────────────────────────────────────────────────────┼──────────┘
                                                        │
                                          POST /embedded/transcribe
                                                        │
┌───────────────────────────────────────────────────────┼──────────┐
│                   WORDPRESS BACKEND (PHP)              │          │
│                                                        ▼          │
│  ┌──────────────────────────────────────────────────────────────┐ │
│  │              Embedded Voice REST Controller (new)              │ │
│  │  POST /mcp-ai/v1/embedded/transcribe                         │ │
│  │  GET  /mcp-ai/v1/embedded/stt/config                         │ │
│  └──────────────────────────┬───────────────────────────────────┘ │
│                             │                                     │
│  ┌──────────────────────────┴───────────────────────────────────┐ │
│  │              Gemma 4 STT Client (new PHP class)               │ │
│  │  • Ollama multimodal API integration                          │ │
│  │  • Audio chunk buffering                                      │ │
│  │  • Model availability check (Gemma 4 E2B/E4B)                 │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐ │
│  │                   Voice REST Controller (existing)             │ │
│  │  /voice/config    /voice/session    /voice/providers          │ │
│  │  /realtime/token  /realtime/session                           │ │
│  └──────────────────────────────────────────────────────────────┘ │
└───────────────────────────────────────────────────────────────────┘
```

### New Files

| File | Purpose | Tier |
|------|---------|------|
| `assets/js/stt-provider-interface.js` | Common STT provider contract (JS) | Base |
| `assets/js/stt-provider-whisper-wasm.js` | whisper.cpp WASM STT provider | Base |
| `assets/js/workers/stt-whisper-wasm-worker.js` | whisper.cpp WASM Web Worker | Base |
| `assets/js/stt-provider-transformers-whisper.js` | Transformers.js Whisper STT provider | Base |
| `assets/js/workers/stt-transformers-whisper-worker.js` | Transformers.js Whisper Web Worker | Base |
| `assets/js/stt-provider-gemma4-server.js` | Gemma 4 server-side STT client | Addon |
| `assets/js/voice-ux-components.js` | Shared PTT, waveform, transcription UI | Base |
| `addons/embedded/includes/stt/class-nvoos-embedded-gemma4-stt.php` | Gemma 4 Ollama STT PHP class | Addon |
| `addons/embedded/includes/stt/class-nvoos-embedded-stt-rest-controller.php` | Embedded STT REST controller | Addon |
| `includes/stt/class-wp-mcp-ai-stt-settings.php` | STT admin settings | Base |

### Modified Files

| File | Change | Scope |
|------|--------|-------|
| `chat-voice-mode-integration.js` | Register local STT providers; add `local` transport mode | +60 lines |
| `chat.js` | Wire local STT into voice flow; add embedded voice toggle | +30 lines |
| `addons/embedded/includes/class-nvoos-embedded.php` | Register STT hooks, REST routes, settings | +80 lines |
| `addons/embedded/nvoos-embedded.php` | Load new STT classes | +10 lines |
| `includes/rest/class-wp-mcp-ai-rest-voice-controller.php` | Add `/voice/providers` local provider entries | +15 lines |
| `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | Add `stt_backend`, `stt_model`, `stt_vad_threshold`, `stt_language` settings | +40 lines |
| `addons/embedded/includes/admin/class-wp-mcp-ai-webllm-settings-page.php` | Add embedded STT settings section | +50 lines |

---

## Settings

### Base Plugin — New Options in `wp_mcp_ai_settings`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `stt_backend` | `string` | `whisper_wasm` | Active STT backend: `whisper_wasm`, `transformers_whisper`, `gemma4_server`, `cloud`, `browser` |
| `stt_model` | `string` | `tiny.en` | Whisper model variant: `tiny.en`, `base.en`, `small.en`, `medium.en` |
| `stt_language` | `string` | `en` | ISO 639-1 language code for transcription |
| `stt_vad_threshold` | `float` | `0.5` | Voice activity detection sensitivity (0.0–1.0) |
| `stt_vad_silence_ms` | `integer` | `800` | Milliseconds of silence before auto-stop |

### Embedded Addon — New Options in `nvoos_embedded_settings`

| Key | Type | Default | Description |
|------|------|---------|-------------|
| `embedded_stt_enabled` | `bool` | `true` | Enable local STT for embedded assistants |
| `embedded_stt_backend` | `string` | `whisper_wasm` | STT backend for embedded: `whisper_wasm`, `transformers_whisper`, `gemma4_server` |
| `embedded_stt_model` | `string` | `tiny.en` | Whisper model for embedded STT |
| `embedded_stt_gemma4_model` | `string` | `gemma4-e2b` | Gemma 4 variant: `gemma4-e2b`, `gemma4-e4b` |
| `embedded_stt_gemma4_endpoint` | `string` | `http://localhost:11434` | Ollama/vLLM endpoint for Gemma 4 |
| `embedded_stt_vad_threshold` | `float` | `0.5` | VAD sensitivity for embedded STT |

---

## STT Provider Interface (JavaScript)

```javascript
/**
 * STT Provider Interface — all STT backends implement this contract.
 *
 * @interface STTProvider
 */

/**
 * @typedef {Object} STTProviderConfig
 * @property {string} model      - Model identifier (e.g. 'tiny.en')
 * @property {string} language   - ISO 639-1 language code
 * @property {number} vadThreshold - VAD sensitivity 0.0-1.0
 * @property {number} sampleRate  - Audio sample rate (default: 16000)
 */

/**
 * @typedef {Object} STTResult
 * @property {string} text      - Transcribed text
 * @property {boolean} isFinal  - Whether this is a final result
 * @property {number} confidence - 0.0-1.0 confidence score
 * @property {number} latencyMs - Processing latency in milliseconds
 */

/**
 * Initialize the STT provider. Called once before any transcription.
 * @param {STTProviderConfig} config
 * @returns {Promise<void>}
 */
function initialize(config) {}

/**
 * Transcribe a complete audio buffer. For one-shot (non-streaming) use.
 * @param {Float32Array} audioBuffer - 16kHz mono PCM
 * @returns {Promise<STTResult>}
 */
function transcribe(audioBuffer) {}

/**
 * Start streaming transcription. Returns an async iterator.
 * Push audio chunks via pushAudio(); read results from the iterator.
 * @returns {AsyncIterator<STTResult>}
 */
function streamStart() {}

/**
 * Push an audio chunk for streaming transcription.
 * @param {Float32Array} chunk - 16kHz mono PCM
 */
function pushAudio(chunk) {}

/**
 * Signal end of audio stream. The provider returns final results.
 */
function streamEnd() {}

/**
 * Check if this STT backend is available in the current environment.
 * @returns {boolean}
 */
function isAvailable() {}

/**
 * Get the estimated processing latency in milliseconds.
 * @returns {number}
 */
function getLatency() {}

/**
 * Release resources. Call when the provider is no longer needed.
 * @returns {Promise<void>}
 */
function dispose() {}
```

---

## REST API

### `POST /mcp-ai/v1/embedded/transcribe` (New)

Server-assisted transcription via Gemma 4.

**Request:**
```json
{
  "audio": "<base64-encoded PCM16/WAV audio>",
  "model": "gemma4-e2b",
  "language": "en",
  "assistant_id": 42
}
```

**Response (200):**
```json
{
  "success": true,
  "text": "transcribed text here",
  "confidence": 0.94,
  "latency_ms": 320
}
```

**Response (error):**
```json
{
  "success": false,
  "error": "Gemma 4 model not available. Ensure gemma4-e2b is pulled in Ollama.",
  "code": "model_not_found"
}
```

**Permission:** `edit_posts` minimum. `manage_options` for admin-only models.

### `GET /mcp-ai/v1/embedded/stt/config` (New)

Returns available STT backends and their configurations.

**Response (200):**
```json
{
  "success": true,
  "backends": {
    "whisper_wasm": { "available": true, "models": ["tiny.en", "base.en"], "default_model": "tiny.en", "license": "MIT" },
    "transformers_whisper": { "available": false, "reason": "WebGPU not available", "models": ["whisper-tiny"], "default_model": "whisper-tiny", "license": "Apache-2.0" },
    "gemma4_server": { "available": true, "models": ["gemma4-e2b", "gemma4-e4b"], "default_model": "gemma4-e2b", "license": "Gemma" }
  },
  "active_backend": "whisper_wasm"
}
```

---

## Pricing Impact

| Backend | Cost per Minute | Infrastructure |
|---------|----------------|----------------|
| whisper.cpp WASM | **$0.00** | None — runs entirely in browser |
| Transformers.js Whisper | **$0.00** | None — runs entirely in browser |
| Gemma 4 (server-side) | **$0.00** (self-hosted) | Existing Ollama/vLLM server; electricity/GPU cost only |
| OpenAI Whisper (current) | ~$0.006/min | Cloud API per-request billing |
| Gemini STT (current) | ~$0.004/min | Cloud API per-request billing |

**Net savings for site owners:** Moving a 60-minute daily voice workload from cloud STT to local saves ~$10.80/month at OpenAI rates. Larger deployments save proportionally more. The feature pays for itself in reduced API costs.

---

## Risk Register

| # | Risk | Probability | Impact | Mitigation |
|---|------|------------|--------|------------|
| R1 | whisper.cpp WASM binary (~75 MB) causes slow first-load on metered connections | Medium | Medium | Load from CDN with lazy initialization; show model download progress bar; `tiny.en` is only ~75 MB (smaller than many hero images) |
| R2 | Web Workers do not support AudioWorklet in Safari (no AudioWorklet in Workers) | Low | Low | Main-thread AudioWorklet → `postMessage` to Worker is the standard pattern; Safari 16.4+ supports AudioWorklet |
| R3 | Gemma 4 E2B/E4B audio quality degrades with background noise | Medium | Medium | VAD pre-filtering; noise suppression via Web Audio API `NoiseSuppressor`; user education on microphone placement |
| R4 | Model download failures cause silent fallback to cloud, frustrating privacy-conscious users | Low | High | Explicit "download failed" notification; retry button; never silently fall back to cloud when user chose local |
| R5 | Transformers.js Whisper model loading conflicts with existing pipeline cache in `transformers-tasks-client.js` | Low | Medium | Separate Worker scope; independent pipeline registry; shared CDN cache only |
| R6 | SharedArrayBuffer requirement (for zero-copy AudioWorklet→Worker) requires COOP/COEP headers that break third-party embeds | Medium | High | Detect SAB availability; fall back to structured cloning with `transfer` list; COOP/COEP is already required by WebLLM multi-threading anyway |
| R7 | Gemma 4 Ollama endpoint not reachable from the WordPress server | Low | Medium | Health-check endpoint; admin notice when configured but unreachable; graceful degradation to browser-side STT |

---

## Phased Implementation Plan

### Phase 1 — whisper.cpp WASM (P0) — 3 weeks

**Goal:** Local STT works in all browsers for both cloud and embedded assistants.

- Week 1: `stt-provider-interface.js` + `stt-whisper-wasm-worker.js` + `stt-provider-whisper-wasm.js`
- Week 2: Wire into `chat-voice-mode-integration.js`; add `local` transport mode; admin settings
- Week 3: `voice-ux-components.js` (PTT button, waveform, transcription, status bar); testing

### Phase 2 — Gemma 4 Unified (P1) — 2 weeks

**Goal:** Server-assisted Gemma 4 audio for embedded/WooCommerce assistants.

- Week 4: `class-nvoos-embedded-gemma4-stt.php` + REST endpoint + `stt-provider-gemma4-server.js`
- Week 5: Admin settings; model availability detection; health checks; testing

### Phase 3 — Voice UX + Transformers.js (P2–P3) — 2 weeks

**Goal:** PR #5479 UX parity; WebGPU-accelerated STT on Chrome/Edge.

- Week 6: `stt-provider-transformers-whisper.js` + Worker; integrate with existing pipeline cache
- Week 7: Commentary phase; accessibility testing; cross-browser validation; documentation

**Total:** 7 weeks for complete feature; 3 weeks for MVP (Phase 1 only).

---

## Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Local STT latency (whisper.cpp tiny.en) | < 500 ms for 3-second utterance | Browser Performance API |
| Model download success rate | > 98% | CDN analytics + client-side telemetry |
| Embedded voice enablement rate | > 40% of embedded users activate voice within 30 days | WordPress option tracking |
| Cloud STT cost reduction | 30%+ for users who enable local mode | API usage dashboards |
| Browser compatibility | Chrome 90+, Firefox 90+, Safari 16.4+, Edge 90+ | Automated browser tests |
| Offline operation | Full STT works without internet after model download | Offline integration test |

---

## WordPress Ecosystem Context

### Related Plugins/Solutions

| Solution | Approach | Limitation |
|----------|---------|------------|
| OpenAI Whisper API | Cloud STT via HTTP | Requires API key; audio leaves device; costs money |
| Google Cloud Speech-to-Text | Cloud STT via gRPC | Requires API key; complex setup |
| Browser SpeechRecognition API | Built-in browser STT (Chrome only) | Chrome-only; sends audio to Google servers; no model control |
| Custom whisper.cpp server | Self-hosted STT server | Requires separate infrastructure; not browser-native |

### WordPress Core Features Leveraged

- WP REST API (`rest_api_init`) for Gemma 4 transcription and STT config endpoints
- `wp_register_script()` / `wp_enqueue_script()` for CDN-sourced JS modules
- `wp_add_inline_script()` for STT configuration localization
- WordPress options API for STT settings persistence
- `wp_localize_script()` for STT backend availability info

### NV oOS Components Affected

- [x] Tool registry — no new tools; STT feeds text into existing tools
- [x] REST API — new `/embedded/transcribe` and `/embedded/stt/config` endpoints
- [x] Chat UI — voice mode integration, PTT button, waveform, transcription overlay
- [x] Admin settings — STT backend selection, model picker, VAD parameters
- [x] Embedded addon — `NV_oOS_Embedded` STT integration, admin settings
- [ ] Database schema — no changes needed (options-based configuration)
- [x] CDN dependencies — whisper.cpp WASM binary; Transformers.js Whisper models (Hugging Face)

---

## Recommendation

**Proceed, phased.**

1. **Phase 1 (P0)** — whisper.cpp WASM. Delivers immediate value: $0 STT, works everywhere, privacy-first. Three weeks to MVP.
2. **Phase 2 (P1)** — Gemma 4 server-side. Unlocks the single-model paradigm for embedded assistants. Two weeks.
3. **Phase 3 (P2–P3)** — Transformers.js Whisper + full voice UX parity. Complements whisper.cpp with WebGPU acceleration. Two weeks.

The STT Provider Interface abstraction ensures that each backend is independent and testable. No existing voice functionality is affected — local STT is purely additive and opt-in.

---

## Appendices

### A. Glossary

| Term | Definition |
|------|-----------|
| STT | Speech-to-Text — converting spoken audio into written text |
| PTT | Push-to-Talk — user holds a button to record, releases to stop |
| VAD | Voice Activity Detection — algorithm that detects when a person is speaking vs. silence |
| WASM | WebAssembly — low-level binary format that runs at near-native speed in the browser |
| WebGPU | Modern browser GPU API for compute shaders and ML inference acceleration |
| AudioWorklet | Web Audio API feature for low-latency audio processing in a dedicated real-time thread |
| COOP/COEP | Cross-Origin Opener Policy / Cross-Origin Embedder Policy — HTTP headers required for SharedArrayBuffer |
| GGUF | GPT-Generated Unified Format — quantized model file format used by llama.cpp and whisper.cpp |
| SAB | SharedArrayBuffer — allows multiple threads to share memory, useful for zero-copy audio pipelines |

### B. Model Size Comparison

| Model | Size | RAM Required | WER on LibriSpeech | First-Load Time (10 Mbps) |
|-------|------|-------------|---------------------|---------------------------|
| whisper-tiny.en | 75 MB | ~ 200 MB | ~7.6% | ~60 seconds |
| whisper-base.en | 142 MB | ~ 300 MB | ~5.0% | ~114 seconds |
| whisper-small.en | 466 MB | ~ 800 MB | ~3.4% | ~373 seconds |
| whisper-medium.en | 1.5 GB | ~ 2 GB | ~2.7% | ~1,200 seconds |
| Gemma 4 E2B | 2B params | ~ 4 GB | N/A (native audio) | N/A (server-side) |
| Gemma 4 E4B | 4B params | ~ 8 GB | N/A (native audio) | N/A (server-side) |

### C. Browser Compatibility Matrix

| Feature | Chrome 90+ | Firefox 90+ | Safari 16.4+ | Edge 90+ |
|---------|-----------|------------|-------------|---------|
| WebAssembly SIMD | ✅ | ✅ | ✅ | ✅ |
| Web Workers | ✅ | ✅ | ✅ | ✅ |
| AudioWorklet | ✅ | ✅ | ✅ | ✅ |
| WebGPU | ✅ (113+) | ❌ (nightly) | ❌ | ✅ (113+) |
| SharedArrayBuffer | ✅ | ✅ | ✅ (15.2+) | ✅ |
| whisper.cpp WASM | ✅ | ✅ | ✅ | ✅ |
| Transformers.js WebGPU | ✅ | ❌ (WASM fallback) | ❌ (WASM fallback) | ✅ |
| Gemma 4 (server) | ✅ | ✅ | ✅ | ✅ |

### D. References

- [whisper.cpp](https://github.com/ggml-org/whisper.cpp) — MIT-licensed C/C++ Whisper implementation with WASM examples
- [Transformers.js v3](https://github.com/huggingface/transformers.js) — Hugging Face transformers in the browser (Apache 2.0)
- [Gemma 4 Announcement](https://blog.google/technology/developers/gemma-4/) — Google's April 2025 open model with native audio
- [Web Audio API — AudioWorklet](https://developer.mozilla.org/en-US/docs/Web/API/AudioWorklet) — MDN reference
- [PR #5479](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5479) — GPT-Realtime-2 cloud voice feature (v1.1.34)
- [GPT-Realtime-2 Upgrade Proposal](./GPT-REALTIME-2-UPGRADE-PROPOSAL.md)
- [GPT-Realtime-2 Implementation Plan](./GPT-REALTIME-2-IMPLEMENTATION-PLAN.md)
- [Web-LLM Enhancement Executive Summary](./WEB-LLM-ENHANCEMENT-EXECUTIVE-SUMMARY.md)
- [Web-LLM Implementation Phase 1](./WEB-LLM-IMPLEMENTATION-PHASE-1.md)
