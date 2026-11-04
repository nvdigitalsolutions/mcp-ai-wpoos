# 111 — DeepSeek-OCR Provider Tier: Local Document Understanding (Proposal)

- **Date:** 2026-11-04
- **Status:** Draft (design accepted for the approach; P0 not yet implemented)
- **Related:** Proposal 109 (document input pipeline) + its research notes, Proposal 076 (local VLM transport), ADR 0003 (local-first engines), ADR 0005 (document engine)

## Context

[DeepSeek-OCR](https://github.com/deepseek-ai/DeepSeek-OCR) ("Contexts
Optical Compression") is a ~3B-parameter MoE document-understanding model
that reads page images and emits structured Markdown (text, headings,
tables, formulas, reading order) in one pass. It holds a top position on
OmniDocBench v1.5 with an order of magnitude fewer visual tokens than peer
VLMs — its whole thesis is optical compression for document images.

Verified facts (2026-11-04, web research):

- **License: MIT** (repo + weights) — compatible with the proprietary app.
- **Local serving is mature:** Ollama ships `deepseek-ocr` (~6.7 GB
  quantized), LM Studio supports it, and llama.cpp merged support
  (PR #17400) with GGUF weights; all three expose OpenAI-compatible
  endpoints. Minimum ~16 GB RAM; GPU strongly preferred (CPU is slow).
- The model outputs **text + structure, not per-word coordinates** — it
  cannot feed hOCR/ALTO boxes.

## Problem

The document pipeline's engine tiers (WinRT OCR, RapidOCR-ONNX) are
line/word recognizers: fast, box-bearing, but layout-blind. Complex pages —
multi-column reports, tables, forms, handwriting, formulas — degrade with
OCR engines even after XY-cut reading order. The app already has the
transport for local multimodal models (Proposal 076's
`LocalOpenAiProvider`), but no tier that consumes a full page image and
returns structured text.

## Decision

**Add DeepSeek-OCR as an optional "document understanding" tier reached
through the existing local-server transport — never as an embedded
engine.**

- Embedding is rejected: ~3B MoE, 6.7 GB quantized weights, 16 GB RAM
  floor — it violates the app's local-server house rule (heavy runtimes
  live in LM Studio/Ollama, not in the Tauri binary) and the pinned
  `ort` rc.9 could not run this architecture acceptably on CPU.
- It **complements**, never replaces: the PDF text layer stays (lossless),
  WinRT/RapidOCR stay (fast, box-bearing, default), DeepSeek-OCR is the
  escalation tier for pages where recognition confidence is low or the
  layout is complex.
- Provenance grows a `model` variant; hOCR/ALTO remain engine-tier
  outputs (model text has no coordinates), txt/md use the finalized text
  as today.

## Design (phases)

### P0 — Provider + settings + probe (no UI risk)

- New `Settings.document.model_provider` block (provider id `ollama` |
  `lm_studio` | `custom`, base_url, model default `deepseek-ocr`,
  enable toggle, timeout) — the same `LocalOpenAiProvider` transport 076
  already ships, with a new `understand_document(page_png_data_uri,
  prior_text)` call shape (vision chat body + a structured-Markdown
  prompt; the existing `post_vision` path is the template).
- `examples/document_model_probe.rs` spike: encode a fixture page →
  local server → print the Markdown. This is the live-verification gate
  before anything ships.

### P1 — Escalation fallback

- After the deterministic OCR pass, compute a page-quality signal (mean
  engine confidence — WinRT reports it, ONNX rec scores are available;
  word-count vs. page-area heuristic otherwise). Below threshold (or user
  opts "always use model for this document"), re-run the page through the
  model tier. Provenance `Polished` today; `Model` for this path.
- Failure-soft by contract: any transport error falls back to the
  deterministic result (the dictation Stage-2 rule).

### P2 — Structured output surface

- Model Markdown becomes the page text for `md`/`txt` export (badged);
  hOCR/ALTO keep engine boxes. Optional: keep model output as an
  additional per-page field so both texts survive for review.

## Open questions / risks

- **Latency:** CPU inference can take minutes per page — the tier must be
  opt-in and page-budgeted (mirror OCRmyPDF's per-page budget standard).
- **API shape:** verify the OpenAI-compatible vision request against
  Ollama's `deepseek-ocr` (image data-URI + prompt) — the probe is the
  arbiter; llama.cpp's server is the fallback serving path.
- **Output parsing:** strip fences/prose like `polish::strip_fences`;
  tolerate JSON-wrapped replies.
- **No boxes:** hOCR/ALTO consumers get engine-tier boxes only — accepted
  and documented (model pages degrade to line-level geometry).

## Verification plan (reference PC)

1. `ollama pull deepseek-ocr` (6.7 GB) — or LM Studio with the llama.cpp
   GGUF set.
2. Run the P0 probe against the committed fixtures
   (`text-2page.pdf` render, `image-text.png`) plus a table-rich PDF;
   record RTF and token counts.
3. Compare output quality vs RapidOCR/WinRT on the same pages (the
   escalation threshold gets tuned from this data).

## Consequences

- One more settings block + one more provider call — no new dependencies.
- Document diagnostics gain a `model` counter/latency row (same shape as
  vision-polish diagnostics).
- The tier stays Windows/macOS/Linux portable (transport-only).
