# Embedded Addon — Comprehensive Enhancement Plan

**Status:** 📋 Proposed  
**Date:** 2026-08-04  
**Author:** AI Agent (Zed) — Research + Audit  
**Based on:** Full codebase audit of `addons/embedded/`, review of 2 existing related proposals, industry best-practice research  
**Related proposals:**
- `healthcare-vitals-openmed-integration-plan-v2.md` (OpenMed — not started)
- `LOCAL-VOICE-EMBEDDED-PROPOSAL.md` + `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md` (Voice/STT — partially implemented)

---

## Executive Summary

The **NV oOS Embedded AI Addon** (v0.1.0) is a production-grade WordPress addon that provides three major capabilities:

1. **Embedded LLM Inference** — Client-side WebLLM (WebGPU) + Server-side llama.cpp (GGUF)
2. **Voice / STT** — 3 backends (whisper.cpp WASM, Gemma 4 Audio, Transformers.js Whisper)
3. **WebChat P2P** — Decentralized WebRTC rooms with JetEngine CCT + 7 tool classes

The addon has significant structural depth (26 JS assets, 18 PHP classes, 15 test files, 16 documentation pages) but also carries **dead code, incomplete features, and architectural debt** that need resolution. Two major enhancement proposals exist — **OpenMed healthcare integration** (7 phases, not started) and **Local Voice Embedded** (partially implemented) — both of which extend the addon's scope considerably.

This plan identifies **16 actionable enhancements** organized into **4 work streams** prioritized by impact and dependency chain.

---

## Current State Audit

### Architecture Overview

```
addons/embedded/
├── nvoos-embedded.php                    ← Entry point (constants, class loader)
├── includes/
│   ├── class-nvoos-embedded.php          ← Core singleton (623 lines, 18 methods)
│   ├── embedded/
│   │   ├── class-wp-mcp-ai-embedded-client.php      ← llama.cpp server-side client (1,803 lines)
│   │   ├── class-nvoos-embedded-webllm-enqueue.php  ← WebLLM script registration
│   │   └── class-wp-mcp-ai-embedded-transcribe.php  ← Gemma 4 server-side STT
│   ├── admin/
│   │   ├── class-wp-mcp-ai-embedded-model-ajax.php  ← Model download/delete AJAX
│   │   └── class-wp-mcp-ai-webllm-settings-page.php ← Settings UI
│   └── webchat/
│       ├── class-wp-mcp-ai-webchat-cpt.php           ← Custom Post Type
│       ├── class-wp-mcp-ai-webchat-settings-page.php ← WebChat admin
│       ├── class-wp-mcp-ai-webchat-signaling-rest-controller.php ← WebRTC signaling
│       ├── class-wp-mcp-ai-jetengine-webchat-messages-cct.php    ← JetEngine storage
│       ├── tools/ (7 tool classes)                   ← create/get/list/status/messages/save/send
│       └── metaboxes/ (4 metabox classes)            ← Admin UI for rooms
├── assets/
│   ├── js/ (26 files)                     ← WebLLM loader, client, tool adapter, STT, voice, workers
│   └── css/ (2 files)                     ← admin-webchat.css, voice-embedded.css
├── docs/ (16 markdown files)              ← Implementation docs, FAQ, comparison, guides
├── tests/php/ (15 test files)             ← PHPUnit tests for all subsystems
└── bin/                                   ← Build tooling
```

### Component Inventory

| Component | Status | Lines/Count | Notes |
|-----------|--------|------------|-------|
| **Client-Side WebLLM** | ✅ Production | 4 JS files + enqueue manager | Primary inference path; 5 MLC-compiled models |
| **Server-Side llama.cpp** | ⚠️ Deprecated (per docs) | `WP_MCP_AI_Embedded_Client` (1,803 lines) | Still in active code path; FAQ says "will be removed" |
| **Voice / STT** | ⚠️ Experimental | 9 JS files + 1 PHP class | whisper.cpp WASM, Gemma 4, Transformers.js; partial |
| **WebChat** | ✅ Production | 12 PHP classes | CPT, WebRTC signaling, JetEngine CCT, 7 tools |
| **WebLLM Tool Calling** | ⚠️ Experimental | 2 JS files (tool-adapter, function-calling) | Phase 1 only; LangChain.js orchestration bundled |
| **Transformers.js Browser AI** | ✅ Production | 1 JS class | 6 browser-native AI tasks (summarize, NER, sentiment, etc.) |
| **LangChain.js Orchestration** | ✅ Production | 2 JS files | Multi-step reasoning, agents, memory |
| **Model Download Admin UI** | ⚠️ Confusing | AJAX handler + settings page | Server-side model management still visible |

