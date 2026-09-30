# Implementation Plan: RF-DETR Cognition Enhancement

**Based on:** Proposal 049 (`docs/project/proposals/049-rf-detr-cognition-enhancement.md`)
**Phase:** 0–6 — sequenced, each phase independently shippable
**Target:** Self-hostable SOTA detection, segmentation masks, and keypoints in the vision cognition stack — one additive Base-file change (ladder rung 3) and no Proposal 043 contract changes; Phase 7 ports the Pro surface into the standalone Content Graph Pro addon

---

## Phase 0 — Service + Settings (foundation)

### Step 0.1 — New provider service
- [ ] `addons/pro/includes/services/class-wp-mcp-ai-roboflow-inference-service.php` — class `WP_MCP_AI_Roboflow_Inference_Service`
  - `infer( $image_base64, $model_id, $confidence = 0.5, $iou = 0.5, $extra = array() )` → canonical result array or `WP_Error`
  - `get_api_key()` — `va_roboflow_api_key` + `WP_MCP_AI_Credential_Resolver` fallback (mirror `WP_MCP_AI_HF_Vision_Inference_Service`)
  - `get_api_url()` — `va_roboflow_api_url`, default `https://serverless.roboflow.com`
  - Endpoint: `POST {api_url}/infer/{model_id}`; `Authorization` header only when a key is configured; SSRF validation via `WP_MCP_AI_URL_Guard::validate()`; HTTPS enforcement for non-loopback hosts
  - Normalizes `predictions[]` → `{label, confidence, box:[x1,y1,x2,y2], mask_points?, keypoints?}`; strips provider-only fields
  - `const MAX_PAYLOAD_BYTES = 5242880` (matches HF service); `const COCO_CLASSES` 80-entry static map; `const APACHE_MODEL_ALIASES` allowlist (N/S/M/L, seg-*, keypoint-preview)
- [ ] `addons/pro/includes/services/README.md` — add to the public-surface table

### Step 0.2 — Settings
- [ ] `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php` — new "RF-DETR / Roboflow" section (`wp_mcp_ai_va_detector` group):
  - `va_roboflow_api_key` (password field), `va_roboflow_api_url` (default `https://serverless.roboflow.com`, self-host helper text `http://<host>:9001`)
  - `va_roboflow_model` (select: `rfdetr-nano|small|medium|large`, default `small`)
  - `va_roboflow_catalog_model` (text, workspace/project/version or alias; empty = disabled)
  - `va_roboflow_allow_pml` (bool, default off) — gates XL/2XL aliases behind the PML 1.0 notice
  - `va_roboflow_confidence` / `va_roboflow_iou` defaults (0.5 / 0.5)
- [ ] `addons/pro/includes/tools/vision-analysis/init.php` — extend `wp_mcp_ai_vision_analysis_get_settings()` defaults with the new `va_roboflow_*` keys

### Step 0.3 — Tests
- [ ] `tests/pro/services/test-roboflow-inference-service.php`
  - missing-key short-circuit with `pre_http_request` spy asserting **no request** (serverless mode)
  - self-host no-key path still sends the request (local inference does not require auth)
  - request-shape test: URL, `Authorization` header presence/absence, body has base64 image + confidence
  - SSRF rejection of private-host URLs when non-loopback; HTTP-rejection of non-loopback endpoints
  - response normalization: raw predictions fixture → canonical shape; COCO class_id → label map; `class_name` passthrough for fine-tuned models
  - PML alias rejected unless `va_roboflow_allow_pml` enabled

---

## Phase 1 — `roboflow` provider in `analyze_image_objects` (no new slug)

### Step 1.1 — Enum + routing
- [ ] `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php`
  - add `roboflow` to the `provider` enum (`auto|huggingface|ollama|roboflow|openai|anthropic|gemini`)
  - `auto` resolution order: huggingface → roboflow → ollama (existing order preserved; roboflow inserted only when `va_roboflow_api_url` + model configured)
  - detection-mode call routes through `WP_MCP_AI_Roboflow_Inference_Service::infer()`; boxes flow into `WP_MCP_AI_Vision_Count_Normalizer` unchanged (**detector owns the count** invariant holds)
- [ ] `addons/pro/includes/tools/vision-analysis/README.md` — update provider list + conventions

