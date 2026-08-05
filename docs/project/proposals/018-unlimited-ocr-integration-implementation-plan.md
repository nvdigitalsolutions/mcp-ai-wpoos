# Unlimited-OCR & DeepSeek-OCR Integration — Implementation Plan

**Proposal 018** | **Implementation Plan**
**Date:** 2026-08-05
**Status:** 📋 Implementation Plan
**Author:** AI Agent (Zed) — Research & Architecture
**Based on:**
- `018-unlimited-ocr-integration.md` — main proposal with model research, industry standards, integration paths
- Full codebase audit of `includes/tools/class-wp-mcp-ai-tool-extract-image-text.php` (614 lines)
- Full codebase audit of `addons/pro/includes/tools/document-generation/` (35 tools)
- Existing provider client patterns (`WP_MCP_AI_Ollama_Client`, `WP_MCP_AI_Gemini_Client`)
- Embedded addon backend registry pattern (`NV_oOS_Embedded_Backend_Registry`)

---

## Table of Contents

1. [Industry Standards Alignment](#1-industry-standards-alignment)
2. [Architecture Design](#2-architecture-design)
3. [Phase 1: Provider Option — Enhance Existing OCR Tools](#3-phase-1-provider-option--enhance-existing-ocr-tools)
4. [Phase 2: Embedded Backend + Dedicated OCR Tool](#4-phase-2-embedded-backend--dedicated-ocr-tool)
5. [Phase 2b: Advanced Services — Extraction, Batch, Dashboard](#5-phase-2b-advanced-services--extraction-batch-dashboard)
6. [Testing Strategy](#6-testing-strategy)
7. [Deployment & Documentation](#7-deployment--documentation)
8. [File Manifest](#8-file-manifest)

---

## 1. Industry Standards Alignment

### 1.1 Provider Client Pattern

Every AI provider in NV oOS follows the same architectural contract. Unlimited-OCR must align:

| Convention | Ollama Client (reference) | Self-Hosted OCR Client (new) |
|-----------|--------------------------|---------------------------|
| **Class name** | `WP_MCP_AI_Ollama_Client` | `WP_MCP_AI_Self_Hosted_OCR_Client` |
| **File location** | `includes/class-wp-mcp-ai-ollama-client.php` | `includes/class-wp-mcp-ai-self-hosted-ocr-client.php` |
| **Endpoint config** | `ollama_endpoint_url` setting | `unlimited_ocr_endpoint_url` / `deepseek_ocr_endpoint_url` settings |
| **Connection test** | `test_connection()` -> `GET /api/tags` | `test_connection($model_type)` -> `GET /v1/models` |
| **HTTP transport** | `WP_MCP_AI_HTTP_Helper` | `WP_MCP_AI_HTTP_Helper` (same) |
| **Error normalization** | Returns `WP_Error` with `wp_mcp_ai_*` codes | Same pattern |
| **Capability flags** | `external-api`, `network-dependent` | `external-api`, `network-dependent`, `local-only` (when self-hosted) |
| **Model abstraction** | Single model | Multi-model: `model_type` discriminator for `unlimited_ocr` / `deepseek_ocr` |

### 1.2 Tool Provider Routing Pattern

Existing OCR tools route to provider implementations via a private dispatch method:

```php
// Pattern from extract-image-text (line 219)
private function call_ocr_provider( $image_url, $image_content, $prompt, $provider, $max_tokens, $settings ) {
    switch ( $provider ) {
        case 'anthropic':  return $this->call_anthropic_ocr( ... );
        case 'gemini':     return $this->call_gemini_ocr( ... );
        case 'openai':
        default:           return $this->call_openai_ocr( ... );
    }
}
```

The `unlimited_ocr` and `deepseek_ocr` provider cases follow the same switch pattern. The client class uses a `model_type` discriminator to route to the correct vLLM endpoint.

### 1.3 WordPress Abilities API (Phase 2)

When registered as an Embedded addon backend, the Unlimited-OCR provider will expose an Ability via `wp_register_ability()`:

```php
wp_register_ability( 'nvoos-embedded/ocr-document', array(
    'label'       => __( 'OCR Document (Unlimited-OCR)', 'mcp-ai-wpoos' ),
    'callback'    => array( $backend, 'ocr_document' ),
    'schema'      => array( /* JSON Schema */ ),
    'permission_callback' => function() { return current_user_can( 'upload_files' ); },
    'meta'        => array( 'mcp' => array( 'public' => true ) ),
) );
```

This makes the OCR capability discoverable by AI agents (Claude Desktop, Cursor, VS Code, Claude Code) via the WordPress MCP Adapter — consistent with the 5 existing embedded abilities.

### 1.4 Two-Gate Sanitisation (Unix Theory P0)

All tool `execute()` methods obey:
- **Gate 1 (entry):** Sanitize every `$arguments[...]` value at entry — `sanitize_text_field()`, `absint()`, `esc_url_raw()`, `rest_sanitize_boolean()`
- **Gate 2 (exit):** Escape every value at exit — `esc_html()`, `esc_url()`, `wp_kses_post()`
- **Return:** Canonical success array or `WP_Error` — never `array( 'success' => false, ... )`

PHPCS sniffs `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry` enforced at severity 5.

---

## 2. Architecture Design

### 2.1 System Context

```
┌─────────────────────────────────────────────────────────────────────┐
│                        WordPress Server                              │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │                    NV oOS Plugin                              │   │
│  │  ┌─────────────┐  ┌──────────────┐  ┌────────────────────┐  │   │
│  │  │ extract_     │  │ pro_document  │  │ Embedded Addon     │  │   │
│  │  │ image_text   │  │ _ocr          │  │ Backend Registry   │  │   │
│  │  │ (Base)       │  │ (Pro)         │  │ (Phase 2)          │  │   │
│  │  └──────┬───────┘  └──────┬───────┘  └─────────┬──────────┘  │   │
│  │         │                 │                     │             │   │
│  │         └─────────┬───────┘                     │             │   │
│  │                   │                             │             │   │
│  │          ┌────────▼────────┐                    │             │   │
│  │          │  Unlimited-OCR  │◄───────────────────┘             │   │
│  │          │  Client         │                                  │   │
│  │          │  (HTTP bridge)  │                                  │   │
│  │          └────────┬────────┘                                  │   │
│  └───────────────────┼───────────────────────────────────────────┘   │
│                      │                                               │
│                      │ HTTP (OpenAI-compatible REST API)              │
│                      │ POST /v1/chat/completions                      │
│                      ▼                                               │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │              vLLM Docker Container (GPU)                      │   │
│  │  ┌──────────────────────────────────────────────────────┐    │   │
│  │  │  vllm/vllm-openai:unlimited-ocr                      │    │   │
│  │  │  ├─ NGramPerReqLogitsProcessor (server-side)         │    │   │
│  │  │  ├─ baidu/Unlimited-OCR model weights (3B, ~6GB)     │    │   │
│  │  │  └─ PyMuPDF (PDF → image conversion)                 │    │   │
│  │  └──────────────────────────────────────────────────────┘    │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 Request/Response Contract

**Request (from plugin → vLLM):**

```json
POST /v1/chat/completions
Content-Type: application/json

{
  "model": "Unlimited-OCR",
  "messages": [
    {
      "role": "user",
      "content": [
        { "type": "text", "text": "<image>document parsing." },
        { "type": "image_url", "image_url": { "url": "data:image/png;base64,iVBORw0KGgo..." } }
      ]
    }
  ],
  "temperature": 0,
  "skip_special_tokens": false,
  "images_config": { "image_mode": "base" },
  "custom_logit_processor": "DeepseekOCRNoRepeatNGramLogitProcessor",
  "custom_params": {
    "ngram_size": 35,
    "window_size": 1024
  }
}
```

**Response (from vLLM → plugin):**

```json
{
  "choices": [
    {
      "message": {
        "content": "<|det|>text [10,20,200,50]<|/det|>Chapter 1: Introduction\n\n<|det|>text [10,80,200,300]<|/det|>This is the first paragraph..."
      }
    }
  ]
}
```

### 2.3 Post-Processing Pipeline

The `<|det|>` markers are stripped and blocks are grouped:

```php
// Adapted from Unlimited-OCR README post-processing
DET_RE = '/<\\|det\\|>([^<\\s]+)(?:\\s*\\[[^\\]]*\\])?\\s*<\\|\\/det\\|>(.*)/';

function remove_det( $raw ) {
    $blocks = array();
    $cur    = array();
    foreach ( explode( "\n", $raw ) as $line ) {
        $line = rtrim( $line );
        if ( '' === $line ) { continue; }
        if ( preg_match( DET_RE, $line, $m ) ) {
            list( , $category, $content ) = $m;
            if ( 'image' === $category ) { continue; }
            if ( ! empty( $cur ) ) { $blocks[] = $cur; }
            $cur = $content ? array( $content ) : array();
            continue;
        }
        $cur[] = $line;
    }
    if ( ! empty( $cur ) ) { $blocks[] = $cur; }
    return implode( "\n\n", array_map( function( $b ) { return implode( "\n", $b ); }, $blocks ) );
}
```

---

## 3. Phase 1: Provider Option — Enhance Existing OCR Tools

**Goal:** Add `unlimited_ocr` as a provider option in existing OCR tools. Users with a self-hosted Unlimited-OCR instance get enhanced OCR through tools they already use.

**Target release:** v1.5.0
**Distribution:** Base + Pro
**Estimated effort:** ~300 lines across 3 files + 1 settings entry

### Task 1.1 — Create Self-Hosted OCR Client Class (Unlimited-OCR + DeepSeek-OCR)

**File:** `includes/class-wp-mcp-ai-self-hosted-ocr-client.php` (new, ~220 lines)

**Class:** `WP_MCP_AI_Self_Hosted_OCR_Client`

**Discovery:** The plugin already has `WP_MCP_AI_OCR_Service` (1,544 lines) which orchestrates OCR across OpenAI/Anthropic/Gemini/Ollama/Tesseract with fallback chains, circuit breakers, and retry logic. Tesseract is already bundled in three tiers (Node.js tesseract.js, PHP wrapper, system binary). The new client class adds self-hosted AI OCR models as additional providers within this existing framework.

**Design rationale:** Both Unlimited-OCR and DeepSeek-OCR share identical infrastructure requirements — vLLM server, OpenAI-compatible REST API, `NGramPerReqLogitsProcessor`, `<image>` prompt prefix. A single client class with a `model_type` discriminator avoids code duplication.

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `get_endpoint_url( $model_type )` | `(string): string` | Read `unlimited_ocr_endpoint_url` or `deepseek_ocr_endpoint_url` from settings based on model type |
| `test_connection( $model_type )` | `(string): array\|WP_Error` | `GET /v1/models` against vLLM endpoint; return `{ connected, model, model_type, endpoints }` |
| `ocr_image()` | `(string $base64_image, string $prompt, string $model_type, array $options): array\|WP_Error` | Single-image OCR: encode as `data:image/...;base64,...`, POST to `/v1/chat/completions`, return `{ text, raw, model_type, metadata }` |
| `ocr_multi_page()` | `(string[] $base64_images, string $prompt, string $model_type, array $options): array\|WP_Error` | Multi-page: include all images in content array, `window_size=1024`, `image_mode=base` |
| `build_request_payload()` | `(array $content, string $image_mode, int $window_size, int $ngram_size): array` | Assemble the JSON payload with logits processor params — adapted per model type |
| `post_process_response()` | `(string $raw, string $model_type): string` | Unlimited-OCR: strip `<\|det\|>` markers, group blocks. DeepSeek-OCR: pass-through (no markers) |
| `get_default_params( $model_type )` | `(string): array` | Return `{ ngram_size, window_size, image_mode, prompt_template }` defaults per model type |

**Model-specific defaults:**

| Parameter | Unlimited-OCR | DeepSeek-OCR |
|-----------|--------------|-------------|
| `ngram_size` | 35 | 30 |
| `window_size` (single) | 128 | 90 |
| `window_size` (multi) | 1024 | 1024 |
| `image_mode` (single) | `gundam` or `base` | `gundam` or `base` |
| `image_mode` (multi) | `base` only | `base` only |
| `prompt_template` | `<image>document parsing.` | `<image>\nFree OCR.` |
| `grounding_prompt` | N/A | `<image>\n<\|grounding\|>Convert the document to markdown.` |
| Post-processing | `remove_det()` regex | None (plain text output) |
| `whitelist_token_ids` | N/A | `{128821, 128822}` (`<td>`, `</td>`) |

**Options array:**
- `image_mode` — `'gundam'` (single image, crop) or `'base'` (single/multi, no crop). Default `'base'`
- `window_size` — `128` for single image, `1024` for multi-page/PDF. Default `128`
- `ngram_size` — `35` (standard). Configurable for experimentation
- `temperature` — default `0.0` for deterministic OCR
- `timeout` — HTTP timeout in seconds. Default `120` (OCR can take time for long docs)

**Implementation notes:**
- Follow `WP_MCP_AI_Ollama_Client` structure exactly: settings retrieval → HTTP call → error normalization
- Use `WP_MCP_AI_HTTP_Helper` for all HTTP transport
- Handle vLLM-specific errors: GPU OOM (retry with smaller batch), model not loaded (return actionable error)
- Add `wp_mcp_ai_unlimited_ocr_before_request` and `wp_mcp_ai_unlimited_ocr_after_response` action hooks

### Task 1.2 — Add Unlimited-OCR Provider to `extract_image_text` Tool

**File:** `includes/tools/class-wp-mcp-ai-tool-extract-image-text.php` (edit, ~60 lines added)

**Changes:**

1. **Parameter schema** (`get_parameters_schema()`, line 76-79): Add both models to the `provider` enum:
   ```php
   'enum' => array( 'openai', 'anthropic', 'gemini', 'tesseract', 'unlimited_ocr', 'deepseek_ocr' ),
   ```
   Note: `tesseract` was already listed in the Pro OCR service's provider list but not exposed in the Base tool's parameter schema — this is an opportunity to align both.

2. **Provider dispatch** (`call_ocr_provider()`, line 220-229): Add cases for both models:
   ```php
   case 'unlimited_ocr':
   case 'deepseek_ocr':
       return $this->call_self_hosted_ocr( $image_url, $image_content, $prompt, $provider, $max_tokens, $settings );
   ```

3. **New private method** `call_self_hosted_ocr()`:
   - Instantiate `WP_MCP_AI_Self_Hosted_OCR_Client`
   - Test connection; return `WP_Error` if unreachable
   - Fetch image content via `wp_remote_get()` and base64-encode
   - Call `$client->ocr_image( $base64_image, $prompt, $provider, $options )` where `$provider` is `'unlimited_ocr'` or `'deepseek_ocr'`
   - Return structured response matching existing provider format: `{ text, provider, model, usage, metadata, ... }`

4. **Capability flags** (`get_capability_flags()`): Add `'local-only'` flag when Unlimited-OCR is the resolved provider

### Task 1.3 — Add Unlimited-OCR Provider to `pro_document_ocr` Tool

**File:** `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php` (edit, ~60 lines added)

**Changes:**
1. Add `'unlimited_ocr'` to the provider enum in parameter schema
2. Add `case 'unlimited_ocr'` in the provider dispatch method
3. New `call_unlimited_ocr()` private method that:
   - Extracts all pages from PDF (using existing PDF-to-image logic in the tool or delegating to the client)
   - Calls `$client->ocr_multi_page( $base64_images, $prompt, $options )` for multi-page
   - Calls `$client->ocr_image()` for single images
   - Preserves all existing Pro metadata (page-level word counts, durations, per-image metadata)
   - Returns structured documents array matching existing response format

### Task 1.4 — Add Settings Entries

**File:** `includes/admin/sections/class-wp-mcp-ai-section-api.php` (or equivalent API settings section) (edit, ~30 lines)

Add two fields:
```php
array(
    'id'          => 'unlimited_ocr_endpoint_url',
    'label'       => __( 'Unlimited-OCR Endpoint URL', 'mcp-ai-wpoos' ),
    'type'        => 'text',
    'placeholder' => 'http://localhost:8000',
    'description' => __( 'URL of your self-hosted Unlimited-OCR vLLM instance. Deploy via: docker run vllm/vllm-openai:unlimited-ocr baidu/Unlimited-OCR ...', 'mcp-ai-wpoos' ),
    'sanitize'    => 'esc_url_raw',
),
array(
    'id'          => 'deepseek_ocr_endpoint_url',
    'label'       => __( 'DeepSeek-OCR Endpoint URL', 'mcp-ai-wpoos' ),
    'type'        => 'text',
    'placeholder' => 'http://localhost:8001',
    'description' => __( 'URL of your self-hosted DeepSeek-OCR vLLM instance. Requires vLLM >=0.11.1 (nightly build) with NGramPerReqLogitsProcessor.', 'mcp-ai-wpoos' ),
    'sanitize'    => 'esc_url_raw',
),
```

Also add "Test Connection" buttons for both (AJAX → `WP_MCP_AI_Self_Hosted_OCR_Client::test_connection()`).

### Task 1.5 — Update Tool Registry & OCR Service Integration

**File:** `includes/tools-init.php` (edit, ~5 lines)

Add client class autoloading:
```php
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-self-hosted-ocr-client.php';
```

**File:** `addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php` (edit, ~30 lines)

Add `unlimited_ocr` and `deepseek_ocr` to the provider routing in the existing OCR service. The OCR service already has a provider dispatch pattern with fallback chains. Adding two new cases to its `determine_best_provider()` and provider routing methods makes Unlimited-OCR and DeepSeek-OCR available to all Pro tools that consume the OCR service (not just the tools we directly edit in Tasks 1.2-1.3).

---

## 4. Phase 2: Embedded Backend + Dedicated OCR Tool

**Goal:** Register Unlimited-OCR as a first-class backend in the Embedded addon and create a dedicated OCR tool exploiting its unique long-horizon capabilities.

**Target release:** v1.6.0
**Distribution:** Pro + Embedded addon
**Dependencies:** Phase 1 complete
**Estimated effort:** ~600 lines across 3 new files + 2 edits

### Task 2.1 — Register Unlimited-OCR Backend in Embedded Addon

**File:** `addons/embedded/includes/embedded/class-nvoos-embedded-unlimited-ocr-backend.php` (new, ~120 lines)

**Class:** `NV_oOS_Embedded_Unlimited_OCR_Backend` implements `NV_oOS_Embedded_LLM_Backend`

Following the existing `NV_oOS_Embedded_Client_Backend` (WebLLM) and `NV_oOS_Embedded_Server_Backend` (llama.cpp) pattern:

```php
class NV_oOS_Embedded_Unlimited_OCR_Backend implements NV_oOS_Embedded_LLM_Backend {
    public function get_slug(): string { return 'unlimited_ocr'; }
    public function get_label(): string { return __( 'Unlimited-OCR (Baidu)', 'mcp-ai-wpoos' ); }
    public function get_description(): string { /* ... */ }
    public function get_type(): string { return 'server_side'; }
    public function is_available(): bool { /* Test connection to vLLM endpoint */ }
    public function get_models(): array { return array( 'Unlimited-OCR' ); }
    public function ocr_document( array $args ): array|WP_Error { /* Delegate to WP_MCP_AI_Unlimited_OCR_Client */ }
}
```

**File:** `addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php` (edit, ~10 lines)

Register the backend in the existing registry initialization:
```php
$this->register_backend( new NV_oOS_Embedded_Unlimited_OCR_Backend() );
```

### Task 2.2 — Register WordPress Ability

**File:** `addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php` (edit, ~30 lines)

Add a 6th ability alongside the existing 5:
```php
wp_register_ability( 'nvoos-embedded/ocr-document', array(
    'label'               => __( 'OCR Document', 'mcp-ai-wpoos' ),
    'description'         => __( 'Extract text from images and PDFs using self-hosted Baidu Unlimited-OCR.', 'mcp-ai-wpoos' ),
    'callback'            => array( $backend, 'ocr_document' ),
    'schema'              => array(
        'type'       => 'object',
        'properties' => array(
            'image_urls' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'format' => 'uri' ) ),
            'prompt'     => array( 'type' => 'string', 'default' => 'document parsing.' ),
            'image_mode' => array( 'type' => 'string', 'enum' => array( 'base', 'gundam' ), 'default' => 'base' ),
        ),
        'required'   => array( 'image_urls' ),
    ),
    'permission_callback' => function() { return current_user_can( 'upload_files' ); },
    'meta'                => array( 'mcp' => array( 'public' => true ) ),
) );
```

### Task 2.3 — Create Dedicated `pro_unlimited_ocr` Tool

**File:** `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-unlimited-ocr.php` (new, ~350 lines)

**Class:** `WP_MCP_AI_Tool_Pro_Unlimited_OCR` implements `WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`

**Tool slug:** `pro_unlimited_ocr`

This tool differs from `pro_document_ocr` by exploiting Unlimited-OCR's unique capabilities:

| Feature | `pro_document_ocr` | `pro_unlimited_ocr` (new) |
|---------|-------------------|--------------------------|
| Provider routing | OpenAI / Anthropic / Gemini / Tesseract | Unlimited-OCR only |
| Long-document mode | Per-page, accumulates cost | Single-pass 32K tokens |
| Structured output | Plain text / JSON / Markdown / HTML | Raw `<\|det\|>` (machine-readable) + cleaned text + structured blocks |
| Table extraction | Best-effort via AI prompt | `<\|det\|>table` markers enable deterministic extraction |
| Layout metadata | AI-derived, inconsistent | `<\|det\|>` markers provide category + bounding box per line |
| Batch PDF | Sequential page processing | Parallel multi-image in single request |
| Post-processing | None | `remove_det()` → grouped blocks, `extract_tables()` → structured arrays |

**Parameter schema:**
```php
array(
    'attachment_id'     => array( /* WordPress attachment ID */ ),
    'attachment_ids'    => array( /* batch, up to 20 */ ),
    'url'               => array( /* single URL */ ),
    'urls'              => array( /* batch URLs, up to 20 */ ),
    'prompt'            => array( 'type' => 'string', 'default' => '<image>document parsing.' ),
    'image_mode'        => array( 'type' => 'string', 'enum' => array( 'gundam', 'base' ), 'default' => 'base' ),
    'output_format'     => array( 'type' => 'string', 'enum' => array( 'text', 'json', 'structured', 'raw' ), 'default' => 'text' ),
    'preserve_layout'   => array( 'type' => 'boolean', 'default' => true ),
    'extract_tables'    => array( 'type' => 'boolean', 'default' => false ),
    'max_pages'         => array( 'type' => 'integer', 'default' => 0, 'description' => '0 = all pages' ),
    'save_to_paper_store' => array( 'type' => 'boolean', 'default' => false ),  // Phase 2 addition: feed into existing Paper Store
)
```

**Output format `structured`:**
```json
{
  "text": "Cleaned plain text...",
  "blocks": [
    { "category": "title", "text": "Chapter 1: Introduction", "bbox": [10, 20, 200, 50] },
    { "category": "text", "text": "This is the first paragraph...", "bbox": [10, 80, 200, 300] },
    { "category": "table", "text": "Name | Age | City\n...", "bbox": [10, 320, 400, 500] }
  ],
  "tables": [
    { "page": 1, "headers": ["Name", "Age", "City"], "rows": [["Alice", "30", "NYC"], ...] }
  ],
  "metadata": { "pages_processed": 5, "total_blocks": 42, "word_count": 1234, "duration_sec": 8.3 }
}
```

### Task 2.4 — Add Embedded Settings Section

**File:** `addons/embedded/includes/admin/class-wp-mcp-ai-webllm-settings-page.php` (edit, ~40 lines)

Add an "Unlimited-OCR" section under Embedded AI settings:
- Endpoint URL field
- "Test Connection" button (AJAX → `NV_oOS_Embedded_Unlimited_OCR_Backend::is_available()`)
- Connection status indicator (green/red dot)
- Model info display (name, version, GPU memory)
- Note: "Requires Docker with GPU. See setup guide."

---

## 5. Phase 2b: Advanced Services — Extraction, Batch, Dashboard

**Decision:** Instead of a standalone addon (Phase 3 from original plan), the remaining features are distributed into existing locations. Base holds the client; Pro holds the tools and services; Embedded holds the backend UI and health monitoring. This avoids addon fragmentation while keeping the architecture clean.

**Goal:** Production-ready structured extraction, batch OCR, and admin dashboard.

**Target release:** v1.6.0 (alongside Phase 2)
**Distribution:** Pro + Embedded addon
**Dependencies:** Phase 2 complete
**Estimated effort:** ~500 lines across 3 files

### Task 2b.1 — Structured Extraction Service (Pro)

**File:** `addons/pro/includes/services/class-wp-mcp-ai-structured-extraction-service.php` (new, ~200 lines)

**Class:** `WP_MCP_AI_Structured_Extraction_Service`

Extracts the table/block parsing logic from `WP_MCP_AI_Tool_Pro_Unlimited_OCR` into a reusable service. Also adds form field detection.

**Methods:**

| Method | Description |
|--------|-------------|
| `parse_blocks( $raw_text )` | Parse `<\|det\|>` markers into structured blocks with category, text, bbox |
| `extract_tables( $blocks )` | Detect `<\|det\|>table` blocks and parse pipe-delimited rows into headers + rows |
| `extract_form_fields( $blocks )` | Detect label-value pairs using bbox proximity heuristics (labels left-aligned, values right-aligned within same y-range) |
| `detect_document_structure( $raw_text )` | Full pipeline: parse → classify → extract → return structured document object |

**Why a service, not a tool:** The structured extraction logic is useful beyond the `pro_unlimited_ocr` tool — any future OCR or document analysis tool can consume it. Following the existing pattern of `WP_MCP_AI_OCR_Service` keeps concerns separated.

### Task 2b.2 — Batch OCR Tool (Pro)

**File:** `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-batch-ocr.php` (new, ~180 lines)

**Class:** `WP_MCP_AI_Tool_Pro_Batch_OCR` implements `WP_MCP_AI_Tool_Interface`

**Tool slug:** `pro_batch_ocr`

Schedules bulk OCR jobs via Action Scheduler, following existing Pro patterns (`WP_MCP_AI_Async_Job_Queue`, `WP_MCP_AI_Workflow_Dispatcher`).

**Parameter schema:**
- `source` — attachment_ids (array, up to 100), urls (array), or a Paper Store collection to process
- `model_type` — `unlimited_ocr` or `deepseek_ocr`
- `options` — image_mode, preserve_layout, output_format, save_to_paper_store
- `async` — boolean; when true, returns immediately with a job ID for polling

**Execution flow:**
1. Validate source and resolve URLs
2. If `async=true`: enqueue an Action Scheduler job (`wp_mcp_ai_batch_ocr`), return `{ job_id, status: 'queued', document_count }`
3. If `async=false`: process sequentially (up to 10 docs), return aggregated results
4. Each job chunk processes 10 documents, emits `wp_mcp_ai_batch_ocr_progress` action

**Capability flags:** `pro`, `local-only`, `async`, `cacheable`, `external-api`

### Task 2b.3 — OCR Health Dashboard (Embedded Addon)

**File:** `addons/embedded/includes/admin/class-nvoos-embedded-ocr-dashboard.php` (new, ~120 lines)

**Class:** `NV_oOS_Embedded_OCR_Dashboard`

Adds an admin sub-page or tab under the existing Embedded AI settings that shows:

| Widget | Data Source |
|--------|------------|
| **Connection status** | `NV_oOS_Embedded_Self_Hosted_OCR_Backend::is_available()` with green/red indicator |
| **Model info** | `get_available_models()` — model name, context window, GPU memory estimate |
| **Health checks** | `get_health_status()` integrated with WordPress Site Health |
| **Quick test** | Upload a test image → run OCR → display result inline |
| **Setup guide** | Link to Docker Compose reference, GPU requirements, deployment docs |

**Integration:** Already partially wired — the backend class implements `get_health_status()` which feeds Site Health. The dashboard adds user-facing visibility.

---

## 6. Testing Strategy

### 6.1 Unit Tests (PHPUnit)

| Test File | Tests | Phase |
|-----------|-------|-------|
| `tests/test-self-hosted-ocr-client.php` | Connection test mock (both model types), request payload assembly per model, response parsing (Unlimited-OCR with `remove_det()`, DeepSeek-OCR pass-through), error handling, default params per model type | Phase 1 |
| `tests/test-extract-image-text-self-hosted-ocr.php` | Provider routing (both `unlimited_ocr` and `deepseek_ocr`), parameter validation, capability flags, error propagation from client | Phase 1 |
| `tests/test-pro-document-ocr-self-hosted-ocr.php` | Multi-page routing, batch processing, metadata preservation, PDF-to-image delegation, both model types | Phase 1 |
| `tests/test-ocr-service-self-hosted-providers.php` | Integration with existing `WP_MCP_AI_OCR_Service` — verify both models appear in `determine_best_provider()`, fallback chain includes them, circuit breaker works | Phase 1 |
| `tests/test-embedded-unlimited-ocr-backend.php` | Backend registration, `is_available()`, ability registration, OCR delegation | Phase 2 |
| `tests/test-pro-unlimited-ocr-tool.php` | Structured output formats, table extraction, Paper Store integration, post-processing pipeline | Phase 2 |
| `tests/test-structured-extraction-service.php` | `<\|det\|>table` block parsing, column detection, form field detection via bbox proximity, full `detect_document_structure()` pipeline | Phase 2b |
| `tests/test-pro-batch-ocr-tool.php` | Batch OCR job enqueue, Action Scheduler integration, async polling, progress events | Phase 2b |

### 6.2 Integration Tests

- Mock vLLM endpoint (PHP built-in server or WireMock) returning sample OCR responses with known `<|det|>` blocks
- End-to-end: attachment → tool execution → post-processed output → assertion on text accuracy
- Concurrent request handling: 5 simultaneous OCR calls, verify no queue corruption
- Error scenarios: endpoint unreachable, GPU OOM, malformed response, timeout

### 6.3 Manual QA

- Deploy vLLM Docker container on a test GPU server
- Run OCR against known test documents (OmniDocBench sample, English PDF, multilingual document, handwriting, complex table)
- Verify structured output correctness
- Test with the WordPress chat UI — "OCR this document" natural language flow
- Verify Paper Store integration (Phase 2): OCR result appears in Paper Store collection

---

## 7. Deployment & Documentation

### 7.1 Docker Compose Reference

```yaml
# docker-compose.unlimited-ocr.yml
services:
  unlimited-ocr:
    image: vllm/vllm-openai:unlimited-ocr
    runtime: nvidia
    environment:
      - NVIDIA_VISIBLE_DEVICES=all
    ports:
      - "8000:8000"
    command:
      - "baidu/Unlimited-OCR"
      - "--trust-remote-code"
      - "--logits-processors"
      - "vllm.model_executor.models.unlimited_ocr:NGramPerReqLogitsProcessor"
      - "--no-enable-prefix-caching"
      - "--mm-processor-cache-gb"
      - "0"
      - "--host"
      - "0.0.0.0"
      - "--port"
      - "8000"
    volumes:
      - ~/.cache/huggingface:/root/.cache/huggingface
    ipc: host
    network_mode: host
```

### 7.2 Documentation Deliverables

| Document | Content | Phase |
|----------|---------|-------|
| `docs/features/unlimited-ocr-setup.md` | GPU requirements, Docker deployment, WordPress config, troubleshooting | Phase 1 |
| `docs/features/unlimited-ocr-tools.md` | Tool reference for `extract_image_text`, `pro_document_ocr`, `pro_unlimited_ocr` | Phase 1-2 |
| `docs/features/unlimited-ocr-embedded.md` | Embedded addon integration, ability discovery, local-first architecture, OCR dashboard | Phase 2-2b |
| `docs/reference/tools/tool-reference.md` | Add new tools to authoritative tool catalogue | Phase 1-2-2b |

### 7.3 Migration Path

- **Cloud OCR → Self-hosted:** Users switch provider from `openai`/`anthropic`/`gemini` to `unlimited_ocr` in tool arguments or assistant config
- **No breaking changes:** Existing OCR tools continue to work unchanged; `unlimited_ocr` is additive
- **Fallback chain:** If Unlimited-OCR endpoint is unreachable, tools can fall back to cloud providers (configurable)

---

## 8. File Manifest

### Phase 1 (v1.5.0)

| File | Action | Lines | Distribution |
|------|--------|-------|-------------|
| `includes/class-wp-mcp-ai-self-hosted-ocr-client.php` | **Create** | ~220 | Base |
| `includes/tools/class-wp-mcp-ai-tool-extract-image-text.php` | Edit (~70 added) | +70 | Base |
| `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php` | Edit (~70 added) | +70 | Pro |
| `addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php` | Edit (~30 added) | +30 | Pro |
| `includes/admin/sections/class-wp-mcp-ai-section-api.php` | Edit (~30 added) | +30 | Base |
| `includes/tools-init.php` | Edit (~5 added) | +5 | Base |

**Phase 1 total:** ~425 lines

### Phase 2 (v1.6.0)

| File | Action | Lines | Distribution |
|------|--------|-------|-------------|
| `addons/embedded/includes/embedded/class-nvoos-embedded-self-hosted-ocr-backend.php` | **Create** | ~140 | Embedded |
| `addons/embedded/includes/embedded/class-nvoos-embedded-backend-registry.php` | Edit (~10 added) | +10 | Embedded |
| `addons/embedded/includes/abilities/class-nvoos-embedded-abilities.php` | Edit (~30 added) | +30 | Embedded |
| `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-unlimited-ocr.php` | **Create** | ~350 | Pro |
| `addons/embedded/includes/admin/class-wp-mcp-ai-webllm-settings-page.php` | Edit (~40 added) | +40 | Embedded |

**Phase 2 total:** ~570 lines (merged into existing locations)

### Phase 2b (v1.6.0) — Architecture Decision: No Standalone Addon

Instead of a standalone addon, advanced features are distributed into existing locations where the infrastructure already lives:

| File | Action | Lines | Distribution |
|------|--------|-------|-------------|
| `addons/pro/includes/services/class-wp-mcp-ai-structured-extraction-service.php` | **Create** | ~200 | Pro |
| `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-batch-ocr.php` | **Create** | ~180 | Pro |
| `addons/embedded/includes/admin/class-nvoos-embedded-ocr-dashboard.php` | **Create** | ~120 | Embedded |

**Phase 2b total:** ~500 lines

**Overall total (Phase 1 + 2 + 2b):** ~1,495 lines across 14 files (vs ~1,360 for standalone addon alone)

---

*Next step: Implement Phase 2b (structured extraction service, batch OCR tool, OCR health dashboard).*
