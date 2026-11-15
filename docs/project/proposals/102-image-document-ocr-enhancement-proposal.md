# Proposal 102 — Image & Document OCR: File/Clipboard Input, Accuracy Preprocessing, and Text-Document Conversion for the NV oOS Dictation Companion

**Status:** Proposed — research complete (2026-11-02); implementation pending approval
**Date:** 2026-11-02
**Related:** Proposal 100 (screen OCR snippet, *implemented* — this proposal extends its `snippet/` stack), 067 (dictation companion), 071 (video/file input), 073 (live camera), 075 (local object detection/tracking), 076 (VLM label polish), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Executive summary

Proposal 100 gave the app the Snipping-Tool gesture: **hotkey → drag region →
text on the clipboard**, entirely on-device (WinRT / PP-OCRv5 ONNX / Apple
Vision). This proposal extends the *same* OCR stack in two directions the
user asked for:

1. **Recognize text images — any image, not just live screen pixels.**
   The snippet surface gains the three industry-standard image entry points
   (open file, drag & drop, paste from clipboard), so a saved screenshot, a
   phone photo of a sign, a receipt, or a scan is OCR'd with the same
   engines and the same cleanup/history pipeline.
2. **Convert the image into text when needed — document-grade output.**
   Raw OCR is a wall of text. This adds the industry-standard accuracy
   ladder (upscale → contrast → deskew → conservative binarization),
   reading-order reconstruction (recursive XY-cut over line boxes), and
   export conversion: plain text (clipboard), Markdown, hOCR/ALTO archive
   formats, and a **searchable PDF** that keeps the original image
   pixel-identical and adds the invisible ISO 32000 text layer underneath.

Everything remains **zero-egress**: no engine in this proposal sends pixels
off-device, and the file/export paths never leave the machine. The
architecture is ~70% already shipped — the engines, the model registry, the
controller state machine, the history table, and the paste module are all
Proposal 100 deliverables that this proposal reuses rather than rebuilds.

## 2. Problem statement

Proposal 100 closed the "trapped text on screen" gap for **live pixels**.
Three adjacent gaps remain, and users hit them daily:

- **Text trapped in image files.** A screenshot saved to disk, a photo of a
  whiteboard, a scanned page, a product label, a meme with a quote — the
  cursor still cannot select any of it. The current snippet only OCRs what
  is on screen *right now*; the user must first display the image, then
  capture it — a two-step dance that loses resolution (screen capture of a
  zoomed-out image) and fails entirely for images larger than the screen.
- **Raw OCR output is unstructured.** Multi-column articles, receipts, and
  scans come back as one blob with scrambled column order, collapsed line
  structure, and no way to archive the result against the original image.
- **Scans and photos degrade accuracy.** Small, skewed, low-contrast images
  measurably hurt every engine: a ~3° residual skew can drop accuracy ~10%,
  and proper binarization can gain 20–40% on typical scans (§3.3). Proposal
  100 intentionally did no preprocessing (screen text is already clean);
  image inputs are not.

The dictation app's identity is "hotkey → text appears where I'm typing".
The same promise in the visual modality is incomplete if it only sees live
pixels: the natural completion is **"any image in → clean, structured text
out"**, with the document formats (searchable PDF, hOCR) covering the
"convert the image to text if needed" half of the request.

## 3. Industry research summary

### 3.1 Image-input UX conventions — what "OCR an image" means in 2026

Every mainstream tool converges on **exactly three entry points**, and we
should copy them rather than invent:

