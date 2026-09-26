# Implementation Plan: Non-LLM Image Identification Pipeline

**Based on:** Proposal 043 (`docs/project/proposals/043-non-llm-image-identification.md`)
**Phase:** 0–6 — sequenced, each phase independently shippable
**Target:** Deterministic, cheap-first image understanding with zero required vision-LLM calls

---

## Phase 0 — Shared Cloud Vision Client (refactor, behavior-neutral)

### Step 0.1 — Extract client service (1 new file + 1 modified)
- [ ] `includes/services/class-wp-mcp-ai-cloud-vision-client.php` — new class `WP_MCP_AI_Cloud_Vision_Client`
  - `annotate( array $features, array $image, array $context = array() )` → normalized array or `WP_Error`
  - Moves endpoint constant, Gemini-key resolution (`wp_mcp_ai_vision_api_key` filter), timeout filter, request/error mapping from `vision_object_localization`
- [ ] `includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php` — refactor `execute()` to delegate to the client; **no change to slugs, params, or error codes**
- [ ] `includes/services/README.md` — add the class to the public-surface table (folder-README convention)

### Step 0.2 — Regression gate
- [ ] `vendor/bin/phpunit tests/test-vision-tools.php` stays green (all 11 cases)

---

## Phase 1 — Classic Cloud Vision Detection Tool (Base)

### Step 1.1 — New tool
- [ ] `includes/tools/class-wp-mcp-ai-tool-detect-image-content.php` — class `WP_MCP_AI_Tool_Detect_Image_Content`, slug `detect_image_content`
  - Params: `image_url` | `image_content` | `attachment_id` (via `WP_MCP_AI_Attachment_File_Resolver`), `features` (enum: `labels`, `text`, `web_entities`, `logos`, `landmarks`, `faces`, `safe_search`; default all), `max_results` (1–100)
  - Feature mapping: `LABEL_DETECTION`, `TEXT_DETECTION`, `WEB_DETECTION`, `LOGO_DETECTION`, `LANDMARK_DETECTION`, `FACE_DETECTION`, `SAFE_SEARCH_DETECTION` on `vision.googleapis.com/v1/images:annotate`
  - Returns a **normalized** envelope (not raw Google JSON): per-feature arrays with `description`, `score`, `confidence`, `bounding_poly` where applicable
  - Capability `edit_posts` + filter; missing key → `wp_mcp_ai_vision_missing_api_key` (no HTTP request)
- [ ] Register in `includes/class-wp-mcp-ai-tool-registry.php`:
  - Class→file map in `load_default_tools()`
  - Category map: `'detect_image_content' => 'external-tools'`
- [ ] `includes/class-wp-mcp-ai-tool-token-limits.php` — add `detect_image_content` (2.0, matching vision peers)

### Step 1.2 — Tests
- [ ] `tests/test-tool-detect-image-content.php`
  - capability gate (subscriber → 403), missing-key short-circuit with `pre_http_request` spy asserting **no request**, feature-list request-body shape, API-error envelope mapping, sanitization (image_content scrubbed)

---

## Phase 2 — Perceptual Hash + Similar Media Lookup (Base, pure PHP)

### Step 2.1 — dHash helper
- [ ] `includes/helpers/class-wp-mcp-ai-image-dhash.php` — class `WP_MCP_AI_Image_DHash`
  - `compute( string $file_path )` → 16-char hex or `WP_Error`; GD 9×8 grayscale downscale, row-adjacent bits; `function_exists( 'imagecreatetruecolor' )` guard
  - `distance( string $hash_a, string $hash_b )` → Hamming distance (0–64)
- [ ] `includes/helpers/README.md` — add to public-surface table

### Step 2.2 — New tool
- [ ] `includes/tools/class-wp-mcp-ai-tool-find-similar-media.php` — class `WP_MCP_AI_Tool_Find_Similar_Media`, slug `find_similar_media`
  - Params: `attachment_id` | `image_url`, `threshold` (bits, default 10), `limit`, `scan_limit` (default 300)
  - Caches hashes in post meta `_wp_mcp_ai_image_dhash`; matches sorted by distance with `similarity = (64 - distance) / 64`
  - Returns matches incl. attachment ID, title, URL, similarity
- [ ] Registry map + category `'find_similar_media' => 'wordpress-core'`; token-limits entry (1.0)

### Step 2.3 — Optional backfill cron
- [ ] `includes/class-wp-mcp-ai-dhash-backfill.php` — hook `wp_mcp_ai_daily`, 100 attachments/run, skips hashed, removes meta on `delete_attachment`

