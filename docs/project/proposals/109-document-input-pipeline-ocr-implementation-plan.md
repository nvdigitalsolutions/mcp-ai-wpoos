# 109 — Document Input Pipeline (PDF & Multi-Page TIFF OCR): Implementation Plan

**Status:** Plan — complete; **M0 spike executed and verified** (2026-11-03); M1 pending approval
**Date:** 2026-11-03
**Related:** `109-document-input-pipeline-ocr-proposal.md`, Proposal 102 implementation plan (`102-image-document-ocr-enhancement-implementation-plan.md` — this plan pulls two of its module specs forward, see §9), Proposals 100/067/071/076, `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Decisions (recommended; owner confirmation needed)

| # | Decision | Recommendation | Rationale |
|---|---|---|---|
| **D1** | PDF engine | **`pdfium-render` 0.9.x, pinned API feature `pdfium_7881`, features `["image_025", "thread_safe"]`, `default-features = false`** | PDFium is the only license-clean engine (BSD-3; MuPDF/Poppler/Ghostscript are AGPL/GPL — proposal §3.3). `image_025` matches the app's `image` 0.25 pin exactly (no duplicate `image` tree); `thread_safe` mutex-wraps all Pdfium calls (Pdfium is not thread-safe; the crate serializes). Version pinning mirrors the `ort` rc.9 discipline |
| **D2** | Pdfium binary provisioning | **Pinned download at bundle time, never committed**: bblanchon `pdfium-win-x64.tgz` (and the macOS equivalent later), SHA-256 pinned in `scripts/fetch-pdfium.ps1` + `NOTICE.md` attribution. Shipped as a Tauri `bundle.resources` entry (Windows) / `externalBin`-style resource (macOS, `libpdfium.dylib`), loaded via `Pdfium::bind_to_library` with an exe-relative path resolved in `document/pdf.rs`. Dev/spike: `pdfium.dll` next to the exe in `target/` (gitignored) | Runtime-dynamic binding is the crate's designed mode; committing a ~40 MB binary is worse than a pinned, checksummed fetch. The spike proves the load path before any bundling work |
| **D3** | Feature flag | **`document = ["snippet", "dep:pdfium-render", "dep:tiff"]`** (new). ONNX OCR rides the existing `snippet-onnx`; no new flag for the VLM polish (076's transport is always compiled with the cleanup providers) | House convention: every surface cfg-gated; `snippet` carries the WinRT/OCR engines; `document` adds only what is genuinely new |
| **D4** | Sequencing vs 102 (not yet implemented) | **109 M1 implements the shared `snippet/layout.rs` (102's D7 spec) as its first deliverable**, because 102 is approved-but-unimplemented and the text-layer path needs reading-order reconstruction day one. 109 M2 pulls forward 102's `preprocess.rs` **`scan` profile only** (upscale + Otsu/adaptive binarize; deskew deferred to 102). If 102 lands first, 109 adopts its modules verbatim and deletes its own copies | The proposal forbids a second layout engine (proposal §3.4); pulling the spec forward avoids drift. The `screen` profile stays byte-identical to Proposal 100 behavior in all cases |
| **D5** | Hybrid-router thresholds | `text` page = ≥ 50 extracted chars AND replacement-char ratio < 5%; else `ocr`. Constants in `document/hybrid.rs` behind unit-tested pure functions; settings-overridable later | OCRmyPDF's skip-text strategy (§3.1); the replacement heuristic demotes pages whose fonts lack a ToUnicode map (`PdfPageTextChar::has_unicode_map_error()` exists as of 0.9.6) |
| **D6** | Rasterization | **300 DPI target**: pixel dims = page points × (300/72), via `PdfRenderConfig::set_target_width` + `set_maximum_height`; hard clamp long edge ≤ 5000 px; settings override 400–600 for small-font docs | §3.2 standards; one-page-at-a-time bounds memory (~33 MB/page Letter @ 300 DPI) |
| **D7** | Multi-page TIFF | **`tiff` 0.9 direct optional dep** — `Decoder::new` + `more_images()`/`read_image()` page loop. Single-page TIFF stays on 102's `image`-crate path | `image` decodes only the first TIFF page (§3.5); `tiff` is already transitively in the tree via `image-tiff`, so the addition is near-free |
| **D8** | Concurrency | **Dedicated `nvoos-document` worker thread** (mirrors `nvoos-snippet`/`nvoos-vision-polish`): owns the `Pdfium` instance, the document handle, and a cancel token; only `Send` handles (progress channel, result `OnceCell`/channel, `Arc<AtomicBool>`) cross into `AppState` | Pdfium is not thread-safe; `cpal`-style worker discipline (skill: "Rust pitfalls") applies to the new C++ runtime too |
| **D9** | VLM polish | **Reuse 076 verbatim**: `document/polish.rs` calls `LocalOpenAiProvider`'s multimodal path (`describe_crop`-pattern) with a correction prompt — crop (≤1568 px long edge, PNG base64) + OCR text in, corrected text out. Default model `qwen/qwen2.5-vl-7b`; local LM Studio/Ollama only by default; timeout budget + deterministic-text fallback (the Stage-2 contract) | Proposal §3.8; zero new dependencies; the no-facial-recognition bright line carries over |
| **D10** | Searchable-PDF export (v1) | **Rebuild via 102's emitter** (D9 in 102's plan: `lopdf`, original-page image + invisible text layer) for documents where **any** page was OCR'd; pure-text documents export TXT/MD/hOCR/ALTO only. Vector-preserving in-place relayer (the OCRmyPDF approach) is deferred — it requires content-stream surgery `lopdf` is not designed for | Ship the honest v1; the proposal §4.5 gate (Ctrl+F finds OCR'd text) is met by the rebuild path. Documented fidelity tradeoff: exported PDF rasterizes pages |

**Approval gates before M1:** D1 (engine pin), D2 (binary provisioning), D3 (feature name), D4 (sequencing pull-forward). D5–D10 are implementation detail; D10 reopens at M4.

## 2. Dependency survey — delta over 102's survey

| Crate / asset | Status | Use |
|---|---|---|
| `pdfium-render` 0.9.x | **NEW** (optional) | Document open, per-page text extraction (segments/chars with boxes), 300-DPI rendering. API verified against the crate README (2026-11-03): `Pdfium::default()` / `Pdfium::bind_to_library(Pdfium::pdfium_platform_library_name_at_path(…))`, `load_pdf_from_file(path, password)`, `PdfRenderConfig::new().set_target_width(..).set_maximum_height(..)`, `page.render_with_config(..)?.as_image()?.into_rgb8()`, `page.text() → PdfPageText{ .all() → segments, .chars() → PdfPageTextChar{ text(), origin_x/y, has_unicode_map_error() } }` |
| `pdfium.dll` (bblanchon prebuilt) | **NEW binary asset** | Runtime-loaded; pinned SHA-256; never committed (D2) |
| `tiff` 0.9 | **NEW** (optional; already transitive via `image-tiff`) | Multi-page TIFF page iteration |
| `lopdf` | Per 102 D9/D10 (its `snippet-pdf` feature) | Searchable-PDF rebuild emitter at M4 — not part of the `document` feature |
| `pdf-extract`, `mupdf`, `poppler`, Ghostscript | **Rejected** | §3.3/§3.7 of the proposal (license, no raster, or Node-only) |
| `pdf-parse` / `pdfjs-dist` (in `addons/media-worker`) | **Cross-check oracle only** | M1 fixtures compare Rust extraction against pdf-parse output; optional media-worker offload deferred (§10) |
| `pdfium-render` `fetch` feature | Dev-only convenience | Not enabled; the pinned-fetch script (D2) keeps builds offline-capable |

## 3. Module specs

### 3.1 `document/mod.rs` (always compiled)

```rust
//! Document input pipeline (Proposal 109): PDF + multi-page TIFF OCR.
/// Whether the document pipeline is compiled into this build.
pub fn available() -> bool;                       // cfg!(feature = "document")

