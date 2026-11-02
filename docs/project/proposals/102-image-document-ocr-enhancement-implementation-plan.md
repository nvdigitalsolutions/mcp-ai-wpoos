# 102 — Image & Document OCR (Image-to-Text + Conversion): Implementation Plan

**Status:** Plan — complete; implementation pending approval
**Date:** 2026-11-02
**Related:** `102-image-document-ocr-enhancement-proposal.md`, Proposal 100 implementation plan (`100-screenshot-ocr-text-extraction-implementation-plan.md` — this plan extends that code verbatim), Proposals 067/071/073/075/076, `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Decisions (recommended; owner confirmation needed)

| # | Decision | Recommendation | Rationale |
|---|---|---|---|
| **D1** | Image file decode | **`image` 0.25 — already a `snippet`-feature dependency** (wired by Proposal 100, `snippet = ["dep:image"]`). PNG/JPEG/WebP/BMP/TIFF single-page decode for free | Zero new deps; decode is a well-trodden library concern, not core IP |
| **D2** | Clipboard image read | **`arboard` 3 `get_image()` — already a non-optional dependency** (used by `paste/`). Windows: PNG→CF_DIB(V5) fallback inside arboard; macOS: TIFF/PNG via NSPasteboard. Hand-rolled Win32 CF_DIBV5 decoder only as a documented fallback if arboard misses an edge format | Reuses the exact crate the app already trusts for clipboard writes; symmetric read path |
| **D3** | File-picker | **Rust-side `tauri-plugin-dialog` — already registered** in `lib.rs` (`.plugin(tauri_plugin_dialog::init())`). A new `snippet_pick_image` command calls `DialogExt` directly | Zero new npm package; no JS-side plugin package needed at all |
| **D4** | Drag & drop | **Built-in Tauri v2 `onDragDropEvent`** on the main window (`@tauri-apps/api` Webview API — already a dependency). `dragDropEnabled: true` is the v2 default; verify explicitly in the M0 spike | Zero new deps; industry-standard gesture (§3.1 of the proposal) |
| **D5** | Review surface placement | **Main window** (new "Image OCR" panel on the Home/Snippet page + history rows), **not** the snippet overlay window | The overlay is a transient, pointer-owning, full-screen capture surface by design (Proposal 100 §2.1); a review/export surface needs a stable home with Settings and History beside it. The overlay keeps its single job |
| **D6** | Preprocessing | **Hand-rolled pure-function ladder** (`preprocess.rs`): upscale (Lanczos3 via `image::imageops`), grayscale + histogram stretch, projection-profile deskew (±10°, only if angle ≥ ~3°), Otsu/adaptive binarization. `imageproc` evaluated and rejected (heavy dep tree; house discipline of Proposal 075's letterbox/NMS) | Algorithms are bounded (~300 lines), golden-testable, and the only part that must never regress the `screen` profile |
| **D7** | Reading order | **Hand-rolled recursive XY-cut** (`layout.rs`) over `SnippetWord` boxes: word→line clustering (vertical overlap), column split (gap ≥ ~2.5× median char width), paragraph split (vertical gap ≥ ~2× line height) | The canonical cheap baseline (PP-StructureV3's own reference); pure geometry, no model, no dep |
| **D8** | Exports — TXT/MD/hOCR/ALTO | **String emitters in Rust** (`export/`) over the word/line structures; no dependencies | Trivial, golden-tested |
| **D9** | Export — searchable PDF | **`lopdf` (pure Rust, feature-gated `snippet-pdf`)** emitting PDF 1.4: original image as a DCTDecode XObject (re-encoded to JPEG via the already-present `image` crate) + invisible per-word text layer (`3 Tr`, base-14 Helvetica, y-flip transform). `printpdf` rejected (wraps lopdf anyway + pulls an older `image`); npm `pdf-lib`/`pdfkit` rejected (see §2.6) | A battle-tested low-level PDF writer is sane infrastructure to pull — xref/stream bookkeeping is where hand-rolling fails silently. Verify no duplicate `image` tree in the M0 spike |
| **D10** | Feature flags | New **`snippet-pdf = ["snippet", "dep:lopdf"]`**. `snippet`/`snippet-onnx` unchanged. Complete-union build list gains `snippet-pdf` | House convention: every surface cfg-gated with `available() == false` when absent; PDF is the only genuinely new dependency |

**Approval gates before M1:** D1–D5 (input surfaces), D9 (PDF crate choice), D10 (feature name). D6–D8 are implementation detail.

## 2. Dependency survey — what the repo already has (npm + cargo)

Per the owner's steer ("the repo is already utilizing a lot of npm packages"), the
survey below is the source of truth for this plan's reuse decisions.

### 2.1 Cargo — reuse directly (zero additions)

| Crate | Already in `src-tauri/Cargo.toml` | Use in this plan |
|---|---|---|
| `image` 0.25 | Yes — `optional = true`, wired into `snippet` by Proposal 100 | File decode (PNG/JPEG/WebP/BMP/TIFF), Lanczos3 upscale, JPEG re-encode for PDF embedding, thumbnail generation |
| `arboard` 3 | Yes — non-optional | Clipboard image read (`get_image()` → RGBA `ImageData`) — the symmetric half of the paste module's write path |
| `tauri-plugin-dialog` 2 | Yes — registered in `lib.rs` | Rust-side open-file **and** save-file dialogs for imports and exports |
| `tauri` v2 core + `@tauri-apps/api` | Yes | `onDragDropEvent` for drag & drop; IPC/events as-is |
| `rusqlite` + `rusqlite_migration` | Yes | Append-only `snippets` schema extension (Proposal 100 created the table + migration harness) |
| `windows` 0.58 (GDI, WinRT, Foundation features — added by Proposal 100) | Yes | Only if the arboard DIB fallback is ever needed (`Win32_System_DataExchange` would be the one feature to add — otherwise untouched) |
| `ort`/`ort-sys` rc.9, `ndarray16` | Yes (behind `snippet-onnx`) | Unchanged — PP-OCRv5 engines already shipped |

### 2.2 npm — surveyed, and why almost none are used in the webview

The repo's npm libraries for PDF/OCR live in **Node-server addons**
(`addons/media-worker`, `addons/pro`), not in the Tauri webview. Each was
evaluated for this plan:

| Package | Where it exists today | Verdict for the Tauri app |
|---|---|---|
| `pdf-lib` ^1.17.1 | `addons/media-worker`, `addons/pro` | **Rejected for PDF export.** Its public API does not expose the PDF text-rendering mode (Tr 3 "invisible"), which a searchable text layer requires — the feature has been an open request for years. Would need image + text as visible draws, defeating the purpose |
| `pdfkit` ^0.17.2 | same | **Rejected.** Node-centric stream API; browser use needs the standalone bundle (~1.5 MB) plus raw `addContent('3 Tr')` hacks — brittle in a Vite build for one feature |
| `pdfjs-dist` / `pdf-parse` | same | Not needed — we *emit* PDFs; validation reads them back with `lopdf` (Rust) instead |
| `tesseract.js` ^5.1.1 | `addons/media-worker` (server-side) | **Rejected.** WASM OCR would add a fourth, weaker engine — the app already ships WinRT / PP-OCRv5 / Apple Vision, all better on screen+scan text |
| `sharp` / `canvas` | addons (Node-native) | N/A — cannot run in the Tauri webview; the Rust `image` crate covers the same ground natively |

**Net result: zero new npm dependencies.** The only addition to the entire
app is one feature-gated pure-Rust crate (`lopdf`). Everything else reuses
what Proposal 100 and earlier proposals already wired.

## 3. Key architectural findings (shape the whole design)

### 3.1 The review surface lives in the main window, not the overlay

Proposal 100's `snippet` window is a **transient, pointer-owning, full-screen
overlay** (its `capabilities/snippet.json` is `core:default` by design; it
renders `views/Snippet.tsx` via the `?window=snippet` route). It is the wrong
home for a review surface that must persist, show exports, and sit beside
History. Therefore:

- **Main window** gets an "Image OCR" panel (new `views/ImageOcr.tsx` on the
  existing Home/Snippet tab area): drop zone, Paste button, Open button,
  result card (text preview + per-line copy), export buttons.
- The overlay window and its selection vocabulary are **untouched** — screen
  capture keeps its exact Proposal 100 behavior and event stream.
- History rows (already rendered from the `snippets` table) gain a source
  badge and an optional thumbnail; any history row gains "Export…" actions
  once `words_json` is stored (see §6).

### 3.2 Decode split: Rust decodes, the webview only previews

The browser `<img>`/canvas can decode common formats — but consistency and
the zero-egress discipline favor one decode path in Rust:

1. File input: Rust reads the path and decodes with `image` (guards:
   file-size cap 25 MB, pixel cap ~40 MP, decode errors surface as
   `SnippetEvent::Error`).
2. Clipboard input: Rust reads `arboard::get_image()` (RGBA), converts to
   `RgbImage`.
3. The webview receives a **scaled preview data-URI** (CSP already allows
   `img-src 'self' data:`) for display only; full-res pixels never leave
   Rust memory except as the preview/thumbnail — mirroring Proposal 100's
   frozen-frame discipline (§2.2 of that plan).
4. After OCR, the result (text + words) is emitted as a `snippet://state`
   `Review` event; the full-res image is dropped unless a bounded thumbnail
   (≤ 160 px, JPEG ≤ 16 KB) is retained for history.

