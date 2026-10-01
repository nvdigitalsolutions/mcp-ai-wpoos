# Media Studio → AI Fashion Production Suite — Enhancement Plan

> **Status:** ✅ Complete — Phase 0 + Phase 1 (base), Phase 2 (Pro — identities, presets, batch/review), Phase 3 (base — marketplace output pipeline), Phase 4 (base — IPTC 2025.1 XMP provenance + best-effort C2PA), and Phase 5 (base video transform + Pro `fashion_*` assistant tools + Workflow Builder preset) implemented 2026-10-01 and validated on WP 6.9 + WP 7.1. Execution details in `media-studio-fashion-photography-implementation.md`.
> **Date:** 2026-10-01
> **Scope:** `addons/media-studio` (primary), `addons/pro` (supporting), `addons/media-worker` (optional sidecar)
> **Source research:** Claid.ai "7 best AI tools for fashion photography in 2026" (Sep 2026) + direct web research on all seven promoted platforms (Botika, Ayna, FASHN AI, MODA AI, On-Model by PiktID, Caimera, Claid), EU AI Act / C2PA provenance standards, Amazon marketplace image requirements, and VTON technical literature (IDM-VTON, CatVTON, TryOffDiff).

---

## 1. Executive Summary

The Media Studio addon is currently a Tier D client-side SPA (image editor, media player, audio waveform) with a single `manage_options`-gated health endpoint. This plan turns it into the visual front-end of an **AI fashion production pipeline** that mirrors what the industry leaders ship, while reusing the existing NV oOS infrastructure instead of building new provider clients:

- **Generation:** existing core tools (`generate_gemini_image`, `edit_gemini_image`, `generate_higgsfield_image` — already fashion-tuned, `analyze_image`, `remove_background`, `resize_image`, `product_actualization`).
- **Heavy compute:** the `addons/media-worker` sidecar (`/api/image/generate`, `/api/image/optimize`, Redis job queue).
- **Asset organization:** the Pro media template/preset system (`WP_MCP_AI_Media_Template_Presets`, `WP_MCP_AI_Media_Template_CPT`, `WP_MCP_AI_Image_Template_CPT`).
- **Async orchestration:** Action Scheduler (used by Pro Schedule Manager) and the Pro Workflow Builder.

The plan delivers, in phases: (1) a server-side AI bridge REST surface for the SPA, (2) a "Fashion Studio" SPA mode covering the industry-standard transform set (flatlay/ghost-mannequin → on-model, model swap, background generation, recolor, packshots, detail repair, virtual try-on), (3) reusable identities + presets + batch/review workflows, (4) marketplace-compliant output pipeline, and (5) provenance/compliance (C2PA/IPTC, AI-disclosure metadata) plus a Pro `fashion_*` tool set.

---

## 2. Research Synthesis — Industry Standards

### 2.1 The seven benchmark platforms (feature matrix)

