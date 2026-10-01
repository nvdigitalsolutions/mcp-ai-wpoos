# Media Studio AI Fashion Production — Implementation Plan

> **Status:** In progress — Phase 0 + Phase 1 (base plugin) and Phase 2 (Pro) implemented; Phase 3–5 pending.
> **Parent plan:** `media-studio-fashion-photography-enhancement-plan.md` (research, gaps G-01…G-10, architecture)
> **Date:** 2026-10-01

This document is the executable task breakdown for the parent plan. It records the
resolved decisions (D-1…D-3, see parent §13), the concrete file-level work per phase,
and the validation gates. Phases are implemented in order; each lands as its own
change-set against the normal flow.

---

## 1. Resolved decisions (from parent §13)

| # | Decision | Default |
|---|---|---|
| D-1 | Disclosure: `metadata` always on (never downgradable); `watermark` **auto-forced for face-swap / identity try-on outputs**; non-face watermark opt-in per export profile; one-time per-user `require_ack` for face transforms. | metadata + forced face watermark + ack |
| D-2 | `fashion-studio` = new **mode** of `nvoos_media_studio_app` (lazy-loaded chunk). No new shortcode in v1. | new mode |
| D-3 | Batch cost tripwires: unknown pricing → review; per-image > $0.25 → review; per-job > $10 → approval; hard cap $100 → reject; consent transforms always review. All ceilings are settings. | $0.25 / $10 / $100 |

---

## 2. Phase 0 — Server Bridge Foundation (base) ✅ IMPLEMENTED

### 2.1 Files

| File | Change |
|---|---|
| `addons/media-studio/includes/ai/class-nvoos-media-studio-ai-service.php` | NEW — capabilities, transform routing, import/export, cost review gate, consent gate, provenance meta, white-bg validation, watermark helper |
| `addons/media-studio/includes/rest/class-nvoos-media-studio-rest.php` | Extend `register_routes()` with `/ai/capabilities`, `/ai/presets`, `/ai/models`, `/ai/generate`, `/ai/import`, `/ai/export` |
| `addons/media-studio/includes/shortcode/class-nvoos-media-studio-shortcode.php` | Add `fashion-studio` to the mode allowlist |
| `addons/media-studio/nvoos-media-studio.php` | `require_once` the AI service; bump version (3 places incl. `package.json`) |
| `addons/media-studio/tests/test-ai-service.php` | NEW — service unit tests |
| `addons/media-studio/tests/test-rest.php` | Extend — route registration, permission matrix, capabilities envelope |

### 2.2 REST surface

- `GET /ai/capabilities` — providers, transforms (availability + backend + fidelity), settings (disclosure, tripwires), sidecar, WC, Pro flags. Permission: `edit_posts`.
- `GET /ai/presets` — base default presets (Pro seeds more later). Permission: `edit_posts`.
- `GET /ai/models` — identity library (empty in base; Pro fills it). Permission: `edit_posts`.
- `POST /ai/generate` — one transform on one attachment. Permission: `upload_files` + `edit_posts`. Nonce required (cookie auth).
- `POST /ai/import` — register an attachment as editor source (returns proxied URL + meta). Permission: `upload_files`.
- `POST /ai/export` — persist canvas/dataURL as attachment with provenance meta. Permission: `upload_files`.

### 2.3 Contracts (enforced by tests)

- Canonical envelope on every write route: `success` array or `WP_Error` with status (REST converts).
- Two-gate sanitization: `sanitize_*` at entry; REST serialization escapes at exit.
- Review gate shape: `{ status: 'review_required', estimate_usd, per_image_usd, reason }`; client re-posts with `confirmed: 1` to proceed.
- Audit: every generate/export writes `WP_MCP_AI_Logger::log_event( 'media_studio_transform', … )`.

### 2.4 Validation gates

- [x] PHPUnit: `addons/media-studio/tests` green (WP 6.9 + WP 7.1 in Docker).
- [x] phpcs (ERRORS gate) clean on all touched PHP files.
- [x] `npm run typecheck`, `npm test`, `npm run build` clean.

---

## 3. Phase 1 — Fashion Transforms + `fashion-studio` SPA mode (base) ✅ IMPLEMENTED

### 3.1 Server-side transform routing (`NV_oOS_Media_Studio_AI_Service::execute_transform`)

