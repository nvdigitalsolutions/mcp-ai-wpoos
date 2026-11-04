# Bundle resources

`pdfium.dll` — the PDFium runtime for the `document` feature — lives here,
**gitignored** (never committed: a 7 MB pinned binary). Fetch it once per
machine:

    pwsh scripts/fetch-pdfium.ps1

The script downloads the pinned bblanchon `chromium/7881` Windows build and
verifies its sha256 before staging. Wiring:

- `tauri.windows.conf.json` ships the whole `resources` DIRECTORY as a
  Windows-only bundle resource — not the dll path directly. The directory
  always exists (this README is committed), so `tauri_build` resource
  validation passes on clean CI checkouts without the dll; when the dll is
  fetched, the NSIS/MSI installers place it under `resources/` beside the
  installed exe and `document/pdf.rs::bind()` resolves that layout.
  macOS bundles are unaffected — PDFium provisioning for macOS is a
  separate follow-up.
- `build.rs` copies it beside dev/test binaries (`target/<profile>/` and
  `target/<profile>/deps/`) when the `document` feature is enabled, so
  `cargo run`/tests find it without a manual copy.
- The publish workflow runs the fetch script before bundling.