/// Per-page provenance, serialized to the frontend.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub enum PageProvenance { Text, Ocr, Polished }   // "text" | "ocr" | "polished"

/// One document result handed to the review surface.
#[derive(Debug, Clone, Serialize)]
pub struct DocumentResult {
    pub path: String,
    pub pages: Vec<DocumentPage>,                 // ordered
    pub provenance: Vec<PageProvenance>,
    pub page_count: usize,
    pub duration_ms: i64,
}
#[derive(Debug, Clone, Serialize)]
pub struct DocumentPage {
    pub index: usize,
    pub text: String,                             // post-layout, post-polish
    pub provenance: PageProvenance,
}
```

### 3.2 `document/pdf.rs` (`#[cfg(feature = "document")]` real body + stub)

- `open(path, password) -> AppResult<PdfHandle>` — `Pdfium::bind_to_library(exe_relative)`
  with `Pdfium::default()` fallback; encrypted docs → `AppError::Document("document is password-protected")` (no silent processing).
- `extract_chars(page) -> Vec<ExtractedChar>` — `PdfPageText::chars()` mapped to
  `{ text, x, y, width, height, has_unicode_map_error }`, y-flipped to top-left origin (proposal §3.4).
- `render_page(page, dpi) -> AppResult<RgbImage>` — 300-DPI config per D6, `into_rgb8()`.
- `page_count`, `page_size_points` accessors.
- **Exe-relative library resolution:** `current_exe().parent()` + `pdfium.dll`/`libpdfium.dylib`; on failure, `Pdfium::default()` (system search). Both paths unit-tested with a missing-library case asserting a clean `AppError`, never a panic.

