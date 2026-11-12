# Changelog

Release history for NV oOS Cognition (`examples/nvoos-dictate`). Earlier
builds are documented on the GitHub releases page.

## [0.4.0] — 2026-11-12

### Added

- **Document OCR** (feature `document`, Proposals 109/111) — PDF,
  multi-page TIFF and raster-image input with a hybrid text-layer/OCR
  router, page progress + cancel, `.txt`/`.md`/hOCR/ALTO exports with
  word boxes, and searchable-PDF export (feature `snippet-pdf`). Optional
  local-VLM polish and a DeepSeek-OCR model tier (Ollama native
  `/api/chat`, LM Studio OpenAI-compatible mount).
- **Memory layer** (feature `memory`, Proposals 127/128) — opt-in local
  hybrid retrieval (FTS5 + sqlite-vec) over dictations, meetings, screen
  grabs and document pages, with the bundled Granite encoder, a Home
  "Ask your words" panel that returns cited passages, and an audited
  `memory_ops` table for reindex/purge.
- Settings → Documents and Settings → Memory surfaces, plus diagnostics
  for PDFium, the vector table and the memory model.
- PDFium runtime bundled with the Windows installers (pinned download,
  checksummed, never committed).

### Changed

- README refreshed for document OCR, memory, the mirror release flow and
  the correct feature/test commands.
- License section corrected to the proprietary `LICENSE.txt` (the app was
  never MIT; `NOTICE.md` is authoritative).

### Fixed

- `package-lock.json` version drift (still 0.2.0 after the 0.3.0 bump);
  versions now move together across `package.json`, `package-lock.json`,
  `src-tauri/tauri.conf.json`, `src-tauri/Cargo.toml` and `Cargo.lock`.

## [0.3.0] — 2026-11-02

### Changed

- Rebranded to **NV oOS Cognition** (Proposal 103): product name, window
  titles, macOS bundle paths, CI workflow names and the release title.
  Technical identifiers (`nvoos-dictate` crate/binary, updater tags
  `nvoos-dictate-v*`, `%APPDATA%/nvoos-dictate`, keychain targets) stay
  for updater, credential and data-dir continuity.
- Committed brand icons are authoritative; the release workflow no longer
  regenerates placeholder icons.
- `examples/nvoos-dictate` is mirrored one-way to the public
  `nvdigitalsolutions/nvoos-cognition` repository (source-visible,
  proprietary license).
