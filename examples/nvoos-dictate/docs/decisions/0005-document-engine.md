# 0005 — Document-input engine: PDFium via `pdfium-render`

- **Date:** 2026-11-03
- **Status:** Accepted (Proposal 109, M0–M1)
- **Related:** Proposal 109 (`docs/project/proposals/109-document-input-pipeline-ocr-*`), Proposal 102 (image OCR), 0001 (paste/Stage-2 contract), 0002 (MCP transport)

## Context

The document pipeline needs to (a) parse PDFs, (b) extract per-character
text-layer boxes for born-digital pages, and (c) rasterize scanned pages at
300 DPI for the existing OCR engines. The engine choice is license-bound:
the app is proprietary (`UNLICENSED`, mirrored with `LICENSE.txt`).

## Decision

**Use PDFium (BSD-3/Apache-2.0) via the `pdfium-render` crate**, loaded
dynamically at runtime from a pinned, checksummed prebuilt binary
(bblanchon/pdfium-binaries, `chromium/7881`), shipped as a bundle resource
— never committed, never fetched at runtime.

Alternatives rejected:

- **MuPDF** — AGPL-3.0 (paid Artifex license required for closed source).
- **Poppler / Ghostscript** — GPL/AGPL; Windows support is second-class.
- **pdfjs-dist in the WebView2** — splits rendering (JS) from OCR (Rust)
  across the IPC bridge; against the locked-down CSP and the app's
  pure-Rust house pattern (Symphonia over FFmpeg, chromiumoxide over
  puppeteer).
- **`lopdf`/`pdf-extract`** — pure-Rust parsers that cannot rasterize.

`lopdf` remains the choice for the searchable-PDF **emitter** (feature
`snippet-pdf`), per Proposal 102's D9 — writing PDFs, not reading them.

## Consequences

- One new native binary per platform in the bundle (~7 MB Windows dll);
  `NOTICE.md` carries the attribution entry.
- `pdfium-render` is pinned to the `pdfium_7881` API feature with
  `image_025` (matches the app's `image` 0.25 pin — no duplicate image
  tree) and `thread_safe` (Pdfium is not thread-safe; the crate
  serializes access).
- The `document` cargo feature implies `snippet`; OCR engines are shared
  with the snippet surface; the optional VLM polish reuses Proposal 076's
  local multimodal transport (zero new dependencies).