### Step 2.4 — Tests
- [ ] `tests/test-image-dhash.php` — identical file → distance 0; resized copy → small distance; unrelated → large distance; GD-missing path
- [ ] `tests/test-tool-find-similar-media.php` — known-distance fixtures, threshold filtering, scan_limit behavior

---

## Phase 3 — Metadata Tool + Layout Composer (Base, no external calls)

### Step 3.1 — Metadata tool
- [ ] `includes/tools/class-wp-mcp-ai-tool-get-image-metadata.php` — slug `get_image_metadata`
  - Params: `attachment_id` | `image_url`; output: alt, title, caption, description, EXIF (`wp_read_image_metadata`), `getimagesize()` info (dimensions, MIME, bits), filename, URL, attachment-page permalink when resolvable
  - Registry map + category `'wordpress-core'`; token-limits entry (1.0)

### Step 3.2 — Layout composer
- [ ] `includes/tools/class-wp-mcp-ai-tool-describe-image-layout.php` — slug `describe_image_layout`
  - Params: `boxes` (JSON array `{label, box:[x1,y1,x2,y2], score}` in normalized coordinates) | `source_tool_result` (raw `vision_object_localization` JSON) | `auto_detect` (bool — runs Cloud Vision localization when key present)
  - Normalizes coordinates, computes 3×3 grid quadrant per object (`top-right`, `center-left`, …), relative size buckets, overlap flags; emits a deterministic prose + structured layout description
  - Malformed JSON → `WP_Error` with actionable message
  - Registry map + category `'wordpress-core'`; token-limits entry (1.0)

### Step 3.3 — Tests
- [ ] `tests/test-tool-get-image-metadata.php` — attachment fixture fields, URL-only degradation
- [ ] `tests/test-tool-describe-image-layout.php` — quadrant math table-driven (top-right/center/bottom-left/edges), relative sizes, malformed JSON, auto-detect key-gating

---

## Phase 4 — Escalation Orchestrator (Base)

### Step 4.1 — New tool
- [ ] `includes/tools/class-wp-mcp-ai-tool-identify-image.php` — slug `identify_image`
  - Params: `attachment_id` | `image_url` | `image_content`, `include_web_search` (bool, default **false**), `include_layout` (bool, default true)
  - Ladder, cheap-first, each step `skipped` with reason when unavailable:
    1. `get_image_metadata` logic (inline or via the tool class — no LLM)
    2. `WP_MCP_AI_Image_DHash` media lookup
    3. Cloud Vision classic detection (only when Gemini key configured)
    4. Reverse-image web search (only when `include_web_search` AND Pro tool available AND key configured)
  - Envelope: `metadata`, `media_matches`, `detections`, `layout_summary`, `web_matches`, `confidence` (weighted across completed rungs), `skipped` (rungs + reasons), `escalation_hint` (`sufficient` | `suggest_analyze_image`)
  - **Never invokes a vision LLM**
  - Registry map + category `'external-tools'`; token-limits entry (2.0)

### Step 4.2 — Tests
- [ ] `tests/test-tool-identify-image.php`
  - ladder order (metadata before detection), no-key → rung 3 skipped with reason (not error), `include_web_search=false` never triggers web rung, confidence math, `escalation_hint` thresholds, subscriber capability gate

---

## Phase 5 — Pro Tools: Classic OCR + Reverse-Image Search

### Step 5.1 — Classic OCR tool
- [ ] `addons/pro/includes/tools/media/class-wp-mcp-ai-tool-ocr-image-classic.php` — slug `ocr_image_classic`
  - Wraps `WP_MCP_AI_OCR_Service` pinned to `provider => 'tesseract'` (worker tesseract.js → system tesseract); returns text, confidence, language, source (`worker` | `system` | error)
  - Register under `enable_media_toolkit` in `addons/pro/includes/tools/media/init.php`
- [ ] `addons/pro/includes/tools/media/README.md` — add to tool list

### Step 5.2 — Reverse-image search tool
- [ ] `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-search-similar-images.php` — slug `search_similar_images`
  - Providers: Bing Visual Search (official API) and SerpApi Google Lens; keys `bing_visual_search_key` / `serpapi_api_key` + provider preference in Pro Vision Analysis settings
  - Image URL passes `WP_MCP_AI_URL_Guard` before any outbound call; tool description states bytes are sent to the provider
  - Register under `enable_vision_analysis_toolkit` in `addons/pro/includes/tools/vision-analysis/init.php`
- [ ] `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php` — add the two key fields + provider select + `va_` defaults
- [ ] `addons/pro/includes/tools/vision-analysis/README.md` — add to tool list