### 3.3 Preprocessing is a profile-driven pure-function ladder

```
screen  → (none)                                   — Proposal 100 behavior, byte-stable
image   → upscale-if-long-edge<1600 (Lanczos3) → grayscale → histogram stretch
scan    → image ladder → deskew-if-|θ|≥3° (projection sweep ±10°, 0.25° step)
        → Otsu binarization → (optional) adaptive threshold
```

- Every stage is a pure function over `image` buffers (or a no-op) — golden
  fixtures make each stage independently testable.
- The **`screen` profile performs no preprocessing at all**, which makes the
  Proposal 100 regression gate mechanical: same profile → same bytes in,
  same output.
- Deskew uses projection-profile variance over a binarized copy (simple,
  adequate for 3–8° scans); rotation is a ~40-line bilinear warp. Hough is
  the documented upgrade path if fixtures show >1° error.
- Opt-in diagnostics (`retain_intermediates`) writes raw/upscaled/deskewed/
  binarized PNGs under the app data dir — never silently.

### 3.4 Reading order is geometry over word boxes — before text assembly

The engines return `SnippetWord { text, x, y, w, h }` already (Proposal 100
§mod). `layout.rs` consumes the word list and produces ordered
`Vec<WordLine>` → paragraphs **before** any text is assembled:

1. Cluster words into lines: vertical overlap ≥ ~50% of the smaller height,
   horizontal gap ≤ ~1.5× median word height.
2. Recursive XY-cut: find the largest vertical whitespace gutter
   (≥ ~2.5× median char width) → split into columns → recurse; sort
   columns left-to-right, lines top-to-bottom, words left-to-right.
3. Paragraphs: vertical gap ≥ ~2× line height → blank line.

The screen path keeps Proposal 100's existing text assembly (engines already
emit reading order); `layout` is wired for the file/document path in M3, and
optionally for screen captures behind a config toggle in M5 (never silently —
single-column screenshots must stay byte-identical).

### 3.5 The ONNX/WinRT/Vision engines are unchanged

No new models, no new weights, no changes to `ocr_winrt.rs`, `ocr_onnx.rs`,
`macos_sidecar.rs`, or `models.rs`. The only engine-adjacent change is that
the controller feeds them a decoded `RgbImage` instead of (or in addition
to) a `FrozenFrame` crop. PP-OCRv6 remains monitor-only (proposal §3.6).

### 3.6 PDF emitter: lopdf, one page, two layers

PDF 1.4, one page sized to the source image:

- **Image layer:** re-encode the (possibly preprocessed) image to JPEG in
  memory via `image::codecs::jpeg::JpegEncoder`, embed as a DCTDecode
  XObject. One code path for every input format — no PNG predictor tables
  to get wrong.
