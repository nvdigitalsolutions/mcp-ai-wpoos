# Proposal 042 — Higgsfield Provider: Video & Image Generation Toolkit Integration

**Status:** ✅ Implemented — awaiting review & merge (branch `feat/higgsfield-provider-integration`)
**Date:** 2026-09-25
**Scope:** Base plugin (`includes/`, `lib/core`, `lib/wordpress-adapter`) + docs + tests
**Related:** [`041-mcp-apps-remote-sites-connection-type.md`](./041-mcp-apps-remote-sites-connection-type.md); Higgsfield API docs: <https://docs.higgsfield.ai>

## Summary

Higgsfield (<https://higgsfield.ai>) is an AI-native creative suite whose API
exposes a curated catalog of **22 video model families (66 workflows)** and
**13 image model families (16 workflows)** behind one authenticated,
asynchronous endpoint per workflow. This proposal integrates Higgsfield as a
first-class generative-media provider in NV oOS: a shared API client, one
video generation tool with unified model routing across five verified models,
an image generation tool, and first-class request-status / cancellation tools.

The implementation follows the industry-standard **submit-and-poll lifecycle**
(fal.ai, Replicate, OpenRouter) and the plugin's existing conventions
(canonical envelope, two-gate sanitisation, usage-guidance interface, coverage
manifests, base-plugin PHP 7.4 compatibility).

## Motivation / Gap Analysis

Before this work, the video generation toolkit covered OpenAI (Sora), Google
(Veo / Omni), and FFmpeg-based processing (Pro toolkit). Gaps addressed here:

1. **No catalog-provider routing.** Higgsfield's differentiator is model
   breadth — switching models is a one-identifier change on their side. The
   plugin had no unified "pick a model, one request shape" surface.
2. **Incomplete async lifecycle.** Sync generations that timed out returned a
   provider `request_id` with no tool able to poll it, and queued work could
   not be canceled.
3. **No image workflow.** The same two-part credential unlocks the SOUL image
   family; no tool used it.
4. **Scattered provider logic.** Credential resolution, polling, and download
   logic needed a shared home instead of per-tool duplication.

## Research — Provider API (verified against docs.higgsfield.ai)

### Authentication

Two-part credential: `Authorization: Key {KEY_ID}:{KEY_SECRET}`. Credentials
resolve from NV oOS settings (`higgsfield_api_key_id` /
`higgsfield_api_key_secret`), then env vars / constants
(`HIGGSFIELD_API_KEY_ID` / `HIGGSFIELD_API_KEY_SECRET`, or a combined
`HIGGSFIELD_API_KEY` in `ID:SECRET` form). Keys must stay server-side.

### Lifecycle (shared by every workflow)

1. `POST {model endpoint}` → `{ status: "queued", request_id, status_url, cancel_url }`
2. `GET /requests/{request_id}/status` → `status ∈ queued | in_progress |
   nsfw | failed | completed | canceled`, optional `error`, outputs in
   `video` / `images[]` / `audio` / `audios[]` (`{ url }` shapes)
3. `POST /requests/{request_id}/cancel` → `202` canceled (no body),
   `400` already started, `401` bad credentials, `404` not found
4. Outputs are retained ≥ 7 days → integrations must download immediately.

### Verified video catalog (five models, endpoints + schemas from official docs)

| Model ID | Text-to-video endpoint | Reference-to-video endpoint | Duration | Resolutions | Aspects | Notes |
|---|---|---|---|---|---|---|
| `cinema-studio-4.0` | `/higgsfield/cinema-studio/4.0` | same endpoint (auto) | 4–30s | 480p/720p | 16:9, 4:3, 1:1, 3:4, 9:16, 21:9 | camera/genre/era controls; refs 30 img / 10 vid / 10 aud |
| `seedance-2.5` | `/bytedance/seedance-2.5/text-to-video` | `/bytedance/seedance-2.5/reference-to-video` | 4–30s | 480p/720p | 16:9, 4:3, 1:1, 3:4, 9:16, 21:9 | refs 30/10/10 |
| `seedance-2.0` | `/bytedance/seedance-2.0/text-to-video` | `/bytedance/seedance-2.0/reference-to-video` | 4–15s | 480p/720p/1080p/4k | 16:9, 4:3, 1:1, 3:4, 9:16, 21:9 | refs 9/3/3 |
| `wan-3.0` | `/alibaba/wan-3.0/text-to-video` | `/alibaba/wan-3.0/reference-to-video` | 2–30s | 480p/720p/1080p | + `adaptive` | seed 0–2147483647; deep thinking; refs 10/5/5 |
| `kling-3.0` | `/kling-video/v3.0/std/text-to-video` | n/a (separate i2v endpoint — not wired) | 3–15s | n/a (no field) | 16:9, 9:16, 1:1 | audio = `sound: on/off`; multi-shot prompts; no URL refs on this endpoint |

### Verified image catalog (two workflows)

| Model ID | Endpoint | Notes |
|---|---|---|
| `soul-2` | `/higgsfield-ai/soul/v2/standard` | portraits/fashion/editorial; optional `style_id` (UUID); batch 1|4 |
| `soul-cinema` | `/higgsfield-ai/soul/cinema` | cinema-inspired stills; fixed style; batch 1|4 |

Shared image params: `aspect_ratio` (9:16, 16:9, 4:3, 3:4, 1:1, 2:3, 3:2,
default 1:1), `resolution` (720p/1080p), `enhance_prompt` (default false),
`seed` (1–1,000,000), `batch_size` (1|4).

### Billing

Prepaid USD balance, pay-as-you-go. Video billed per second of output; images
billed flat per image (SOUL workflows ≈ $0.0032/image). Failed and canceled
requests are not billed. No subscription.

## Industry Standards Applied

1. **Submit-and-poll, never block.** Every generation is asynchronous on the
   provider side; the tool returns immediately (async executor) or polls with
   backoff in background context.
2. **Polling discipline** (Higgsfield guidance): initial delay 2s, ×1.5 growth
   to a 10s cap, 0–1s jitter, stop at terminal states, app-level deadline.
   Permanent errors (400/401/404) abort; transient (5xx/network) retry.
3. **First-class queue control.** Status and cancel are dedicated tools, not
   optional parameters — matching fal.ai's "full control over the queue".
4. **Unified model routing.** One canonical parameter set (prompt, duration,
   resolution, aspect ratio, audio, references) mapped to each model's native
   endpoint and schema, with per-model clamping — the "canonical parameter
   set → backend mapping" pattern from fal.ai / Replicate.
5. **Immediate download to own storage.** Provider retention is ~7 days;
   outputs are persisted to the Media Library at generation time.
6. **Server-side credentials.** Key ID + secret never reach the browser; a
   credential-resolver chain (settings → env → constants) mirrors the
   existing provider pattern.

## Architecture

### `WP_MCP_AI_Higgsfield_Client` (`includes/class-wp-mcp-ai-higgsfield-client.php`)

Single shared lifecycle client used by every Higgsfield tool:

- `get_credentials()` / `get_auth_headers()` — resolution chain + `Key ID:SECRET` header
- `submit_request( $endpoint_path, $payload )` — parses the queued handle
- `get_request_status( $request_id )` — normalised state + raw outputs
- `cancel_request( $request_id )` — 202/400/401/404 mapping
- `wait_for_completion( $request_id, $timeout, $initial_delay )` — backoff +
  jitter polling; `wp_mcp_ai_higgsfield_poll_initial_delay` filter seam for
  tests and tuning
- `download_file( $url )` — validated https download
- `extract_outputs()` — normalises `video` / `images[]` / `audios[]` shapes

### Tools (base plugin, registered like `generate_sora_video`)

| Tool | Purpose |
|---|---|
| `generate_higgsfield_video` | Five verified models behind one `model` parameter; text-to-video + reference-to-video (image/video/audio URL refs); Cinema Studio creative controls (34 camera movements, genre, era); async executor queueing; Media Library saving; per-second cost estimation |
| `generate_higgsfield_image` | SOUL V2 / SOUL Cinema; batch 1|4; style_id gating per model; per-image cost estimation |
| `check_higgsfield_request` | Poll any `request_id`; terminal flag + output URLs + provider error |
| `cancel_higgsfield_request` | Cancel queued work; `destructive` capability flag |

`check_video_status` additionally resolves `async_*` executor job IDs, so
Higgsfield background jobs are monitorable through the standard tool.

### Dual-layer parity

Framework-agnostic equivalents live in `lib/core/src/Tool/`
(`GenerateHiggsfieldVideoTool`, `GenerateHiggsfieldImageTool`,
`CheckHiggsfieldRequestTool`, `CancelHiggsfieldRequestTool`) and are
registered in `includes/bootstrap/oos-bridge.php`. The WordPress adapter's
`SettingsStore::getApiKey( 'higgsfield' )` returns the combined
`KEY_ID:SECRET` credential for the core layer.

### Settings

- Provider section: `enable_higgsfield`, `higgsfield_api_key_id`,
  `higgsfield_api_key_secret`, video defaults (resolution, aspect ratio,
  duration, generate audio) — subtab group "Higgsfield".
- Connector definition (Settings → Connectors) with `docs_url`.
- Sensitive-fields list + defaults in `WP_MCP_AI_Admin_Settings_Base`.
- Async-executor timeout override: 480s for both generation tools.

## Implementation Notes

- **Model IDs contain dots** (`wan-3.0`). They are sanitised with
  `sanitize_text_field`, not `sanitize_key` (which strips `.` and would
  break catalog lookups — caught by the test suite before merge).
- **Payload gating.** Higgsfield endpoints reject unknown fields
  (`additionalProperties: false`); every payload key is gated by the
  selected model's definition.
- **WP 6.9 URL validation.** `wp_http_validate_url()` DNS-resolves hosts;
  the client relies on it as an SSRF guard. Test fixtures use resolvable
  hosts (`example.com`), not fake CDN hosts.
- **Base vs Pro.** All tools live in `includes/tools/` and register
  unconditionally — available in base-only and base+Pro installs, like
  Sora/Veo. Base files pass the PHP 7.4–8.3 compatibility gate.
- **Included fix (same branch):** `typesafe_decide` / `typesafe_eval`
  array-item schemas and the Pro coverage manifest (separate commits,
  see branch history).

## File Manifest

**New — base plugin:** `includes/class-wp-mcp-ai-higgsfield-client.php`,
`includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php`,
`includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php`,
`includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php`,
`includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php`

**New — core:** `lib/core/src/Tool/GenerateHiggsfieldVideoTool.php`,
`lib/core/src/Tool/GenerateHiggsfieldImageTool.php`,
`lib/core/src/Tool/CheckHiggsfieldRequestTool.php`,
`lib/core/src/Tool/CancelHiggsfieldRequestTool.php`

**New — tests:** `tests/test-higgsfield-client.php`,
`tests/test-higgsfield-video-tool.php`,
`tests/test-higgsfield-image-tool.php`,
`tests/test-higgsfield-request-tools.php`

**Changed:** tool registry, `includes/bootstrap/oos-bridge.php`,
`includes/bootstrap/cron.php` (timeout overrides),
`includes/tools/class-wp-mcp-ai-tool-check-video-status.php`,
provider settings section + admin settings base + connector definitions,
`lib/wordpress-adapter/src/Adapter/SettingsStore.php`, coverage manifest,
`docs/tool-status.txt`, `includes/tools/README.md`,
`.agents/skills/design-video-creation/SKILL.md`

## Validation

- PHPUnit (Docker, WP 6.9 core, MySQL): Higgsfield cluster + registry
  coverage **56/56 tests, 2933 assertions, 0 failures**; broader regression
  (Sora, provider settings/subtabs, admin settings, tool registry)
  **121/121**.
- phpcs (repo standard): 0 errors on all new/changed files.
- PHPCompatibilityWP `7.4-8.3`: clean on all base-plugin files.
- No live-API calls were made during development (no credentials available);
  endpoint contracts were verified against the official docs and mocked in
  tests. A live smoke test against a funded account is recommended post-merge.

## Out of Scope / Future Work

- **Kling image-to-video & motion control**, Wan 2.6/2.7, MiniMax, LTX,
  PixVerse, Grok Imagine Video — verified-docs required per model before
  adding to the catalog (docs must not be guessed).
- **Genjutsu** (motion transfer, object swap) — niche editing workflows.
- **Remaining image families** (SOUL standard, Soul ID, Marketing Studio,
  Ideogram, Recraft, Qwen, Z-Image) — one `model` entry each once verified.
- **SOUL styles listing** (`GET /v1/text2image/soul-styles/v2`) as a
  read-only helper for `style_id` selection.
- **Webhooks** for completion — executor + polling already covers
  reliability; webhooks add complexity without a current use case.
- **Media upload API** (`asset://` inputs) — public URL references cover
  the WordPress use case.
- **Pricing table** — add Higgsfield per-model `per_second` / `per_image`
  rates to the cost calculator; today costs report `is_estimated: true`.

## Risks / Documented Uncertainties

- Catalog entries are limited to **documented, verified endpoints**; the
  broader catalog (Kling Pro/4K/Turbo variants etc.) is intentionally not
  exposed until verified. Endpoints may drift — the model catalog is a
  single `VIDEO_MODELS` / `IMAGE_MODELS` constant map for easy maintenance.
- `wp_http_validate_url()` DNS resolution means download URLs must resolve
  at runtime; real Higgsfield CDN hosts do.
- Per-model default resolutions/aspects follow the provider docs; defaults
  configured in admin settings are clamped to the selected model's ranges.
