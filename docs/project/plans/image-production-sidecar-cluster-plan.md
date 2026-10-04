# Image-Production Sidecar Cluster — Real Implementations for the Placeholder AI Tools

> Status: **Planned** · Issue: (filed with this plan) · Owner: toolkit-audit / media-worker tracks
> Created: 2026-10-04 · Last reviewed: 2026-10-04

## Context

The image-production toolkit audit (PR #6876) found four tools whose bodies are
placeholder no-ops that **claim success** without processing anything:

| Tool | Current behavior | Capability gap |
|---|---|---|
| `enhance_image_quality` | Re-saves the source unchanged; sharpen/color/contrast/denoise helpers are empty | Contrast/saturation/denoise; sharpen is a boolean no-op |
| `upscale_image_ai` | Standard WP_Image_Editor resize (2x/4x/8x), branded as "AI super-resolution" | Real high-quality upscaling; optional AI super-resolution |
| `colorize_image` | Saves the image as-is, stamps `_wp_mcp_ai_colorized` | Grayscale→color (no Sharp equivalent — needs an AI model) |
| `apply_artistic_style` | Saves the image as-is, stamps `_wp_mcp_ai_artistic_style` | Neural style transfer (needs an AI model) |

This cluster routes all four through the **media worker sidecar**
(`addons/media-worker/`) for real implementations, keeping the local fallback
path working (worker architecture rule #1: routing is additive).

## Verified current state

- Worker image routes today (`addons/media-worker/src/routes/image.js`):
  `GET /api/image/providers`, `POST /api/image/generate` (11 text-to-image
  providers), `POST /api/image/optimize`, `POST /api/image/batch`,
  `POST /api/image/vectorize`. **No image-to-image routes exist.**
- Local Sharp script `addons/pro/bin/sharp-process.js` already implements
  `operation: enhance` (`.sharpen()`, `.blur()`) — but only those two, and the
  sidecar `/api/image/optimize` route rejects `enhance`/`rotate`/height-only
  resize (the tool's `optimize_via_sidecar()` surfaces that honestly today).
- `optimize_image_sharp` already demonstrates the canonical dual path:
  local Sharp subprocess (`WP_MCP_AI_NodeJS_Subprocess` trait →
  `WP_MCP_AI_Process_Service`) with the sidecar as fallback
  (`WP_MCP_AI_Media_Worker_Client` trait), and a honest
  `wp_mcp_ai_sharp_unavailable` error when neither is present.
- Harmonization tools already call provider image **edit** APIs from PHP
  (`WP_MCP_AI_Gemini_Client::edit_image`, `WP_MCP_AI_OpenAI_Client::edit_image`)
  — the established provider-edit pattern.
- Worker route conventions: multer `upload.single('file')` + file-size limits,
  `X-Site-Token` auth middleware, JSON envelope `{ success, original_size,
  optimized_size, …, b64 }`; tests are Jest files next to routes
  (`routes/*.test.js`).
- Proposals run to `docs/project/proposals/054+`; this cluster needs no
  proposal — it is a plan-level feature cluster in `docs/project/plans/`.

## Design rules

1. **Additive routing.** New worker routes never remove the local Sharp
   subprocess path; the no-backend degrade is an honest `WP_Error`
   (`wp_mcp_ai_sharp_unavailable` / `wp_mcp_ai_not_implemented`), never a fake
   success envelope.
2. **Honest results.** Response fields state what actually ran
   (`upscale_method: 'lanczos3'` until an AI model exists; `enhancements`
   echo the operations applied). `_wp_mcp_ai_colorized` /
   `_wp_mcp_ai_artistic_style` meta is written **only** when real processing
   happened.
3. **Worker contract mirrors `optimize`.** New routes follow the existing
   envelope (`success`, `original_size`, `optimized_size`, `savings_percent`,
   `width`/`height`, `b64`) so the plugin client needs no new parsing paths.
4. **Security baseline unchanged.** Auth, upload limits, temp-file hygiene,
   and the SSRF guard (N/A for uploads, but required for any provider fetch)
   follow the existing route conventions.
5. **Mirror discipline.** Every tool change lands byte-identically in
   `plugins/nvoos-content-graph-pro/src/tools/image-production/` (own text
   domain) per the ecosystem-port rules. Worker code lives only in
   `addons/media-worker/` (one-way mirrored repo).

## Waves

### Wave 1 — Sharp-native real implementations (enhance + upscale)

Fully buildable with Sharp on both the worker and the local subprocess.

**W1a. Worker: new `POST /api/image/enhance` + `POST /api/image/upscale` routes**

- `/api/image/enhance` — fields: `sharpen` (bool or 0–1 strength),
  `contrast` (multiplier, default 1), `saturation` (multiplier, default 1),
  `denoise` (bool → `.median(1)`), `strength` (0–1 master). Sharp ops:
  `.linear()`, `.modulate({ saturation })`, `.sharpen()`, `.median()`.
- `/api/image/upscale` — fields: `factor` (2/4/8; worker caps at 8 and the
  available memory), `kernel` (`lanczos3` default; `nearest`/`cubic` allowed).
  `.resize({ width: w*factor, height: h*factor, kernel })` with
  `withoutEnlargement: false`.
- Envelope identical to `/api/image/optimize` (+ `width`/`height` echo).
- Jest coverage in `routes/image.test.js` (new — follows `workflow.test.js`
  style): happy paths per operation, invalid-factor rejection, oversize
  rejection, auth failure.

**W1b. Local: extend `bin/sharp-process.js`**

- `enhance` operation gains `contrast`, `saturation`, `denoise` params and
  numeric `sharpen` strength (today: boolean `.sharpen()`).
- New `upscale` operation: `factor` + `kernel` (lanczos3 default).
- Keep `optimize`/`resize`/`convert`/`rotate` untouched (byte-compatible).

**W1c. Tools: `enhance_image_quality` + `upscale_image_ai` rewrite**

- Both adopt the `optimize_image_sharp` dual-path pattern: local Sharp
  subprocess when available → `WP_MCP_AI_Media_Worker_Client::sidecar_upload`
  to the new route → `wp_mcp_ai_sharp_unavailable` WP_Error.
- `enhance_image_quality`: map the existing `enhancements` enum
  (`sharpness`/`color`/`contrast`/`denoise`/`auto`) + `strength` onto the
  worker fields; drop the four empty PHP helper methods; response includes
  the applied-operation echo. The existing `use_remote` argument is honored
  as a sidecar preference (default false = local first, unchanged semantics).
- `upscale_image_ai`: `scale_factor` → worker `factor`; response gains
  `upscale_method: 'lanczos3'` (honest branding until a super-resolution
  model lands in Wave 2); the WP_Image_Editor resize stays as the
  no-Node/no-worker final fallback (documented standard-scaling degrade, and
  only then — not silently branded AI).
- Mirrors + characterization tests in both trees (mock `pre_http_request`
  for the sidecar JSON contract; subprocess path via the existing
  availability probes).

**W1d. Sidecar route contract note**

`optimize_image_sharp::optimize_via_sidecar()` currently errors for
`enhance`/`rotate`/height-resize on the worker — after W1a it re-routes
`enhance`/`rotate` to `/api/image/enhance` where sensible (or documents the
split). Small, additive.

### Wave 2 — AI image-to-image (colorize + style)

Needs provider models — no Sharp equivalent exists.

**W2a. Worker: new `POST /api/image/edit` route**

- Fields: `operation` (`colorize` | `style_transfer`), `style` (preset slug
  for style_transfer), `prompt` (optional override), `provider` (auto
  default).
- Provider resolution reuses the existing per-site → shared provider-key
  chain (`.context/media-worker.md` rule 3):
  - `colorize` → Gemini image **edit** API (`gemini-3.1-flash-image` with a
    "colorize this black-and-white photo, preserve realism" instruction) or
    OpenAI gpt-image edit; Replicate `deoldify` as the shared-pool fallback.
  - `style_transfer` → preset → prompt/model map (`van_gogh`, `picasso`,
    `monet`, `kandinsky`, `ukiyo-e`, `pop_art`, `watercolor`,
    `oil_painting`, `sketch`) targeting Gemini/OpenAI edit first, Replicate
    style-transfer models as the deterministic shared-pool option.
- `503 capability_unavailable` when no provider key resolves (existing
  contract).
- Jest coverage: operation validation, no-key degradation, mocked provider
  responses (mock the provider HTTP layer, same as `generateWithProvider`
  tests).

**W2b. Tools: `colorize_image` + `apply_artistic_style` rewrite**

- Route: sidecar `/api/image/edit` when available → PHP provider clients
  (`WP_MCP_AI_Gemini_Client::edit_image` / OpenAI) as the local fallback
  (harmonization `ai_edit_image` precedent, shared helper promoted) →
  `wp_mcp_ai_not_implemented`/`no_provider` honest errors when nothing is
  configured.
- `colorize_image`: `color_mode` (`auto`/`vibrant`/`subtle`) maps to prompt
  modifiers; `_wp_mcp_ai_colorized` meta only on success.
- `apply_artistic_style`: `style` enum validated against the shared
  preset→prompt map (single source of truth mirrored to both trees);
  `style_image` (reference image) supported on the provider-edit paths that
  accept image inputs; `_wp_mcp_ai_artistic_style` meta only on success.
- Mirrors + characterization tests in both trees.

### Wave 3 — optional: AI super-resolution for upscale

- Replicate `real-esrgan` on `/api/image/upscale` via an `engine: 'ai'`
  field once W2a's provider plumbing exists; `upscale_method` flips to the
  model id. Deferred — not required for honesty (lanczos3 is a real,
  high-quality upscale).

## Out of scope

- GD/Imagick local implementations of enhance ops (WP_Image_Editor does not
  expose `imagefilter`; Sharp is the canonical engine — consistent with
  `optimize_image_sharp`).
- Comic tools that simulate results (`colorize_comic_panel`,
  `upscale_comic_page`, etc.) — separate toolkit (comic-creation).
- The guidance-sweep drift (#6741) — separate port-debt track.

## Sequencing & PR slices

Each slice is a reviewable PR against `alpha-working` (fresh branch per
slice, per the ecosystem-port discipline):

1. **#1 W1a+b**: worker routes + Jest tests + `sharp-process.js` ops.
2. **#2 W1c**: `enhance_image_quality` rewrite + mirror + tests.
3. **#3 W1c**: `upscale_image_ai` rewrite + mirror + tests (+ W1d sidecar
   re-route note).
4. **#4 W2a**: worker `/api/image/edit` + Jest tests.
5. **#5 W2b**: `colorize_image` + `apply_artistic_style` rewrite + mirrors
   + tests.

## Docs & follow-ups

- `.context/media-worker.md` — route table + version bump (v3.4.0) with W1a.
- `docs/tool-reference.md` — tool descriptions updated when the tools stop
  being placeholders.
- Toolkit-audit skill: image-production case-study note updated to point at
  this plan.
- The `#6741` guidance-port slice for image-production can ride along any
  slice that touches the mirror files (optional).

## Open questions for the first slice

1. Worker route names: dedicated `/api/image/enhance` + `/api/image/upscale`
   (this plan's default — clean contracts) vs extending `/api/image/optimize`
   with operations. Recommend dedicated routes.
2. `enhance_image_quality`'s `use_remote` default stays false (local-first)
   — confirm no site depends on the current no-op being "instant success".
3. Style preset map: ship the 9 existing enum presets only, or extend?
