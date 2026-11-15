# Document & Media Input — Industry Standards and Best-Practice Research (Proposal 109)

Research companion to `109-document-input-pipeline-ocr-proposal.md` and
`109-document-input-pipeline-ocr-implementation-plan.md`. Written
2026-11-04, covering the four questions raised during the M1–M4 review:
media-vs-document input scoping, industry-standard implementation, the
Rust-vs-PHP (addons/pro) reuse question, and small local image models
for polish.

## 1. Media input and document input are separate surfaces

Industry practice keeps media transcription and document text extraction
as distinct pipelines — different decoders, different formats, different
UX. They share only the optional LLM polish/summarize stage.

- **Audio/video** — the app's "Transcribe media" card accepts MP3, M4A,
  WAV, FLAC, OGG and MP4, MOV, MKV, WebM, demuxing the audio track
  on-device via Symphonia (no FFmpeg sidecar). It deliberately does not
  accept PDFs or images.
- **PDF / multi-page TIFF** — handled by the separate Home "Document OCR"
  panel (Proposal 109): text-layer extraction where present, on-device
  300 DPI OCR where not.
- **Single raster images (PNG/JPEG/WebP)** — implemented in the follow-up
  PR: decoded via the existing `image` crate into the shared RGB8 frame,
  then the same scan-profile + OCR path as a TIFF page.

## 2. Industry standards and best practices

### 2.1 Local-first audio/video transcription

Whisper-class models (whisper.cpp / faster-whisper) plus Silero VAD
(`vad_filter`) are the standard local recipe — VAD skips silence and
non-speech before decoding, cutting wasted compute and silence-related
hallucinations. Local processing is the recommended pattern for sensitive
audio (institutions such as Harvard's library guidance point users at
local Whisper for sensitive interviews). Long media is chunked: fixed
~30 s windows with overlap + context carry-over for Whisper-class
decoders, VAD-silence splits for transducer models with a decode limit.
The app implements all three (whisper/parakeet/fastconformer engines,
silero VAD, `media/chunker.rs`), and its only cloud touchpoint is the
optional polish/summarize call.

### 2.2 Hybrid PDF text extraction (text layer first, OCR fallback)

The standard, formalized by OCRmyPDF: pages that already carry a text
layer are "born digital" — extract, never re-OCR; pages without text are
rasterized and OCRed (`--skip-text` semantics). OCRmyPDF also
standardizes a per-page OCR time budget (default 180 s) and page
skipping with graceful degradation. The app mirrors both: the
`text_page_min_chars` router in `document/hybrid.rs` and per-page error
isolation.

Engine choice for rendering + text extraction:

| Engine | License | Notes |
| --- | --- | --- |
| PDFium (pdfium-render) | BSD-3 / Apache-2.0 | Chrome's engine; the license-clean choice — used here |
| MuPDF / PyMuPDF | AGPL-3.0 (commercial alternative) | Fastest (~2x Poppler); copyleft disqualifies embedding in a proprietary app |
| Poppler | GPL | Good pdftotext quality; GPL disqualifies embedding |
| PDF.js | Apache-2.0 | Lower print-fidelity rendering |

The PDF Association's 2021 open-source survey and 2026 community
comparisons agree on this matrix. Render DPI: 300 DPI is the archival/
OCR standard for black-and-white scans (state archives guidance; the
CCITT G4 spec allows 200/300/400 DPI with 300 the most common for
archival quality). Multi-page TIFF (CCITT G4/JPEG) is the
enterprise/legal archival format; the app's `document/tiff.rs` streams
per-frame decode with a page cap to bound memory.

### 2.3 OCR engine selection

| Engine | Strengths | Weaknesses |
| --- | --- | --- |
| PaddleOCR / PP-OCRv5 | Open-source SOTA: 80.1% weighted accuracy across a 13-category benchmark (up from 53% for v4); ≈GPT-4o-level OCR with a 5M-param model; F1 0.938 vs Tesseract 0.797 (IJRPR, Oct 2025) | PaddlePaddle runtime is GPU-oriented; CPU paths are slower |
| RapidOCR (PP-OCR ONNX port) | Same DBNet + SVTR lineage on ONNX Runtime CPU — the right shape for a desktop app | English-centric dictionary unless extra dicts ship |
| Tesseract | Mature baseline; the OCRmyPDF budget standard | Weakest accuracy of the modern set |
| TrOCR / docTR | Strong document benchmarks | Larger models; docTR is a research framework |