- **Text layer (invisible):** per word, `BT /F1 <size> Tf 3 Tr 1 0 0 1 x y'
  Tm (<escaped text>) Tj ET` with `y' = H − y − h` (top-left → bottom-left
  origin transform, the documented trap) and `size` scaled so the nominal
  advance ≈ word box width (average advance 0.5 em for Helvetica; a compact
  ~95-char ASCII width table embedded in `export/pdf.rs` tightens
  selection fidelity for Latin text). Base-14 Helvetica → no font
  embedding, no licensing surface.
- `lopdf` handles xref/stream bookkeeping. Validation reads the file back
  with `lopdf::Document::extract_text` and asserts the image XObject
  exists (M4 gate).

Rejected again for the record: `printpdf` (wraps lopdf + older `image`
duplicate tree), npm `pdf-lib`/`pdfkit` (§2.2).

## 4. File map

```
examples/nvoos-dictate/
├── src/                                    # React webview
│   ├── views/ImageOcr.tsx                  # NEW — drop zone, paste/open, review card, exports
│   ├── views/Snippet.tsx                   # unchanged (screen overlay)
│   ├── views/Home.tsx                      # MOD — mount ImageOcr panel; history rows: source badge + thumb
│   ├── views/Settings.tsx                  # MOD — Image OCR section (profile, default export, review, intermediates)
│   ├── views/Diagnostics.tsx               # MOD — image-OCR + export counters
│   ├── lib/ipc.ts                          # MOD — new command wrappers + Review event type
│   └── App.tsx / styles                    # MOD — route/tab wiring, styles for the panel
└── src-tauri/
    ├── src/
    │   ├── snippet/
    │   │   ├── input_image.rs              # NEW — file decode + clipboard read + preview/thumb pipeline
    │   │   ├── preprocess.rs               # NEW — profile ladder (pure functions + golden tests)
    │   │   ├── layout.rs                   # NEW — word→line→column→paragraph ordering
    │   │   ├── export/mod.rs               # NEW — dispatch + save-dialog plumbing
    │   │   ├── export/txt.rs md.rs hocr.rs alto.rs pdf.rs   # NEW emitters
    │   │   ├── controller.rs               # MOD — SourceKind, Reviewing phase, Review event
    │   │   └── mod.rs                      # MOD — shared types (SourceKind, ExportFormat, ReviewEvent)
    │   ├── config.rs                       # MOD — SnippetConfig fields (profile, default_export, review, intermediates)
    │   ├── ipc.rs                          # MOD — 5 new commands (below)
    │   ├── storage/migrations.rs           # MOD — append-only columns migration
    │   ├── storage/mod.rs                  # MOD — snippets CRUD gains words_json + source + thumb
    │   └── lib.rs                          # MOD — command registration (unchanged otherwise)
    ├── examples/snippet_image_probe.rs     # NEW — headless probe: path → decode→preprocess→OCR→layout stats
    ├── tests/                              # MOD — fixtures + integration tests
    ├── capabilities/                       # unchanged (main + snippet both core:default)
    └── Cargo.toml                          # MOD — lopdf (optional), `snippet-pdf` feature
```

Plus docs: `.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md` (complete-union
list + new module), `examples/nvoos-dictate/README.md`, `NOTICE` if lopdf
warrants it (MIT — add to the attribution list), and
`scripts/verify_complete_build.ps1` marker list (see §11).

## 5. Milestones

| M | Deliverable | Exit gate |
|---|---|---|
| **M0 — spikes** | lopdf spike (feature gate + `cargo tree -i image` shows a single 0.25 tree); arboard `get_image` round-trip on the reference PC; `onDragDropEvent` path list; projection-deskew prototype on a synthetic skew; fixture set generated | Each spike proven before its milestone starts; PDF spike emits a lopdf-written one-pager that `extract_text` reads back |
| **M1 — input** | `input_image.rs` (file + clipboard), `snippet_pick_image`, `snippet_ocr_image_path`, `snippet_ocr_clipboard_image`, ImageOcr drop zone + paste/open buttons, SourceKind plumbing, `Review` event | Paste a PNG/JPEG/BMP from three apps (Snipping Tool, browser, Explorer copy) → identical OCR to hotkey capture of the same content; 25 MB/40 MP guards reject with friendly errors |
| **M2 — preprocessing** | `preprocess.rs` ladder + profiles + intermediates toggle; `screen` profile wired as a no-op | Skewed-scan fixture CER measurably improves; `screen` profile byte-identical on the Proposal 100 fixtures |
| **M3 — layout** | `layout.rs` XY-cut; file path assembles text from boxes; Markdown + TXT exports | Two-column fixture reads column-major; blank-line structure survives to Markdown |
| **M4 — exports** | hOCR/ALTO/PDF emitters + save-dialog plumbing + `snippet_export` | PDF: Ctrl+F finds fixture text, page renders pixel-identical, `extract_text` matches, y-transform verified against a known box |
| **M5 — UI/history/config** | Settings section, history badges/thumbs/export actions, Diagnostics counters, review polish, screen-path layout toggle | Full manual pass: drop → review → export each format; history survives restart |
| **M6 — docs + gates** | skill/README/NOTICE updates, verify-script markers, full gate matrix (below) | All gates green; complete-union debug exe builds and boot-smokes |

