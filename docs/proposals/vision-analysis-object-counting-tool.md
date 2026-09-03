# Vision Analysis Toolkit — Image Object Counting Tool

**Status:** Accepted — Phases 1 & 2 implemented
**Date:** 2026-09-03
**Component:** `addons/pro/includes/tools/vision-analysis/`
**Tool slug:** `analyze_image_objects`

## 1. Summary

Add a Pro toolkit tool that takes an image (attachment, file, URL, or base64),
identifies the objects in it, and returns a **count breakdown** grouped by
category — e.g. `person × 3, car × 4, cup × 5` — with per-box confidence data
and an optional annotated copy of the image with bounding boxes drawn on it.

## 2. Research findings (industry standards)

| Model / approach | Hosting | Counting accuracy | License notes |
|---|---|---|---|
| Chat VLMs (GPT-4o, Gemini, Claude, Qwen-VL) | Hosted APIs already supported by the plugin | Weak on dense scenes — under/over-counts; COUNTS (CVPR 2025) measured ~57 % / ~28 % grounding accuracy for GPT-4o / Gemini-1.5 | Commercial APIs |
| `google/owlv2-base-patch16` | HF Inference API — **already wired** in `WP_MCP_AI_HF_Vision_Inference_Service` | Strong for enumeration via box counting (open-vocabulary) | Apache-2.0 |
| Grounding DINO / Grounded SAM 2 | HF hub / self-host | Strong, long-tail friendly; heavier | Apache-2.0 |
| Florence-2 | HF hub / self-host | Detection + dense captioning + OCR in one model | MIT |
| **LocateAnything-3B** (NVIDIA, 2026-05-26) | Self-host only (transformers/BF16, ~12–35 GB VRAM, or `locate-anything.cpp`/LocalAI ggml). "Not deployed by any Inference Provider" on HF. | Excellent grounding/dense detection (Parallel Box Decoding) | **Non-commercial research license** — cannot ship as a default for a commercial plugin |
| Nemotron 3 Nano Omni (embeds LocateAnything capabilities) | **NVIDIA NIM** (`nvidia/nemotron-3-nano-omni-30b-a3b-reasoning` on build.nvidia.com, OpenAI-compatible). The plugin already has `NvidiaNimClient`. | Grounding-capable production VLM | Commercial NIM terms |

**Conclusion:** count with a dedicated detector (boxes = counts) and use a VLM
only for open-world label normalization. LocateAnything/NIM stays a
bring-your-own-endpoint option (Phase 3), never the default.

## 3. Design

```
resolve source (attachment_id / file_id / url / image_url / image_data)
        │
        ├─ validate: SSRF URL guard, MIME allowlist, payload size
        │
        ├─ mode = detection ──► HF OWLv2 / Ollama vision ──► group boxes by label
        ├─ mode = vlm ────────► OpenAI / Anthropic / Gemini counting prompt (JSON)
        └─ mode = hybrid (default)
                 │
                 ├─ detection first (authoritative counts)
                 └─ optional VLM label-normalization pass (renames, never recounts)
        │
        ├─ Phase 2: annotate=true ──► GD-drawn bounding boxes ──► new attachment
        │
        └─ canonical envelope
             success, mode, provider, model,
             counts[] {label, count, avg_confidence, boxes[]},
             total_items, image_url (echoed for orchestrator vision follow-up),
             annotated_image {attachment_id,url} (when requested),
             message
```

