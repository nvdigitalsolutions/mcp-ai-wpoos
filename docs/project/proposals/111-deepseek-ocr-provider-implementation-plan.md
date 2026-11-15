# 111 — DeepSeek-OCR Provider Tier: Implementation Plan

- **Date:** 2026-11-04
- **Related:** `111-deepseek-ocr-provider-proposal.md`, Proposal 109 pipeline, Proposal 076 transport, ADR 0003/0005

## Verified research facts (this session)

- **Prompt contract (official):** `<image>\n<|grounding|>Convert the document to markdown.` —
  a plain grounding prompt; the DeepWiki/Cohorte guidance is explicit that this
  model family performs best with plain prompts, not generic chat-vision
  scaffolding. **No system message.**
- **Serving:** Ollama ships `deepseek-ocr` (~6.7 GB quantized, v1, MIT);
  llama.cpp merged support (PR #17400, GGUF); vLLM/transformers serve v1/v2;
  LM Studio supports the family. All expose OpenAI-compatible endpoints.
- **DeepSeek-OCR-2** (2026-01-27): Apache 2.0, 3B, **91.09% OmniDocBench v1.5**
  (+3.73% over v1), DeepEncoder V2 "visual causal flow", 256–1,120 visual
  tokens/page, outperforms Gemini 3 Pro on complex layouts. Served via
  vLLM/transformers today — the app's model id is user-settable, so v2 works
  wherever a server exposes it; v1 (`deepseek-ocr` in Ollama) is the
  live-verified default on the reference PC.
- **Confidence gate reality:** WinRT reports a constant 1.0 ("exposes no
  per-word confidence") and the ONNX tier reports `None` ("kept honest") —
  so a confidence-based escalation trigger is dead weight with today's
  engines. The honest knobs are a budgeted **all-scans opt-in** plus a
  `min_confidence` field that activates automatically when an engine starts
  reporting real scores.

## Milestones

### M0 — Config, transport, probe (verify-first)

- `DocumentModelConfig` in `config.rs` (serde-default): `enabled: false`,
  `provider: "ollama" | "lm_studio"`, `model: "deepseek-ocr"`,
  `min_confidence: Option<f32>` (None = off), `all_scans: bool`,
  `max_pages: 5` (budget — the tier is slow), `load_timeout_ms: 120_000`.
- `openai_shape.rs`: `document_model_prompt(prior_text)` (the official
  grounding prompt; weak box-level OCR text offered as prior context),
  `build_document_model_body` (single user message, image data-URI, no
  system message, temperature 0.1), `parse_document_model_reply`
  (fence-stripping, same contract as `polish::strip_fences`).
- `local_openai.rs::understand_document(image_data_uri, prior_text)` —
  mirrors `correct_ocr` (model-omission convention included).
- `document/model.rs`: `DocumentModel` blocking worker (own current-thread
  tokio runtime, the `OcrPolisher` shape), `build_document_model(settings,
  secrets)` (configured provider first, the other local provider as
  fallback), `model_page_to_png_data_uri` (long edge 3000 — the tier needs
  more pixels than the 1568 polish budget), `maybe_model(...)` (failure-
  soft, mirrors `maybe_polish`).
- `examples/document_model_probe.rs`: page raster → model tier → Markdown.
  **Live gate:** `ollama pull deepseek-ocr` (6.7 GB) on the reference PC;
  run against `text-2page.pdf` render + `image-text.png`; record RTF.

### M1 — Escalation in the controller

- `PageProvenance::Model` (+ id `"model"`; serde lowercase already).
- `run_pipeline` builds the worker once; the OCR sites (PDF `Ocr` arm,
  shared raster path) escalate when: tier enabled AND (`all_scans` OR
  confidence below `min_confidence`) AND the per-document budget remains.
- Escalation input is the **raw** page raster (never the binarized scan
  profile — the model needs photos/tables intact); output replaces the
  page text; `lines` fall back to synthetic line-level boxes (models emit
  no coordinates — by design).
- `DocumentStats.model_pages` counter + `DocumentDiagnostics.model_pages`
  + a `document.model` block (enabled/provider/model) for the UI.
- Ordering: escalate → (else) polish → deterministic. Failure always
  falls back to the box-level result (the Stage-2 rule).

### M2 — IPC + settings UI

- `document_model_available()` and `test_document_model_connection()`
  (a one-token completion ping through the built worker — the connection
  is the thing under test; quality is the probe's job). Registered in
  `lib.rs`.
- Settings gains a **Documents** tab: the existing document knobs (DPI,
  page cap, polish) plus the model tier (toggle, provider, model id,
  all-scans checkbox, min-confidence, page budget, Test button).
- TS: `Settings.document.model`, `PageProvenance 'model'`,
  `DocumentProgress.phase 'model'`, `api.testDocumentModelConnection`.

### M3 — Surface polish + docs

- Home: `model` progress phase text + green `model` provenance badge.
- Diagnostics: model-pages row + tier status.
- Research notes + dictation-app skill + this plan.

## Risks & decisions

- **Latency:** CPU inference is minutes/page — the tier is opt-in and
  page-budgeted (`max_pages` default 5).
- **Resolution:** feed the raw page at ≤3000 px long edge; larger pages
  downscale Lanczos3 (the model's internal compression handles the rest).
- **Parsing:** fence/prose-tolerant reply parsing; empty or verbatim
  replies are treated as failures (no text swap).
- **Licenses:** v1 MIT, v2 Apache-2.0 — both compatible; NOTICE entry
  when weights ship (they never ship — server-side).

## Verification matrix

1. Unit: prompt/body/parse tests; config defaults; provenance id.
2. Gates: fmt; clippy (default + document + full union); tests (default,
   mcp, document); `npm run build`.
3. Live: probe against `deepseek-ocr` on Ollama; escalation exercised
   end-to-end when a local server is running (documented recipe).
4. CI: the standard build-nvoos-dictate matrix.

## Live verification results (2026-11-04)

`ollama pull deepseek-ocr` (6.7 GB, F16, 3.3B) on the reference PC, then
the `document_model_probe` against the existing fixtures:

- `tests/fixtures/image-text.png` (600x200) -> `## IMAGE OCR FIXTURE 123`
  (correct Markdown, ~10-19 s cold, ~3 s warm).
- `tests/fixtures/text-2page.pdf` (rendered 2549x3299 -> 2319x3000 PNG) ->
  the fox text, clean (24 s cold).

Two findings changed the transport design (both shipped):

1. **The OpenAI-compat mount drops image features for the `deepseekocr`
   architecture.** `/v1/chat/completions` with `image_url` content parts
   derailed non-deterministically on some page sizes (replies became
   `<|im_start|>` template loops or `\ |\` noise) while Ollama's native
   `/api/chat` with `images: [b64]` was reliable at every tested size
   (600x200 .. 3000x3000). The tier now branches: **ollama -> native
   `/api/chat`** (endpoint derived from the base URL by stripping the
   `/v1` suffix), **lm_studio -> the compat mount**. The Settings
   connection test pings Ollama with a blank 64x64 image through the
   native route (text-only pings are off-contract for this vision model
   and loop).
2. **Even the native route occasionally prefixes replies with stray
   template fragments** on some page sizes (`\<|im_start|>user`,
   `</|im_start|>user`, `\>`). The content after them is valid, so
   `parse_document_model_reply` strips the fragment list deterministically
   (including slash variants and a bare `\`) before fence-stripping.

Local-machine ops note: the Ollama install on the reference PC was
missing `llama-server.exe` and the CPU runners (every model call returned
HTTP 500) — a silent reinstall (`OllamaSetup.exe /S`) restored it. The
probe exe needs `pdfium.dll` copied beside it under
`target/debug/examples/` (`bind()` searches next to the exe; build.rs
stages the dll at `target/debug/` for the main binary only).