### Step 1.2 — Tests
- [ ] `tests/pro/tools/vision-analysis/` — new cases: `provider=roboflow` request routing, count-breakdown math from RF-DETR fixture boxes, missing-key → `WP_Error`, capability gate (subscriber → 403)

---

## Phase 2 — `rfdetr_detect` tool (masks + keypoints)

### Step 2.1 — New tool
- [ ] `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php` — slug `rfdetr_detect`
  - Params: image source (via `WP_MCP_AI_Tool_Image_Base`), `task` enum `detect|segment|keypoints` (default `detect`), `model_id` override, `confidence`, `iou`, `max_results`
  - `detect` → `{label, confidence, box}` list; `segment` → adds normalized `mask_points` polygons; `keypoints` → per-instance 17-COCO-skeleton keypoints `{class, x, y, confidence}`
  - Category `vision`; capability `edit_posts` + filter; token-limits entry (2.0, vision peers)
- [ ] Register in `addons/pro/includes/tools/vision-analysis/init.php` under `enable_vision_analysis_toolkit`
- [ ] `addons/pro/includes/tools/vision-analysis/README.md` — add tool row

### Step 2.2 — Tests
- [ ] `tests/pro/tools/vision-analysis/test-tool-rfdetr-detect.php`
  - per-task response mapping from fixtures (boxes/masks/keypoints), `max_results` truncation, `model_id` override validation against the Apache allowlist, missing-key `WP_Error`, capability gate

---

## Phase 3 — `rfdetr_catalog_search` tool (fine-tuned models)

### Step 3.1 — New tool
- [ ] `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php` — slug `rfdetr_catalog_search`
  - Reads `va_roboflow_catalog_model` (alias or `workspace/project/version`); `WP_Error` when unset
  - Output: ranked `{label, confidence, box}` using the checkpoint's `class_name` values; `catalog` context passthrough (sku, product_id hints)
  - 5-min transient cache keyed by model + image dHash (reuses `WP_MCP_AI_Image_DHash`) — open question 4 default
- [ ] Register under the e-commerce toolkit init; update `addons/pro/includes/tools/ecommerce/README.md`

### Step 3.2 — Tests
- [ ] `tests/pro/tools/ecommerce/test-tool-rfdetr-catalog-search.php` — unset-model `WP_Error`, workspace path resolution, cache hit/miss, capability gate

---

## Phase 4 — Ladder + Layout Wiring (behavior-neutral)

### Step 4.1 — `identify_image` rung 3 eligibility
- [ ] `includes/tools/class-wp-mcp-ai-tool-identify-image.php` — when Pro is active and `roboflow` is configured, include RF-DETR detections alongside the Cloud Vision rung in the `detections` envelope (reported as an additional `source`, default order unchanged). No vision LLM, ever.
- [ ] Note: this Base change is **monolith-only** — it does not port to the CG standalone (no `identify_image` there; direct tools surface RF-DETR). Documented as a deviation in the Phase 7 cluster PR.
- [ ] `.context/image-identification.md` — document the new rung 3 source + canonical facts (no contract changes)

### Step 4.2 — Layout composer verification
- [ ] `tests/test-tool-describe-image-layout.php` — add a fixture case consuming RF-DETR-shaped boxes (normalized coords) → quadrant text; no code change expected, test proves detector-agnosticism

---

## Phase 5 — Media Pipeline Follow-Ons (documented, not built)

- [ ] Record follow-on issues in `docs/project/proposals/049-*.md` "Out of Scope" + GitHub issues:
  - GD mask application from `segment` polygons → `remove_background` enhancement (image-production toolkit)
  - Keypoint posture-QA tool for product photography (`design-product-photography` input)
  - Mask bitmask (PNG) output format alternative

---

## Phase 6 — Docs, Registry Hygiene, Full Validation

### Step 6.1 — Documentation
- [ ] `docs/reference/tools/tool-reference.md` — `rfdetr_detect`, `rfdetr_catalog_search`, and the `roboflow` provider entries with params + examples
- [ ] `docs/deploy/roboflow-inference-server.md` (new) — Docker compose recipe, GPU/CPU notes, CORS/port config, self-host-vs-serverless comparison, PML note
- [ ] `readme.txt` changelog entry for the shipping version

### Step 6.2 — Registry hygiene
- [ ] `includes/class-wp-mcp-ai-tool-recommendations.php` — add new slugs to vision/e-commerce recommendation groups (Pro)
- [ ] `tests/tools/.coverage-manifest.txt` — verify/add entries for the new tools

