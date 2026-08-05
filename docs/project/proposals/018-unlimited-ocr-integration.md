# Baidu Unlimited-OCR Integration — Proposal

**Proposal 018**
**Date:** 2026-08-05
**Status:** Proposal
**Target release:** v1.5.0
**Author:** AI Agent (Zed) — Research & Architecture
**Related:**
- `addons/pro/includes/tools/document-generation/` — existing 35-tool document generation toolkit
- `addons/embedded/` — local-first AI inference addon (WebLLM, llama.cpp, STT)
- `includes/tools/class-wp-mcp-ai-tool-extract-image-text.php` — Base OCR tool
- `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php` — Pro OCR tool

---

## 1. Executive Summary

**Baidu Unlimited-OCR** is a 3B-parameter, MIT-licensed vision-language model purpose-built for long-horizon document parsing. Released June 2026, it achieves **93.23% on OmniDocBench v1.5** (+6.22 pts over the DeepSeek-OCR baseline) and can process dozens of PDF pages in a single forward pass using Reference Sliding Window Attention (R-SWA) to maintain a flat KV cache.

This proposal evaluates Unlimited-OCR for integration into the NV oOS plugin ecosystem and recommends a **staged, three-phase approach**: first enhance existing OCR tools with Unlimited-OCR as a provider option (Phase 1), then add it as a first-class backend in the Embedded addon (Phase 2), and optionally graduate to a standalone addon (Phase 3). **The same integration pattern also supports DeepSeek-OCR** — both models share the vLLM + OpenAI-compatible API + NGram logits processor architecture, so the provider client abstraction naturally accommodates either (or both).

The model's OpenAI-compatible vLLM API, MIT license, and consumer-GPU-sized footprint make it uniquely suited for self-hosted, privacy-respecting document processing within a WordPress AI framework.

### Why This Matters

- **Cost elimination.** A self-hosted 3B model processes documents at zero per-page API cost, where cloud vision APIs charge per image/token.
- **Privacy.** Documents never leave the server — critical for legal, healthcare, and financial use cases already served by the Pro document-generation and QMS toolkits.
- **Long-document capability.** Unlimited-OCR's 32K-token single-pass parsing handles books, contracts, and regulatory filings that exceed cloud vision API limits.
- **Existing infrastructure.** The plugin already has OCR tools, a document-generation toolkit, a provider architecture, and a local-inference embedded addon — Unlimited-OCR slots into all of them.

---

## 2. Model Overview

### 2.1 Technical Profile

| Dimension | Detail |
|-----------|--------|
| **Parameters** | 3B |
| **Architecture** | Vision-language model with Reference Sliding Window Attention (R-SWA) |
| **Context window** | 32,768 tokens |
| **License** | MIT — permissive, no restrictions on commercial use or redistribution |
| **OmniDocBench v1.5** | 93.23% overall (SOTA among open models, +6.22 pts over DeepSeek-OCR) |
| **OmniDocBench v1.6** | 93.92% |
| **Throughput** | ~5,580 tok/s (12.7% faster than DeepSeek-OCR), ~35% faster in ideal |
| **GPU requirements** | Single consumer GPU (RTX 3060 12GB sufficient); Hopper GPUs benefit from CUDA 12.9 Docker image |
| **Repository** | https://github.com/baidu/Unlimited-OCR |
| **HuggingFace** | `baidu/Unlimited-OCR` |

### 2.2 Inference Backends

| Backend | Protocol | Docker Image | Best For |
|---------|----------|-------------|----------|
| **vLLM** | OpenAI-compatible REST API (`/v1/chat/completions`) | `vllm/vllm-openai:unlimited-ocr` (CUDA 13.0) or `:unlimited-ocr-cu129` (CUDA 12.9) | Production servers with GPU |
| **SGLang** | OpenAI-compatible streaming API | Manual setup with local wheel | High-throughput batch, research |
| **Transformers** | Direct Python (HuggingFace) | None | Development, single-request experiments |

### 2.3 Input/Output

**Input modes:**
- Single image — `gundam` config (base_size=1024, image_size=640, crop_mode=True) or `base` config (image_size=1024, crop_mode=False)
- Multi-page images — `base` only (image_size=1024), up to 32K tokens
- PDF — auto-converted to images via PyMuPDF (`fitz`) at configurable DPI (default 300)

**Prompt format:** Must begin with the literal `<image>` prefix (e.g., `<image>document parsing.`)