## 6. Config, IPC, and storage deltas

**`SnippetConfig` (config.rs) additions**

```
preprocess_profile: "image" | "scan"        (default "image"; screen path ignores it)
default_export:     "clipboard" | "txt" | "md" | "hocr" | "alto" | "pdf"
review_after_ocr:   bool                     (default true for file/clipboard, false for screen)
retain_intermediates: bool                   (default false)
history_thumbnails: bool                     (default true; never applies to screen sources)
```

**New IPC commands (ipc.rs, registered in lib.rs)**

```
snippet_pick_image()             → Option<String>          (Rust-side dialog plugin)
snippet_ocr_image_path(path)     → AppResult<()>           (starts file OCR)
snippet_ocr_clipboard_image()    → AppResult<()>           (arboard read → OCR)
snippet_export(id, format)       → AppResult<String>       (save dialog; returns path)
snippet_delete_snippet(id)       → existing (unchanged)
```

**`snippet://state` event — one new variant**

```
Review { id, source: "file"|"clipboard", engine, text, words: Vec<SnippetWord>,
         thumb: Option<String> /* data-URI */, ocr_ms }
```

**Storage (append-only, `storage/migrations.rs`)**

New migration entry after Proposal 100's — never edited, only appended:

```
ALTER TABLE snippets ADD COLUMN source     TEXT NOT NULL DEFAULT 'screen';
ALTER TABLE snippets ADD COLUMN file_name  TEXT NOT NULL DEFAULT '';
ALTER TABLE snippets ADD COLUMN words_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE snippets ADD COLUMN export     TEXT NOT NULL DEFAULT 'clipboard';
ALTER TABLE snippets ADD COLUMN thumb_jpeg BLOB;
```

`words_json` is the export-enabling column (hOCR/ALTO/PDF need boxes; the DB
row must survive restarts). Screen-source rows keep `words_json` too — the
engines already return boxes and this enables "Export this snippet as
searchable PDF" retroactively.

## 7. Controller state machine changes

```
SnippetPhase::Idle | Selecting | Recognizing | Reviewing      (new: Reviewing)
SourceKind::Screen | File | Clipboard                          (new)
```

- Single-flight stays: a second arm/import while busy is ignored (matching
  every snippet tool).
- File/clipboard flow: decode → preprocess(profile) → OCR on the existing
  `nvoos-snippet` worker thread → layout → write history row → emit
  `Review`. The screen flow is byte-for-byte Proposal 100 until the review
  event fires (screen emits `Done`, unchanged).
- `SnippetStats` gains `image_ocrs`, `exports`, `last_preprocess_ms`.

## 8. Test plan

**Pure-function golden tests (run everywhere, no engines):**

- `preprocess`: deskew angle recovery on synthetic rotated text-grid
  (±3/5/8°), Otsu threshold on a bimodal synthetic, upscale-threshold
  boundary (1599 vs 1600 px), profile no-op property (`screen` = identity).
- `layout`: two-column fixture ordering, interleaved-line fixture,
  paragraph-gap fixture, empty/single-word edge cases.
- `export`: hOCR + ALTO snapshot tests (byte-stable golden files), Markdown
  paragraph structure, PDF emit→`extract_text` round-trip + image-XObject
  presence + known-box y-transform assertion.