| Capability | Claid | Botika | Ayna | FASHN AI | MODA AI | PiktID On-Model | Caimera |
|---|---|---|---|---|---|---|---|
| Flatlay → on-model | ✅ | ✅ | ✅ | ✅ (product-to-model) | ✅ | ✅ | ✅ |
| Ghost mannequin / mannequin → model | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (packshot out) | ✅ (image-to-ghost) |
| Model swap (keep garment) | ✅ | ✅ (pose+size preserved) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Face swap / custom faces | ✅ | ✅ | ✅ | ✅ (face-to-model) | ✅ (face ref) | ✅ (identities) | ✅ |
| Background generation | ✅ | ✅ | ✅ (500+ bgs) | ✅ (reframe/edit) | ✅ (bg ref) | ✅ | ✅ |
| Packshots (white-bg, ghost) | ✅ | — | ✅ (marketplace) | ✅ | ✅ | ✅ (3 packshot types) | ✅ |
| Garment recolor | ✅ (edit) | — | ✅ | ✅ (edit) | — | ✅ (consistent across job) | ✅ (texture intact) |
| Detail repair (logos/text) | ✅ | ✅ (retouch) | ✅ (stylist) | ✅ (edit) | — | ✅ | ✅ |
| Virtual try-on (on person) | ✅ | ✅ | ✅ | ✅ (Try-On Max) | ✅ | — | ✅ |
| Video (image→video) | ✅ | ✅ | ✅ | ✅ | ✅ | — | ✅ |
| Reusable model identities | ✅ | ✅ (custom models) | ✅ (1k models) | ✅ (model create) | ✅ | ✅ (shared identities) | ✅ (custom models) |
| Batch / catalog scale | ✅ API | ✅ | ✅ (100+ prod/hr) | ✅ API | ✅ (bulk sets) | ✅ (batch, 4K) | ✅ (batch) |
| Marketplace-ready variants | ✅ | — | ✅ (Myntra, Amazon, Macy's, Zalando) | — | ✅ (lookbooks, line sheets) | ✅ | ✅ |
| Review/retouch workflow | ✅ (managed) | ✅ (retouch rounds) | ✅ (stylist) | — | — | ✅ (managed review) | ✅ (post-processing) |
| Pricing model | credits, $9/mo+ | $55/mo | $13–14/mo | $19/mo + usage | $1.25/10-photo set | €17–89/mo | $15/user/mo |

### 2.2 Cross-cutting industry standards

1. **Separate PDP from campaign production.** PDP imagery optimizes *accuracy* (garment fidelity: color, fit, fabric, seams, prints, logos). Campaign imagery optimizes *creative range* (lifestyle scenes, editorial light, movement, accessories, locations, video). The plan encodes this as two distinct generation profiles.
2. **Content mix per SKU.** One SKU needs: PDP images, detail crops, marketplace-ready images, social variants, ad creatives, lookbook images, short video, regional/seasonal versions. This is the Pro "media collection" concept.
3. **Start from existing assets.** Input classes map to transforms: supplier photo/flatlay/ghost mannequin → on-model; existing model photo → model swap / face swap / background change / campaign variants; sketch → image (Caimera); model photo → ghost/packshot (virtual try-off, TryOffDiff-style).
4. **Production consistency checklist** (must gate batch output): accurate colors/materials; preserved logos, prints, seams, hardware; natural fit; consistent crops/framing; repeatable lighting/background; few artifacts (hands, hair, edges, fabric folds); layout match.
5. **Scale features** (table stakes for catalogs): batch processing, API access, saved presets, reusable models, review workflow, high-res export (4K), marketplace/PDP formats, transparent credit usage.
6. **Cost by finished asset** — include failed generations, retouching, upscaling, resizing, and review time. Pro tools must surface per-job cost/usage via the existing cost tracker.
7. **Fidelity tech** (open literature): IDM-VTON / CatVTON / StableVITON for garment-preserving try-on; TryOffDiff for virtual try-off (model → flatlay/ghost); LoRA/identity embeddings for consistent custom models; preprocessing matters (masking, resolution) — the provider layer must keep preprocessing server-side.
8. **Ethics & diversity:** explicit control over body type, age, skin tone; guardrails against stereotypes/unrealistic proportions; kids/plus-size categories only with explicit opt-in.
9. **Compliance:**
   - **EU AI Act Art. 50 transparency** (obligations apply Aug 2026): machine-readable provenance embedded in the file — **C2PA Content Credentials**; **IPTC Photo Metadata Standard 2025.1** adds four XMP fields for AI-generated/AI-assisted content. Every AI output should carry IPTC fields + a C2PA manifest when available, plus a visible-disclosure policy option.
   - **Amazon**: main image must be pure white **RGB 255,255,255**, ≥1000px longest side (1600px+ optimal, 2000–3000px recommended); no misleading edits. The output pipeline must validate/normalize white backgrounds and dimensions per marketplace profile.
   - **Returns risk**: unrealistic fit increases returns — surface "true-to-product" fidelity checks before publish.

---

## 3. Current State vs. Target (Gap Analysis)

### 3.1 `addons/media-studio` today

- React 19 SPA, three modes: `image-editor` (react-konva: crop/rotate/flip/brightness/contrast, PNG download), `media-player` (react-player), `audio-waveform` (wavesurfer.js). `src/App.tsx` dispatches by `config.mode`.
- Client-side only. `NV_oOS_Media_Studio_REST` exposes exactly one route: `/nvoos-media-studio/v1/health` (`manage_options`). No server-side media operations, no Media Library integration in code (README mentions it; the editor only downloads a PNG).
- Shortcode `nvoos_media_studio_app` (`toolkit`, `theme`, `height`, `mode`, `src`) + block `nvoos/media-studio`. `wp_localize_script` already injects `apiUrl`, `proApi` (`mcp-ai-pro/v1`), and a REST nonce — an unused seam we will use.
- Bundle ≈826KB gzip, all modes statically bundled. Version bump rule: three places (`Version:` header, `NVOOS_MEDIA_STUDIO_VERSION`, `package.json`).

### 3.2 Reusable infrastructure (build on, don't rebuild)

| Existing asset | Location | Reused for |
|---|---|---|
| Image-generation tools | `includes/tools/class-wp-mcp-ai-tool-generate-{gemini,openai,cloudflareai,higgsfield}-image.php`, `edit-{gemini,openai}-image.php` | All AI transforms; Higgsfield SOUL is explicitly fashion/editorial |
| Image analysis / OCR / alt text | `class-wp-mcp-ai-tool-analyze-image.php`, `extract_image_text`, `generate_image_alt_text(_validated)` | Quality gates, provenance, alt text |
| Bridge ops | `remove_background`, `resize_image`, `product_actualization`, `extract_video_frames` | Packshots, platform variants, scene placement |
| Media worker sidecar | `addons/media-worker/src/routes/image.js` (`/api/image/generate`, `/optimize`, `/providers`) + Redis job queue w/ processors | Heavy/batch processing; provider fallback (Replicate/Stable Diffusion/Midjourney/etc.) |
| Pro media templates/presets | `addons/pro/includes/class-wp-mcp-ai-media-template-presets.php` (social + e-commerce resize presets, seeded with version key), `-media-template-cpt.php`, `-media-collection-cpt.php`, `-image-template-cpt.php` | Fashion presets (lighting/background/model), lookbooks, per-SKU collections |
| Pro scheduling/workflows | `class-wp-mcp-ai-pro-schedule-manager.php`, workflow presets, Action Scheduler | Batch jobs, recurring catalog refreshes, review gates |
| Pro SPA infra | `class-wp-mcp-ai-pro-spa-{config,loader,shortcode}.php` | Hosting pattern reference; reuse config/localize conventions |
| Core cost/usage tracking | cost tracker, `includes/security/` | Per-job credit/cost reporting (industry asks for "clear credit usage") |

### 3.3 Gap summary

| # | Gap | Industry benchmark | Phase |
|---|---|---|---|
| G-01 | No server-side AI surface for the SPA (only `/health`) | API-first platforms (FASHN) | 0 |
| G-02 | No Media Library save/import in the editor | Every platform exports to production stack | 0 |
| G-03 | No fashion transforms (on-model, swap, bg, recolor, packshot, try-on) | All seven platforms | 1 |
| G-04 | No reusable model identities | Claid/Botika/PiktID/Caimera | 2 |
| G-05 | No preset system wired into Media Studio | PiktID preset extraction; Ayna 100+ templates | 2 |
| G-06 | No batch jobs / review queue | PiktID/Claid/Ayna | 2 |
| G-07 | No marketplace-compliant output (white-bg validation, dimensions) | Ayna marketplace variations; Amazon rules | 3 |
| G-08 | No AI provenance/disclosure (C2PA/IPTC) | EU AI Act Art. 50 | 4 |
| G-09 | No fashion video | Botika/MODA/FASHN/Claid/Caimera | 5 |
| G-10 | No `fashion_*` tools for assistants | API-first platforms | 5 |

---

## 4. Target Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│  Media Studio SPA (addons/media-studio/src)                        │
│   modes: image-editor · media-player · audio-waveform ·            │
│          fashion-studio (NEW)                                      │
│                                                                    │
│   fashion-studio                                                   │
│   ├── Input classification (flatlay / ghost / mannequin / model /  │
│   │    sketch / packshot)                                          │
│   ├── Transform panel: on-model · model-swap · face-swap ·         │
│   │    background · recolor · packshot · try-on · detail-repair    │
│   ├── Identity & preset pickers (from Pro REST)                    │
│   ├── Review grid (approve / reject / re-roll per variant)         │
│   └── Export: Media Library · WooCommerce gallery · download       │
└──────────────────────────────┬─────────────────────────────────────┘
                               │ X-WP-Nonce (existing localize)
┌──────────────────────────────▼─────────────────────────────────────┐
│  Media Studio REST (NEW in class-nvoos-media-studio-rest.php)      │
│  /nvoos-media-studio/v1/ai/*                                       │
│   POST  /ai/generate       (single transform)                      │
│   POST  /ai/jobs           (batch → Action Scheduler)              │
│   GET   /ai/jobs           · GET /ai/jobs/<id> · POST /ai/jobs/<id>│
│   GET   /ai/capabilities   (providers, tools, quotas, compliance)  │
│   POST  /ai/import         (attachment_id → editor canvas)         │
│   POST  /ai/export         (dataURL → Media Library attachment,    │
│                             meta: AI provenance + disclosure)      │
│                                                                    │
│   AI Transform Service (NEW: includes/ai/class-nvoos-media-studio- │
│   ai-service.php) — provider resolution + transform composition    │
└──────┬────────────────────────────┬─────────────────────┬──────────┘
       │ wp_mcp_ai tool classes      │ HTTP (X-Site-Token)  │ Pro REST
┌──────▼──────────────┐  ┌──────────▼───────────┐  ┌───────▼─────────┐
│ Core plugin tools    │  │ media-worker sidecar  │  │ Pro (addons/pro)│
│ gemini/openai/       │  │ /api/image/generate   │  │ identities CPT  │
│ higgsfield edit/     │  │ /api/image/optimize   │  │ fashion presets │
│ analyze/remove_bg/   │  │ Redis job queue       │  │ batch review    │
│ resize/product_act.  │  │ (fallback providers)  │  │ fashion_* tools │
└──────────────────────┘  └───────────────────────┘  └─────────────────┘
```

Design rules:

1. **The SPA never holds provider credentials.** All provider calls happen server-side via existing clients (WP_MCP_AI_Gemini_Client etc.) or the media-worker sidecar. Keys stay in the existing API key store.
2. **Media Studio ships no new provider SDK.** It composes the existing core tool layer + optional sidecar. If a site has no provider configured, `/ai/capabilities` reports degraded mode and the UI disables AI panels.
3. **Base vs Pro.** Base plugin (no Pro addon) gets: transforms (G-03), Media Library export, marketplace variants, provenance flags. Pro addon adds: identity library, fashion presets, batch review queue, WooCommerce gallery attachment, `fashion_*` tools.
4. **Async boundary.** Single images are synchronous (existing tool envelope with timeout discipline). Anything > N variants or marked `batch` becomes an Action Scheduler job with a pollable status endpoint; workers use the canonical envelope (`success` array or `WP_Error` — never `array('success'=>false)`).

---

## 5. Phase 0 — Server Bridge Foundation

**Goal:** give the SPA a typed, capability-gated AI surface and Media Library round-tripping.

### 5.1 REST surface (`addons/media-studio/includes/rest/class-nvoos-media-studio-rest.php`)

Extend `register_routes()` with (permission: `edit_posts` for read-only ops, `upload_files` for export/import; nonce required; rate limiting via existing request guard patterns):

| Route | Method | Purpose |
|---|---|---|
| `/ai/capabilities` | GET | Providers available, transforms supported, quotas, compliance settings, sidecar status |
| `/ai/generate` | POST | One transform on one attachment (synchronous) |
| `/ai/jobs` | POST | Create batch job (attachment IDs × transform × presets) |
| `/ai/jobs` | GET | List current user's jobs |
| `/ai/jobs/<id>` | GET | Job status + variant results (approve/reject state) |
| `/ai/jobs/<id>/review` | POST | Approve/reject variant; approve triggers export pipeline |
| `/ai/import` | POST | Register an attachment as editor source (returns proxied URL + meta) |
| `/ai/export` | POST | Persist canvas/dataURL output as attachment with provenance meta |
| `/ai/models` | GET | Identity library (Pro-gated; empty in base) |
| `/ai/presets` | GET | Fashion presets (Pro seeds; base ships core defaults) |

All args validated with `sanitize_*` at entry; every response value escaped/serialized via REST (no raw HTML). Follow the folder README convention — update `addons/media-studio/includes/rest/` docs and add tests in `addons/media-studio/tests/test-rest.php`.

### 5.2 AI Transform Service (`includes/ai/class-nvoos-media-studio-ai-service.php` — NEW)

- `get_capabilities()`: introspects configured provider clients (Gemini/OpenAI/Higgsfield/Cloudflare), the media-worker URL constant (`WP_MEDIA_WORKER_URL`), and active Pro features. Returns a typed capability map the SPA consumes.
- `execute_transform( $transform, $attachment_id, $args )`: routes each transform to the correct backend (see §6 matrix). Returns canonical envelope with attachment IDs/URLs of results.
- `import_attachment()` / `export_image()`: `media_sideload`-style helpers; export writes `_nvoos_ai_generated`, `_nvoos_ai_provider`, `_nvoos_ai_model`, prompt hash, and IPTC/XMP flags (Phase 4 adds C2PA).
- Guard: reuse core request/concurrency guards; log via audit logger.

### 5.3 SPA plumbing

- `src/components/ImageEditor.tsx`: add "Save to Media Library" button calling `/ai/export` (uses the already-localized `apiUrl` + nonce). Add "Load from Library" via `wp.media` if `wp` global present, else the REST import route.
- `src/index.tsx` bootstrap already reads `apiUrl`/`nonce` — extend the interface with `capabilities`.
- Version bump (3 places) per the addon README rule.

**Acceptance:** PHPUnit tests for all new routes (permission matrix + happy path + envelope on error); vitest for SPA buttons; `npm run build` clean; PHPCS (`composer run lint`) clean.

---

## 6. Phase 1 — Fashion Transforms (base plugin)

### 6.1 Transform catalog & routing

| Transform | Inputs | Backend | Notes |
|---|---|---|---|
| `on-model` | flatlay / ghost / mannequin / supplier photo | Gemini `generate_image` with reference image + garment-lock prompt; Higgsfield SOUL for editorial; sidecar Replicate (IDM-VTON-class) when configured | Core PDP path. Prompt template pins garment fidelity: colors, seams, prints, hardware |
| `model-swap` | existing on-model photo | Gemini `edit_image` (inpaint person region) + face/identity ref | Preserve pose & garment (Botika-style) |
| `face-swap` | on-model photo + face ref | sidecar provider (faceswap) → fallback Gemini edit with face guidance | Requires consent metadata on face asset |
| `background` | any product/on-model photo | Gemini edit (bg replacement) or `product_actualization` for pure product scene placement | Style presets: studio / lifestyle / gradient / custom |
| `recolor` | garment photo + target color/pattern | Gemini edit with color-lock prompt | Consistency: same target color across a job (PiktID rule) |
| `packshot` | model photo or flatlay | `remove_background` + `resize_image` + white-bg normalization (server-side pixel check to 255,255,255) | Virtual try-off when input is a model photo (sidecar when available) |
| `detail-repair` | product photo + mask | Gemini edit (inpaint logos/text) | |
| `try-on` | garment photo + person/model photo | sidecar VTON provider preferred; Gemini multi-image edit fallback | Virtual fitting-room use case |

**Prompt discipline:** fixed template parts (lighting, background, style, composition) identical per preset; only the garment descriptor varies (mirrors `.agents/skills/design-product-photography` AI-consistency rule). Templates live in PHP constants in the AI service so base and Pro share them.

### 6.2 SPA `fashion-studio` mode

- New `src/components/FashionStudio.tsx` + `src/hooks/useAiTransform.ts`; register `'fashion-studio'` in `App.tsx` `ALLOWED_MODES` and shortcode allowlist.
- UI: left rail input image + auto class suggestion (flatlay/ghost/model/sketch); center transform picker with per-transform options (target model/identity, background preset, aspect ratio, count 1–4, seed); right rail result grid with before/after and re-roll.
- Accessibility: toolbar patterns, `aria-live` status on generation, keyboard reachable (consistent with existing modes).
- Bundle discipline: lazy-load `FashionStudio` via dynamic `import()` so image-editor-only pages don't pay for it (addresses the README's 826KB note).

### 6.3 WooCommerce touchpoint (base-safe)

`/ai/capabilities` reports `wc_active`; if active, the export dialog offers "Attach to product gallery" (`_product_image_gallery`), guarded by `edit_products`.

**Acceptance:** each transform covered by PHPUnit (mocked provider clients → envelope assertions) + one SPA integration test per panel; manual smoke against a sandboxed site with a test Gemini key.

---

## 7. Phase 2 — Identities, Presets, Batch & Review (Pro)

### 7.1 AI Model Identity library

- New CPT `mcp_ai_fashion_model` (Pro): `title` (name), featured image (face/full-body reference), meta: `_fashion_model_gender`, `_fashion_model_skin_tone`, `_fashion_model_body_type`, `_fashion_model_age_group`, `_fashion_model_height`, `_fashion_model_consent_status`, `_fashion_model_prompt_embed` (descriptor block), `_fashion_model_is_custom` (trained identity vs. prompt-only).
- Seed library: a starter set of prompt-only identities across demographics (mirrors `WP_MCP_AI_Media_Template_Presets::seed_presets()` versioned seeding pattern with its own option key, e.g. `wp_mcp_ai_fashion_models_seeded`).
- Consent gate: identity cannot be used for face-swap/try-on until `_fashion_model_consent_status = granted`; audit-log usage per job.
- Diversity controls explicit (Claid FAQ standard): body type, skin tone, age group selectable; plus-size and kids categories require opt-in.

### 7.2 Fashion presets

Extend `addons/pro/includes/class-wp-mcp-ai-media-template-presets.php`:

- New category `fashion` in `get_presets()` + `seed_categories()` (bump `PRESET_VERSION` and seeding key).
- Preset shape: `{ operation: 'fashion_generate', parameters: { transform, lighting, background, style_tokens, composition, aspect_ratio, fidelity_level } }` alongside the existing `resize_graphic` presets.
- Ship presets for: PDP-white, PDP-lifestyle, editorial, lookbook, social-variant (reuse the existing social resize presets as the *output* stage of a two-stage preset chain: generate → resize).
- Reuse `WP_MCP_AI_Image_Template_CPT` for user-created templates.

### 7.3 Batch jobs & review queue

- Job store: Action Scheduler (single + recurring) with an indexed job table mirror for status (`pending/processing/review/completed/failed`, variant list, approve/reject state). Groups: `nvoos_media_studio_batch`.
- Endpoints: `POST /ai/jobs` (attachment IDs × transform × preset × count), `GET /ai/jobs`, `GET /ai/jobs/<id>`, `POST /ai/jobs/<id>/review`.
- Review UX: SPA review grid (approve → export pipeline runs: white-bg check, resize variants, WebP, alt text via `generate_image_alt_text_validated`, WooCommerce attach if enabled; reject → re-roll with same seed rules).
- Per-job cost surfaced from the core cost tracker + provider usage (industry "clear credit usage" requirement).

### 7.4 Media collections

Use existing `mcp_ai_media_collection` CPT to group per-SKU outputs (PDP set, social set, lookbook) and link back to the WooCommerce product ID.

**Acceptance:** Pro PHPUnit coverage for CPT registration/seeding, preset seeding idempotency, job lifecycle incl. failure paths, review permission matrix; JS tests for review grid.

---

## 8. Phase 3 — Marketplace-Compliant Output Pipeline

A shared `NV_oOS_Media_Studio_Output_Pipeline` (base) consumed by SPA export and batch review:

1. **White-background validation** — server-side pixel sampling of edges; if not 255,255,255 within tolerance, either auto-normalize (levels clamp) or flag `needs_review` (Amazon main-image rule).
2. **Dimension profiles** — `amazon` (1600px+ recommended, square), `woocommerce` (≥800px), `social` (existing Pro resize presets), `web` (2000px, from the project's product-photography spec).
3. **Format/optimization** — WebP for web/social, JPEG for Amazon (some marketplaces reject WebP); route through media-worker `/api/image/optimize` when present, else GD/Imagick.
4. **Naming** — `brand-product-view-<variant>.webp` convention (project standard), never `IMG_xxxx`.
5. **Alt text** — auto via `generate_image_alt_text_validated` on export (opt-out per job).

**Acceptance:** unit tests on the validator (tolerance math), profile mapping, and naming.

---

## 9. Phase 4 — Provenance, Disclosure & Compliance (base)

1. **Attachment meta on every AI output:** `_nvoos_ai_generated` (1), `_nvoos_ai_provider`, `_nvoos_ai_model`, `_nvoos_ai_prompt_hash`, `_nvoos_ai_job_id`, `_nvoos_ai_identity_id`.
2. **IPTC 2025.1 XMP fields** written at export when the sidecar is present (or via a small PHP XMP writer): `Iptc4xmpExt:DigitalSourceType` (e.g. `trainedAlgorithmicMedia`), plus `CreditLine`/`Source` describing generation (EU AI Act Art. 50 machine-readable transparency).
3. **C2PA manifest** — best-effort via media-worker (Phase 4.5); without a signing key, files carry IPTC fields + the visible-disclosure path below. Documented as a known limitation.
4. **Visible disclosure policy** — site setting (`nvoos_media_studio_settings` → `ai_disclosure`): `none|metadata|watermark|block-until-ack`; SPA review grid shows a compliance chip on every variant (provider + model + identity consent status).
5. **Guardrails** — transform layer refuses face-swap/try-on on identities without consent; kids/plus-size categories opt-in; prompt injection hardening on user-supplied text (sanitize, length-cap, strip instructions).
6. **Audit** — every generate/export writes to the core audit logger (provider, job, user, cost).

**Acceptance:** tests asserting meta/XMP writes, consent gate, and settings behavior.

---

## 10. Phase 5 — Video & Assistant Tooling (Pro, optional)

### 10.1 Fashion video (G-09)

- Media-worker `/api/video/generate` image-to-video (existing route group) driven from an approved still: `video` transform in the SPA, 5–15s clips, model/garment continuity note in prompt.
- Reuse `extract_video_frames` bridge for thumbnail choice; `media-player` mode for preview.

### 10.2 `fashion_*` tools for assistants (G-10)

Registered in Pro (`addons/pro/includes/tools/fashion/`, wired via the main `$pro_tools` map in `wp_mcp_ai_pro_register_tools()` — each tool self-gates with a static `is_available()` on the Media Studio addon, so the registration loop marks them unavailable when the addon is inactive):

| Tool slug | Wraps | Notes |
|---|---|---|
| `fashion_onmodel_generate` | AI service `on-model` | attachment_id + identity/preset args |
| `fashion_model_swap` | `model-swap` | |
| `fashion_background_generate` | `background` | |
| `fashion_recolor` | `recolor` | consistent color across job |
| `fashion_packshot` | `packshot` | returns white-bg checked assets |
| `fashion_virtual_tryon` | `try-on` | consent-gated |
| `fashion_batch_job` | batch service | create/status/review one tool |
| `fashion_identity_manage` | identity CPT CRUD | consent flags mandatory |

All obey the tool authoring rules: canonical envelope (success array or `WP_Error`), two-gate sanitization (entry sanitize + exit escape), `get_usage_guidance` with `when_to_use` / `related_tools`, capability declarations, and base-version gating (`WP_MCP_AI_BASE_VERSION`).

### 10.3 Workflow Builder integration

Expose each transform as a workflow node input type so the existing Pro Workflow Builder can chain generate → validate → resize → attach-to-product → schedule (Pro Schedule Manager), mirroring the platform "API workflow" pitch.

---

## 11. File-Level Change Plan

### 11.1 `addons/media-studio` (base)

| File | Change |
|---|---|
| `nvoos-media-studio.php` | Version bump per release; require new `includes/ai/*` files |
| `includes/rest/class-nvoos-media-studio-rest.php` | Add `/ai/*` routes; keep `/health` |
| `includes/ai/class-nvoos-media-studio-ai-service.php` | NEW — capability introspection, transform routing, prompt templates, import/export helpers |
| `includes/ai/class-nvoos-media-studio-output-pipeline.php` | NEW — white-bg validator, profiles, WebP/JPEG, naming, alt text |
| `includes/ai/class-nvoos-media-studio-provenance.php` | NEW — meta keys, XMP/IPTC writer, disclosure policy, consent gate |
| `includes/ai/class-nvoos-media-studio-job-store.php` | NEW — Action Scheduler batch jobs + status store (works without Pro) |
| `includes/shortcode/class-nvoos-media-studio-shortcode.php` | Allow `fashion-studio`; localize capabilities |
| `includes/block/block.json` | Add mode option |
| `src/App.tsx` | Add `fashion-studio` mode + lazy `import('./components/FashionStudio')` |
| `src/components/FashionStudio.tsx` | NEW — SPA mode |
| `src/components/ImageEditor.tsx` | Save-to-library, load-from-library |
| `src/hooks/useAiTransform.ts`, `src/hooks/useAiJobs.ts` | NEW |
| `src/index.tsx` | Extend bootstrap interface (capabilities) |
| `tests/test-rest.php`, `tests/test-ai-service.php` (new) | Route + service tests |
| `src/__tests__/*` | SPA tests |
| `README.md`, `THIRD_PARTY_NOTICES.md` | Docs; credits (no new deps expected in base) |

### 11.2 `addons/pro` (Pro-gated features)

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-fashion-model-cpt.php` | NEW — identity CPT + seeds + consent meta |
| `includes/class-wp-mcp-ai-media-template-presets.php` | Add `fashion` presets + category; bump `PRESET_VERSION` |
| `includes/tools/fashion/*.php` | NEW — 8 tools per §10.2 |
| `includes/fashion-toolkit-init.php` | NEW — registration + toolkit gating |
| `includes/rest/` (Pro REST surface) | Identity/preset/job review endpoints (or bridge through Media Studio `/ai/*` with capability gate) |
| `class-wp-mcp-ai-pro-spa-config.php` | Extend config for fashion studio (if hosted under Pro SPA pattern) |
| Pro tests | CPT, presets, tools |

### 11.3 `addons/media-worker` (optional, no breaking changes)

| File | Change |
|---|---|
| `src/routes/image.js` | Add optional `transform` parameter passthrough for sidecar-native VTON/faceswap providers when API keys configured; extend `/api/image/providers` with capability flags |
| `README.md` | Document fashion endpoints + env keys |

---

## 12. Testing & Validation Strategy

1. **Unit (PHPUnit):** every new REST route (permissions, nonce, arg validation, envelope shape on failure); AI service routing matrix with mocked provider clients (no network); provenance writer; output pipeline validator; job store lifecycle (Action Scheduler mocks); Pro identity CPT + preset seeding idempotency; `fashion_*` tools.
2. **JS (vitest):** FashionStudio panels, export/save buttons, review grid, error/loading states; a11y lint (`npm run lint:a11y`).
3. **Lint/CI:** `composer run lint` (WPCS incl. custom tool sniffs for Pro tools), `composer run lint:compat` (PHP 7.4 floor), `npm run lint:js`, `npm run build`.
4. **Integration smoke:** sandbox site — flatlay → on-model → packshot → Amazon-profile export; verify white-bg pixel values; verify meta/XMP; verify batch job end-to-end via cron runner.
5. **Consistency gate test:** batch of 10 garments across fabrics/prints with a fixed preset; assert style tokens unchanged and only garment descriptor varies (industry consistency checklist).

---

## 13. Risks & Open Questions

| # | Risk / Question | Mitigation / Decision needed |
|---|---|---|
| R-1 | Gemini/OpenAI flatlay→on-model fidelity is prompt-bound, not a trained VTON | Route through sidecar VTON (IDM-VTON-class via Replicate) when configured; fidelity_level param; document provider limits in capabilities |
| R-2 | Cost runaway on batch jobs | Per-job cost estimate in `/ai/capabilities` + hard caps; cost tracker integration; job-level quota |
| R-3 | Deepfake-adjacent face-swap liability | Consent-gated identities only; audit log; disclosure policy default `metadata+watermark` for face outputs; legal sign-off needed on default |
| R-4 | C2PA signing requires a key/cert infra we don't control | Phase 4 ships IPTC/XMP + visible disclosure; C2PA manifest best-effort behind an optional key setting |
| R-5 | Bundle growth violates the Tier D lazy-load principle | Lazy-load FashionStudio + keep transform logic server-side; re-measure bundle per mode |
| R-6 | Base vs Pro split drift | Keep transform execution + output pipeline + provenance in base; Pro adds only identities/presets/tools/review; document in addon READMEs |
| R-7 | Sidecar unavailable on managed hosts (Velocity, no Docker) | Every transform has a core-provider fallback path; capabilities report which path is active |

**Decisions (resolved with the maintainer, 2026-10-01):**

| # | Decision | Effective default |
|---|---|---|
| D-1 | Disclosure policy defaults to `metadata` (always on, never downgradable below metadata); `watermark` is **auto-forced for face-swap (and try-on with identity) outputs** per EU AI Act Art. 50 deepfake clause. Non-face watermarking stays opt-in per export profile. A one-time `require_ack` gate applies to face-swap/try-on (consent awareness, stored per user). | `metadata` + forced watermark on face outputs + ack for face transforms |
| D-2 | `fashion-studio` ships as a **new mode** of the existing `nvoos_media_studio_app` shortcode (lazy-loaded chunk). A thin alias shortcode `nvoos_fashion_studio` may be added later for discoverability — not in v1. | new mode |
| D-3 | Batch cost tripwires: unknown/unestimable pricing → review required; per-image estimate > **$0.25** → variant requires review; per-job estimate > **$10** → job requires approval; hard cap **$100/job** → rejected. Consent-driven transforms (face-swap, try-on) always require review regardless of cost. All ceilings are site settings. | $0.25 / $10 / $100 |

---

## 14. Rollout Order & Backwards Compatibility

1. Phase 0 + Phase 1 (base): additive routes, new mode, no changes to existing modes' behavior. Existing shortcodes/blocks untouched.
2. Phase 2–3 (Pro): additive CPTs/presets/tools; presets versioning follows the existing seeded-version key pattern so upgrades are idempotent.
3. Phase 4: new attachment meta only on new exports; old media unaffected. Settings default to least-surprising (metadata disclosure on, watermark off).
4. Phase 5: optional, gated by sidecar + Pro.

Each phase lands as its own PR against the normal flow, with the three-place version bump rule applied for every SPA-affecting change.