### Step 6.3 — Validation gates (in order)
- [ ] `php -l` on all new/modified PHP files
- [ ] `composer run lint` (WPCS incl. `CanonicalReturnEnvelope` + `SanitizeAtEntry`)
- [ ] `composer run lint:compat` (PHP 7.4–8.3; Pro files 8.1+)
- [ ] `composer run test` (watch `test-vision-tools.php`, `test-image-*`, `tests/pro/tools/vision-analysis/`)
- [ ] Base-mode sanity: `WP_MCP_AI_BASE_VERSION=1 vendor/bin/phpunit tests/test-tool-identify-image.php` (no Pro → unchanged)
- [ ] `composer run docs:check-folder-readmes` — services/tools folder READMEs updated
- [ ] Manual smoke in Docker: `roboflow/roboflow-inference-server-cpu` container + `analyze_image_objects provider=roboflow` → COCO counts; `rfdetr_detect task=segment` → masks; no-key self-host path; serverless path with a scratch API key

---

## Phase 7 — Ecosystem Port Cluster (base+pro → `plugins/nvoos-content-graph-pro`)

Runs per the ecosystem port loop (`.agents/skills/mcp-ai-wpoos-ecosystem-port/SKILL.md`) after Phases 0–6 merge to `alpha-working`. If the vision-analysis toolkit port cluster has not landed by then, this cluster is **F3 vision-analysis + RF-DETR** (whole toolkit + both services + `rfdetr_detect`); the `rfdetr_catalog_search` slice goes with the F2 e-commerce work. Only the standalone Pro addon files are touched — **D-NOBASE: zero changes under `includes/`**.