**Output:** Structured text with per-line `<|det|>type [bbox]<|/det|>` markers. Types include `text`, `title`, `table`, `image`, `header`, `footer`, etc. A post-processing regex strips markers and groups lines belonging to the same block with `\n`, separating different blocks with `\n\n`.

**Special parameters:**
- `ngram_size=35` — no-repeat n-gram size for the logits processor
- `window_size=128` — for single image; `window_size=1024` for multi-page/PDF
- The `NGramPerReqLogitsProcessor` must be registered on the vLLM server at startup

### 2.4 Community Ecosystem

- **FastAPI wrapper:** `maxoyed/Unlimited-OCR-FastAPI` — bridges vLLM to a clean REST API
- **HuggingFace Spaces demo:** Interactive web demo
- **ms-swift:** Training/fine-tuning support
- **ModelScope:** Alternative model hosting

---

## 3. Industry Standards & Competitive Landscape

### 3.1 Document Generation Toolkit Standards

Modern document processing pipelines follow a **Capture → OCR → Structure → Generate → Store → Distribute** pattern. Industry standards:

| Standard | Relevance to Unlimited-OCR |
|----------|---------------------------|
| **ISO/IEC 42001:2023** (AI Management Systems) | Already cited in Pro Document OCR tool; Unlimited-OCR as a self-hosted model simplifies audit and transparency requirements |
| **NIST AI RMF** (Risk Management Framework) | Self-hosted models reduce supply-chain risk vs cloud APIs; no third-party data exposure |
| **GDPR / HIPAA** | Unlimited-OCR's self-hosted deployment means no documents leave the server — a critical advantage over cloud OCR APIs for regulated industries |
| **EU AI Act** | MIT-licensed, transparent architecture supports compliance documentation; risk categorization is straightforward |
| **ISO 19005 (PDF/A)** | Extracted text can feed PDF/A archival workflows already supported by the Pro PDF manipulation tools |

### 3.2 Competitive OCR Model Landscape (2026)

| Model | Params | License | OmniDocBench | vLLM Support | Key Differentiator |
|-------|--------|---------|-------------|-------------|-------------------|
| **Unlimited-OCR** (Baidu) | 3B | MIT | **93.23%** | ✅ Docker image | Long-horizon single-pass, flat KV cache (R-SWA) |
| **DeepSeek-OCR** (DeepSeek) | ~3B | MIT | 87.01% | ✅ Upstream vLLM | Original baseline; broader community (23.7k★); "Contexts Optical Compression" approach |
| **PaddleOCR-VL** | 0.9B | Apache 2.0 | ~82% | ✅ | Smaller/faster, weaker on long docs |
| **GLM-OCR** (Z.ai) | 0.9B | MIT | ~80% | ✅ (via Ollama) | Supports Ollama, tiny footprint |
| **GOT-OCR** | ~580M | Apache 2.0 | ~75% | ❌ | Edge deployment, no long-horizon |
| **Tesseract 5** | N/A | Apache 2.0 | N/A (traditional) | N/A | No AI understanding, layout-poor, language-dependent; **already bundled in plugin** |
| **Azure AI Document Intelligence** | Proprietary | — | N/A | N/A | Cloud-only, per-page pricing, vendor lock-in |
| **Google Document AI** | Proprietary | — | N/A | N/A | Cloud-only, per-page pricing, vendor lock-in |

**Why both Unlimited-OCR AND DeepSeek-OCR are relevant:**

Both models share the exact same integration pattern — vLLM + OpenAI-compatible REST API + `NGramPerReqLogitsProcessor` + `<image>` prompt prefix. This means a single `WP_MCP_AI_Self_Hosted_OCR_Client` can support either model (or both simultaneously). The key differences:

| Aspect | Unlimited-OCR | DeepSeek-OCR |
|--------|--------------|-------------|
| **Long-document performance** | 93.23% (+6.22 pts on OmniDocBench) | 87.01% (baseline) |
| **KV cache** | Flat (R-SWA) — constant memory, dozens of pages | Linear growth — per-page memory accumulation |
| **Docker deployment** | Official `vllm/vllm-openai:unlimited-ocr` image | Requires manual vLLM setup (v0.8.5+ / nightly for upstream) |
| **Maturity** | Newer (June 2026), 22.1k★ | Established (Oct 2025), 23.7k★, DeepSeek-OCR2 released Jan 2026 |
| **Prompt modes** | `<image>document parsing.` (single mode) | `<image>\nFree OCR.`, `<image>\n<\|grounding\|>Convert to markdown.`, `<image>\nParse the figure.`, `<image>\nLocate <\|ref\|>text<\|/ref\|>` (multiple modes) |
| **Resolution modes** | `base` (1024²) + `gundam` (n×640² + 1024²) | `tiny` (512²) → `large` (1280²) + `gundam` |
| **Community** | 22.1k★, 2.2k forks, 13 commits | 23.7k★, 2.2k forks, 7 commits |