The app already ships RapidOCR-ONNX for snippet OCR (`snippet-onnx`,
~90–120 ms/shot) and reuses it as the document fallback, plus WinRT OCR
on Windows (~35 ms/shot) — the "native engine first, portable ONNX
fallback" pattern. Preprocessing follows the OCRmyPDF preprocess pass:
binarization (Otsu), 2x upscale for small text, noise removal; deskew is
the documented deferred item.

### 2.4 Reading order (layout analysis)

Recursive XY-cut is the canonical geometric baseline for reading order;
learned approaches (LayoutReader, DocTR) are the upgrade for
magazine-style multi-column layouts (arxiv 2607.01018; Meunier's
optimized XY-cut; XY-Cut++). The app's shared `snippet/layout.rs`
implements XY-cut over words *before* line clustering — the documented
fix for cross-column word merging.

### 2.5 Export standards

The standard set: hOCR (the open OCR output standard), ALTO XML (Library
of Congress archival standard), plain text, Markdown, and searchable
PDFs (invisible text layer over the original page image — the OCRmyPDF
output model). The app emits txt/md/hOCR/ALTO plus searchable PDF
(`snippet-pdf`, lopdf).

### 2.6 On-device vs cloud economics and privacy

Cloud plain OCR costs $1.50/1,000 pages across AWS/Google/Azure;
structured extraction runs $10–$65/1,000 pages (Azure prebuilt ~$10,
Textract Forms+Tables ~$65). On-device OCR is free at the margin and
keeps legal/medical/HR documents on the machine — the app's whole
document pipeline is local except the optional polish call.

## 3. In-repo package survey

### 3.1 addons/pro (PHP toolkits)

`addons/pro/includes/tools/` holds 58 server-side WordPress toolkits;
the media/document-adjacent ones are `media`, `image-production`,
`video-production`, `vision-analysis`, `document-generation`,
`extended-cognition`, `capture`, `paper-store`, `vector-storage`, `okf`.
All of them are PHP MCP tools that run on the site/server and orchestrate
cloud AI providers or site data — none can run on a user's laptop and
none can link into a Rust/Tauri binary. Reuse is therefore **by contract,
not by code**: the dictate app already calls the site through the `nvoos`
provider for polish/summarize. Keep the desktop implementation in Rust;
keep PHP as the optional cloud bridge.

### 3.2 The Rust stack already in the app

`pdfium-render` (PDFium 7881), `tiff`, `lopdf`, `ort` rc.9 + `ndarray`,
`image` 0.25, `symphonia`, `sherpa-onnx` (parakeet/fastconformer),
`whisper-rs`, `silero-vad-rs`, `nokhwa`, `windows-rs` (WinRT OCR + GDI
capture), and the OpenAI-compatible local transport (LM Studio /
Ollama / nvoos). The document pipeline needed exactly two additions:
`pdfium-render` and `tiff`.

### 3.3 Verdict: Rust is correct

- One binary per OS, no runtime install; on-device ONNX inference is
  already proven in the app (RapidOCR 90–120 ms, YOLO ~49 ms).
- Memory safety matters for a parsing-heavy pipeline that must survive
  malformed and hostile inputs — the committed malformed/encrypted PDF
  fixtures exercise exactly this.
- PHP cannot ship on the desktop; an Electron port would forfeit the
  entire engine track.

## 4. Local image models for polish

Small VLMs are now the industry default for OCR-adjacent verification and
relabeling on-device:

- **Qwen2.5-VL 7B** — the best practical OCR/DocVQA default on a 12 GB
  GPU at 4-bit; the app's default VLM polish model
  (`qwen/qwen2.5-vl-7b` via LM Studio), verified live returning fenced
  JSON.
- **MiniCPM-V 2.6 (8B)** — strong OCR alternative.
- **Moondream2 (~1.9B) / moondream 0.5B** (int4 ~375 MiB, ~816 MiB RAM) —
  ultra-light captioning/verification for weak hardware.
- **Florence-2** — repeatedly rated the highest-quality of the smallest
  models; strong structured outputs.

The document polish step reuses Proposal 076's vision transport (crop →
base64 → OpenAI-compatible multimodal request through the local
provider), so a local LM Studio/Ollama VLM polishes OCR pages with zero
cloud, opt-in, failure-soft (deterministic cleanup is the fallback).
Cloud VLM OCR is deliberately not required.