### Step 7.1 — Fresh branch + slice scoping
- [ ] `git fetch origin alpha-working && git switch -c feat/ecosystem-port-rfdetr origin/alpha-working` (after the previous cluster's PR is merged)
- [ ] Stage explicit paths only (never `git add -A`, never `vendor/`)

### Step 7.2 — Byte-identical ports
- [ ] `src/services/class-wp-mcp-ai-roboflow-inference-service.php` ← `addons/pro/includes/services/class-wp-mcp-ai-roboflow-inference-service.php` (transforms: port-note header, `declare(strict_types=1);`, domain swap, path swap; `WP_MCP_AI_Count_Normalizer` require re-pointed at the ported `src/tools/vision-analysis/` copy)
- [ ] `src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php` ← Pro source (same transforms; `WP_MCP_AI_Tool_Image_Base` seam resolves to the existing `src/tools/class-wp-mcp-ai-tool-image-base.php` copy)
- [ ] `src/tools/vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php` + HF vision service + toolkit init — ported only if the F3 vision-analysis cluster has not landed (fresh branch from the tracker's merged state decides)
- [ ] `src/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php` ← Pro source (F2 slice; e-commerce toolkit already ported)
- [ ] `php -l` every new file; `ls` confirms writes (port scripts can echo success on failed writes)

### Step 7.3 — Standalone-only wiring
- [ ] Slim `src/tools/vision-analysis/init.php`: full-body `! defined( 'WP_MCP_AI_PATH' )` guard; settings accessor reading `va_roboflow_*` from the `wp_mcp_ai_settings` option
- [ ] Tool filter `wp_mcp_ai_pro_register_vision_analysis_tools( $tools )` on `wp_mcp_ai_pro_tools` (mirrors the monolith inline map; subset/inert standalone)
- [ ] Ecosystem registration `wp_mcp_ai_pro_register_vision_analysis_ecosystem_tools()` via `WP_MCP_AI_Pro_Tool_Adapter` + `GraphToolAdapter` (duplicate slugs non-fatal)
- [ ] `toolkit_vision_analysis` module in `WP_MCP_AI_Pro_Module_Registry::define_modules()` (files guard on the init); update `tests/test-module-registry.php` `$expected` — count the list yourself
- [ ] Entry autoloader subtree probe for `src/tools/vision-analysis/` in `nvoos-content-graph-pro.php`
- [ ] Settings screen port deferred (documented) — standalone users configure via option/code; admin-screen slice is a later cluster

### Step 7.4 — Characterization tests (dual-matrix)
- [ ] `plugins/nvoos-content-graph-pro/tests/` — service contract (normalization, SSRF, missing-key) + tool gate tests; every asserted constant derived from the ported source, not guesses
- [ ] Capability variance check: `grep get_required_capability` across the batch before asserting (map, not uniform string)
- [ ] Serving-source assertions: monolith asserts `addons/pro/includes/<file>`; standalone accepts `nvoos-content-graph-pro/src/<file>`
- [ ] Monolith + standalone Docker matrices green (filter first, then full; retry at 900 s before investigating)
- [ ] PHPCS with `--standard=plugins/nvoos-content-graph-pro/phpcs.xml.dist` exit 0 (phpcbf for array alignment; re-run phpcs alone)

### Step 7.5 — Byte-identity verifier + tracker + PR
- [ ] One-shot `bin/verify-rfdetr.php` (reconstruct expected body; per-pair source path + domain `addons/pro/includes/` + `mcp-ai-wpoos-pro`); delete after use
- [ ] `docs/project/ecosystem-port-tracker.md` — append the sub-cluster entry under Wave F3 (+ F2 for the e-commerce slice) and rewrite the row's "Remaining:" tail only
- [ ] Commit → push → PR to `alpha-working` with the standard body (landed slices, documented deviations incl. monolith-only `identify_image` wiring, standalone-only wiring, validation)
- [ ] Merge after both `PHPUnit Pro Addon/test` CI checks pass; next cluster starts from the merged `origin/alpha-working`

---

## Files Created

1. `addons/pro/includes/services/class-wp-mcp-ai-roboflow-inference-service.php`
2. `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php`
3. `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php`
4. `tests/pro/services/test-roboflow-inference-service.php`
5. `tests/pro/tools/vision-analysis/test-tool-rfdetr-detect.php`
6. `tests/pro/tools/ecommerce/test-tool-rfdetr-catalog-search.php`
7. `docs/deploy/roboflow-inference-server.md`
8. `docs/project/proposals/049-rf-detr-cognition-enhancement.md` (this proposal pair)

**Phase 7 (ecosystem port) — standalone CG Pro mirrors:**
9. `plugins/nvoos-content-graph-pro/src/services/class-wp-mcp-ai-roboflow-inference-service.php`
10. `plugins/nvoos-content-graph-pro/src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php`
11. `plugins/nvoos-content-graph-pro/src/tools/vision-analysis/init.php` (slim standalone init)
12. `plugins/nvoos-content-graph-pro/src/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php`
13. `plugins/nvoos-content-graph-pro/tests/` characterization tests
14. (`src/tools/vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php` + HF vision service + toolkit init — only if the F3 vision-analysis cluster has not landed yet)

## Files Modified

15. `addons/pro/includes/admin/class-wp-mcp-ai-vision-analysis-settings.php` (RF-DETR settings section)
16. `addons/pro/includes/tools/vision-analysis/init.php` (settings defaults + `rfdetr_detect` registration)
17. `addons/pro/includes/tools/vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php` (`roboflow` provider)
18. `addons/pro/includes/tools/vision-analysis/README.md`, `addons/pro/includes/services/README.md`
19. `addons/pro/includes/tools/ecommerce/init.php` (if needed) + `ecommerce/README.md`
20. `includes/tools/class-wp-mcp-ai-tool-identify-image.php` (rung 3 extra source — Base file, monolith-only; does not port)
21. `.context/image-identification.md` (canonical-facts update)
22. `docs/reference/tools/tool-reference.md`, `readme.txt`, `tests/tools/.coverage-manifest.txt`
23. `includes/class-wp-mcp-ai-tool-recommendations.php` (Pro recommendation groups)
24. `plugins/nvoos-content-graph-pro/nvoos-content-graph-pro.php` (entry autoloader probe) + module registry + `tests/test-module-registry.php`
25. `docs/project/ecosystem-port-tracker.md` (Wave F3 + F2 cluster entries, "Remaining:" tail)

## Out of Scope (recorded for later)

- `remove_background` mask application (Phase 5 follow-on)
- Keypoint posture-QA tool (Phase 5 follow-on)
- XL/2XL PML models beyond the consent toggle wiring
- Video-frame RF-DETR streaming (extended-cognition `ext_cog_analyze_video_feed` integration)
- Fetching class-name maps dynamically from the Inference server
- CG Pro admin-settings screen port for `va_roboflow_*` (standalone config via option/code until the F-UI settings slice)
- Porting the Base `identify_image` rung-3 wiring into the CG standalone (no `identify_image` there — direct tools surface RF-DETR)