- `input_image`: guard tests (size/pixel caps), thumbnail bound (≤ 16 KB).

**Integration (reference PC, real engines — mirrors Proposal 100's
probes):**

- `snippet_image_probe.rs`: fixture set (skewed scan, two-column article,
  receipt photo, low-res screenshot, code screenshot, Thai/Greek sample)
  → decode→preprocess→OCR→layout, printing CER + per-stage ms.
- Clipboard round-trip: `arboard` set→get on PNG/DIB sources (Windows-only
  test, gated).
- UI: Playwright e2e for the ImageOcr panel happy path (mock event feed).

**Regression gate (mechanical):** `screen`-profile output on the Proposal
100 fixtures stays byte-identical (no preprocessing, same assembly).

## 9. Validation / gate matrix (per the dictation-app skill)

```sh
cd examples/nvoos-dictate
npm run build                                   # tsc + vite
export PATH="/c/Users/rasta/.cargo/bin:$PATH"   # MSYS only
cargo fmt --check
cargo clippy --all-features -- -D warnings      # default+snippet+snippet-onnx+snippet-pdf
cargo test                                      # pure-function + gated tests
npm run tauri -- build --debug --no-bundle \
  --features fastconformer,parakeet,whisper,silero-vad,camera,vision,agent,mcp,snippet,snippet-onnx,snippet-pdf
# → PE feature-scan (verify_complete_build.ps1) + 30 s boot smoke + clean kill
```

`verify_complete_build.ps1` gains a `snippet-pdf`/lopdf marker string
(Proposal 100's lesson: use app-specific markers, not library strings that
false-positive).

## 10. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **PDF text-layer fidelity** — selection highlight drift vs word boxes | Per-word scaling with the embedded ASCII width table; invisible text cannot affect rendering, only selection/search; fixtures assert `extract_text` content, not pixel text |
| **lopdf dep-tree drift** (older `image`, etc.) | M0 spike gate: `cargo tree -i image` must show one 0.25 tree; pin lopdf version in Cargo.toml like the ort rc.9 pin |
| **Deskew/binarize harming clean images** | Profile gating + the `screen` identity property test; `image` profile skips both stages |
| **arboard clipboard edge formats** (CF_BITMAP-only, animated) | PNG-first inside arboard; documented Win32 fallback behind the existing `windows` crate — add `Win32_System_DataExchange` only if the spike shows a real gap |
| **Multi-column failures on exotic layouts** | XY-cut degrades to column-major text — accepted and documented (proposal §7); tables deferred |
| **History growth from thumbnails** | 160 px / ≤16 KB JPEG cap, `history_thumbnails` off-switch, same retention rules as Proposal 100 |
| **Screen-path regression** | The screen flow is untouched except the new stats/event fields; regression gate in §8 |

## 11. Doing it carefully — worktree & house rules

- **Shared worktree** (`F:\GITHUB\mcp-ai-wpoos`): another agent is active.
  Always `git branch --show-current` before staging; **stage explicitly**
  (never `git add -A`); never `reset --hard`; do not touch
  `docs/project/proposals/README.md`, `--target`, or `097-*` (other
  agent's files). Branch `feat/snippet-image-ocr` from `alpha-working`,
  one commit per milestone, PR to `alpha-working` (owner merges).
- **Feature-gate trap** (skill): a build missing a feature silently boots
  with the surface "not compiled" — every hand-off exe uses the full union
  (§9) and the PE scan proves it.
- **MSYS traps**: `powershell -File x.ps1` (never `-Command`), forward
  slashes, kill the running exe before rebuilding.
- **Migration discipline**: append-only `M::up` entries; existing migration
  rows are never edited (CI parity with shipped DBs).
- **Engine/plumbing separation**: new pure functions take/return plain
  data (`RgbImage`, `Vec<SnippetWord>`); only `controller.rs`/`ipc.rs`
  touch Tauri state — keeps the golden-test surface engine-free.

## 12. Deferred (unchanged from the proposal §10)

PDF/multi-page TIFF *input*, table structure recognition, PP-OCRv6 swap,
shell right-click integration, VLM fallback, word-click mode (Proposal 100
§10), macOS multi-display stitching (Proposal 100 §10).