### Security Posture (per April 2026 audit)

| Severity | Count | Notes |
|----------|-------|-------|
| Critical | 0 | |
| High | 0 | |
| Medium | 2 | Not detailed in ADDON_INVENTORY |
| Low | 1 | Not detailed in ADDON_INVENTORY |

### Test Coverage

| Test File | Coverage Area |
|-----------|---------------|
| `test-embedded-client-knowledge-tools.php` | Knowledge tool integration |
| `test-embedded-client-logging.php` | Logging behavior |
| `test-embedded-client-shared-libs.php` | Shared library detection |
| `test-embedded-model-service.php` | Model service operations |
| `test-embedded-model-slug-sanitization.php` | Slug sanitization |
| `test-embedded-provider-*.php` (5 files) | Provider visibility, dropdown, Elementor, Pro loading, subtabs |
| `test-embedded-transcribe-endpoint.php` | STT endpoint |
| `test-jetengine-webchat-cct-module-access.php` | JetEngine CCT access |
| `test-multiple-widgets-embedded-provider.php` | Multi-widget support |
| `test-webchat-assistant-assignment.php` | Assistant assignment |

---

## Gap Analysis

### 1. Architectural Gap — No Backend Registry Pattern for LLM Inference

**Problem:** The embedded addon supports two LLM inference modes — client-side WebLLM (browser) and server-side llama.cpp (WordPress server) — but they don't follow a unified backend pattern. The STT system already demonstrates the right architecture: multiple backends implementing a common `STTServiceAPI` contract, registered in a `STTProviderInterface`, with the active backend selected via settings.

**Current state:**
- `WP_MCP_AI_Embedded_Client` (1,803 lines) handles server-side llama.cpp GGUF inference — this was the original embedded feature
- `embedded-llm-client.js` handles client-side WebLLM browser inference — added later for shared hosting compatibility
- The two paths are wired through different mechanisms: the PHP class hooks into `wp_mcp_ai_embedded_chat_completion` filter, while the JS client bypasses server-side entirely

**The documentation contradicts itself:**
- `EMBEDDED_LLM_FAQ.md` says "Server-Side Embedded LLM is deprecated ❌" and lists files to remove
- `IMPLEMENTATION_COMPLETE.md` says "Server-side will be removed in future version"
- But `IMPLEMENTATION_SUMMARY.md` describes server-side as a core feature with detailed Cloudways setup guides

**Why the contradiction exists:** The original concern was about **base plugin size** — bundling llama.cpp binaries would make a WordPress.org-distributed plugin 200MB+. But the embedded addon is a **separate, proprietary addon** not distributed on WordPress.org. No size constraint applies. The server-side path is a legitimate, well-implemented feature for dedicated servers and VPS environments.

