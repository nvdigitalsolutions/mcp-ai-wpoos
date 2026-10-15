# 075 — Local-Model Image Identification & Object Tracking: Implementation Plan

**Status:** In progress
**Date:** 2026-10-13
**Related:** `075-local-model-image-identification-tracking-proposal.md`, Proposal 043 (identification ladder), `includes/class-wp-mcp-ai-openai-client.php`, `includes/tools/class-wp-mcp-ai-tool-analyze-image.php`

## Key research finding that governs the whole plan

The `WP_MCP_AI_OpenAI_Client` **already** supports a custom base URL
(`openai_base_url` setting → `get_base_url()` → `resolve_endpoint()`), and the
vision transport (`analyze_image::call_openai_vision`) already emits the
OpenAI-compatible multimodal `image_url` message shape. Local VLMs (Ollama/LM
Studio) accept **exactly** that shape over `/v1/chat/completions`.

Therefore the integration is **not a new SDK or transport** — it is a
*surgical, per-tool* provider path (its own base URL + model, key-optional) so
that a local model is used **without** repurposing the global `openai_base_url`
(which would silently break cloud OpenAI for every other tool).

## Decisions (from the proposal's open questions, resolved for v1)

1. **Embedding mode deferred** — v1 ships the VLM bounding-box (`boxes`) mode
   only; OpenCLIP/embedding corpus-scale matching is a follow-up.
2. **Default model** pinned to `qwen2.5vl` (overridable).
3. **Payload** = base64 data URI (most portable across Ollama/LM Studio).
4. **`track_objects_local`** returns a fresh in-memory manifest; Paper Store
   persistence is a follow-up.
5. **Person identity stays a non-goal** (no facial recognition) — enforced in
   tool descriptions and tested.

## Work items

### M1 — Local vision config + client service

- [ ] Add settings (Pro): `local_vision_base_url` (default
  `http://localhost:11434/v1`), `local_vision_model` (default `qwen2.5vl`),
  optional `local_vision_api_key` (blank = none), `enable_local_vision`
  (off by default, mirroring `enable_ollama`).
- [ ] Register the settings fields + defaults in `WP_MCP_AI_Admin_Settings`
  (the "Providers" settings group, following the existing
  `ollama_endpoint_url`/`ollama_model` convention).
- [ ] New `WP_MCP_AI_Local_Vision_Client` service (or a small class shared by
  the two tools): builds a `WP_MCP_AI_OpenAI_Client` pointed at the local base
  URL via the **existing** `resolve_endpoint` semantics, with **no key required**
  when blank (the OpenAI client's `get_api_key()` already tolerates an empty
  key via credential resolver; ensure an empty local key does not 503).
- [ ] Exit: unit test that `local_vision_base_url` routing hits the overridden
  endpoint and that a blank key does not hard-fail.

### M2 — `identify_image_local` tool (Base)

- [ ] Implement `includes/tools/class-wp-mcp-ai-tool-identify-image-local.php`
  modeled on `analyze_image` (same capability + attachment-resolution +
  `WP_MCP_AI_URL_Guard` SSRF discipline), but:
  - pinned to `qwen2.5vl`-class prompt returning a structured JSON envelope
    (`objects[]` with label/bbox/confidence, `text_blocks[]`, `layout`,
    `entities[]`, `unsure`),
  - **no pre-resize** (Qwen2.5-VL degrades if resized) — cap payload size only,
  - `missing config` → clear actionable error (never silently cloud-routes).
- [ ] Hardened JSON parse (reuse the media worker's balanced-JSON extraction
  approach as a small PHP helper) so a fenced/verbose model response still
  yields the JSON object.
- [ ] Register in the tool registry (Base) + add the slug to `.context/tool-registry.md`
  note and an image-analysis preset group.
- [ ] Exit: PHPUnit for request shape (multimodal `image_url` content + image
  data URI), no-key short-circuit, SSRF-flagged URL rejection, JSON parse.

### M3 — `track_objects_local` tool (Pro)

- [ ] Implement `addons/pro/includes/tools/vision-analysis/` tool that:
  - takes a positive/example image + a media range (post IDs / uploaded-after),
  - for each candidate runs the local VLM asking for the target's bounding
    box, and collects `[{attachment_id, bbox, confidence}]`,
  - returns a ranked manifest (the "track").
- [ ] Reuse `WP_MCP_AI_Local_Vision_Client`; single-pass per image; no embedding
  dependency.
- [ ] Register as Pro + gated behind `enable_local_vision`.
- [ ] Exit: mock-client test producing a correct ranked manifest for a fixture
  corpus (no real model in CI).

### M4 — Compliance, docs, skill updates

- [ ] Tool descriptions + guidance explicitly state "identifies objects/assets,
  not people" (mirror the §2 bright line).
- [ ] Update `.agents/skills/design-image-generation` (local vision provider),
  `.context/tool-registry.md`, and the plugin skill's provider table
  (`LOCAL_VISION_*` env → setting auto-detect).
- [ ] Update `075` proposal status → implemented.

### M5 — Verification

- [ ] `composer run lint`, `lint:compat`, and the Docker PHPUnit workflow green;
  `test-vision-tools.php` has no regression.
- [ ] Manual smoke (reference PC, not CI): run `identify_image_local` against a
  real Qwen2.5-VL in Ollama and confirm the structured envelope.

## Test strategy

- **No live model in CI** — all engine/model interactions are mocked (the
  OpenAI-compatible client is injectable; follow the existing `test-*.php`
  contract-test convention).
- Assert the **request** shape (image data-URI, structured prompt, correct
  resolved endpoint) and the **response** parse (fenced JSON, empty result),
  plus the capability/SSRF/no-key guards.

## Security & compliance

- `WP_MCP_AI_URL_Guard` on every fetched URL (unchanged discipline).
- Blank local key must not 503 (key-optional); `enable_local_vision=false`
  default keeps it opt-in.
- Pixels stay on-device by construction (localhost base URL default); the only
  egress is the URL the user configured.
- No facial-recognition / biometric storage (hard non-goal); tool guidance says
  so.

## Open follow-ups (explicitly deferred)

- `embed` mode (OpenCLIP/local CLIP) for corpus-scale tracking.
- Paper Store persistence of the track manifest.
- A consent-gated person-identity proposal (075b), if ever approved.
