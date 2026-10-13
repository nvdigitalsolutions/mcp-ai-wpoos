# Proposal 074 — Local-Model Providers for the Media Worker (Text + Image)

**Status:** Draft for discussion
**Date:** 2026-10-13
**Related:** Proposal 071/073 (`nvoos-dictate` local-model work), `addons/media-worker/`, `.agents/skills/design-image-generation`, `.agents/skills/design-document-generation`

## 1. Context

The Design Stack **media worker** (`addons/media-worker/`, a Node/Express + Sharp
service on `:3100`) is the repo's server-side pipeline for image generation,
image optimization, document generation (PDF/Excel/Word/OCR), video processing,
social publishing, and workflow orchestration.

Today its **image generation** is hard-wired to **eleven cloud providers**
(`image.js` `PROVIDERS` registry: OpenAI/DALL·E, Gemini/Imagen, Stability,
Replicate, Midjourney, Leonardo, Ideogram, Getimg, DeepAI, Firefly, Clipdrop),
each gated by a cloud API-key env var. Its **document/content** text is
deterministic templating (pdfkit/exceljs/docx) — it does not call an LLM for
the prose it embeds.

The companion module `utils/llm-extract.js` **already** contains a multi-provider
LLM abstraction that can target *"any OpenAI-compatible endpoint (Ollama, Groq,
OpenRouter, vLLM …) — key optional, Ollama runs without one"* via
`CRAWL_LLM_BASE_URL`. That seam is today used **only** for crawl structured
extraction, not for image or document content.

This proposal extends that existing local-model capability so that **document
generation, content drafting, and image generation can be configured to run
against self-hosted local models** — the server-side analogue of the
`nvoos-dictate` LM Studio/Ollama work from earlier this session.

## 2. Industry research summary

### 2.1 Local image generation: serving standards

Two self-hosted surfaces dominate; the choice matters because their APIs are
**fundamentally different**:

| Surface | HTTP API | Model of integration | Notes |
|---|---|---|---|
| **ComfyUI** | `POST /prompt` (submit workflow) + `GET /history` + `/ws` websocket (progress) | Node-graph workflow JSON | **The orchestration-native choice**: `/prompt` + `/history` is the documented headless/CI/CD integration point ([The Neural Base](https://theneuralbase.com/comfyui/learn/advanced/vs-automatic1111-comparison/)) |
| **AUTOMATIC1111 / Forge** | `POST /sdapi/v1/txt2img` | Simple form-style (`prompt`, `negative_prompt`, `steps`, `cfg_scale`, `width`, `height`, `sampler`) | Form-based; Forge is the 2026-recommended drop-in over vanilla A1111 ([aumiqx](https://aumiqx.com/ai-tools/stable-diffusion-webui-setup-guide-2026/)) |

Key implications for the proposal:

- **A1111/Forge's `/sdapi/v1/txt2img` is the lower-friction target** — a single
  synchronous POST returns base64 images, mapping naturally onto the media
  worker's existing `generateWithProvider` shape.
- **ComfyUI** is more powerful (ControlNet/LoRA/upscale as native nodes) but
  requires submitting a workflow JSON + polling `POST /history/{prompt_id}` —
  a richer, async integration. It is the right *long-term* target; A1111/Forge
  is the pragmatic *first* local backend.

### 2.2 Local LLM (text) for document/content

The industry pattern is unambiguous: **any OpenAI-compatible `/v1/chat/completions`
endpoint** (Ollama `localhost:11434/v1`, LM Studio `localhost:1234/v1`, vLLM,
llama.cpp `server`) is the de-facto standard for local text models, with the
`openai` SDK pointed at a `base_url` and a dummy/absent key. This is exactly the
convention the media worker's `llm-extract.js` already implements — the proposal
merely *generalizes* it from "crawl extraction" to "any worker text task."

### 2.3 Architecture principle (mirrors the dictation app)

The correct shape is the same as what was built for `nvoos-dictate` this session:

- **Local models are an additional *provider*, not a replacement.** Keep cloud
  providers exactly as-is; add a `local`/`openai-compatible` family behind the
  same registry + credential patterns.
- **Key-optional for local.** Ollama/LM Studio need no key; a `baseUrl` is what
  routes to them. Credential gating must not 503 a local provider for a missing
  cloud key.
- **No new service.** The media worker already runs on the user's machine; it
  points at the user's local model servers. This is an *integration*, not a
  port.

## 3. Goals

1. **Local text for document/content generation.** Extend the document/content
   path so the prose it produces can be drafted by a local LLM (Ollama/LM
   Studio/any OpenAI-compatible URL), reusing the existing `llm-extract.js`
   provider-resolution seam.
2. **Local image generation.** Add first-class `local_sd` (A1111/Forge
   `/sdapi/v1/txt2img`) and `comfyui` (workflow submit + poll) providers to the
   image registry, selectable like any cloud provider.
3. **Consistent observability.** Surface local providers in
   `GET /api/image/providers` and `/status`, with `configured` reflecting a
   reachable base URL rather than a cloud key.
4. **Parity of behavior.** Local providers honor the same `size`/`count`/`seed`/
   `steps`/`cfg_scale`/`negative_prompt` options the cloud backends already
   accept, translated to each local API's dialect.

## 4. Non-goals

- **Porting the media worker into the Tauri app**, or the app into the worker.
  The two stay peers that both *consume* the same local-model convention.
- **Training/fine-tuning** or model management (downloading checkpoints/LoRA) —
  the user points the worker at an already-running local server.
- **Text-to-video** local models (AnimateDiff/SVD) — video is out of scope here.
- **GPU/job scheduling** beyond the worker's existing queue — ComfyUI jobs are
  enqueued to the worker's queue as they are for cloud async providers.

## 5. Design

### 5.1 Generalize the LLM seam (text)

Extract the OpenAI-compatible client out of `utils/llm-extract.js` into a shared
`utils/llm-client.js`:

```js
// Resolves { provider, model, apiKey, baseUrl? } with the SAME rules, but
// reusable for any text task, not just crawl extraction.
export function chatCompletion({ prompt, system, site, responseFormat }) { … }
```

- Keeps the "explicit `CRAWL_LLM_PROVIDER` > `CRAWL_LLM_BASE_URL` (local) >
  autodetect" selection order, so an existing Ollama/LM Studio install is used
  with a single `CRAWL_LLM_BASE_URL=http://localhost:11434/v1` env var.
- The `document.js` route gains an optional *"draft content via LLM"* step: the
  caller may pass a `content_prompt`; when the document span is prose (an email
  body, a proposal narrative, an Excel cell set), the worker asks the local LLM
  for the text, then renders it deterministically.

### 5.2 Local image providers (image.js)

Add a `local` family to `PROVIDERS` and `MODELS_BY_PROVIDER`, with a
`baseUrl`-driven credential (key optional):

```js
const PROVIDERS = {
  …existing cloud providers…,
  local_sd:  { name: 'Local (A1111/Forge)',  key: null, baseUrlEnv: 'LOCAL_SD_BASE_URL' },
  comfyui:   { name: 'Local (ComfyUI)',      key: null, baseUrlEnv: 'COMFYUI_BASE_URL' },
};
```

- **`local_sd`** (`case 'local_sd'`): `POST {baseUrl}/sdapi/v1/txt2img` with
  `{ prompt, negative_prompt, steps, cfg_scale, width, height, seed, sampler,
  batch_size }` → `images[]` (base64) → the worker's existing `b64_json` return
  shape. Synchronous, minimal translation.
- **`comfyui`** (`case 'comfyui'`): build a minimal text2img workflow JSON →
  `POST /prompt` → poll `GET /history/{prompt_id}` (or subscribe `/ws`) → extract
  `images[]` → same return shape. Async; enqueued like Replicate/Midjourney
  today.
- **`configured`** for local providers = `!!getCredential(site, baseUrlEnv)`
  (a reachable URL), *not* a cloud key — so `/providers`/`/status` list them
  correctly without 503-ing on a missing cloud key.

Model naming: local models are the *loaded checkpoint/workflow*, not a fixed
catalogue. `MODELS_BY_PROVIDER.local_sd`/`comfyui` expose a single `"local"`
entry plus allow an arbitrary `model` string passthrough (the checkpoint name
or ComfyUI workflow id).

### 5.3 Config & observability

- New env vars: `LOCAL_SD_BASE_URL`, `COMFYUI_BASE_URL`, optional
  `LOCAL_SD_AUTH` / `COMFYUI_AUTH` bearer tokens for password-protected local
  servers.
- `index.js` `/status` `providers` + `capabilities.ai_providers` gain
  `local_sd`/`comfyui`; `ai_content`/`ai_image_generation` reflect local
  availability too.

### 5.4 Credential & security

- Local base URLs are **origin-allowlisted** (localhost / LAN, like the
  worker's existing CORS + safe-url handling) to prevent a hostile URL driving
  outbound requests; no cloud key is required for local.
- Local provider responses are still routed through the worker's existing
  output-optimization (Sharp → WebP) and usage-recording path.

## 6. Dependencies

- **None new.** The `openai` SDK + `axios` are already present; A1111/Forge and
  ComfyUI are plain HTTP. Extracting `llm-client.js` is a refactor of an
  existing module, not a new package.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| A1111 vs ComfyUI API churn | Isolate behind the two `case` blocks; one sync + one async backend; test with real local servers |
| Local server down / unreachable | Surface a clear "local server unreachable" error + `configured` false in `/providers` |
| Credential gating 503s local for a missing cloud key | `configured` for local = base URL presence, never a cloud key |
| SSRF via attacker-controlled base URL | Origin allowlist (localhost/LAN), matching existing safe-url discipline |
| ComfyUI async complexity | Enqueue via the existing job queue; treat like Replicate's poll-today path |

## 8. Milestones

1. **M1 — shared LLM client.** Extract `utils/llm-client.js` from `llm-extract.js`
   (no behavior change); refactor `crawl4ai.js` to use it; unit tests for the
   resolution order and key-optional path.
2. **M2 — local text for documents.** `document.js` optional `content_prompt` →
   `chatCompletion` (Ollama/LM Studio) for prose spans; test with a real Ollama
   endpoint (manual) + a mocked client (CI).
3. **M3 — `local_sd` (A1111/Forge) image provider.** Registry + `case 'local_sd'`
   + `/providers` + `/status`; test against a running Forge (manual).
4. **M4 — `comfyui` image provider.** Workflow submit + poll; enqueue async;
   test against a running ComfyUI (manual).
5. **M5 — docs + skill updates.** `design-image-generation` +
   `design-document-generation` skills note the local providers and the env
   vars; deployment doc update.

## 9. Open questions

1. **Default local image target**: ship `local_sd` (A1111/Forge, simplest) first
   and treat `comfyui` as M4, or prioritize ComfyUI (more future-proof) despite
   the async complexity?
2. **Scope of "local text"**: just document *prose*, or also the image-prompt
   augmentation (LLM rewrites the user's prompt before sending to the image
   model)? The latter is a natural local integration but expands scope.
3. **Multi-site tenant model**: should `LOCAL_SD_BASE_URL` be per-site
   (`SITE_PROVIDER_KEYS` style), or a single worker-global local server?
4. **Auth**: support bearer tokens on the local servers, or assume
   unauthenticated localhost-only?

---

*Mirrors the dictation-app work: local models are a peer provider behind the
existing registries, key-optional for OpenAI-compatible endpoints, with the
same least-privilege and observability discipline. Image-gen-local (M3/M4) is
the genuinely new surface; local text (M1/M2) is a low-risk generalization of
a seam the worker already ships.*