| Tool | Entry points | Notes |
|---|---|---|
| **ShareX** ([getsharex.com](https://getsharex.com)) | File/URL/region → OCR as after-capture action | OCR is one of dozens of actions; result lands in an editor window |
| **Text Grab** (basis of PowerToys Text Extractor) | Screen region modes + **"Grab Frame"** | Dedicated OCR-first UX; no file save by default |
| **Snipaste** | Paste (Ctrl+V) → OCR | Clipboard paste is a first-class input, not a fallback |
| **Web OCR tools** (PixelTools [pixeltools.io](https://pixeltools.io/image-to-text), imgocr, miniwebtool, BC Clipboard OCR [clipboardocr.com](https://clipboardocr.com)) | **Drag & drop · click-to-open · Ctrl+V paste** | The three-entry-point pattern is universal across every implementation surveyed |
| **Windows 11 Snipping Tool Text Actions** | Screenshot → Text Actions | Copy / redact only; no file input |

**Synthesis (binding design principles):**

1. **Three input verbs:** drag & drop onto the surface, open-file dialog,
   paste-from-clipboard. Any tool missing one of these feels broken.
2. **The result is shown in a review surface before leaving** (ShareX
   editor, Text Grab edit window) — for file inputs users expect to *see*
   the text and pick an output (copy all, copy line, export), not just
   have the clipboard silently overwritten.
3. **History shows provenance** — a thumbnail plus a source badge
   (screen / file / clipboard) is the standard memory cue.
4. **No intermediate files.** The clipboard remains the default hand-off;
   exports are explicit user actions.

### 3.2 Clipboard image standards — how to read a pasted image correctly

- **Windows:** prefer the registered `PNG` clipboard format first, then
  `CF_DIBV5`, then `CF_DIB`; `CF_BITMAP`-only clipboards are rare (apps
  that set a bitmap almost always set a DIB too) and can be skipped
  ([mistral-vibe #1382](https://github.com/mistralai/mistral-vibe/issues/1382),
  [Edgedrop](https://www.edgedrop.app/blog/images-copied-as-dib-png-or-html-which-one-pastes)).
  DIBs need a header-to-PNG conversion path — a bounded, well-trodden
  decoder (BITMAPINFOHEADER/V5 + BGRA/BGR rows), not a new dependency.
- **macOS:** `NSPasteboard` TIFF/PNG; Sonoma+ supports
  `org.nspasteboard.ConcealedType` to mark sensitive content so clipboard
  history features (e.g. Universal Clipboard) skip it
  ([clipbroad PR #24](https://github.com/fcamus00/clipbroad/pull/24)) —
  the sidecar can set this when it writes OCR results of sensitive-looking
  sources.
- **Rust:** the app already owns Win32 clipboard writes via the paste
  module; reads are symmetric work. No new crate required for the
  paste-read path on Windows (the macOS side is the existing Swift sidecar).

### 3.3 Preprocessing standards — the accuracy ladder

Published guidance converges on the same ladder, applied *conditionally*
per source type ([IRI](https://www.iri.com/blog/data-protection/preprocessing-images-for-ocr-darkshield/),
[imagetotable](https://imagetotable.ai/blog/ocr-image-preprocessing-guide),
[byteocr](https://byteocr.com/ocr-preprocessing-techniques)):

1. **Upscale small images** — rescale when the long edge is under ~1600 px
   (or 2× when the crop is tiny). Cheap and the single biggest win on
   phone-screenshot-of-text and downscaled memes.
2. **Deskew first, then binarize** — binarization destroys the gradient
   information deskew detection needs; a 3° residual skew invisible to the
   eye can cost ~10% accuracy. Only deskew when the estimated angle exceeds
   ~3–5° (screen captures are already axis-aligned; deskewing them is
   *harmful*).
3. **Adaptive binarization** (Otsu/adaptive threshold) — gains 20–40% on
   typical scans; **conservative on sharpening**.
4. **Keep intermediates** — a diagnostics toggle that saves each stage
   (raw → upscaled → deskewed → binarized) makes accuracy bugs debuggable
   instead of mystical.

**Key architectural decision:** the ladder must be **profile-driven**, not
unconditional. `screen` profile = none (crisp, already axis-aligned — the
Proposal 100 behavior); `image` profile = upscale-if-small + contrast;
`scan`/`photo` profile = full ladder. A default profile per source with a
per-source override.

### 3.4 Layout & reading order — reconstructing documents, not just glyphs

- **Recursive XY-cut** is the canonical cheap baseline — it is what
  PaddleOCR's own PP-StructureV3 ships as the reference reading-order
  method ([arXiv:2607.01018](https://arxiv.org/html/2607.01018)). Over the
  det-stage line boxes it correctly handles multi-column text for the cost
  of a few sort passes: cut vertically when a column gap is detected, then
  read each column top-to-bottom.
- **Reading order is geometry, not language** — LayoutReader showed layout
  alone predicts order well; end-to-end document VLMs (dots.ocr,
  PaddleOCR-VL, OvisOCR2 at 96.58 OmniDocBench v1.6) bake order in
  implicitly ([thelastaimile.substack.com](https://thelastaimile.substack.com/p/part-3-layoutreader-reading-order))
  but are GPU-class models — explicitly out of scope here.
- **Paragraph grouping** — merge adjacent lines whose boxes overlap
  vertically and are within ~1.5 line-heights; blank-line detection from
  vertical gaps. This is what turns OCR output into copy-pasteable
  Markdown.

### 3.5 Document conversion standards — searchable PDF, hOCR, ALTO

- **Searchable PDF = original image + invisible text layer** (ISO 32000,
  Tr 3 render mode / no-fill-no-stroke text). The image stays
  pixel-identical; the text layer sits underneath so Ctrl+F, selection, and
  copy work ([fileconvertlab](https://fileconvertlab.com/blog/ocr-output-formats),
  [olocr](https://olocr.com/pdf-to-searchable)). **Coordinate trap:** OCR
  boxes use top-left origin (y grows down); PDF user space is bottom-left
  (y grows up) — every box must be transformed `y' = pageHeight − y`
  ([UniDoc](https://docs.unidoc.io/unipdf/faq/extracting/add-invisible-text-layer/)).
- **hOCR** is XHTML with per-word `bbox` — the Internet Archive's archival
  OCR format of choice ([archive.org/developers/ocr.html](https://archive.org/developers/ocr.html));
  **ALTO XML** is the library-standard equivalent. Both are trivial
  emitters over the per-word boxes the engines already return (WinRT,
  PP-OCRv5, and Vision all provide them).
- **Output ladder (all local):** clipboard/TXT (default) → Markdown
  (structure) → hOCR/ALTO (archive) → searchable PDF (document
  conversion). A PDF emitter in Rust is a real but bounded dependency
  decision (`printpdf` vs `lopdf` — resolved in the implementation plan;
  both are pure-Rust and license-compatible).

### 3.6 Engine landscape — delta from Proposal 100

Nothing changes about engine *choice*: WinRT (Windows default), PP-OCRv5
ONNX (portable, 106 languages), Apple Vision (macOS) remain correct. Two
deltas worth recording:

- **PP-OCRv6** shipped with PaddleOCR 3.7.0 (2026-06-11) — a single
  1.5/7.7/34.5M-param model covering 50 languages
  ([pasqualepillitteri.it](https://pasqualepillitteri.it/en/news/25568/best-local-ocr-pc-server-2026)).
  **Monitor, don't adopt yet**: Proposal 100 pinned verified v5 weights and
  hashes; swapping is a benchmark-gated follow-up, not part of this
  proposal.
- **Per-word boxes are already universal** across the three engines — this
  is the linchpin that makes reading-order reconstruction, hOCR/ALTO, and
  the PDF text layer possible without new models.

## 4. Proposed scope

### In scope

1. **Image input surfaces (M1):** open-file dialog, drag & drop onto the
   snippet window, paste-image (Ctrl+V) — decoded from PNG/JPEG/WebP/BMP
   (+ TIFF single-page best-effort). Decode via the app's existing `image`
   crate usage; Windows clipboard read = PNG → CF_DIBV5 → CF_DIB fallback
   per §3.2.
2. **Accuracy ladder (M2):** profile-driven preprocessing (`screen` |
   `image` | `scan`) with the §3.3 stages and a diagnostics toggle that
   retains intermediates.
3. **Reading order + structure (M3):** recursive XY-cut over det boxes,
   paragraph grouping, line/paragraph-preserving text assembly.
4. **Export conversion (M4):** clipboard (default) · Markdown · hOCR ·
   ALTO · searchable PDF (invisible text layer, original image preserved).
5. **Review surface + provenance (M5):** the snippet window gains a result
   view (text preview, export buttons, per-line selection) and history
   rows gain source badges + bounded thumbnails.
6. **Config + IPC (M5):** `SnippetConfig` gains preprocessing profile +
   default export; new IPC commands (`snippet_ocr_image_path`,
   `snippet_ocr_clipboard_image`, `snippet_export_*`); capability updates.
7. **Docs (M6):** proposal + implementation plan + skill/README updates,
   matching the Proposal 100 documentation trail.

### Out of scope (deferred — see §10)

PDF/multi-page TIFF *input*, table-structure recognition, handwriting
guarantees, Windows shell right-click integration, VLM fallback (already
deferred in Proposal 100), cloud OCR, any network egress.

## 5. Architecture

```
snippet/
├── capture.rs          (existing — live screen)
├── ocr_winrt.rs        (existing — WinRT, per-word boxes)
├── ocr_onnx.rs         (existing — PP-OCRv5, per-word boxes)
├── macos_sidecar.rs    (existing — Vision via Swift sidecar)
├── models.rs           (existing — pinned catalogue; unchanged)
├── input_image.rs      (NEW — file decode + Windows clipboard-image read)
├── preprocess.rs       (NEW — profile-driven ladder, pure functions)
├── layout.rs           (NEW — line grouping, XY-cut, paragraphs)
├── export/             (NEW — txt, md, hocr, alto, pdf emitters)
└── controller.rs       (extended — source type, review flow, export routing)
```

- **No new engines, no new model weights.** Every stage after OCR consumes
  the per-word boxes the three engines already return.
- **Pure-function core:** `preprocess` and `layout` are deterministic
  transforms over decoded pixel buffers / box lists — directly unit-testable
  with golden fixtures, same discipline as the Stage-1 cleanup pipeline.
- **Zero egress invariant unchanged:** file input and exports are local
  disk/clipboard operations; the OCR engines remain the on-device trio.
- **macOS path:** input/export logic is cross-platform Rust; clipboard-read
  and ConcealedType marking go through the existing Swift sidecar; Vision
  already returns the needed boxes.

## 6. Milestones

| M | Deliverable | Exit gate |
|---|---|---|
| **M1** | Image input: file open, drag & drop, clipboard paste; source type plumbing | Paste a PNG/JPEG/BMP from three different apps; OCR matches the same image captured via hotkey |
| **M2** | Preprocessing ladder + profiles + intermediates toggle | Skewed-scan fixture CER drops measurably vs raw; screen-profile output is byte-identical to Proposal 100 behavior |
| **M3** | Reading order + paragraph assembly | Two-column fixture reads column-major correctly; blank-line structure survives to Markdown |
| **M4** | Exports: Markdown, hOCR, ALTO, searchable PDF | PDF: Ctrl+F finds fixture text, page renders pixel-identical, `y`-transform verified against a known box |
| **M5** | Review surface, history provenance, config, IPC | Full UI pass: drag → review → export; history shows source badge + thumbnail |
| **M6** | Docs + full gate matrix | `cargo fmt`, clippy `-D warnings`, tests, `npm run build`, complete-union debug exe + boot smoke |

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **PDF text-layer fidelity** — positioning invisible text under arbitrary images | Use per-word boxes with a base-14 font + width scaling (standard for search layers); validate with the §9 spot checks; ship Markdown/hOCR first so PDF is never a blocker |
| **Deskew/binarize destroying crisp screen text** | Profile gating: `screen` profile performs *no* preprocessing (regression-proof vs Proposal 100) |
| **Clipboard DIB decoding edge cases** (top-down vs bottom-up rows, V5 masks) | PNG-first preference means DIB is the fallback; fixture-driven decoder tests; skip CF_BITMAP-only |
| **Multi-column failures on exotic layouts** (tables, sidebars) | XY-cut is the conservative baseline; table/sidebar cases degrade to column-major text — accepted, documented, deferred to PP-StructureV3-class tooling |
| **Language coverage for file OCR** | Unchanged engines: WinRT language packs, PP-OCRv5 per-language dicts, Vision `recognitionLanguages`; mirror Text Extractor's "no OCR languages installed" error |
| **History bloat from file sources** | Thumbnails bounded (capped dimension + JPEG quality), same retention rules as screen entries |

## 8. Privacy & compliance

- **Zero egress is preserved and extended:** image files, clipboard
  bitmaps, and exports never leave the machine. The engines remain the
  on-device trio; no new network surface is introduced.
- **No hidden writes:** inputs are read only; exports happen only on an
  explicit user action with a chosen path. The frozen-frame
  never-touches-disk rule from Proposal 100 is unchanged.
- **Clipboard sensitivity:** on macOS the sidecar marks OCR results with
  `org.nspasteboard.ConcealedType` so system clipboard-history features
  skip them (Sonoma+ behavior per §3.2); Windows uses the existing
  paste-module write path, and transient-clipboard clearing stays a
  user-configurable option, off by default.
- **Diagnostics intermediates are opt-in** and written under the app's
  own data directory with a visible toggle — never silently.

## 9. Validation & acceptance

Fixture suite (golden oracle, same discipline as the cleanup pipeline):

1. Skewed low-contrast scan (~3–8°) — ladder profile must beat raw by a
   measured CER margin.
2. Two-column article — column-major reading order asserted.
3. Receipt photo (phone) — currency amounts and line items survive to
   Markdown with line breaks.
4. Low-res screenshot (≤ 800 px wide) — upscale-if-small path.
5. Code screenshot — monospace alignment preserved in plain-text export.
6. Non-Latin sample (e.g. Thai/Greek via PP-OCRv5) — dict path verified.
7. PDF export — Ctrl+F finds a known phrase; rendered page is
   pixel-identical; a known word box maps through the `y` transform.
8. Regression — `screen` profile output byte-identical to Proposal 100
   behavior on the existing WinRT/ONNX fixtures.

Gates: `cargo fmt --check`, clippy `-D warnings` (all feature sets), unit +
integration tests, `npm run build`, complete-union debug exe build +
30 s boot smoke, mirroring the Proposal 100 validation matrix.

## 10. Deferred / future phases

- **Document input pipeline** — multi-page TIFF/PDF input, page
  splitting (would open a second input modality; deliberate separate
  proposal).
- **Table structure recognition** — PP-StructureV3-class layout models.
- **PP-OCRv6 engine swap** — benchmark-gated follow-up once v5 weights
  are obsolete.
- **Shell integration** — right-click "Extract text" on image files.
- **VLM fallback + word-click mode** — already deferred in Proposal 100
  §10; unchanged.

## 11. References

- PowerToys Text Extractor — https://learn.microsoft.com/en-us/windows/powertoys/text-extractor
- Text Grab — https://github.com/TheJoeFin/Text-Grab
- ShareX — https://getsharex.com/
- BC Clipboard OCR (paste/drag/open entry points) — https://clipboardocr.com/
- PixelTools image-to-text — https://pixeltools.io/image-to-text
- Clipboard format best practice (DIB vs PNG vs CF_BITMAP) — https://www.edgedrop.app/blog/images-copied-as-dib-png-or-html-which-one-pastes
- Windows clipboard image read pattern — https://github.com/mistralai/mistral-vibe/issues/1382
- macOS ConcealedType clipboard marker — https://github.com/fcamus00/clipbroad/pull/24
- OCR preprocessing pipeline guidance — https://imagetotable.ai/blog/ocr-image-preprocessing-guide
- Preprocessing thresholds (1600 px / 5°) — https://www.iri.com/blog/data-protection/preprocessing-images-for-ocr-darkshield/
- Deskew-before-binarize ordering — https://byteocr.com/ocr-preprocessing-techniques
- Recursive XY-cut as reading-order baseline (PP-StructureV3) — https://arxiv.org/html/2607.01018
- Reading order is geometry (LayoutReader) — https://thelastaimile.substack.com/p/part-3-layoutreader-reading-order
- Invisible PDF text layer & coordinate transform — https://docs.unidoc.io/unipdf/faq/extracting/add-invisible-text-layer/
- Searchable PDF definition — https://fileconvertlab.com/blog/ocr-output-formats
- Internet Archive hOCR rationale — https://archive.org/developers/ocr.html
- PP-OCRv6 arrival & v5 latin accuracy — https://pasqualepillitteri.it/en/news/25568/best-local-ocr-pc-server-2026
- Non-LLM OCR engine comparison (v5 80.1% weighted) — https://intuitionlabs.ai/articles/non-llm-ocr-technologies