### 3.3 `document/tiff.rs` (`#[cfg(feature = "document")]`)

- `pages(path) -> AppResult<Vec<RgbImage>>` — `tiff::decoder::Decoder` page loop
  (bounded: the page cap from settings, default 200); each page converted to
  RGB8 for the shared OCR core. Page order = IFD chain order.

### 3.4 `document/hybrid.rs` (pure functions, always compiled where testable)

- `classify(chars: &[ExtractedChar]) -> PageClass` — the D5 thresholds.
- `chars_to_lines(chars) -> Vec<Line>` — char→word→line clustering calling the
  shared `layout` module (§3.5); pure + golden-tested.
- `route(page) -> RoutedPage { Text(String) | Ocr(RgbImage) }` — the per-page
  decision the controller consumes.

### 3.5 Shared `snippet/layout.rs` (pulled forward from 102's D7 spec)

- `Line { words: Vec<Word>, bbox }` / `Word { text, bbox }` over a normalized
  box type shared by PDF chars and OCR det boxes.
- `cluster_words(boxes) -> Vec<Line>`, `paragraphs(lines) -> Vec<Paragraph>`,
  `xy_cut(lines, page_dims) -> reading order`.
- Golden fixtures from 102's plan: two-column fixture reads column-major;
  blank-line structure survives. The `screen` OCR path keeps calling the
  Proposal 100 word-assembly code until 102 adopts this module — **no change
  to `screen` behavior in this plan**.

### 3.6 `document/polish.rs` (`#[cfg(feature = "document")]`)