| Transform | Core backend | Sidecar (when `WP_MEDIA_WORKER_URL`) | Notes |
|---|---|---|---|
| `on-model` | `edit_gemini_image` (garment-lock prompt on flatlay/ghost/mannequin) | pluggable via filter `nvoos_media_studio_sidecar_transform` | PDP core path |
| `model-swap` | `edit_gemini_image` (person-region inpaint, keep garment) | filter | pose/garment preservation |
| `face-swap` | `edit_gemini_image` (face guidance) — consent + watermark forced | filter | D-1 rules |
| `background` | `edit_gemini_image` (bg replacement, style presets) | filter | studio/lifestyle/gradient |
| `recolor` | `edit_gemini_image` (color-lock prompt) | filter | one color per job |
| `packshot` | `edit_gemini_image` (white-bg prompt) → white-bg validation | `remove_background` (Pro) + GD white composite when registry has it | Phase 3 validator |
| `detail-repair` | `edit_gemini_image` (inpaint logos/text) | filter | |
| `try-on` | `edit_gemini_image` on person photo (garment descriptor), prompt-bound fidelity | sidecar VTON preferred | consent-gated |

- Prompt templates: PHP constants in the AI service; fixed lighting/background/style
  parts, only garment descriptor varies (project product-photography consistency rule).
- Provider selection: core tool slug map; tool availability from
  `WP_MCP_AI_Model_Config::get_available_providers()` + registry.
- Cost: `WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $args )`; D-3 tripwires.

### 3.2 SPA `fashion-studio` mode

| File | Change |
|---|---|
| `addons/media-studio/src/App.tsx` | Add `'fashion-studio'` to `MediaMode`/`ALLOWED_MODES`; lazy-load via `React.lazy` + `Suspense` |
| `addons/media-studio/src/components/FashionStudio.tsx` | NEW — input picker, transform panel (capabilities-driven), options (bg style, color, aspect, count, seed), review-confirm flow, result grid with re-roll + compliance chip |
| `addons/media-studio/src/hooks/useAiApi.ts` | NEW — typed REST helpers (nonce auth) |
| `addons/media-studio/src/components/ImageEditor.tsx` | Add "Save to Media Library" (`/ai/export`) + "Load from Library" (`wp.media`) |
| `addons/media-studio/src/styles/fashion-studio.css` | NEW — fashion studio styles |

- Bundle discipline: `FashionStudio` ships as a dynamic `import()` chunk so
  `image-editor`-only pages never pay for it (addresses README 826KB note, R-5).
- A11y: `aria-live="polite"` on generation status, toolbar pattern, keyboard-reachable
  buttons; axe-core dev audit already wired in `src/index.tsx`.

### 3.3 Validation gates

- [x] vitest: `app.test.tsx` extended for mode dispatch; NEW `fashion-studio.test.tsx`
      (capabilities-driven UI, review-confirm flow, compliance chip).
- [x] PHPUnit: transform routing (filter seam `nvoos_media_studio_execute_tool` returns
      canned result), prompt templates, review gate, consent gate, provenance meta.
- [x] Build + typecheck clean.

---

## 4. Phase 2 — Identities, Presets, Batch & Review (Pro) ✅ IMPLEMENTED

### 4.1 AI Model Identity library (Pro)

- CPT `mcp_ai_fashion_model` (`addons/pro/includes/fashion/class-wp-mcp-ai-fashion-model-cpt.php`):
  `title` + featured image; meta `_fashion_model_gender|skin_tone|body_type|age_group|height|consent_status|prompt_embed|is_custom`
  (all `register_post_meta` with sanitize callbacks + REST exposure).
- Seeded starter library (6 prompt-only identities across demographics, consent `none`)
  using the versioned seeding pattern (`wp_mcp_ai_fashion_models_seeded`).
- Consent gate: `consent_filter()` hooks `nvoos_media_studio_identity_consent`;
  face transforms reject non-`granted` identities. Audit-log usage per job via the base service.
- `/ai/models` returns the library when Pro is active.

### 4.2 Fashion presets (Pro)

- `WP_MCP_AI_Media_Template_Presets::get_presets()` gains the `fashion` category
  (PDP-white, PDP-lifestyle, editorial, lookbook, social-variant) with
  `operation => 'fashion_generate'` and the plan's parameter shape
  (`transform, lighting, background, style_tokens, composition, aspect_ratio, fidelity_level`).
- `get_fashion_presets()` converts them to the SPA payload shape and merges into
  the `/ai/presets` endpoint via the `nvoos_media_studio_presets` filter.

### 4.3 Batch jobs & review queue (Pro)

- Job store: private CPT `mcp_ai_fashion_job` (`class-wp-mcp-ai-fashion-batch.php`)
  with statuses `pending|processing|review|completed|failed` and per-variant state
  (`pending|generated|approved|rejected|replaced|failed`) in `_fashion_job_variants`.
- Action Scheduler dispatch (group `nvoos_media_studio_batch`) with an **inline
  fallback** when AS is unavailable or enqueue fails (robustness on managed hosts
  and in test environments without AS tables).