**The right pattern** (from the STT proposal's Architecture Integration diagram, Section 1.1):
```
┌─────────────────────────────────────────────────────────┐
│              Embedded LLM Backend Registry               │
│                                                          │
│  ┌──────────────────┐  ┌────────────────────────────┐   │
│  │ Client-Side       │  │ Server-Side                │   │
│  │ WebLLM (WebGPU)   │  │ llama.cpp (GGUF/CPU)       │   │
│  │                   │  │                            │   │
│  │ • Browser exec    │  │ • Server CPU exec          │   │
│  │ • Zero server CPU │  │ • shell_exec required      │   │
│  │ • Works on shared │  │ • Needs VPS/dedicated      │   │
│  │ • GPU accelerated │  │ • Consistent performance   │   │
│  └────────┬─────────┘  └─────────────┬──────────────┘   │
│           │                          │                   │
│           └──────────┬───────────────┘                   │
│                      ▼                                   │
│    ┌─────────────────────────────────────┐              │
│    │  Embedded Provider Interface         │              │
│    │  • is_available() → bool            │              │
│    │  • create_chat_completion() → result│              │
│    │  • stream_chat_completion() → stream│              │
│    └─────────────────────────────────────┘              │
└─────────────────────────────────────────────────────────┘
```

**Impact:** Both backends are valuable and should coexist as peer options — same as whisper.cpp WASM and Gemma 4 Server coexist in the STT system. The documentation needs correction, not the code.

### 2. Missing Feature — OpenMed Healthcare Integration (Not Started)

**Problem:** A comprehensive v2 integration plan (`healthcare-vitals-openmed-integration-plan-v2.md`, 1,041 lines) exists for integrating OpenMed (1,500+ clinical NER models, HIPAA-compliant PII de-identification) via a Dockerized FastAPI service. This would extend the embedded addon's scope into healthcare AI — a natural fit since the embedded addon already handles local-first, privacy-preserving inference.

The proposal covers:
- 5-layer defense-in-depth security model
- 7 implementation phases (21 days estimated)
- 8 new PHP classes + 8 modified files
- Docker Compose integration
- WordPress Site Health integration
- HIPAA 2025 NPRM readiness

**Status:** 📋 Not Started. No code written.

**Industry standard:** Healthcare AI plugins (John Snow Labs, AWS HealthLake, Azure API for FHIR) use external HIPAA-compliant services with defense-in-depth security. WordPress should never store PHI directly — route to external services.

### 3. Incomplete Feature — Voice / STT (Partially Implemented)

**Problem:** The `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md` (1,574 lines) defines **5 epics, 25 stories** over 8 weeks. Current implementation status:

| Epic | Stories Complete | Status |
|------|-----------------|--------|
| Epic 1: Browser-Side Local STT | 6/6 | ✅ JS files exist (`audio-capture-service.js`, `stt-service-api.js`, `stt-vad-processor.js`, `stt-whisper-cpp-backend.js`, `stt-whisper-cpp-worker.js`, `stt-transformers-backend.js`) |
| Epic 2: Gemma 4 Audio | 6/6 | ✅ PHP transcribe class + REST endpoint + JS backend exist |
| Epic 3: Voice Mode UX (PR #5479 parity) | 0/5 | ❌ Not implemented (push-to-talk, waveform viz, overlay, CSS, a11y) |
| Epic 4: Tool Calling from Voice | 0/3 | ❌ Not implemented |
| Epic 5: Polish, Documentation & Testing | 0/5 | ❌ Not implemented |

**Impact:** STT works at the infrastructure level but lacks the user-facing voice mode UX. The `voice-mode-embedded.js` file exists but the UX features (push-to-talk button, waveform visualization, transcription overlay) are missing.

### 4. Missing Feature — Multi-Modal Vision Support

**Problem:** WebLLM docs mention multi-modal as "Phase 2 — Coming Soon" and the enqueue manager has a `OPTION_ENABLE_MULTIMODAL` constant and a `webllm-multimodal-client.js` file exists in assets, but the feature flag is not exposed in the settings UI and no vision models are configured.

### 5. Missing Integration — WordPress Site Health

**Problem:** No `WP_Site_Health` integration exists for the embedded addon. The base plugin has extensive Site Health integration (security posture, cost tracking, etc.) but the embedded addon does not report:
- WebLLM model availability
- llama.cpp binary status
- STT backend status
- WebChat room count
- Model cache size

### 6. Settings Fragmentation

**Problem:** Settings are split across multiple locations:
- `wp_mcp_ai_settings` (base plugin) — `enable_webchat_integration`, provider selection
- `nvoos_embedded_settings` (embedded addon) — `enabled`, `enable_voice_mode`, `stt_backend`, `stt_model`, `vad_threshold`, `gemma4_audio_endpoint`
- `wp_mcp_ai_enable_webllm_tools` (feature flag)
- `wp_mcp_ai_enable_webllm_vision` (feature flag, unused)

No single settings page shows all embedded addon configuration.

### 7. Documentation Fragmentation

16 markdown files with overlapping content. Some explicitly contradict each other:
- `IMPLEMENTATION_COMPLETE.md` says server-side is deprecated
- `IMPLEMENTATION_SUMMARY.md` (root of `docs/`) describes server-side as a core feature
- `EMBEDDED_LLM_FAQ.md` lists files to remove from server-side

---

## Enhancement Plan — 4 Work Streams

### Stream A: Architecture Hardening & Consolidation (Priority: HIGH — 5 days)

These improvements establish a clean backend-registry pattern, unify settings, fix documentation contradictions, and harden both inference paths.

#### A.1 Formalize Backend Registry for Embedded LLM Inference (Days 1–2)

**Action:** Follow the STT system's pattern — define a common backend interface, register both backends, and select via settings.

**New file:** `addons/embedded/includes/embedded/interface-nvoos-embedded-llm-backend.php`

```php
interface NV_oOS_Embedded_LLM_Backend {
    public function get_slug(): string;
    public function get_label(): string;
    public function get_description(): string;
    public function is_available(): bool;
    public function get_requirements(): array;  // e.g. ['shell_exec' => true]
    public function create_chat_completion( array $messages, array $options ): array|WP_Error;
    public function get_available_models(): array;
    public function get_health_status(): array;  // For Site Health
}
```

**Backend 1 — Server-Side llama.cpp** (rename + enhance existing class):
- `class-nvoos-embedded-server-backend.php` — implements `NV_oOS_Embedded_LLM_Backend`
- Wraps existing `WP_MCP_AI_Embedded_Client` logic (1,803 lines)
- Adds `get_health_status()` for Site Health: binary found, shell_exec available, models cached, RAM usage
- Adds streaming support via `proc_open` (replaces blocking `shell_exec`)

**Backend 2 — Client-Side WebLLM** (formalize existing JS path):
- `class-nvoos-embedded-client-backend.php` — implements `NV_oOS_Embedded_LLM_Backend`
- `is_available()` always returns true (no server requirements)
- `create_chat_completion()` returns config for JS client (model, CDN URL) — actual inference in browser
- `get_health_status()` reports WebGPU support detection

**Backend Registry:**
- `class-nvoos-embedded-backend-registry.php` — singleton similar to STT's `STTServiceAPI`
- `register_backend( NV_oOS_Embedded_LLM_Backend $backend )`
- `get_active_backend(): NV_oOS_Embedded_LLM_Backend` — reads `inference_backend` setting
- `get_available_backends(): array` — filters by `is_available()`
- Hooks into `wp_mcp_ai_embedded_chat_completion` filter to dispatch to active backend

**Settings addition:**
- `inference_backend` — `client_side` (default) | `server_side` | `auto`
- `auto` mode: tries server-side first; falls back to client-side if `shell_exec` unavailable

**Files:**
| Action | File |
|--------|------|
| New | `interface-nvoos-embedded-llm-backend.php` |
| New | `class-nvoos-embedded-server-backend.php` |
| New | `class-nvoos-embedded-client-backend.php` |
| New | `class-nvoos-embedded-backend-registry.php` |
| Modify | `class-wp-mcp-ai-embedded-client.php` — keep as internal implementation; wrapped by server backend |
| Modify | `class-nvoos-embedded.php` — wire registry instead of direct client calls |
| Modify | `nvoos-embedded.php` — load new backend classes |

#### A.2 Enhance Server-Side Backend with Streaming & Health Checks (Days 2–3)

**Action:** The server-side llama.cpp path gains capabilities the client-side path doesn't need (streaming, resource monitoring).

**Streaming support:**
- Replace blocking `shell_exec()` with `proc_open()` + non-blocking stdout reads
- Emit tokens via existing SSE infrastructure (`WP_MCP_AI_SSE_Handler`)
- Add `stream_chat_completion()` to `NV_oOS_Embedded_LLM_Backend` interface
- Client-side WebLLM already supports streaming via `engine.chat.completions.create({ stream: true })`

**Model management hardening:**
- Validate GGUF file integrity on download (SHA-256 hash check against Hugging Face model card)
- Add `wp_mcp_ai_embedded_model_downloaded` action hook for post-download processing
- Implement model pre-warming: load model into RAM on server start via WP-Cron + keep-alive
- Add model size warnings in admin UI when server RAM is insufficient

**Binary management improvements:**
- Add `get_binary_version()` to detect llama.cpp version for compatibility checks
- Auto-detect platform-specific optimized binaries (AVX2, AVX512, CUDA, Metal)
- Add `wp_mcp_ai_embedded_binary_status` filter for custom binary paths

#### A.3 Consolidate Settings into Single Option (Days 3–4)

**Action:** Migrate all embedded settings under `nvoos_embedded_settings` with backward compatibility.

**New structure:**
```php
'nvoos_embedded_settings' => array(
    // Inference
    'inference_backend'          => 'client_side',  // client_side | server_side | auto
    'client_model'               => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
    'server_model'               => 'granite-3.1-2b-instruct',
    'server_binary_path'         => '',             // Custom llama.cpp binary path
    'server_max_tokens'          => 512,
    'server_temperature'         => 0.7,
    'server_context_window'      => 2048,
    // Voice
    'enable_voice_mode'          => false,
    'stt_backend'                => 'whisper_cpp_wasm',
    'stt_model'                  => 'tiny.en',
    'vad_threshold'              => 0.01,
    'gemma4_audio_endpoint'      => '',
    // Features
    'enable_tool_calling'        => false,
    'enable_multimodal'          => false,
    'enable_langchain'           => false,
    // WebChat
    'enable_webchat'             => false,
    'webchat_max_rooms'          => 50,
);
```

**Migration strategy:** Read from old keys on first access, write to new key, delete old keys. Preserve old keys for 1 release cycle.

#### A.4 Fix Documentation Contradictions (Days 4–5)

**Action:** The current docs incorrectly claim server-side is deprecated. Rewrite to accurately reflect the dual-backend architecture.

**Documents to fix (contradictions to resolve):**

| File | Current Claim | Correction |
|------|--------------|------------|
| `EMBEDDED_LLM_FAQ.md` | "Server-side is deprecated ❌" | Replace with: "Server-side is for VPS/dedicated servers that have shell_exec. Client-side is for shared hosting. Both are supported." |
| `EMBEDDED_LLM_FAQ.md` Q4 | "Should server-side be removed? YES" | Replace with: "No — both backends serve different hosting environments. See choosing a backend below." |
| `IMPLEMENTATION_COMPLETE.md` | "Server-side will be removed in future version" | Replace with: "Server-side is maintained as a peer backend alongside client-side." |
| `EMBEDDED_LLM_COMPARISON.md` | "Server-Side Embedded LLM (Legacy)" | Replace with: "Server-Side llama.cpp Backend" — remove "legacy" label |
| `README.md` | "Don't use server-side" | Replace with: "Choose the backend that matches your hosting." |

**New consolidated docs structure:**
```
addons/embedded/docs/
├── README.md                          ← Quick start (keep, update)
├── architecture.md                    ← NEW: backend registry, dual-path diagram, STT comparison
├── configuration.md                   ← NEW: settings reference (all under nvoos_embedded_settings)
├── backends/
│   ├── client-side-webllm.md          ← Client-side WebLLM guide (sharing, browser reqs, models)
│   └── server-side-llamacpp.md        ← Server-side setup (binary install, Cloudways, model mgmt)
├── voice-stt.md                       ← NEW: voice mode user guide + STT backend config
├── webchat.md                         ← NEW: WebChat admin guide + WebRTC architecture
├── security.md                        ← NEW: security posture, HIPAA considerations
├── CHANGELOG.md                       ← Keep
└── archive/                           ← Archived: old docs moved here with redirect notice
```

**Files to archive (with redirect header pointing to new location):**
- `EMBEDDED_LLM_COMPARISON.md` → `architecture.md`
- `EMBEDDED_LLM_FAQ.md` → `backends/client-side-webllm.md` + `backends/server-side-llamacpp.md`
- `IMPLEMENTATION_COMPLETE.md` → `CHANGELOG.md`
- `IMPLEMENTATION_SUMMARY.md` → `architecture.md`
- `BUNDLING_BINARIES_ANALYSIS.md` → `backends/server-side-llamacpp.md`
- `SHELL_EXEC_REQUIREMENTS.md` → `backends/server-side-llamacpp.md`
- `CLIENT_SIDE_MODEL_DISTRIBUTION.md` → `backends/client-side-webllm.md`

### Stream B: Voice / STT Completion (Priority: MEDIUM — 7 days)

Complete the remaining work from the `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md`.

#### B.1 Epic 3: Voice Mode UX — PR #5479 Parity (Days 1–3)

**Stories:**
1. **Voice mode state machine** — Implement `VoiceModeState` in `voice-mode-embedded.js` with states: `idle → listening → transcribing → responding`
2. **Push-to-Talk button** — Add microphone button to chat input area; hold-to-talk, release-to-send
3. **Waveform visualization** — Canvas-based audio level display during recording
4. **Transcription display overlay** — Show interim + final transcript before sending
5. **Voice mode CSS and accessibility** — WCAG 2.1 AA compliance; keyboard navigation; screen-reader labels

#### B.2 Epic 4: Tool Calling from Voice (Days 3–5)

**Stories:**
1. **Transcript-to-tool-call pipeline** — Route transcribed text through WebLLM with tool definitions
2. **Voice-initiated function calling** — Allow "create a post titled X" to trigger `create_post` tool
3. **Commentary/progress during tool execution** — TTS or text feedback while tools run

#### B.3 Epic 5: Polish, Documentation & Testing (Days 5–7)

**Stories:**
1. PHPUnit tests for transcribe endpoint (edge cases: large files, invalid base64, timeout)
2. JS unit tests for STT service and worker
3. Manual integration test plan execution
4. Browser compatibility testing matrix (Chrome, Edge, Safari, Firefox WebAssembly fallback)
5. Documentation (voice mode user guide, STT backend configuration)

### Stream C: New Feature — OpenMed Healthcare Integration (Priority: MEDIUM — 14 days)

Implement Phase 1–3 from `healthcare-vitals-openmed-integration-plan-v2.md`. The v2 plan covers 7 phases; this work stream covers the foundational phases that deliver immediate value.

#### C.1 Phase 1: OpenMed Service Client (Days 1–3)

**New file:** `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php`

A reusable, configuration-driven HTTP client following the existing `WP_MCP_AI_Healthcare_Engine` singleton pattern.

**Key methods:**
- `get_instance()` — Singleton accessor
- `is_configured()` — Health check
- `health()` — GET `/health`
- `deidentify( $text, $method, $opts )` — POST `/pii/deidentify`
- `extract_pii( $text, $opts )` — POST `/pii/extract`
- `analyze_text( $text, $model_name, $opts )` — POST `/analyze`
- `get_loaded_models()` — GET `/models`

**Settings UI:** Add "OpenMed Connection" section to Healthcare Vitals settings page with:
- Service URL
- API key (password field)
- Default PII model dropdown
- Default NER model dropdown
- "Test Connection" button

#### C.2 Phase 2: PII/PHI De-identification Tool (Days 3–5)

**New tool:** `deidentify_health_record`

**Security gates (5-layer defense-in-depth):**
1. PHI Acknowledgement gate (admin must accept legal agreement)
2. User capability check (`deidentify_phi`)
3. Tool-level capability enforcement
4. Audit trail recording
5. No raw PHI persisted in WordPress

**Features:**
- All 18 HIPAA Safe Harbor identifiers
- Configurable de-identification method: `mask`, `remove`, `replace`, `hash`, `shift_dates`
- Integration with existing FHIR/CCDA export tools via filter hooks
- Immutable audit record on every call

#### C.3 Phase 3: Clinical NER for Unstructured Text (Days 5–7)

**New tools:**
- `extract_clinical_entities` — Extract diseases, medications, procedures, anatomy from unstructured text
- `extract_and_import_clinical_entities` — Composite: extract entities → map to CCT fields → import

**Integration:** Hooks into existing Document Upload tool for automatic clinical entity extraction on document ingestion.

#### C.4 Docker Compose & Deployment (Days 7–10)

**Add to `docker-compose.yml`:**
```yaml
openmed:
  image: openmed:1.8.1
  ports: ["8080:8080"]
  environment:
    OPENMED_PROFILE: prod
    OPENMED_SERVICE_KEEP_ALIVE: 10m
    OPENMED_SERVICE_NO_PHI_LOGGING: "true"
    OPENMED_SERVICE_PRELOAD_MODELS: >
      OpenMed/OpenMed-PII-SuperClinical-Small-44M-v1,
      disease_detection_superclinical
  volumes:
    - openmed_cache:/app/cache
  networks: [internal]
  healthcheck:
    test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
```

#### C.5 Site Health Integration (Days 10–12)

Add OpenMed health status to WordPress Site Health:
- Connection status (green/yellow/red)
- Loaded models
- Latency
- Cache size

#### C.6 Testing & Documentation (Days 12–14)

- PHPUnit tests with mock OpenMed server
- Integration tests with Docker Compose
- HIPAA compliance checklist
- User guide for healthcare administrators

### Stream D: Quality & Hardening (Priority: LOW — 5 days)

#### D.1 Multi-Modal Vision Support (Day 1–2)

**Action:** Expose the existing `webllm-multimodal-client.js` through the settings UI.

**Changes:**
- Add "Enable Multi-Modal (Vision)" checkbox to Embedded AI settings
- Configure 2 vision-capable models: LLaVA v1.5 7B, Qwen2-VL 2B
- Wire up image upload → base64 → WebLLM vision pipeline
- Add `client_analyze_image` tool

#### D.2 Client-Side Streaming Wiring (Day 3)

**Action:** Enable token-by-token streaming from WebLLM to chat UI.

**Changes:**
- Update `embedded-llm-client.js` to use `engine.chat.completions.create({ stream: true })`
- Wire streaming chunks through existing SSE infrastructure
- Add typing indicator during generation

#### D.3 Security Hardening (Day 4)

**Action:** Address 3 remaining findings from security audit.

**Specific checks:**
- Verify all WebChat REST endpoints have proper `permission_callback` (not `__return_true`)
- Audit STT transcribe endpoint for file size DoS protection (already has 10MB limit — verify)
- Verify nonce verification on all AJAX handlers
- Add rate limiting to transcribe endpoint

#### D.4 Expand Test Coverage (Day 5)

**Action:** Add tests for new backend registry + untested code paths.

**New tests:**
- `test-embedded-voice-mode-state-machine.php` — Voice mode state transitions
- `test-embedded-multimodal-client.php` — Vision model loading
- `test-embedded-streaming.php` — Streaming response handling
- `test-embedded-settings-migration.php` — Settings consolidation migration
- `test-webchat-signaling-security.php` — WebRTC signaling auth

---

## Timeline Summary

| Work Stream | Days | Dependencies | Priority |
|-------------|------|-------------|----------|
| **A: Architecture Hardening** | 5 | None | 🔴 HIGH |
| A.1 Backend registry pattern | 2 | None | |
| A.2 Server-side enhancements | 1 | A.1 | |
| A.3 Settings consolidation | 1 | A.1 | |
| A.4 Fix documentation | 1 | A.2, A.3 | |
| **B: Voice/STT Completion** | 7 | Stream A complete | 🟡 MEDIUM |
| B.1 Voice UX (Epic 3) | 3 | A | |
| B.2 Voice tool calling (Epic 4) | 2 | B.1 | |
| B.3 Polish & testing (Epic 5) | 2 | B.2 | |
| **C: OpenMed Healthcare** | 14 | Stream A complete | 🟡 MEDIUM |
| C.1 Service client (Phase 1) | 3 | A | |
| C.2 PII de-identification (Phase 2) | 2 | C.1 | |
| C.3 Clinical NER (Phase 3) | 2 | C.2 | |
| C.4 Docker deployment | 3 | C.1 | |
| C.5 Site Health integration | 2 | C.1 | |
| C.6 Testing & documentation | 2 | C.3-C.5 | |
| **D: Quality & Hardening** | 5 | Stream A complete | 🟢 LOW |
| D.1 Multi-modal vision UI | 2 | A | |
| D.2 Client-side streaming wiring | 1 | A | |
| D.3 Security hardening | 1 | A | |
| D.4 Test coverage expansion | 1 | D.3 | |
| **TOTAL** | **31 days** | | |

### Recommended Execution Order

```
Week 1:       Stream A (Architecture) — backend registry, settings, doc fixes
Week 2-3:     Stream B (Voice UX) — complete partially-implemented feature
Week 4-6:     Stream C (OpenMed) — new healthcare capability
Week 6-7:     Stream D (Quality) — polish, harden, benchmark
```

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-----------|--------|------------|
| Backend registry breaks existing filter consumers | Low | High | Keep filter as pass-through; registry dispatches through same filter |
| Server-side streaming via proc_open incompatibility | Medium | Medium | Feature-detect proc_open; fall back to blocking shell_exec |
| OpenMed Docker adds deployment complexity | Medium | Medium | Feature-gate behind constant; graceful degradation |
| Voice UX browser compatibility gaps | Medium | Medium | Feature-detect Web Audio API; fallback to text input |
| Multi-modal models too large for browser | High | Low | Model size warnings in UI; server-side fallback |
| Settings migration breaks existing installs | Low | High | Read-old-write-new; preserve old keys for 1 version |

---

## Success Metrics

| Metric | Current | Target | Measurement |
|--------|---------|--------|-------------|
| Backend registry backends | 0 (ad-hoc) | 2 (client + server) | Registry count |
| Documentation contradictions | 5+ (deprecation claims) | 0 | Doc audit |
| Documentation files | 16 fragmented | 8 consolidated | File count |
| Settings option locations | 4 scattered | 1 unified | `get_option()` calls |
| Server-side streaming | None (blocking) | proc_open streaming | Feature test |
| Model integrity check | None | SHA-256 on download | Feature test |
| Voice UX stories | 11/25 (44%) | 25/25 (100%) | Story checklist |
| OpenMed tools | 0 | 3 | Tool registry count |
| Security findings open | 3 (2M, 1L) | 0 | Audit re-run |
| Test coverage | 15 files | 22 files | `vendor/bin/phpunit` |
| Site Health checks | 0 | 5+ (both backends) | Site Health dashboard |
| Multi-modal models | 0 (code, no UI) | 2 (LLaVA, Qwen2-VL) | Settings dropdown |

---

## Appendix A: File Manifest (Net Changes)

### New Files (Stream A — Architecture)
```
addons/embedded/includes/embedded/interface-nvoos-embedded-llm-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-server-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-client-backend.php
addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php
```

### New Files (Stream C — OpenMed)
```
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-openmed-client.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-extract-and-import-clinical-entities.php
addons/pro/includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php
addons/pro/includes/admin/class-wp-mcp-ai-openmed-settings.php
```

### New Files (All Streams — Documentation)
```
addons/embedded/docs/architecture.md
addons/embedded/docs/configuration.md
addons/embedded/docs/backends/client-side-webllm.md
addons/embedded/docs/backends/server-side-llamacpp.md
addons/embedded/docs/voice-stt.md
addons/embedded/docs/webchat.md
addons/embedded/docs/security.md
```

### Modified Files
```
addons/embedded/nvoos-embedded.php                              ← Load backend classes + registry
addons/embedded/includes/class-nvoos-embedded.php               ← Wire registry, settings, Site Health
addons/embedded/includes/embedded/class-wp-mcp-ai-embedded-client.php  ← Streaming + health checks (internal impl)
addons/embedded/includes/embedded/class-nvoos-embedded-webllm-enqueue.php ← Multi-modal enqueue
addons/embedded/includes/admin/class-wp-mcp-ai-embedded-model-ajax.php   ← Model validation hardening
addons/embedded/includes/admin/class-wp-mcp-ai-webllm-settings-page.php  ← Settings UI for both backends
addons/embedded/assets/js/embedded-llm-client.js                ← Streaming token-by-token
addons/embedded/assets/js/voice-mode-embedded.js                ← Voice UX state machine
addons/embedded/assets/css/voice-embedded.css                   ← Voice UX styles
docker-compose.yml                                               ← OpenMed service
```

### Archived Files (Documentation)
```
addons/embedded/docs/features/ai-providers/embedded/EMBEDDED_LLM_COMPARISON.md
addons/embedded/docs/features/ai-providers/embedded/BUNDLING_BINARIES_ANALYSIS.md
addons/embedded/docs/features/ai-providers/embedded/SHELL_EXEC_REQUIREMENTS.md
addons/embedded/docs/features/ai-providers/embedded/IMPLEMENTATION_COMPLETE.md
addons/embedded/docs/features/ai-providers/embedded/IMPLEMENTATION_SUMMARY.md
addons/embedded/docs/features/ai-providers/embedded/CLIENT_SIDE_MODEL_DISTRIBUTION.md
```

---

## Appendix B: Key Decisions & Rationale

1. **Why keep server-side as a peer backend, not deprecate it?** The embedded addon is a separate, proprietary addon — not the WordPress.org base plugin. No binary-size constraint applies. Server-side llama.cpp serves VPS/dedicated server users who want consistent server-side inference, while client-side WebLLM serves shared hosting users. Both are valid. The STT system already demonstrates this pattern with whisper.cpp WASM (browser) and Gemma 4 Server (server-assisted) coexisting as peer backends.

2. **Why OpenMed instead of OpenAI for healthcare?** OpenAI/Google APIs transmit data to third-party servers — HIPAA BAAs exist but add complexity. OpenMed runs 100% on-premise in a Docker container, matching the embedded addon's "local-first" philosophy. No patient data leaves the network.

3. **Why Stream C depends on Stream A?** OpenMed integration touches the healthcare toolkit (Pro addon) rather than the embedded addon directly, but the OpenMed client follows the same architecture patterns as the embedded client. Establishing the backend registry first ensures the OpenMed client doesn't inherit ad-hoc dispatch patterns.

4. **Why voice UX before OpenMed?** Voice UX is a partially implemented feature — completing it delivers immediate user value with lower risk. OpenMed is a net-new feature requiring external service deployment.

5. **Why formalize the backend registry before anything else?** Both the STT system and the LLM inference system need the same architectural pattern — a registry of backends implementing a common interface. Building the registry first (Stream A.1) establishes the pattern that Stream B (Voice UX) already partially follows and Stream C (OpenMed) will extend. It also makes Stream D changes (multi-modal, performance benchmarking) trivial to add as new backends.

---

## References

- `.agents/skills/wp-plugin-architecture/SKILL.md` — Plugin architecture conventions
- `.agents/skills/wp-rest-api/SKILL.md` — REST API endpoint patterns
- `.agents/skills/wp-security-audit/SKILL.md` — Security audit checklist
- `.agents/skills/wp-security-deep/SKILL.md` — Deep security patterns
- `.agents/skills/wp-plugin-options-storage/SKILL.md` — Options/settings patterns
- `AGENTS.md` — Multi-agent coordination rules
- `CLAUDE.md` — Naming conventions, PHP compat, security rules
- `healthcare-vitals-openmed-integration-plan-v2.md` — OpenMed v2 reference plan
- `LOCAL-VOICE-EMBEDDED-IMPLEMENTATION-PLAN.md` — Voice/STT reference plan
- [OpenMed v1.8.1](https://github.com/maziyarpanahi/openmed) (Apache-2.0)
- [WebLLM Documentation](https://webllm.mlc.ai/)
- [llama.cpp](https://github.com/ggerganov/llama.cpp) (MIT)
- [WordPress Plugin Development Best Practices](https://developer.wordpress.org/plugins/)
- [HIPAA 2025 NPRM Security Rule](https://www.hhs.gov/hipaa/for-professionals/security/index.html)