**Recommendation:** Support both. The provider client abstraction makes this trivial — a `provider` parameter value of `deepseek_ocr` routes to one vLLM endpoint, `unlimited_ocr` to another. Users with GPU infrastructure can run either (or both) depending on their accuracy vs. maturity preference.

### 3.3 WordPress OCR Plugin Ecosystem

| Plugin | Method | Limitation vs NV oOS + Unlimited-OCR |
|--------|--------|-------------------------------------|
| WP Power OCR | External OCR API | Cloud-dependent, per-page cost, no AI understanding |
| Filestack OCR | Third-party service | Vendor lock-in, data leaves server |
| Media Library Assistant | Tesseract CLI | Poor layout preservation, no long-document support |
| Nanonets OCR (n8n) | Cloud ML API | Complex setup, cloud-only, per-page pricing |

No existing WordPress plugin combines self-hosted AI OCR with long-document, single-pass parsing and structured output — Unlimited-OCR would be a **category-defining differentiator** for NV oOS.

---

## 5. DeepSeek-OCR Compatibility

### 5.1 Architectural Alignment

DeepSeek-OCR (MIT license, 23.7k★) is the model Unlimited-OCR directly improves upon. Both share:

- **Same inference architecture:** vLLM with OpenAI-compatible `/v1/chat/completions` endpoint
- **Same logits processor:** `NGramPerReqLogitsProcessor` (DeepSeek-OCR originated this pattern)
- **Same prompt convention:** `<image>` prefix required
- **Same resolution system:** base/gundam with `base_size` / `image_size` / `crop_mode` config
- **Same output format:** Plain text (no `<|det|>` markers in DeepSeek-OCR; Unlimited-OCR adds these)

### 5.2 Why the Integration Pattern Supports Both

The provider client class designed for Unlimited-OCR (`WP_MCP_AI_Self_Hosted_OCR_Client`) can support DeepSeek-OCR with zero code changes beyond the endpoint URL. The only differences are:

1. **vLLM setup:** DeepSeek-OCR uses upstream vLLM (nightly build, `vllm>=0.11.1` for clean support) vs Unlimited-OCR's dedicated Docker image
2. **Prompt format:** DeepSeek-OCR supports richer grounding modes (`<|grounding|>`, `<|ref|>`, `Parse the figure`)
3. **No `<|det|>` markers:** DeepSeek-OCR outputs plain text; Unlimited-OCR adds structured markers
4. **`ngram_size`/`window_size`:** DeepSeek-OCR defaults `30/90`; Unlimited-OCR defaults `35/128` (single) / `35/1024` (multi)

### 5.3 Implementation

In Phase 1, the provider client will accept a `model_type` parameter (`unlimited_ocr` or `deepseek_ocr`) and adjust:
- Default `ngram_size`/`window_size` per model
- Prompt template (add grounding markers for DeepSeek-OCR if requested)
- Post-processing (apply `remove_det()` for Unlimited-OCR; pass-through for DeepSeek-OCR)

Both models appear as distinct values in the `provider` parameter enum:
```php
'enum' => array( 'openai', 'anthropic', 'gemini', 'ollama', 'tesseract', 'unlimited_ocr', 'deepseek_ocr' ),
```

## 6. Existing Plugin Architecture Audit

### 4.1 What Already Exists

**Base plugin (`includes/tools/`):**
- `extract_image_text` — OCR via OpenAI/Anthropic/Gemini vision APIs (single image, cloud-dependent, ~614 lines)
- `analyze_image` — general vision analysis with OCR mode (~551 lines)
- `submit_document_prompt` — upload document + prompt to AI model (~484 lines)
- `paper-store/` — 6 CRUD tools for flat-file JSON document store (list, read, search, write, update, delete)