## 5. Decision map (ADR 0005)

| Decision | Choice | Basis |
| --- | --- | --- |
| D1 | Hybrid text-layer-first routing | OCRmyPDF `--skip-text` standard |
| D2 | PDFium via pdfium-render | only license-clean renderer (BSD-3) |
| D3 | WinRT OCR → RapidOCR-ONNX fallback | native-first + portable ONNX |
| D4 | Local-VLM polish, opt-in, failure-soft | small-VLM maturity (§4) |
| D5 | txt/md/hOCR/ALTO + searchable PDF | industry export standards |

## 6. Recommended follow-ups

1. ~~Raster-image input (PNG/JPEG/WebP) through the same OCR path~~ —
   **done** (follow-up PR: `document/images.rs`, Images dialog filter,
   PNG/JPEG fixtures). An image result still labels itself `document-ocr`
   in history, same as a scanned page — cosmetic, not a gap.
2. ~~Word-level bounding boxes → hOCR/ALTO v2~~ — **done** (follow-up PR:
   `document/words.rs`, `DocumentLine`/`DocumentWord`, boxed PDF text
   layers + OCR-engine boxes, finalized text reconciled onto raw boxes;
   polished rewrites degrade to line-level boxes). Searchable-PDF word
   placement remains deferred.
3. ~~pdfium.dll installer provisioning~~ — **done** (follow-up PR:
   `scripts/fetch-pdfium.ps1` sha256-pinned fetch, Windows-only
   `tauri.windows.conf.json` bundle resource, `build.rs` dev/test staging,
   publish-workflow fetch step; NSIS/MSI installers verified to contain
   the dll beside the exe).
4. Deskew pass (102's deferred item) for rotated scans.
5. Optional VLM reading-order / table detection for complex layouts
   (XY-Cut++/DocTR-style) behind the polish toggle.
6. Batch/folder document input.
7. DeepSeek-OCR model tier (Proposal 111): optional local document-
   understanding pass for layout-complex / low-confidence pages, via the
   existing local-server transport — see the proposal for the phased plan.

## Sources

- OCRmyPDF docs — `--skip-text` semantics and per-page budget: https://ocrmypdf.readthedocs.io/en/latest/cookbook.html · https://manpages.debian.org/testing/ocrmypdf/ocrmypdf.1.en.html
- PP-OCRv5 accuracy: https://lofttools.com/blog/on-device-ocr-reviewed/ · https://abit.ee/en/soft/paddleocr-tesseract-github-ocr-baidu-pp-ocrv5-open-source-text-recognition-en · https://ijrpr.com/uploads/V6ISSUE10/IJRPR53627.pdf
- PaddleOCR vs Tesseract throughput/CPU: https://gigagpu.com/paddleocr-vs-tesseract-vs-easyocr/ · https://www.koncile.ai/en/ressources/paddleocr-analyse-avantages-alternatives-open-source
- PDF engine licensing: https://pdfa.org/wp-content/uploads/2021/06/Survey-of-OpenSource-Solutions.pdf · https://theproductguy.in/blogs/pdf-rendering-engines/
- Scanning standards / G4: https://www.pa.gov/content/dam/copapwp-pagov/en/phmc/documents/archives/records-management/documents/03_What%20standards%20should%20I%20follow.pdf · https://convertio.co/tiff-g4/
- Local transcription + VAD: https://www.umevo.ai/blogs/ume-all-posts/how-to-self-host-whisper-the-complete-guide-to-private-offline-ai-transcription · https://openwhispr.com/blog/local-vs-cloud-transcription · https://asklib.hks.harvard.edu/faq/399910
- Reading order: https://arxiv.org/html/2607.01018 · https://arxiv.org/html/2504.10258v1 · https://www.researchgate.net/publication/4214809_Optimized_XY-cut_for_determining_a_page_reading_order
- Cloud OCR pricing: https://aliteq.com/idp-pricing-per-page · https://imagetotable.ai/blog/google-vs-aws-vs-azure-ocr-2026
- Small VLMs: https://trelis.substack.com/p/top-vision-models-2025 · https://huggingface.co/blog/vlms-2025 · https://learnopencv.com/vlm-on-edge-devices/ · https://moondream.ai/models · https://localaimaster.com/blog/local-ai-vision-tasks · https://blog.roboflow.com/local-vision-language-models/
