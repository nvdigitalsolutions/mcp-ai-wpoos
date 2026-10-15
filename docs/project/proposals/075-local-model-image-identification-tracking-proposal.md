# Proposal 075 — Local-Model Image Identification & Object Tracking

**Status:** Draft for discussion
**Date:** 2026-10-13
**Related:** Proposal 043 (non-LLM image identification ladder), Proposal 074 (media-worker local providers), `nvoos-dictate` LM Studio/Ollama work, `.agents/skills/design-image-generation`, `includes/tools/class-wp-mcp-ai-tool-analyze-image.php`

## 1. Context

The plugin already ships a vision-LLM path (`analyze_image`, `extract_image_text`)
that sends pixels to a cloud vision model (OpenAI/Anthropic/Gemini/vLLM), plus a
Pro detector pipeline (`analyze_image_objects`, OWLv2/Ollama + optional VLM), and
Proposal 043's deterministic "identification ladder" (metadata → pHash → classic
detectors → web search → vision LLM as last resort).

None of these currently run against a **self-hosted local vision model**. This
proposal adds that: image **identification** ("what objects/entities/text are in
this image, where, with what layout, at what confidence") and **object tracking**
("where does this object/logo/entity appear across a media set") served by a
local VLM (Qwen2.5-VL / LLaVA / MiniCPM-V / Gemma 3) via Ollama or LM Studio,
using the same OpenAI-compatible seam already established in this session.

## 2. Critical definition: "identity" vs "identification"

This proposal deliberately draws a bright line, because the two are legally and
ethically very different things:

| Kind | Meaning | Local-model viability | Compliance posture |
|---|---|---|---|
| **Image *identification*** | What objects/labels/text/layout are present; where they are; what a logo/entity is | ✅ high — the strong use case | Routine; no special consent |
| **Object *tracking*** | Where a *given* object/logo/asset appears across a set/stream | ✅ medium — embeddings/clustering or VLM bounding boxes | Routine, but see §7 data-fidelity |
| **Person *identity* (facial recognition)** | *Who* is in the image; matching faces against a known-person database | ⚠️ possible, but NOT in scope | **Regulated** — biometrics; explicit opt-in consent required (BIPO 2026; Virginia §15.2-1723.2 effective 2026-07-01, which bans real-time tracking of identified individuals + live-feed face DBs) |

**Non-goal (§4)**: person *identity*, facial *recognition*, and any biometric
enrollment/matching. Those are a separate, consent-gated effort (call it 075b)
and are explicitly excluded here. "Identity" in this proposal means *asset
identity* — "this is a cat, a red chair, the Acme logo, this ISBN" — not *who a
human is*.

## 3. Industry research summary

### 3.1 Local open-weight vision models (2026)

| Model | Strength | VRAM | Notes |
|---|---|---|---|
| **Qwen2.5-VL (7B)** | Documents, layouts, charts; **bounding-box/point localization with stable JSON output** | ~6–8 GB | The all-rounder for OCR/structure; native-resolution encoding (don't pre-resize) |
| **LLaVA 1.6 (7B)** | General image Q&A, screenshot analysis | ~6 GB | Mature, broadly available on Ollama |
| **MiniCPM-V 4.5 (8B)** | Strong on-device, low VRAM | ~6 GB | Good "starting point" per 2026 surveys |
| **Gemma 3** | Multimodal (text+image) | varies | Google's open-weight option |

Sources: [promptquorum](https://www.promptquorum.com/power-local-llm/local-vision-models-llava-ollama-2026),
[localaimaster](https://localaimaster.com/blog/local-ai-vision-tasks),
[Ollama qwen2.5vl](https://ollama.com/library/qwen2.5vl),
[Markaicode](https://markaicode.com/deploy-qwen25-vl-vision-language-model-local/).

### 3.2 The decisive integration fact

**Ollama (and LM Studio) expose these vision models through an OpenAI-compatible
`/v1/chat/completions` endpoint.** The message format is the standard
multimodal shape — an `image_url` (base64 data URI or URL) inside the `content`
array alongside text ([ML Journey](https://mljourney.com/how-to-use-ollama-vision-models-for-local-image-analysis/)).
This means the plugin's existing `analyze_image` transport can target a local
model by swapping `base_url` → `http://localhost:11434/v1` and `model` →
`qwen2.5vl`, with **no** new SDK and no cloud key. This is the exact same
"key-optional OpenAI-compatible" pattern already implemented twice this session
(dictation app providers; media worker `llm-extract.js`).

### 3.3 Bounding-box localization → "tracking"

Qwen2.5-VL emits **object bounding boxes / points** as structured JSON. For
*object tracking* across a media corpus, the two viable local approaches are:

1. **VLM per-image boxes** — ask the model for the target object's box each
   image; deterministic-ish, but slow (one inference per image) and
   prompt-sensitive.
2. **Local embedding/clustering** — derive image/region embeddings (OpenCLIP or
   the VLM's vision encoder) and cluster/match; fast at corpus scale, but needs
   a separate embedding stack vs. a single VLM call.

The proposal recommends (2) for corpus-scale "track an asset across N images"
and (1) for "locate within a single image", both served locally.

## 4. Non-goals

- **Person identity / facial recognition / biometric matching** — excluded
  (see §2); a separate consent-gated 075b.
- **Real-time / live-feed tracking** (of people or objects) — file/batch only.
- **Training/fine-tuning** — use released checkpoints, no custom weights.
- **Model management** — the user points at a running Ollama/LM Studio; the
  plugin does not download or manage weights.
- **Cloud fallback for identity/OCR** — the *local* path is the deliverable;
  existing cloud paths remain, unchanged.

## 5. Design

### 5.1 A local vision provider (config + transport)

Reuse the established "provider + base URL, key optional" convention:

- New settings (Pro): `wp_mcp_ai_local_vision_base_url`
  (default `http://localhost:11434/v1`), `wp_mcp_ai_local_vision_model`
  (default `qwen2.5vl`), optional `wp_mcp_ai_local_vision_key` (blank = none).
- A small `WP_MCP_AI_Local_Vision_Client` service: builds the OpenAI-compatible
  `chat.completions.create` call with `messages[].content` = `[{type:"text",…},
  {type:"image_url", image_url:{url: data-uri}}]`. **Do not pre-resize** — Qwen2.5-VL
  needs native resolution (research finding); cap payload size only to guard memory.
- Reuse the existing vision tool's **capability key short-circuit** pattern: if
  no local base URL is configured, the tool reports `skipped`/`missing config`,
  never sends bytes anywhere, and never 503s on a *cloud* key.

### 5.2 `identify_image_local` (Base tool, local-only)

A new tool mirroring `analyze_image` but pinned to the local provider:

- Input: attachment/URL (existing validation + `WP_MCP_AI_URL_Guard` SSRF check).
- Prompt strategy: a deterministic structured prompt asking for
  `objects[]` (label, bbox, confidence), `text_blocks[]`, `layout` (grid
  description), `entities[]` (logo/brand/landmark), and an `unsure` flag.
- Output: canonical envelope (success array / `WP_Error`) + the parsed JSON —
  exactly the discipline Proposal 043's `identify_image` already models.
- Hard-error only on a genuine config/transport failure; "no local model
  configured" is a clear, actionable error (not silently routed to cloud).

### 5.3 `track_objects_local` (Pro tool, corpus-scale)

- Input: a positive/example image (or a target object label) + a media range
  (post IDs / upload period).
- Two modes, both local:
  - **Single-pass VLM** (`mode=boxes`): run `identify_image_local` over each
    candidate, collect the target's bounding box per image → a manifest
    `[{attachment_id, bbox, confidence}]`.
  - **Embedding index** (`mode=embed`, recommended for > ~50 images): compute
    image embeddings via a local OpenCLIP/vision-encoder endpoint (or the VLM's
    encoder) and nearest-neighbour match; returns a ranked manifest.
- Output: a time/order-able manifest (the "track") persisted optionally to
  Paper Store for later review.

### 5.4 Integration with the Proposal 043 ladder

`identify_image_local` fits as an **alternative final rung** of 043's ladder:
metadata → pHash → classic detectors → (local VLM instead of, or before, cloud
`analyze_image`). When a local model is configured, it becomes the preferred
"expensive" rung so that pixels never leave the box by default.

### 5.5 Base vs Pro

| Deliverable | Distribution | Rationale |
|---|---|---|
| `identify_image_local` | Base | Pure transport; key-optional; no paid dependency |
| `track_objects_local` (boxes mode) | Pro | Corpus orchestration + optional Paper Store persistence |
| `track_objects_local` (embed mode) | Pro | Additional embedding service invocation |
| Local vision config settings | Pro | Plugin infra (mirrors other provider settings) |

## 6. Dependencies

- **None new** at the plugin layer: the OpenAI-compatible client already exists
  (the vision tools use an OpenAI/Anthropic/Gemini transport); only a
  `base_url`/`model` override is added.
- **Embedding mode (optional)**: a local OpenCLIP/vision-encoder endpoint
  (e.g. via Ollama's embeddings or a small CLIP server) — recorded as a
  Pro enhancement, not a hard dependency for the v1 `boxes` mode.

## 7. Risks, privacy & compliance

| Risk | Mitigation |
|---|---|
| Local model hallucination / unstable JSON | Deterministic prompt + hardened JSON parsing (reuse the media worker's balanced-JSON extractor pattern); `unsure` flag on low confidence |
| Native-resolution payload can be large | Payload size cap + clear error; never pre-resize (degrades Qwen2.5-VL) |
| SSRF via a hostile image URL | `WP_MCP_AI_URL_Guard` on every fetched URL (same as existing tools) |
| **Person-identity creep** | Hard non-goal (§2/§4); tool descriptions + guidance explicitly say "identifies objects/assets, not people"; no face-enrollment DB, no live tracking |
| Local server down | Actionable "local vision server unreachable at {url}" error; `configured` reflects reachability in status |
| Data leaves the box | By construction the *local* path keeps pixels on-device; the only network egress is the base URL the user configured (localhost by default) |

## 8. Success metrics

1. `identify_image_local` returns the full objects/text/layout/entities envelope
   against a real Qwen2.5-VL (Ollama) in Docker, with no cloud key present.
2. `track_objects_local` (boxes mode) produces a correct ranked manifest for a
   small fixture corpus.
3. No regression in `test-vision-tools.php`; new tools have unit + contract
   tests (request shape, no-key short-circuit, SSRF-flagged URL rejection).
4. `composer run lint` + `lint:compat` + CI PHPUnit stay green.

## 9. Open questions

1. **Embedding mode in v1** — ship only the VLM `boxes` mode now, and defer
   OpenCLIP `embed` to a follow-up? (Proposal: yes — v1 = `boxes` only.)
2. **Default model** — pin default to `qwen2.5vl` (recommended), or keep
   `model` free-form with no default?
3. **Payload encoding** — base64 data URI vs. passing a local file path
   (some local servers accept a `path`)? Data URI is the most portable; confirm.
4. **`track_objects_local` persistence** — Paper Store optional, or always a
   fresh in-memory result?
5. **075b (person identity) scoping** — should we even draft the consent-gated
   facial-recognition follow-up now, or explicitly rule it out indefinitely?

## 10. References

- Proposal 043 (identification ladder): `docs/project/proposals/043-non-llm-image-identification.md`
- Proposal 074 (media worker local providers): `docs/project/proposals/074-media-worker-local-model-providers-proposal.md`
- Qwen2.5-VL: <https://ollama.com/library/qwen2.5vl>, <https://lmstudio.ai/models/qwen2.5-vl>
- Local VLM comparatives: <https://www.promptquorum.com/power-local-llm/local-vision-models-llava-ollama-2026>, <https://localaimaster.com/blog/local-ai-vision-tasks>
- Ollama vision over OpenAI-compatible API: <https://markaicode.com/deploy-qwen25-vl-vision-language-model-local/>, <https://mljourney.com/how-to-use-ollama-vision-models-for-local-image-analysis/>
- Facial-recognition ethics/regulation: BIPO 2026 consent guidance; Virginia Code §15.2-1723.2 (effective 2026-07-01)

---

*"Think and identify anything" resolves to: local open-weight VLMs (Qwen2.5-VL
leading) identify objects/text/layout/entities and — via bounding boxes —
track *assets* across a corpus, over the same OpenAI-compatible seam established
this session. Person *identity* is explicitly excluded as a regulated, consent-gated
concern rather than bundled into "identification".*