**OCR infrastructure (`addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php` — 1,544 lines):**
The plugin already has a dedicated `WP_MCP_AI_OCR_Service` class that orchestrates OCR across multiple providers with intelligent fallback chains, circuit breakers, and retry logic. It currently supports:
- **OpenAI GPT-4 Vision** — primary cloud provider
- **Google Gemini Vision** — secondary cloud provider
- **Ollama Vision Models** — local fallback (via Ollama client)
- **Tesseract OCR** — system fallback, with three-tier resolution:
  1. **Node.js OCR service** (bundled `tesseract.js` via `extract_with_node_ocr()`) — best performance
  2. **PHP wrapper** (`thiagoalessio/TesseractOCR` Composer package) — if available
  3. **System binary** (`shell_exec('tesseract ...')`) — last resort, requires `apt-get install tesseract-ocr`
- **Canvas addon** (`addons/canvas/`) — provides pre-compiled native binaries for `tesseract.js` PDF OCR without system library installation

The OCR service also includes `convert_pdf_to_images()`, `determine_best_provider()`, `get_fallback_providers()`, circuit breaker cooldown, and max retries — all of which would automatically benefit from adding `unlimited_ocr` and `deepseek_ocr` as new provider options.

**Pro addon (`addons/pro/includes/tools/document-generation/`)** — 35 tools across 5 categories:
- **AI-powered generation:** `pro_pdf`, `pro_word`, `pro_excel` (advanced), `generate_pdf`, `generate_word`, `generate_excel` (simplified)
- **OCR:** `pro_document_ocr` (advanced batch: multi-page PDFs, up to 20 images, layout preservation, Tesseract fallback), `ocr_pdf_text` (basic scanned PDF OCR)
- **PDF manipulation:** `extract_pdf_text` (digital PDF via `pdftotext`), `html_to_pdf`, `merge_pdfs`, `add_watermark_to_pdf`, `generate_invoice_pdf`, `generate_invoice_batch`
- **Excel:** `excel_data_import`, `excel_data_export`
- **QMS (Quality Management):** `qms_create_controlled_document`, `qms_submit_for_review`, `qms_approve_document`, `qms_release_document`, `qms_sign_document`, `qms_supersede_document`, `qms_mark_obsolete`, `qms_schedule_review`, `qms_list_controlled_documents`, `qms_get_audit_trail`
- **Audit/batch:** `get_expired_documents`, `get_uninvoiced_orders`, `archive_documents`

**Provider architecture:** The plugin supports 15+ AI providers (OpenAI, Anthropic, Gemini, Ollama, DeepSeek, Cloudflare, HuggingFace, NVIDIA, OpenRouter, Kimi, LM Studio, DigitalOcean, BaseTen, Z.ai, xAI) — all following a consistent `WP_MCP_AI_{Provider}_Client` pattern. Each client wraps HTTP calls with settings retrieval, error normalization, and capability detection.

### 4.2 Capability Gaps Unlimited-OCR Would Fill

| Gap | Current State | With Unlimited-OCR |
|-----|--------------|-------------------|
| **Long-document OCR** | Cloud vision APIs limited to ~20 pages per call, accumulate cost per page | 32K-token single pass, dozens of pages at zero per-page cost |
| **Self-hosted privacy** | All OCR currently cloud-dependent (OpenAI/Anthropic/Gemini APIs) | Documents never leave the server |
| **Structured extraction** | Plain text or basic Markdown from cloud APIs | `<|det|>` markers with category + bbox — enables table extraction, form field detection |
| **Batch throughput** | Rate-limited by cloud APIs, cost-prohibitive at scale | Local GPU processes continuously with no rate limits |
| **Offline operation** | OCR unavailable without internet | Fully functional on air-gapped servers |
| **Layout preservation** | Best-effort via AI prompts, inconsistent | R-SWA preserves document structure natively |

---

## 7. Integration Architecture

### 5.1 Data Flow

```
WordPress Admin / AI Assistant / REST API
    │
    ├─ Tool: extract_image_text(provider="unlimited_ocr")
    │       │
    │       ├─ Sanitize args, resolve attachment ID → image URL
    │       ├─ If PDF: convert pages to images (PyMuPDF in vLLM container or Node.js bridge)
    │       ├─ POST to vLLM endpoint /v1/chat/completions
    │       │   ├─ Headers: Content-Type, custom_params (ngram_size, window_size)
    │       │   └─ Body: { model: "Unlimited-OCR", messages: [{ role: "user", content: [{ type: "text", text: "<image>document parsing." }, { type: "image_url", image_url: { url: "data:image/png;base64,..." } }] }], images_config: { image_mode: "base"|"gundam" } }
    │       ├─ Receive structured text with <|det|> markers
    │       ├─ Post-process: strip markers, group blocks
    │       └─ Return canonical success array with text, metadata, word/char counts
    │
    ├─ Tool: pro_document_ocr(provider="unlimited_ocr")
    │       │
    │       ├─ Same flow as extract_image_text but with Pro batch/multi-page/bulk enhancements
    │       ├─ Uses existing Pro infrastructure (document-response trait, attachment-file-resolver, output formatting)
    │       └─ Returns structured documents array with per-page/per-image metadata
    │
    └─ Tool: analyze_image(provider="unlimited_ocr")
            │
            ├─ Same flow, but with custom user prompt
            └─ Returns vision analysis result
```