**Invariants**
- Canonical envelope: success array or `WP_Error` (PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope`).
- Two-gate sanitisation: sanitize `$arguments[...]` at entry, escape at exit.
- The VLM never produces the final count in hybrid mode — it only normalizes labels.
- Remote URLs pass `WP_MCP_AI_Url_Guard::validate()` (SSRF) before fetching.
- Image payloads respect `WP_MCP_AI_HF_Vision_Inference_Service::MAX_PAYLOAD_BYTES` (5 MB); oversized images are downscaled first.

## 4. File map

| File | Purpose |
|---|---|
| `addons/pro/includes/tools/vision-analysis/README.md` | Folder context (required by folder-README convention) |
| `.../vision-analysis/init.php` | Toolkit gate + settings accessor functions |
| `.../vision-analysis/class-wp-mcp-ai-vision-count-normalizer.php` | Pure breakdown math: grouping, VLM JSON normalization, label-alias merge, message building |
| `.../vision-analysis/class-wp-mcp-ai-vision-vlm-client.php` | Provider-specific VLM counting/normalization calls (OpenAI, Anthropic, Gemini) |
| `.../vision-analysis/class-wp-mcp-ai-vision-annotator.php` | Phase 2: GD bounding-box annotation |
| `.../vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php` | The tool |
| `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php` | Admin settings page (toggle, models, thresholds) |
| `addons/pro/includes/services/class-wp-mcp-ai-hf-vision-inference-service.php` | + `count_objects()` wrapper |
| `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` | + `vision_analysis` conditional toolkit |
| `addons/pro/mcp-ai-wpoos-pro.php` | + gated `$pro_tools` map entry |
| `tests/pro/tools/vision-analysis/test-analyze-image-objects.php` | Unit tests |
| `docs/toolkits/vision-analysis-toolkit.md` | User-facing toolkit doc |

## 5. Tool contract

**Capability:** `upload_files`
**Flags:** `pro`, `requires-capability`, `read-only`, `requires-credentials`,
`requires-vision-model`, `external-api`, `network-dependent`, `consumes-tokens`,
`model-dependent`, `async`, `rate-limited`, `performance-impact`
**Categories:** `vision`, `object-detection`, `counting`, `image-analysis`

Inputs (merged with the shared image source schema):
`mode` (`hybrid`|`detection`|`vlm`), `provider` (`auto`|`huggingface`|`ollama`|`openai`|`anthropic`|`gemini`),
`model` (override), `categories[]` (≤ 100 candidate labels), `min_confidence` (0–1, default 0.5),
`include_boxes` (bool, default true), `annotate` (bool, default false), `max_tokens` (default 1024).

Settings (subset getter `wp_mcp_ai_vision_analysis_get_settings()`):
`enable_vision_analysis_toolkit`, `va_detection_model` (default `google/owlv2-base-patch16`),
`va_min_confidence` (default 0.5), `va_vlm_provider` (default `auto`),
`va_vlm_model` (default empty → provider default), `va_annotate_default` (default false),
`va_max_image_bytes` (default 5242880).

## 6. Phases

- **Phase 1 (done):** tool + normalizer + HF/Ollama detection + VLM counting + hybrid + registration + settings + tests.
- **Phase 2 (done):** GD bounding-box annotation → annotated attachment in envelope; JSON-enforced VLM responses (`response_format` on OpenAI, tolerant JSON extraction everywhere).
- **Phase 3 (future):** BYO OpenAI-compatible grounding endpoints (LocateAnything via vLLM/SGLang/LocalAI or NVIDIA NIM), `<box>` token parsing, caching by image hash.
- **Phase 4 (future):** Florence-2 dense captioning pass, client-side overlay rendering.

## 7. Testing

```bash
vendor/bin/phpunit tests/pro/tools/vision-analysis/test-analyze-image-objects.php
composer run lint            # includes the canonical-envelope / sanitise-at-entry sniffs
composer run docs:check-folder-readmes
```

Coverage: envelope shape, breakdown math (grouping, threshold filtering,
sorting), VLM JSON parsing (fenced/plain), label-alias merging, SSRF rejection
(private-IP URL), auth gates (no user / wrong capability), provider fallback
(hybrid → vlm when detection fails), mocked-HTTP detection + VLM paths,
annotation attachment creation (skipped when GD is absent).

## 8. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Dense-scene undercounts | Return `avg_confidence` + boxes so users can audit; hybrid VLM sanity pass |
| Image bytes leave the site (hosted inference) | Toolkit off by default; admin note in settings page; Ollama local option |
| SSRF via image URLs | `WP_MCP_AI_Url_Guard::validate()` before every remote fetch |
| VLM JSON drift | Tolerant JSON extraction + strict schema coercion in the normalizer |
| LocateAnything licensing | Never shipped as default; BYO endpoint only, documented |
