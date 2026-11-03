# Proposal 109 — Document Input Pipeline: PDF & Multi-Page TIFF OCR for NV oOS Cognition

**Status:** Proposed — research complete (2026-11-03); implementation pending approval
**Date:** 2026-11-03
**Related:** Proposal 100 (screen OCR snippet, *implemented*), Proposal 102 (image & document OCR — file/clipboard input, accuracy ladder, exports; *proposed, plan complete — this proposal implements 102 §10's explicitly deferred "document input pipeline"*), 067 (dictation companion), 071 (video/file input), 076 (local VLM label polish — its multimodal transport is reused for OCR polish, §4.6), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Executive summary

Proposal 100 gave the app "hotkey → text on the clipboard" for live screen
pixels. Proposal 102 extends the same OCR stack to **any image file**
(clipboard, drag & drop, open-file) plus document-grade output. This
proposal closes the last input gap: **document files — PDF and multi-page
TIFF — recognized page-by-page, with the industry-standard two-pass hybrid
pipeline**:

1. **Born-digital pages are read, not OCR'd.** Where a PDF page already has
   a text layer, the page's text is extracted directly with per-character
   coordinates and reconstructed into reading order — instant, lossless,
   and free. (This is the `--skip-text` strategy that OCRmyPDF, the
   canonical open-source implementation, ships as its mixed-document
   default.)
2. **Scanned pages are rasterized at the industry-standard 300 DPI and
   OCR'd** with the exact engines, accuracy ladder, and reading-order
   reconstruction already delivered/planned by Proposals 100/102.
3. **Every page carries its provenance** (`text` | `ocr` | `mixed`), so the
   review surface and history show *how* each page was processed, and the
   searchable-PDF export reuses 102's emitter to give scanned PDFs a
   real text layer (the OCRmyPDF workflow) while born-digital pages keep
   theirs.
4. **Optional local VLM polish.** After the deterministic OCR cleanup, an
   opt-in Stage-2 correction pass sends the page crop + OCR text to a
   **local** multimodal model — reusing Proposal 076's shipped transport
   verbatim (`qwen2.5-vl-7b` default; Moondream-class for tiny hardware) —
   so misreads (wrong glyph, dropped currency symbol) are repaired against
   the actual pixels. Failure always falls back to deterministic text, and
   local-only providers keep it zero-egress.

Everything remains **zero-egress**: PDFs are parsed, rendered, and OCR'd
on-device; no pixel or byte leaves the machine. The architecture is ~60%
already shipped or planned — engines, preprocessing ladder, reading-order
module, exports, review surface, and history are Proposal 100/102
deliverables that this proposal reuses. The genuinely new surface is one
feature-gated dependency (PDFium via `pdfium-render`, BSD-licensed — the
same engine that renders PDFs in Chrome and WebView2) plus a per-page
hybrid orchestrator; the polish pass adds **no** new dependencies
(Proposal 076 already shipped the multimodal transport).

## 2. Problem statement

The Home "Transcribe media" panel (Proposal 071) answers "audio/video in →
transcript out". The image path (Proposal 102) answers "image in → text
out". The user-facing question that remains — *"can I drop a PDF here?"* —
is today answered with "unsupported media format": the Symphonia decode
seam rejects PDFs (and every image) because it only demuxes audio tracks.

The documents users actually have are PDFs and multi-page scans:

- **PDF is the interchange format for documents.** Contracts, invoices,
  research papers, manuals, scan batches. They split into two populations:
  **born-digital** (text already embedded — OCR is pure waste and
  degrades fidelity) and **scanned** (pages are images — extraction
  requires OCR). A correct pipeline must detect which page is which and
  route accordingly; treating every page as an image doubles latency and
  *lowers* quality on text pages, while treating every PDF as text
  silently returns nothing for scans.
- **Multi-page TIFF is the scanner/fax interchange format.** The `image`
  crate (which Proposal 102 uses for file decode) reads only the **first
  TIFF page** — a 12-page scan becomes 1 page of text. This is a
  well-known Rust ecosystem gap (users.rust-lang.org #134374) with a
  small, well-trodden solution: the lower-level `tiff` crate exposes
  multi-page iteration.
- **Reading order is still geometry.** Whether text comes from a PDF text
  layer or from OCR boxes, reconstructing columns/paragraphs is the same
  XY-cut problem Proposal 102 already solves — the document pipeline must
  feed both sources through the *same* layout module so behaviour is
  identical across sources.

The product story is the same one-liner as the rest of the app: **"any
document in → clean, structured, copyable text out"**, with per-page
provenance and the same review/export surface as images.

## 3. Industry research summary

### 3.1 Hybrid extraction — the two-pass standard

OCRmyPDF (the reference open-source implementation of "add a text layer to
scanned PDFs") formalizes the two-population problem
([ocrmypdf docs](https://ocrmypdf.readthedocs.io/en/latest/advanced.html),
[Debian manpage](https://manpages.debian.org/testing/ocrmypdf/ocrmypdf.1.en.html)):

- **`--skip-text` / `--mode skip`:** pages that already contain text are
  copied through untouched — no image processing, no OCR. This is the
  documented strategy for documents mixing born-digital and scanned
  content.
- **`--force-ocr`:** the opposite mode, rasterizing everything into an
  image-only PDF first.
- **Default hybrid:** OCR runs where needed; text pages keep their native
  layer.

**Synthesis (binding design principles):**

1. **Per-page text-layer detection is mandatory.** A page with ≥ ~50
   extractable characters is treated as `text`; below that, `ocr`
   (threshold tunable; the exact value is a golden-fixture gate in M1).
2. **Never OCR a page that has usable text.** It is slower *and* worse —
   extraction is lossless while OCR introduces recognition error.
3. **Provenance is first-class.** The pipeline reports per page whether
   text came from the layer (`text`) or from recognition (`ocr`), exactly
   like OCRmyPDF's per-page reporting.

### 3.2 Rasterization standards — 300 DPI is the floor

Published guidance converges sharply
([tesseractocr.org](https://tesseractocr.org/pdf/),
[UIUC LibGuides](https://guides.library.illinois.edu/OCR/bestpractices),
[imagetotable](https://imagetotable.ai/blog/how-to-improve-ocr-accuracy)):

- **300 DPI is the standard balance** for OCR rasterization; below ~200
  DPI accuracy drops sharply.
- **400–600 DPI** helps small fonts (< 8 pt) and dense tables;
  **above 600 DPI** returns ~2–3% for roughly 4× the pixels —
  diminishing.
- Rasterizing at the source's *intended* size is what makes this
  possible: PDFs carry page dimensions, so the renderer can target an
  exact DPI (screen captures cannot — another reason Proposal 100 skipped
  this entirely).

**Binding rule:** render OCR-bound pages at **300 DPI** (target long edge
≈ 3300 px for Letter/A4), clamped to a hard ceiling (≈ 5000 px long edge)
to bound per-page memory; expose a settings override for 400–600 DPI on
small-font documents.

### 3.3 PDF engine landscape & licensing — PDFium is the only clean choice

The app is proprietary (`UNLICENSED`, mirrored with `LICENSE.txt`).
Licensing therefore disqualifies most engines before engineering:

| Engine | License | Verdict |
|---|---|---|
| **MuPDF** (Artifex) | **AGPL-3.0** | ❌ Requires open-sourcing or a paid Artifex commercial license ([mupdf.readthedocs.io](https://mupdf.readthedocs.io/en/1.27.0/license.html), [file2markdown analysis](https://www.file2markdown.ai/blog/is-pymupdf-free-for-commercial-use)) |
| **Poppler** | GPL-2+ | ❌ GPL linking into a proprietary binary; Windows builds are second-class |
| **Ghostscript** | AGPL | ❌ Same as MuPDF |
| **PDFium** (Google) | **BSD-3 / Apache-2.0** | ✅ The renderer inside Chrome and WebView2 — the app already ships Chromium-derived binaries, so this is philosophically and legally consistent |
| `lopdf` / `pdf-extract` (pure Rust) | MIT/Apache | ⚠️ Parse text but **cannot rasterize pages** (no PDF graphics interpreter) — fine for 102's PDF *emitter*, insufficient for scanned-page input |

**Rust binding:** [`pdfium-render`](https://github.com/ajrcarey/pdfium-render)
is the maintained, idiomatic wrapper (0.9.x, 2026). Facts that matter for
this codebase (verify in the M0 spike):

- **Dynamic runtime binding** to the C++ library (prebuilts from
  [bblanchon/pdfium-binaries](https://github.com/bblanchon/pdfium-binaries)),
  with an explicit `bind_to_library(path)` escape hatch — the app can ship
  `pdfium.dll` / `libpdfium.dylib` next to the exe (the `externalBin` /
  resources mechanism already used for the macOS sidecar) and load it
  deterministically. Dev builds can use the crate's `fetch` feature.
- **`image_025` feature** (0.8.26+) pins the crate's `image` integration
  to **0.25 — the exact version the app already pins** via Proposal 100's
  `snippet` feature. No duplicate `image` tree.
- **Per-character text API** — `PdfPageText::chars()` returns characters
  with bounding boxes (`chars_for_object()` uses FPDFText_GetTextObject,
  introduced in PDFium 6611, to fix historical bounds issues) — the raw
  material the reading-order module needs.

### 3.4 Text-layer extraction & reading order

PDF text extraction is a reconstruction problem, not a string copy
([nutrient.io 2026 guide](https://www.nutrient.io/blog/how-to-extract-text-from-a-pdf-using-javascript/),
[mozilla/pdf.js #5643](https://github.com/mozilla/pdf.js/issues/5643),
[theneuralbase](https://theneuralbase.com/pdf-processing/learn/beginner/text-blocks-and-positioning/)):

- PDFs store **characters with absolute coordinates**, not words or lines;
  extraction clusters chars → words → lines from geometry. This is
  literally the same pipeline shape as Proposal 102's word→line
  clustering, so the document path must **feed PDF char boxes through
  102's `layout.rs`**, not build a second layout engine.
- PDF.js' own text layer assembles reading order from coordinates, and
  PDFBox's `setSortByPosition(true)` exists because raw stream order is
  *not* reading order — both confirm the XY-cut/sort-by-geometry approach
  as the canonical cheap baseline.
- **Coordinate origin differs:** PDF user space is bottom-left origin;
  OCR boxes are top-left. The y-flip transform is already documented in
  102 §3.5 for the PDF emitter — the same transform applies when mapping
  extracted char boxes into the shared word/line model.
- **ToUnicode caveat:** without a ToUnicode CMap, glyph ids from
  custom-encoded fonts map to garbage. PDFium resolves ToUnicode itself;
  the pipeline treats a page as `ocr` when extraction yields a high ratio
  of replacement/mapped-unusual characters (spike-measured heuristic).

### 3.5 Multi-page TIFF handling

- The `image` crate (and its `image-tiff` backend) decodes **only the
  first page** of a multi-page TIFF — an explicitly acknowledged ecosystem
  limitation ([users.rust-lang.org #134374](https://users.rust-lang.org/t/crate-that-manage-multipage-tiff/134374),
  [image-tiff](https://github.com/image-rs/image-tiff)).
- The low-level **`tiff` crate** (same image-rs family, already in the
  dependency tree transitively) exposes page iteration (`more_images()` /
  page-stepping decoder APIs) — a direct optional dependency, not a new
  library. Page order follows the IFD chain (the scan order), which is
  what users expect.
- Each decoded TIFF page feeds the same OCR core with the `scan` profile
  from 102 §3.3 — no TIFF-specific recognition logic.

### 3.6 Security & robustness standards

- **Encrypted PDFs:** PDFium exposes password-protection state
  (`is_password_protected()`). The pipeline fails cleanly with a
  user-facing error and an optional password parameter — never a hang,
  never a silent empty result.
- **PDF JavaScript:** document JS executes only if the *host* explicitly
  invokes it. `pdfium-render` never does, and neither will the app —
  Chrome's sandboxing lesson applies by default.
- **Malformed input:** PDF parsing is hostile-input parsing. The M0 spike
  runs a small fuzz corpus (mutated headers/offsets, truncated streams)
  and asserts clean `AppError`s, no panics, bounded memory.

### 3.7 Repo dependency survey — what the monorepo already ships, and why the app still uses Rust

The monorepo's **Node server addons** already carry a complete PDF/OCR
stack (verified against `package.json` files in the tree, 2026-11-03):

| Package | Where it lives today | Role there |
|---|---|---|
| `pdfjs-dist` ^4.9.155 | `addons/media-worker`; vendored in `addons/pro/assets/vendor/` | Server-side PDF parse/render |
| `pdf-parse` ^1.1.4 | `addons/media-worker`; vendored in `addons/pro/assets/vendor/` | Server-side text extraction |
| `pdf-lib` ^1.17.1 | `addons/media-worker`; vendored in `addons/pro/assets/vendor/` | PDF manipulation |
| `pdfkit` ^0.17.2 | `addons/media-worker`; vendored in `addons/pro/assets/vendor/` | PDF generation |
| `tesseract.js` ^5.1.1 | `addons/media-worker` | Server-side WASM OCR |
| `puppeteer` ^25.7.0 | `addons/media-worker` | Browser automation/rendering |
| `sharp` ^0.35.5 | `addons/media-worker`, `addons/cloud-worker` | Server-side image processing |
| `canvas` ^3.2.3 / 2.11.2 | `addons/media-worker`, `addons/canvas` | Server-side raster surface |
| `docx` ^9.5.1, `multer` ^2.5.0 | `addons/media-worker` | Office generation, upload handling |

**Verdict: stick with Rust/PDFium for the desktop app.** The Node stack
lives in *server-side services* the standalone app cannot assume:

1. **The app is standalone and offline.** `nvoos-dictate` runs on a user
   machine with no Node runtime and no media-stack service. Depending on
   `media-worker` would break the zero-install contract; bundling Node is
   a non-starter for a 50 MB Tauri app.
2. **pdfjs-dist could run in the WebView2** (it *is* the browser library),
   but the pipeline would split across the bridge: JS renders pages, then
   ships megapixel buffers over IPC to the Rust OCR engines — strictly
   worse than PDFium rendering in-process, and against the app's
   locked-down CSP (`connect-src ipc:`, no remote).
3. **House precedent is pure Rust.** No FFmpeg sidecar (Symphonia for
   audio), no Node subprocess (chromiumoxide, not puppeteer, for the
   browser agent), Rust OCR engines (100). PDFium matches the pattern;
   it is also the engine inside WebView2, which the app already ships.
4. **The emitters are already settled.** 102's survey rejected
   `pdf-lib`/`pdfkit` for searchable-PDF output (no Tr 3 invisible-text
   exposure) in favour of `lopdf`; `tesseract.js` was rejected as a
   fourth, weaker WASM engine. Nothing in the survey changes those calls.
5. **Real reuse is validation, not execution.** `pdf-parse`/`pdfjs-dist`
   outputs on the fixture corpus become a *cross-check oracle* for the
   Rust extraction path; and an **optional media-worker offload** (the
   Proposal 079 pattern, local-network only) is recorded as a deferred
   follow-up for very long documents (§10).

### 3.8 Local multimodal polish — the 2026 model landscape

Proposal 076 already proved the transport: `vision/polish.rs` crops,
PNG-encodes, and sends images to a local OpenAI-compatible server
(`LocalOpenAiProvider`), with LM Studio's `qwen/qwen2.5-vl-7b` returning
(fenced-JSON-tolerated) structured labels at ~500 ms on the reference PC.
OCR polish is the same call with a different prompt. Current model
guidance ([roboflow](https://blog.roboflow.com/local-vision-language-models/),
[localaimaster](https://localaimaster.com/blog/local-ai-vision-tasks),
[bestllmfor](https://bestllmfor.com/best/vision-multimodal-tested/)):

| Model | Class | Role here |
|---|---|---|
| **Qwen2.5-VL 7B** | ~7B | **Default** — the 2026 consensus pick for pure OCR/document correction; already the app's VLM default (076) |
| **Moondream 2** | ~1.9B | Featherweight CPU option — captioning/light correction on tiny hardware; llama.cpp GGUF available |
| **SmolVLM2 / SmolVLM-500M** | ≤2.2B | Even lighter fallback; weaker on dense documents |
| Llama 3.2 Vision 11B / MiniCPM-V 2.6 / Gemma 3 | 8–12B | Mid-range alternatives; no reason to prefer over the default |
| Qwen3-VL | 2026 line | **Monitor, don't adopt** — better reasoning/charts, but 2.5-VL remains the cheaper recommendation for pure OCR |

Design rules carried over from 076 and the dictation Stage-2 contract:
prompt is text-only correction with the crop as context (never identity,
never facial work — the no-facial-recognition bright line stays); polish
runs on a dedicated thread with a timeout budget; **failure always falls
back to the deterministic OCR text and can never block a result**; the
provider list defaults to local servers so zero-egress holds unless the
user explicitly configures a cloud provider.

## 4. Proposed scope

### In scope

1. **PDF input (M1–M2):** open-file picker + drag & drop for `.pdf`,
   routed to the new hybrid pipeline: per-page text-layer detection →
   text extraction (char boxes → shared layout) or 300-DPI rasterization →
   existing OCR engines. Per-page provenance map returned to the UI.
2. **Multi-page TIFF input (M2):** same entry points, page iteration via
   the `tiff` crate, every page through the `scan` profile OCR path.
   (Single-page TIFF already belongs to 102's image path — no overlap.)
3. **Page-progress + cancel (M2):** `document://progress` events
   (`page i/n`, per-page phase) and a cancel token; pages are rendered,
   OCR'd, and released one at a time so memory is bounded by a single page
   regardless of document length.
4. **Document review surface (M3):** a Home "Document OCR" panel
   (sibling of the Proposal 071 media panel, consistent with 102 D5's
   main-window placement) with the shared review component: page list,
   per-page `text`/`ocr` badge, combined copy, exports. The media panel's
   helper copy gains one line: "PDFs and scans use Document OCR."
5. **Exports & history (M4):** all 102 M4 emitters (TXT/MD/hOCR/ALTO)
   plus **searchable-PDF export**: the original PDF with an invisible
   text layer added *only on `ocr` pages* (the OCRmyPDF workflow; born-
   digital pages keep their native layer). History rows carry the
   document source badge + first-page thumbnail.
6. **OCR VLM polish (M4, opt-in, default off):** a Stage-2 multimodal
   correction pass after the deterministic OCR cleanup — page crop +
   OCR text to a local VLM via the Proposal 076 transport (`Settings.ocr.polish_enabled` +
   `polish_model`; local LM Studio/Ollama by default). Per-page
   provenance gains a `polished` flag; failure always falls back to the
   deterministic text.
7. **Config, IPC, features (M4):** `Settings.ocr_document` (dpi,
   text-layer threshold, page cap, polish block); new IPC commands
   (`pick_document`, `transcribe_document`, `cancel_document`,
   `export_document_*`); new Cargo feature **`document = ["snippet",
   "dep:pdfium-render", "dep:tiff"]`** (ONNX engine rides the existing
   `snippet-onnx`; polish needs no new deps); complete-union build list
   gains `document`.
8. **Docs (M5):** implementation plan, skill + README updates, NOTICE.md
   entry for the PDFium binary (BSD-3), matching the 100/102 trail.

### Out of scope (deferred — see §10)

Table-structure recognition (PP-StructureV3-class), form fields and
annotations, signatures, Office formats (DOCX/PPTX — see §10), EPUB,
PDF/A archival conversion, handwriting guarantees, cloud OCR, any network
egress.

## 5. Architecture

```
snippet/                      (existing — Proposal 100/102)
├── input_image.rs            (102 — single-image decode + clipboard read)
├── preprocess.rs             (102 — profile-driven accuracy ladder)
├── layout.rs                 (102 — word/line clustering + recursive XY-cut)
├── export/                   (102 — txt/md/hOCR/ALTO/searchable-PDF emitters)
└── controller.rs             (extended — source type, page progress, cancel)

document/                     (NEW — feature `document`)
├── mod.rs                    (always-compiled: DocumentResult, available())
├── pdf.rs                    (pdfium-render: open, password check,
│                              per-page char extraction + 300-DPI render)
├── tiff.rs                   (tiff crate: page iteration → BGRA frames)
├── hybrid.rs                 (per-page router: text-layer heuristic →
│                              extract | render+OCR; provenance map)
├── polish.rs                 (opt-in Stage-2: page crop + OCR text →
│                              local VLM via the 076 LocalOpenAiProvider
│                              transport; deterministic-text fallback)
└── controller.rs             (DocumentController on `nvoos-document`
                               worker: one-page-at-a-time loop, progress,
                               cancel; snapshot + events into AppState)
```

- **One pipeline, two sources.** Extracted PDF chars and OCR word boxes
  both enter `layout.rs` as the same normalized line model — reading
  order, paragraph grouping, and every export behave identically whether a
  page was read or recognized.
- **Pure-function core.** `hybrid.rs` decisions (char-count threshold,
  replacement-char ratio) are deterministic over extracted metrics and
  golden-testable, same discipline as the cleanup pipeline and 102's
  preprocess/layout modules.
- **Polish reuses 076 verbatim.** `document/polish.rs` calls the same
  multimodal `LocalOpenAiProvider` path as `vision/polish.rs` (crop →
  base64 PNG → chat request) with a correction prompt — the only new
  artifact is the prompt and the settings block; no new crate, no new
  thread design.
- **Bounded memory.** The controller holds at most one rendered page
  (~3300×2550×4 ≈ 33 MB at 300 DPI) plus the document handle; the page
  loop releases each frame before rendering the next.
- **Zero egress invariant unchanged.** PDFium and all OCR engines run
  on-device; the file is read from disk, results land on the clipboard /
  explicit exports.
- **macOS path.** `libpdfium.dylib` via the same `externalBin`/resources
  mechanism as the audio-helper sidecar; Apple Vision remains the OCR
  engine; document routing logic is platform-neutral Rust.

## 6. Milestones

| M | Deliverable | Exit gate |
|---|---|---|
| **M0** | Spike: pdfium-render dynamic load, 300-DPI render of a fixture, `chars()` extraction, `image_025` composition with the app's `image` 0.25 pin, fuzz-corpus no-panic check, binary-size measurement | Fixture page renders to a pixel buffer; char boxes extract with coordinates; decision table D1–D6 closed (see implementation plan) |
| **M1** | PDF text-layer path: hybrid router, char→word→line normalization into `layout.rs`, per-page provenance, encrypted-PDF error | Born-digital fixture returns lossless text with correct column order; scanned fixture routes to `ocr`; password-protected fixture errors cleanly |
| **M2** | Render+OCR path at 300 DPI + multi-page TIFF + page loop (progress, cancel, bounded memory) | Scanned-PDF fixture CER beats a raw 72-DPI baseline; 12-page TIFF returns 12 pages in order; cancel mid-document releases resources |
| **M3** | Home "Document OCR" panel, page list with `text`/`ocr` badges, shared review component, media-panel copy pointer | Full UI pass: pick PDF → progress → review → copy; provenance badges correct per page |
| **M4** | Exports (incl. searchable PDF with text layer on `ocr` pages only), history provenance, opt-in local VLM polish, settings, IPC, `document` feature + complete-union wiring | Searchable-PDF export: Ctrl+F finds OCR'd fixture text, born-digital pages keep native layer, page renders pixel-identical; polish: fixture CER improves with the model live, and a killed LM Studio server degrades to deterministic text without blocking |
| **M5** | Docs + full gate matrix | `cargo fmt`, clippy `-D warnings` (all feature sets), tests, `npm run build`, complete-union debug exe + boot smoke + PE marker scan |

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **PDFium binary shipping** (size, per-platform plumbing, updater) | Bundle bblanchon prebuilts via the existing resources/externalBin path; measure at M0; `fetch` feature for dev; fallback plan: download-on-first-use into the app data dir (still on-device, explicit consent) |
| **Text-layer fidelity gaps** (custom encodings, ligatures, no ToUnicode) | Hybrid router's replacement-char heuristic demotes bad pages to `ocr`; fixture suite covers CJK + custom-encoded samples |
| **Per-page memory** at 300 DPI on long documents | Hard one-page-at-a-time discipline in the controller + long-edge clamp (~5000 px); page cap setting |
| **`pdfium-render` maintenance pace / API drift** | Pin a known-good release; all pdfium-facing calls isolated in `document/pdf.rs` (the seam the skill's engine-track discipline already applies to STT) |
| **Extraction vs rendered order divergence** | Never trust stream order — every page goes through the shared geometry-based `layout.rs`, the same path OCR boxes use |
| **Multi-column / table PDFs** | XY-cut is the conservative baseline; tables degrade to column-major text — accepted and deferred to PP-StructureV3-class tooling (same stance as 102) |
| **Scope creep into full document AI** (summarize-PDF, chat-with-PDF) | Explicit out-of-scope line; the `summarize` hook exists on the review surface but consumes the extracted text only |
| **VLM polish latency / flaky local server** | Reuses 076's dedicated polish thread + timeout budget; failure always falls back to deterministic text (the same Stage-2 contract as dictation polish) |

## 8. Privacy & compliance

- **Zero egress preserved and extended:** PDFs/TIFFs are opened, rendered,
  and OCR'd entirely on-device. PDFium performs no network I/O; the app
  adds none.
- **Polish stays local by default:** the OCR polish provider list defaults
  to the on-device local servers (LM Studio / Ollama). The cloud providers
  (`nvoos`, `openai_compat`) are an explicit opt-in, surfaced as egress in
  the settings UI.
- **No hidden writes or telemetry:** documents are read-only inputs;
  exports occur only on explicit user action. Intermediates (rendered
  pages) live in memory and are never written to disk — extending the
  frozen-frame never-touches-disk rule from Proposal 100.
- **Encrypted documents** are never silently processed: a password is an
  explicit user action per document, and credential-free failure is the
  default.
- **Licensing clean:** PDFium is BSD-3/Apache-2.0 — compatible with the
  app's proprietary license, unlike MuPDF/Poppler/Ghostscript (AGPL/GPL).
  `NOTICE.md` gains the PDFium attribution entry, matching the existing
  model-weight license notices (YOLO, sherpa-onnx, et al.).
- **History thumbnails** follow 102's bounded-thumbnail + retention rules.

## 9. Validation & acceptance

Fixture suite (golden oracle, same discipline as the cleanup pipeline and
102's fixtures):

1. Born-digital multi-page PDF (3+ pages, embedded text) — text path, no
   OCR, column order correct, extraction lossless vs reference.
2. Scanned PDF (300-DPI grayscale scan) — OCR path; CER measurably better
   than a 72-DPI raster of the same page.
3. Mixed PDF (2 text + 2 scanned pages) — per-page provenance map exactly
   `text/ocr` as labelled.
4. Two-column scanned page — column-major reading order via shared
   `layout.rs`.
5. Multi-page TIFF (12 pages) — 12 pages, IFD order, `scan` profile.
6. Encrypted PDF — clean error, no panic, optional-password path works.
7. Malformed PDF corpus (mutated offsets/truncations) — clean `AppError`s,
   bounded memory, no crash (fuzz spot-check).
8. CJK PDF with ToUnicode — extracted text renders correctly through the
   char-box path.
9. Regression — audio/video transcribe path untouched; `screen` profile
   OCR byte-identical to Proposal 100 behavior.

Gates: `cargo fmt --check`, clippy `-D warnings` (default + `document` +
all existing feature sets), unit + integration tests, `npm run build`,
complete-union debug exe build + 30 s boot smoke + `verify_complete_build.ps1`
marker scan — mirroring the Proposal 100/102 validation matrix.

## 10. Deferred / future phases

- **Table structure recognition** — PP-StructureV3-class layout models
  (shared deferral with 102).
- **Optional media-worker offload** — the monorepo's `addons/media-worker`
  already runs a server-side PDF/OCR stack (pdfjs-dist, tesseract.js,
  pdf-lib; §3.7); an opt-in **local-network** offload (the Proposal 079
  ingest-offload pattern) could route long documents there while the
  in-app PDFium pipeline stays the default. Not a dependency, ever.
- **Qwen3-VL-class polish models** — monitor; adopt only behind the
  benchmark gate when 2.5-VL stops being the OCR recommendation.
- **Form fields & annotations** — extracting/rendering form state and
  annotations is a separate data model, not an OCR concern.
- **Office documents** — DOCX/PPTX/XLSX are ZIP+XML containers with real
  text layers; extraction is a *different* (and simpler) pipeline than
  OCR and deserves its own proposal rather than riding this one.
- **EPUB** — same reasoning as Office formats.
- **PDF/A archival conversion** — OCRmyPDF's `--output-type pdfa` class
  of feature; depends on the 102/109 PDF emitters maturing.
- **Document-summarize & chat-with-document** — the extracted text already
  flows through the existing summarize hook; deeper document AI stays out.

## 11. References

- OCRmyPDF hybrid/skip-text strategy — https://ocrmypdf.readthedocs.io/en/latest/advanced.html
- OCRmyPDF manpage (skip-text / force-ocr semantics) — https://manpages.debian.org/testing/ocrmypdf/ocrmypdf.1.en.html
- OCR rasterization DPI guidance — https://tesseractocr.org/pdf/
- UIUC OCR best practices (300 DPI) — https://guides.library.illinois.edu/OCR/bestpractices
- DPI diminishing returns (400–600 DPI for small fonts) — https://imagetotable.ai/blog/how-to-improve-ocr-accuracy
- MuPDF AGPL license — https://mupdf.readthedocs.io/en/1.27.0/license.html
- AGPL analysis for closed-source products — https://www.file2markdown.ai/blog/is-pymupdf-free-for-commercial-use
- pdfium-render crate (0.9.x, dynamic binding, image_025 feature) — https://github.com/ajrcarey/pdfium-render
- pdfium-render changelog (chars_for_object / FPDFText_GetTextObject) — https://crates.io/crates/pdfium-render
- PDFium prebuilt binaries — https://github.com/bblanchon/pdfium-binaries
- PDF text extraction & reading order (2026 guide) — https://www.nutrient.io/blog/how-to-extract-text-from-a-pdf-using-javascript/
- pdf.js text-layer coordinates — https://github.com/mozilla/pdf.js/issues/5643
- PDFBox sortByPosition rationale — https://www.tutorialkart.com/pdfbox/how-to-extract-coordinates-or-position-of-characters-in-pdf
- PDF char-coordinate reconstruction model — https://theneuralbase.com/pdf-processing/learn/beginner/text-blocks-and-positioning/
- Multi-page TIFF gap in the `image` crate — https://users.rust-lang.org/t/crate-that-manage-multipage-tiff/134374
- image-tiff (image-rs) — https://github.com/image-rs/image-tiff
- tiff crate (multi-page decoding) — https://docs.rs/tiff
- Local VLM landscape 2026 — https://blog.roboflow.com/local-vision-language-models/
- Local VLM OCR tasks (Qwen2.5-VL default, Moondream featherweight) — https://localaimaster.com/blog/local-ai-vision-tasks
- Qwen3-VL vs Qwen2.5-VL for OCR workloads — https://bestllmfor.com/best/vision-multimodal-tested/
- Local multimodal via llama.cpp GGUF builds (Gemma 3/4, InternVL, Moondream, SmolVLM) — https://www.promptquorum.com/power-local-llm/local-vision-models-llava-ollama-2026