### 5.2 Provider Client Pattern

Following the established `WP_MCP_AI_Ollama_Client` pattern, a new `WP_MCP_AI_Unlimited_OCR_Client` class would:

```php
class WP_MCP_AI_Unlimited_OCR_Client {
    public function get_endpoint_url();       // Settings → unlimited_ocr_endpoint_url
    public function test_connection();         // Health check against vLLM /v1/models
    public function ocr_image( $image_data, $prompt, $options );  // Single-image OCR
    public function ocr_multi_page( $image_data_array, $prompt, $options ); // Multi-page OCR
    // Reuses WP_MCP_AI_HTTP_Helper for HTTP transport
}
```

### 5.3 Four Integration Paths

| Path | Location | Effort | Impact | Description |
|------|----------|--------|--------|-------------|
| **A — Provider option** (Phase 1) | Add `unlimited_ocr` case to existing OCR tools | ~80 lines | ⭐⭐⭐ | Lowest effort; users with self-hosted Unlimited-OCR get enhanced OCR through tools they already use. |
| **B — Embedded backend** (Phase 2) | Register as backend in Embedded addon's `NV_oOS_Embedded_Backend_Registry` | ~300 lines | ⭐⭐⭐⭐ | Full local-first story; appears in Embedded settings alongside llama.cpp and WebLLM; registered as WordPress Ability for MCP discovery. |
| **C — Dedicated OCR tool** (Phase 2b) | New `pro_unlimited_ocr` tool in document-generation toolkit | ~400 lines | ⭐⭐⭐⭐ | Exploits Unlimited-OCR's unique capabilities (long-horizon, structured output, post-processing); dedicated tool class with full parameter schema. |
| **D — Standalone addon** | `addons/unlimited-ocr/` with admin UI, health monitoring, advanced post-processing | ~1,500 lines | ⭐⭐⭐ | Rejected — adds unnecessary addon fragmentation. Features distributed to Pro (services, batch tool), Embedded (dashboard), and Base (client) instead. See Phase 2b in implementation plan. |

---

## 8. Recommended Approach: Staged Integration

### Phase 1 — Provider Option (v1.5.0)

Add `unlimited_ocr` as a provider option in existing tools — the lowest-effort, highest-immediate-value change.

**Files to change (2):**
1. `includes/tools/class-wp-mcp-ai-tool-extract-image-text.php` — add `unlimited_ocr` to provider enum and `call_unlimited_ocr()` method
2. `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php` — add `unlimited_ocr` to provider enum and `call_unlimited_ocr()` method

**New file (1):**
3. `includes/class-wp-mcp-ai-self-hosted-ocr-client.php` — provider client class (~200 lines) with endpoint config, connection test, single-image and multi-page OCR methods. Supports both `unlimited_ocr` and `deepseek_ocr` model types.

**Settings (2 entries):**
4. `unlimited_ocr_endpoint_url` in Settings → API Configuration
5. `deepseek_ocr_endpoint_url` in Settings → API Configuration

### Phase 2 — Embedded Backend + Dedicated Tool (v1.6.0)

**Files to change (2):**
1. `addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php` — register `unlimited_ocr` backend
2. `addons/embedded/includes/admin/` — add Unlimited-OCR settings section

**New files (3):**
3. `addons/embedded/includes/embedded/class-nvoos-embedded-unlimited-ocr-backend.php` — implements `NV_oOS_Embedded_LLM_Backend`
4. `addons/embedded/includes/abilities/` — `nvoos-embedded/ocr-document` ability
5. `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-unlimited-ocr.php` — dedicated tool exploiting unique capabilities

### Phase 2b — Advanced Services (v1.6.0)

