# Non-LLM Image Identification Ladder

> **GSD Context File** — Load this when editing any image/vision tool, the
> shared Cloud Vision client, the dHash helper, or the Pro visual-search tool.
> Last reviewed: September 26, 2026 (1.1.87, Proposal 043).

---

## What It Is

A cheap-first escalation ladder for answering "what is on this image?"
without sending pixels to a vision LLM. Each rung is deterministic; the
vision model is only the fallback of last resort.

| Rung | Tool | Cost | Distribution |
|---|---|---|---|
| 1. Metadata | `get_image_metadata` | Free | Base |
| 2. Perceptual-hash media lookup | `find_similar_media` | Free (GD) | Base |
| 3. Classic Cloud Vision detection | `detect_image_content` | ~Free | Base (key-gated) |
| 3b. Layout description | `describe_image_layout` | Free / key-gated (`auto_detect`) | Base |
| 4. Reverse-image web search | `search_similar_images` | Per-query | Pro (key-gated) |
| Orchestrator | `identify_image` | Sum of rungs | Base |
| Classic OCR | `ocr_image_classic` | Free (worker/system tesseract) | Pro |

## Canonical Facts (avoid drift)

- **`identify_image` never calls a vision LLM.** Its `escalation_hint` is
  `sufficient` or `suggest_analyze_image`; the model decides whether to
  spend vision tokens.
- **Credentials fail closed.** Every external rung mirrors the
  `wp_mcp_ai_vision_missing_api_key` pattern: no key → the rung is reported
  as `skipped` with a reason (never an error, never an HTTP request). User
  image bytes never leave the server without configured credentials.
- **Web search is opt-in per call** (`include_web_search`, default false) —
  it sends bytes to Bing/SerpApi.
- **Cloud Vision key plumbing is shared** via
  `WP_MCP_AI_Cloud_Vision_Client` (`includes/services/`) — key resolution
  (`wp_mcp_ai_vision_api_key` filter), timeout
  (`wp_mcp_ai_vision_request_timeout`), request, and error mapping. Legacy
  tools return the raw decoded response; new tools wrap results in the
  canonical envelope.
- **dHash is pure PHP/GD** (`WP_MCP_AI_Image_DHash`, 64-bit difference hash,
  post meta `_wp_mcp_ai_image_dhash`, daily backfill
  `WP_MCP_AI_DHash_Backfill`). No npm packages in the plugin — npm-based
  work belongs in the media-worker sidecar.
- **Layout composer is the text-mode Set-of-Mark analogue:** boxes
  (`vision_object_localization` / `analyze_image_objects` JSON or
  `auto_detect`) become 3×3-grid quadrants, relative positions, and size
  buckets in deterministic prose.
- **Registry:** Base tools register via the class→file map in
  `WP_MCP_AI_Tool_Registry::load_default_tools()` + the category map
  (`external-tools` for API-backed, `wordpress-core` for pure-WP) + token
  limits in `class-wp-mcp-ai-tool-token-limits.php`. Pro tools register in
  `wp_mcp_ai_pro_register_tools()` under `enable_media_toolkit`
  (`ocr_image_classic`) and `enable_vision_analysis_toolkit`
  (`search_similar_images`).
- **SSRF:** any URL the plugin fetches server-side passes
  `WP_MCP_AI_URL_Guard::validate()` first.

## Also Load

- `.context/tool-registry.md` — canonical envelope, slug rules, capability gating (always)
- `.context/security-checklist.md` — sanitisation/auth rules (always)
- `.context/pro-vs-base.md` — Base vs Pro placement rationale
- `.context/media-worker.md` — worker routing for `ocr_image_classic`
- `docs/project/proposals/043-non-llm-image-identification.md` — design decisions D1–D7
