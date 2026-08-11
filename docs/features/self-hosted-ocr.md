# Self-Hosted OCR

**Version:** 1.1.45+
**Category:** Pro feature (with base tool support)
**Proposal:** [018-unlimited-ocr-integration](../project/proposals/018-unlimited-ocr-integration.md)
**Lines:** 17 files, +4,087 lines

## Overview

NV oOS supports two self-hosted OCR models via a unified vLLM client: **Baidu Unlimited-OCR** and **DeepSeek-OCR**. Both models run on self-hosted GPU infrastructure and share the same integration pattern (vLLM + OpenAI-compatible REST API).

## Supported Models

| Model | Params | License | Benchmark | Best For |
|---|---|---|---|---|
| Unlimited-OCR | 3B | MIT | 93.23% OmniDocBench | Long documents, complex layouts |
| DeepSeek-OCR | ~3B | MIT | Competitive on OmniDocBench | General purpose, code/math documents |

## Prerequisites

### Hardware
- GPU with sufficient VRAM (recommended: 24GB+ for either model)
- vLLM inference server running

### vLLM Setup

```bash
# Install vLLM
pip install vllm

# Start Unlimited-OCR server
vllm serve Baidu/Unlimited-OCR \
  --host 0.0.0.0 \
  --port 8000 \
  --max-model-len 8192

# Or start DeepSeek-OCR server
vllm serve deepseek-ai/DeepSeek-OCR \
  --host 0.0.0.0 \
  --port 8001 \
  --max-model-len 8192
```

## NV oOS Configuration

### Admin Settings
1. Go to **Settings → NV oOS → Media → OCR**
2. Enter the vLLM endpoint URL for Unlimited-OCR
3. Enter the vLLM endpoint URL for DeepSeek-OCR
4. Click "Test Connection" to verify
5. Save settings

### Constants

```php
// Override Unlimited-OCR endpoint
define( 'WP_MCP_AI_UNLIMITED_OCR_URL', 'http://gpu-server:8000/v1' );

// Override DeepSeek-OCR endpoint
define( 'WP_MCP_AI_DEEPSEEK_OCR_URL', 'http://gpu-server:8001/v1' );

// Set default OCR provider
define( 'WP_MCP_AI_DEFAULT_OCR_PROVIDER', 'unlimited_ocr' );
```

## Tools

### Base Tool (Enhanced)

`extract_image_text` — now includes `unlimited_ocr` and `deepseek_ocr` as provider enum values:

```json
{
  "provider": "unlimited_ocr",
  "image_url": "https://example.com/document.png",
  "preserve_layout": true
}
```

### Pro Tools

#### `pro_unlimited_ocr`
Dedicated long-horizon OCR with structured output modes:
- **text** — plain text extraction
- **structured** — with table extraction and form field detection
- **raw** — full API response

Supports Paper Store persistence for batch review.

#### `pro_batch_ocr`
Action Scheduler batch processing:
- Sync mode: up to 10 documents
- Async mode: up to 100 documents
- Progress tracking via Action Scheduler hooks
- Per-document status reporting

## Structured Extraction Service

The `WP_MCP_AI_Structured_Extraction_Service` provides:
- Marker parsing for document structure
- Table extraction with cell-level accuracy
- Form field detection and labeling
- Integration with the Pro document generation toolkit

## Architecture

```
User Request
  → extract_image_text / pro_unlimited_ocr / pro_batch_ocr
    → WP_MCP_AI_Self_Hosted_OCR_Client (640 lines)
      → vLLM REST API (OpenAI-compatible /v1/chat/completions)
        → Unlimited-OCR or DeepSeek-OCR model
          → NGramPerReqLogitsProcessor
            → Structured output parsing
              → Response formatting
```

## Performance Considerations

- First request after server start may have cold-start latency
- vLLM supports continuous batching for concurrent requests
- Recommended to keep vLLM server running (no auto-shutdown)
- Batch OCR uses Action Scheduler for queue management instead of synchronous loops

## Troubleshooting

| Symptom | Likely Cause | Solution |
|---|---|---|
| Connection refused | vLLM not running | Check `vllm serve` process |
| CUDA out of memory | Insufficient VRAM | Reduce `max-model-len` or use smaller model |
| Timeout on large documents | Default 60s timeout | Increase via `WP_MCP_AI_OCR_TIMEOUT` constant |
| "Provider not configured" | Missing endpoint URL | Set in Settings → Media → OCR |

## Related

- [Proposal 018](../project/proposals/018-unlimited-ocr-integration.md)
- [Implementation Plan](../project/proposals/018-unlimited-ocr-integration-implementation-plan.md)
- [Embedded Addon v0.2.0](embedded-addon-v020.md) — Embedded OCR backend
- [OCR Settings](ocr-settings.md)