- Endpoints (`class-wp-mcp-ai-fashion-rest.php`, registered into the shared
  `nvoos-media-studio/v1` namespace): `POST /ai/jobs`, `GET /ai/jobs`,
  `GET /ai/jobs/<id>`, `POST /ai/jobs/<id>/review`.
- Review UX in the SPA (`src/components/FashionBatchPanel.tsx`): source-ID batch
  creation with the same review-confirm + ack flows as single runs, auto-refreshing
  job list, expandable variant grid with approve/reject/re-roll.
- D-3 tripwires evaluated per job (per-image estimate × source count); hard cap
  blocks, tripwires require confirm, consent transforms require ack.
- Per-job cost surfaced from the base cost tracker via the
  `nvoos_media_studio_cost_estimate` filter seam.
- Approve runs the export pipeline: WooCommerce gallery attach
  (`_product_image_gallery`, `edit_products`-capable) + media collection add.

### 4.4 Media collections (Pro)

- `add_to_collection()` appends approved attachments to the existing
  `mcp_ai_media_coll` CPT's `_mcp_ai_collection_items` meta; job-level
  `collection_id`/`product_id` flow through create → approve.

### 4.5 Loading

- New Pro module `fashion_studio` in `WP_MCP_AI_Pro_Module_Registry` (depends on
  `toolkit_media`, requires `NV_oOS_Media_Studio_AI_Service`) →
  `addons/pro/includes/fashion/init.php` wires CPT init, AS hook, REST routes, and
  the `nvoos_media_studio_{models,identity_consent,presets}` seams.

---

## 5. Phase 3 — Marketplace-Compliant Output Pipeline (base) ⏳ PARTIALLY IMPLEMENTED

- [x] White-background validation (edge pixel sampling, tolerance math) — implemented
      as `NV_oOS_Media_Studio_AI_Service::validate_white_background()` and used by
      `packshot`.
- [x] Provenance naming on AI outputs (`fashion-<transform>-<hash>` — never `IMG_xxxx`).
- [ ] Dimension profiles (amazon/woocommerce/social/web) + auto-resize — pending.
- [ ] Format/optimization (WebP vs JPEG per marketplace) via media-worker `/optimize` — pending.
- [ ] Auto alt text via `generate_image_alt_text_validated` on export — pending.

---

## 6. Phase 4 — Provenance, Disclosure & Compliance (base) ⏳ PARTIALLY IMPLEMENTED

- [x] Attachment meta on every AI output: `_nvoos_ai_generated`, `_nvoos_ai_provider`,
      `_nvoos_ai_model`, `_nvoos_ai_prompt_hash`, `_nvoos_ai_transform`, `_nvoos_ai_identity_id`.
- [x] D-1 policy: disclosure setting (metadata floor), forced GD watermark on face
      outputs, per-user one-time ack for face transforms.
- [x] Consent gating for face-swap/try-on identities (filter seam
      `nvoos_media_studio_identity_consent`; Pro CPT plugs in during Phase 2).
- [x] Prompt-injection hardening on user-supplied text (length cap + instruction-strip).
- [x] Audit logging of every generate/export.
- [ ] IPTC 2025.1 XMP fields (small PHP XMP writer) — pending.
- [ ] C2PA manifest via media-worker (best-effort, optional signing key) — pending.

---

## 7. Phase 5 — Video & Assistant Tooling (Pro, optional) ⏳ PENDING

- Fashion video: media-worker `/api/video/generate` driven from an approved still;
  `video` transform in the SPA; `extract_video_frames` for thumbnails.
- 8 `fashion_*` Pro tools (`fashion_onmodel_generate`, `fashion_model_swap`,
  `fashion_background_generate`, `fashion_recolor`, `fashion_packshot`,
  `fashion_virtual_tryon`, `fashion_batch_job`, `fashion_identity_manage`) —
  canonical envelope + two-gate sanitization + `get_usage_guidance` +
  capability declarations; base-version gating.
- Workflow Builder: expose transforms as workflow node input types.

---

## 8. Rollout & backwards compatibility

1. Phase 0+1 are additive (new routes, new mode, new buttons); existing modes untouched.
2. Version bump rule: `Version:` header, `NVOOS_MEDIA_STUDIO_VERSION`, `package.json` (3 places).
3. All new attachment meta only on new outputs; old media unaffected.
4. Settings default to least-surprising: metadata on, watermark off (except forced face outputs), conservative tripwires.

## 9. Test environment

- PHPUnit in Docker: `oos-wp` container, WP 6.9 primary + WP 7.1 second validation
  (see `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` for exact commands).
- phpcs: `--standard=phpcs.xml.dist --error-severity=1 --warning-severity=1`.
- JS: `npm run typecheck` + `npm test` (vitest) + `npm run build` in `addons/media-studio`.
