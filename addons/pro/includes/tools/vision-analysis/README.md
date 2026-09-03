# Vision Analysis

## Purpose

Houses the Vision Analysis toolkit: sensor-free image understanding tools for AI
agents. The flagship tool `analyze_image_objects` detects the objects in an
image and returns a per-category **count breakdown** (label, count, average
confidence, optional bounding boxes), using dedicated detectors (HuggingFace
OWLv2, local Ollama vision models) with an optional VLM pass (OpenAI /
Anthropic / Gemini) for open-world label normalization and counting. Phase 2
adds GD-based bounding-box annotation that returns an annotated copy of the
image as a WordPress attachment.

Design principle: **the detector owns the count** — boxes are counted, and a
VLM is only used to rename/verify labels, never to recount.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry via Vision Analysis toolkit (`wp_mcp_ai_vision_analysis_get_settings()`) |
| **Optional dependencies** | GD extension (annotation only); HuggingFace API key or Ollama for detection; OpenAI/Anthropic/Gemini keys for VLM modes |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Analyze_Image_Objects` | `class-wp-mcp-ai-tool-analyze-image-objects.php` | tool registry |
| `WP_MCP_AI_Vision_Count_Normalizer` | `class-wp-mcp-ai-vision-count-normalizer.php` | tool, HF vision service, tests |
| `WP_MCP_AI_Vision_VLM_Client` | `class-wp-mcp-ai-vision-vlm-client.php` | tool |
| `WP_MCP_AI_Vision_Annotator` | `class-wp-mcp-ai-vision-annotator.php` | tool |
| `WP_MCP_AI_Vision_Analysis_Settings` | `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php` | admin |
| `wp_mcp_ai_vision_analysis_is_enabled()` | `init.php` | registry, tools |
| `wp_mcp_ai_vision_analysis_get_settings()` | `init.php` | tool |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_vision_analysis_get_settings()` (toggle, detection model, thresholds); `WP_MCP_AI_Admin_Settings` (provider API keys); image sources via `WP_MCP_AI_Tool_Image_Base` (`attachment_id`, `file_id`, `url`, `image_url`, `image_data`)
- **Writes to:** WordPress media library (annotated image attachment, when `annotate=true`)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_HF_Vision_Inference_Service` (`run_object_detection`, `count_objects`), `WP_MCP_AI_Ollama_Client`, `WP_MCP_AI_OpenAI_Client`, `WP_MCP_AI_Anthropic_Client`, `WP_MCP_AI_Url_Guard` (SSRF), `WP_MCP_AI_Tool_Image_Base` (source resolution, attachment saving)
- **Events fired:** None explicit
- **Events listened to:** None

## Conventions

- Tool slug: `analyze_image_objects`; category tags: `vision`, `object-detection`, `counting`, `image-analysis`.
- Settings live in the main `wp_mcp_ai_settings` option under `va_*` keys (toggle: `enable_vision_analysis_toolkit`).
- Remote image URLs must pass `WP_MCP_AI_Url_Guard::validate()` before any fetch (SSRF defence).
- Payloads respect `WP_MCP_AI_HF_Vision_Inference_Service::MAX_PAYLOAD_BYTES`; oversized images are downscaled before upload to the inference provider.
- Breakdown entries are `{label, count, avg_confidence, boxes[]}` — produced only by `WP_MCP_AI_Vision_Count_Normalizer` so the math stays in one place.
- Canonical envelope: success array or `WP_Error`; sanitize arguments at entry, escape at exit (PHPCS sniffs `WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/vision-analysis/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [Proposal: Vision Analysis Object Counting Tool](../../../docs/proposals/vision-analysis-object-counting-tool.md)