### Step 5.3 — Tests
- [ ] `tests/test-tool-ocr-image-classic.php` — tesseract routing, worker-unavailable → system fallback, both-unavailable → `WP_Error`, capability gate
- [ ] `tests/test-tool-search-similar-images.php` — missing-key short-circuit (no HTTP), Bing request shape, SSRF-guard rejection of private hosts, SerpApi provider switch, capability gate

---

## Phase 6 — Docs, Registry Hygiene, Full Validation

### Step 6.1 — Documentation
- [ ] `docs/reference/tools/tool-reference.md` — 5 Base + 2 Pro tool entries with params and examples
- [ ] `includes/tools/README.md` — media/vision category line additions
- [ ] `.context/image-identification.md` — new subsystem context (ladder spec, tool slugs, key gating rules); referenced from `includes/tools/README.md` "Also Load"
- [ ] `readme.txt` changelog entry for the version that ships

### Step 6.2 — Registry hygiene
- [ ] `includes/class-wp-mcp-ai-tool-recommendations.php` — add slugs to media/vision recommendation groups
- [ ] `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` — add slugs where image presets exist
- [ ] `tests/tools/.coverage-manifest.txt` — remove/verify entries for the 7 new tools

### Step 6.3 — Validation gates (in order)
- [ ] `php -l` on all new/modified PHP files
- [ ] `composer run lint` (WPCS incl. `WPMCPAI.Tools.CanonicalReturnEnvelope` + `SanitizeAtEntry` sniffs)
- [ ] `composer run lint:compat` (PHP 7.4–8.3)
- [ ] `composer run test` (full suite; watch `test-vision-tools.php`, `test-image-*`)
- [ ] Base-mode sanity: `WP_MCP_AI_BASE_VERSION=1 vendor/bin/phpunit tests/test-tool-identify-image.php`
- [ ] `composer run docs:check-folder-readmes` — services/helpers/tools folder READMEs updated
- [ ] Manual smoke in Docker: upload an image → `identify_image` (no keys) → metadata + media matches; add Gemini key → detections appear; `describe_image_layout` output feeds a text-only chat prompt

---

## Files Created

1. `includes/services/class-wp-mcp-ai-cloud-vision-client.php`
2. `includes/helpers/class-wp-mcp-ai-image-dhash.php`
3. `includes/class-wp-mcp-ai-dhash-backfill.php`
4. `includes/tools/class-wp-mcp-ai-tool-detect-image-content.php`
5. `includes/tools/class-wp-mcp-ai-tool-find-similar-media.php`
6. `includes/tools/class-wp-mcp-ai-tool-get-image-metadata.php`
7. `includes/tools/class-wp-mcp-ai-tool-describe-image-layout.php`
8. `includes/tools/class-wp-mcp-ai-tool-identify-image.php`
9. `addons/pro/includes/tools/media/class-wp-mcp-ai-tool-ocr-image-classic.php`
10. `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-search-similar-images.php`
11. `tests/test-tool-detect-image-content.php`
12. `tests/test-image-dhash.php`
13. `tests/test-tool-find-similar-media.php`
14. `tests/test-tool-get-image-metadata.php`
15. `tests/test-tool-describe-image-layout.php`
16. `tests/test-tool-identify-image.php`
17. `tests/test-tool-ocr-image-classic.php`
18. `tests/test-tool-search-similar-images.php`
19. `.context/image-identification.md`
20. `docs/project/proposals/043-non-llm-image-identification.md` (this proposal pair)

## Files Modified

21. `includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php` (delegate to shared client)
22. `includes/class-wp-mcp-ai-tool-registry.php` (class map + category map)
23. `includes/class-wp-mcp-ai-tool-token-limits.php`
24. `includes/class-wp-mcp-ai-tool-recommendations.php`
25. `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php`
26. `includes/services/README.md`, `includes/helpers/README.md`, `includes/tools/README.md`
27. `addons/pro/includes/tools/media/init.php`, `addons/pro/includes/tools/media/README.md`
28. `addons/pro/includes/tools/vision-analysis/init.php`, `addons/pro/includes/tools/vision-analysis/README.md`
29. `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php`
30. `docs/reference/tools/tool-reference.md`, `readme.txt`, `tests/tools/.coverage-manifest.txt`

## Out of Scope (recorded for later)

- Worker `POST /api/image/phash` (sharp-based pHash upgrade for the dHash index)
- Transformers.js local captioning/CLIP in the worker (local AI models — different trust tier)
- Layout-hint templates for known layouts (e.g., "logo top-right" presets per theme) — natural Phase 2 after `describe_image_layout` telemetry