- `polish_page(crop: &RgbImage, text: &str, provider, model) -> AppResult<String>`
  — crop→PNG→base64, multimodal chat request through `LocalOpenAiProvider`
  (076's `describe_crop` shape), fenced-JSON-tolerant parse, timeout budget.
  Deterministic-text fallback on any error (never blocks a result).

### 3.7 `document/controller.rs` (`#[cfg(feature = "document")]`)

- `DocumentController` on the `nvoos-document` worker: `run(path, settings,
  cancel)` — open → per-page `hybrid::route` → extract or render+OCR →
  optional polish → release page pixels → progress events → `DocumentResult`.
- Progress: `document://progress { page, page_count, phase: "extract" |
  "render" | "ocr" | "polish", fraction }`; cancel via `Arc<AtomicBool>`.
- Diagnostics block in `get_diagnostics`: `document` availability, pdfium
  library status, last-run stats (pages, text/ocr split, per-page ms).

## 4. IPC & settings schema

### Settings (`config.rs`, serde-default, backward-compatible)

```rust
pub struct DocumentConfig {
    pub dpi: u32,                  // 300 default; 400–600 for small fonts
    pub text_page_min_chars: usize, // 50
    pub max_pages: usize,          // 200
    pub polish_enabled: bool,      // false
    pub polish_model: Option<String>, // None → provider default (qwen2.5-vl-7b)
}
```

### IPC commands (`lib.rs::run` registration)

| Command | Shape | Notes |
|---|---|---|
| `pick_document` | `(window) -> Option<String>` | Dialog filter `pdf, tif, tiff` |
| `transcribe_document` | `(state, path) -> DocumentResult` | Async; emits `document://progress` |
| `cancel_document` | `(state)` | Sets the controller's cancel flag |
| `export_document_txt` / `_markdown` / `_hocr` / `_alto` / `_searchable_pdf` | `(state, result_id, dest) -> bool` | M4; PDF via 102's emitter |

### Complete-union build (updated at M1, when lib code exists)

`npm run tauri -- build --debug --no-bundle --features fastconformer,parakeet,whisper,silero-vad,camera,vision,agent,mcp,snippet,snippet-onnx,document`
— `verify_complete_build.ps1` gains a `document (pdfium)` marker matching a
lib string (e.g. the `DocumentResult` serde variant names or an error string);
the skill's complete-build line is updated in the same change.

## 5. Frontend spec (M3)

- Home gains a **"Document OCR"** panel (sibling of the media panel): pick
  button, per-page progress, page list with `text`/`ocr`/`polished` badges,
  shared review component (from 102 M5: preview, copy-all, per-line select,
  export buttons).
- `src/lib/ipc.ts` additions mirror the command table; `onDocumentProgress`
  event listener alongside the existing `onMediaProgress` pattern.
- Media-panel copy gains the pointer line from proposal §4.4.

## 6. Test conventions

- **Unit (always):** `hybrid.rs` thresholds + clustering (feature-gated tests
  run under `--features document`), `layout.rs` golden fixtures, settings
  serde round-trip, URL-free pure functions.
- **Integration (`src-tauri/tests/`):** fixture PDFs committed under
  `src-tauri/tests/fixtures/` (born-digital, scanned-as-image, mixed,
  encrypted, malformed, two-column, CJK, 12-page TIFF) generated by a
  checked-in script, not hand-edited. Oracle cross-checks vs
  `pdf-parse` output recorded in the fixture README.
- **Ignored/hardware:** none (documents need no devices) — the full matrix
  runs in CI.
- **Gates:** `cargo fmt --check`; `clippy --all-targets -- -D warnings`
  default + `--features document`; `cargo test` default + `document`; `npm run
  build`; complete-union exe + boot smoke + marker scan.

## 7. M0 spike script (exact — executed 2026-11-03, results recorded)

```bash
# 1. Deps + feature (Cargo.toml edit) — done
#    pdfium-render = { version = "0.9", default-features = false,
#      features = ["pdfium_7881", "image_025", "thread_safe"], optional = true }
#    tiff = { version = "0.9", optional = true }
#    document = ["snippet", "dep:pdfium-render", "dep:tiff"]
# 2. Binary (pinned tag; SHA-256 recorded below)
curl -L -o /tmp/pdfium.tgz \
  https://github.com/bblanchon/pdfium-binaries/releases/download/chromium%2F7881/pdfium-win-x64.tgz
#    NOTE: the Windows archive layout is bin/pdfium.dll (not lib/) — the dll
#    is copied next to the exe for the spike (target/ is gitignored).
#    pdfium.dll: 7,211,520 bytes, sha256
#    79d4676b656cfb1abcea88f9ade3b4b0826c5200382db5f4ec72a636c598c118
# 3. Fixtures — scripts/make_doc_fixtures.py (checked-in generator) — done
python scripts/make_doc_fixtures.py
#    → src-tauri/tests/fixtures/text-2page.pdf (910 B), malformed.pdf (455 B)
# 4. Spike example — done, verified
cargo run --features document --example document_probe -- tests/fixtures/text-2page.pdf
#    VERIFIED OUTPUT: pages: 2; page 0 612x792pt, 44 chars, unmapped=0,
#    per-char origins/bounds correct, rendered 2550x3300 (exact 300-DPI
#    target); malformed.pdf → PdfiumLibraryInternalError(FormatError),
#    exit 1, no panic.
# 5. Gates — all green: cargo fmt --check; clippy --all-targets
#    --features document -- -D warnings; cargo test --features document;
#    cargo test (default, 117 lib + 17 golden + 6 history + 5 validation,
#    1 ignored hardware test).
```

**API corrections vs the README (verified against pdfium-render 0.9.5
source):** `PdfPageTextChars::iter()` yields **owned** `PdfPageTextChar`
(no `.cloned()`); chars borrow `PdfPageText`, so extract owned fields
inside the closure; `origin()`/`origin_x()` return `Result<PdfPoints,
PdfiumError>`; `PdfRect` exposes `width()`/`height()` accessors;
`PdfPoints { value }` is a public-field struct; `set_target_width`/
`set_maximum_height` take `i32`; `has_unicode_map_error()` is 0.9.6-only —
0.9.5 uses `unicode_char() == None` as the unmapped-glyph signal (D5).

**Deferred from M0 to M1:** the encrypted-PDF fixture (needs a correct
Standard-Security-Handler RC4 writer in the generator); the M0 exit
criteria otherwise met in full.

## 8. Gate matrix & complete-union updates

M1 also updates: `.agents/skills/mcp-ai-wpoos-dictation-app` complete-build
command + feature notes, `scripts/verify_complete_build.ps1` markers,
`NOTICE.md` (PDFium BSD-3), `src-tauri/README.md` (document feature),
`docs/decisions/` ADR (document engine choice), and the
`docs/project/proposals/` trail. The example and `document/` module docblocks
follow the existing `vision/`/`snippet/` style.

## 9. Sequencing & rollback

- **M0 (this session):** D1–D4 implemented as spike (feature + deps + binary +
  probe + fixture generator). No lib code lands — the complete-union command
  and verify markers are **not** touched until M1 adds lib code (a marker
  update before that would false-alarm on the main exe).
- **M1:** `document/mod.rs` + `pdf.rs` + `hybrid.rs` + shared `snippet/layout.rs`
  (pull-forward per D4). Pure-text PDFs work end-to-end via the probe; first
  integration tests land.
- **M2:** render+OCR path (needs the `scan` profile pull-forward from 102),
  `tiff.rs`, controller + progress/cancel, `document` IPC.
- **M3:** frontend panel. **M4:** exports, polish wiring, settings, history.
  **M5:** docs + gates.
- **Rollback:** every module is feature-gated; `document` off = zero code
  compiled. The 102 pull-forwards are additive (`layout.rs` unused by `screen`
  until 102 adopts it) — removing them cannot affect Proposal 100 behavior.