Instead of a standalone addon, advanced features are distributed into existing locations:
- **Pro:** Structured extraction service (`addons/pro/includes/services/`) + batch OCR tool (`addons/pro/includes/tools/document-generation/`)
- **Embedded:** OCR health dashboard (`addons/embedded/includes/admin/`)

This avoids addon fragmentation — Base holds the client, Pro holds tools and services, Embedded holds backend UI and health monitoring.

---

## 9. Constraints & Risks

### 7.1 Technical Constraints

| Constraint | Impact | Mitigation |
|-----------|--------|------------|
| **GPU required** — vLLM needs CUDA 12.9+ or 13.0 GPU | Shared hosting unsupported; target is VPS/dedicated/cloud GPU | Document this clearly; only offer as self-hosted option for users with GPU infrastructure |
| **Docker image size** — ~8-12GB for vLLM + model weights (~6GB) | Large download, significant disk usage | Offer cloud GPU marketplace images; document disk requirements |
| **PDF conversion** — model needs images, not raw PDFs; PyMuPDF required | Additional dependency in deployment pipeline | PyMuPDF runs inside vLLM container; Node.js bridge alternative for non-Docker setups |
| **Server-side logits processor** — `NGramPerReqLogitsProcessor` must be registered at vLLM startup | Non-negotiable server configuration | Document in setup guide; provide pre-configured Docker Compose file |
| **PHP ↔ GPU gap** — WordPress runs PHP; Unlimited-OCR runs Python/CUDA | Architectural separation required | HTTP bridge via OpenAI-compatible REST API — same pattern as Ollama integration |

### 7.2 Operational Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **GPU cost barrier** — users without GPU can't use this | High | Medium | Position as optional enhancement; cloud OCR providers remain available for non-GPU users |
| **Model staleness** — OCR models evolve rapidly | Medium | Low | MIT license allows drop-in replacement; provider client abstraction shields tool code |
| **vLLM version lock** — Unlimited-OCR requires specific vLLM image | Medium | Medium | Pin to official `vllm/vllm-openai:unlimited-ocr` Docker tag; test before upgrading |
| **Concurrent requests** — vLLM serves one request at a time efficiently, but concurrent users may queue | Medium | Medium | Document queue behavior; Phase 2 could add concurrency guard and queue management |

### 7.3 Licensing

Unlimited-OCR is **MIT-licensed**. No restrictions on:
- Commercial use
- Modification
- Distribution
- Private use

The vLLM Docker image and PyMuPDF are also permissively licensed (Apache 2.0 and AGPL respectively; PyMuPDF's AGPL applies only if modified and redistributed — using it as a dependency is fine).

---

## 10. Success Metrics

| Metric | Baseline | Target (Phase 1+2 done) | Target (Phase 2b) |
|--------|----------|--------------------------|-------------------|
| OCR tool provider count | 3 (OpenAI, Anthropic, Gemini) | 6 (adds Unlimited-OCR, DeepSeek-OCR, dedicated tool) | 7 (adds batch OCR tool) |
| Max PDF pages per OCR call | 20 (cloud API limit) | 32K tokens (~50+ pages) | 100+ (batch via Action Scheduler) |
| Per-page OCR cost | ~$0.0015 (GPT-4o) | $0.00 (self-hosted) | $0.00 (self-hosted) |
| Document privacy | Documents sent to OpenAI/Anthropic/Google | Documents stay on-server | End-to-end self-hosted + Paper Store persistence |
| Offline OCR availability | None | Available (GPU-dependent) | Available + WordPress Ability discovery + admin dashboard |
| Structured extraction | None | Table extraction (via `pro_unlimited_ocr`) | Table + form field detection (via service) |

---

## 11. Open Questions

1. **GPU provisioning strategy:** Should the plugin provide a one-click cloud GPU deployment (e.g., RunPod, Vast.ai, Lambda Labs) or assume users bring their own GPU?
2. **PDF conversion boundary:** Should PDF-to-image conversion happen in the vLLM container (PyMuPDF) or in a Node.js bridge service within the plugin?
3. **Caching strategy:** Should OCR results be cached (Paper Store, transients) to avoid re-processing identical documents?
4. **Model updates:** Should the plugin auto-detect new Unlimited-OCR releases and offer in-admin upgrade?
5. **Multi-tenant GPU sharing:** For multisite deployments, should a single vLLM instance serve multiple WordPress sites?

---

*Next step: Architect creates the Implementation Plan (`018-unlimited-ocr-integration-implementation-plan.md`).*
