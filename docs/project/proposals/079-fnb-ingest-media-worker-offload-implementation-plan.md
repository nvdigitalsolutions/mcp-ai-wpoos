# Proposal 079 — Implementation Plan: F&B Ingest × Media Worker Offload

**Proposal:** [`079-fnb-ingest-media-worker-offload-proposal.md`](079-fnb-ingest-media-worker-offload-proposal.md)
**Date:** 2026-10-18
**Status:** IMPLEMENTED - core phases plus Appendix B items 1-3 (OCR-service PDF wiring, image batch offload, vision OCR rung) landed and validated (worker jest 31/31; PHPUnit 154 tests / 961 assertions green on WP 6.9 via the cross-worktree Docker runner). Outstanding: WP 7.1 validation matrix, the CG-Pro mirror port (Appendix B item 5), and the remaining toolkit-audit sweep (item 4).
**Effort:** ~5-6 developer-days across Phases 0-6.

---

## Phase 0 — Baseline & fixtures (0.5 day)

**Goal:** a measurable memory baseline and reusable fixture matrix, so the
success criteria in the proposal §8 are checkable.

- [ ] **P0.1** Add a benchmark fixture: generate a synthetic ~15k-row,
      12-column XLSX (mirrors the real daily-sales workbook shape) under
      `addons/pro/includes/tools/food-beverage/tests/fixtures/`.
- [ ] **P0.2** Add a PHPUnit benchmark helper (not a CI-gated perf test —
      a `phpunit --group=fnb-bench` script) that parses the fixture via the
      current `WP_MCP_AI_Fnb_Loader::load_file()` and reports
      `memory_get_peak_usage()`.
- [ ] **P0.3** Record the baseline in the PR description / release notes.
- [ ] **P0.4** Add the same fixture as a jest fixture for the worker route
      (Phase 1) so both sides measure against identical input.

**Gate:** baseline number captured; fixtures committed.

---

## Phase 1 — Worker: `/api/ingest/parse` route (1.5 days)

**Goal:** one toolkit-neutral parse endpoint returning the F&B matrix
contract, reusing existing worker dependencies and routes.

- [ ] **P1.1** New `addons/media-worker/src/routes/ingest.js`:
  - `POST /api/ingest/parse` — multipart `file` (managed-host contract) or
    `source` path (shared-volume contract), mirroring `pdf.js`'s
    `resolveSource()` + `pathGuard` discipline.
  - Format auto-detect by extension with content fallback (`xlsx`, `xls`,
    `ods`, `pdf`, `png/jpg/webp/…`).
  - **XLSX/XLS** via ExcelJS (`workbook.xlsx.read`) — already a dependency
    (`document.js`) — converting the first sheet (or a `sheet` param) to the
    `{columns, rows}` matrix. No new npm packages.
  - **ODS** behind an env gate (`INGEST_ODS=1`) via SheetJS — the only new
    dependency, lazy-imported, `503 capability_unavailable` when disabled
    (same shape as `pdf.js`'s canvas guard).
  - **PDF** delegates to the existing `/api/pdf/extract` internals; if the
    text layer is empty, chain `/api/pdf/render` + `/api/ocr/recognize`
    per page (scanned-PDF loop).
  - **Images** delegate to the existing tesseract OCR internals
    (`/api/ocr/recognize` body) with the same 15 MB cap.
  - Response: `{ success, format, columns, rows, meta }`; for matrices over
    a size threshold, chunked NDJSON with `has_more`/`next_offset` and an
    `offset` param.
- [ ] **P1.2** Register the route in `src/index.js` alongside the existing
      routers (token-gated like every `/api` route).
- [ ] **P1.3** Async support: register a lazy `ingest` queue processor in
      `src/queue.js` following the Proposal 031 v3.1.1 pattern (replays the
      job through the sync endpoint, reconstructs `X-Site-Token` from worker
      config, never stores it in job data). Add optional `callback` URL with
      the SSRF `validatePublicUrl` guard (or site-namespace loopback).
- [ ] **P1.4** Jest coverage in `src/routes/ingest.test.js` (mirror
      `crawl.test.js` / `image.test.js` conventions): xlsx fixture → matrix
      shape, scanned-PDF chain, ODS gate 503, size-limit 413, auth 401.

**Gate:** `npm test` green in the worker; matrix contract matches the PHP
loader's output for the same fixture (byte-shape comparison, not byte-equal —
normalise both sides in the test harness).

---

## Phase 2 — Plugin: loader cascade seam (1.5 days)

**Goal:** `load_file()` tries the sidecar for heavy formats and falls back to
the current parsers — with zero behavioural drift when off.

- [ ] **P2.1** New `addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-worker-client.php`:
  - Reuses the existing config resolution (constant → option) and health
    check pattern from `WP_MCP_AI_Media_Worker_Settings` /
    `wp_mcp_ai_sidecar_json_post()`; adds `send_file()` multipart via the
    same `sidecar_upload()` helper shape used by `WP_MCP_AI_OCR_Service`
    (extract the helper into a shared method if it is trait-private today).
  - `parse_file( $path, $opts )` → matrix array or `WP_Error`; 120 s timeout;
    maps worker errors onto the existing F&B error codes
    (`wp_mcp_ai_fnb_file_unreadable`, `wp_mcp_ai_fnb_photo_needs_ocr`, …)
    so callers see identical error vocabulary.
  - Capability probe: `GET /api/status` (or a cheap route 404 check) cached
    per-request; a missing `ingest` route falls back permanently for that
    request, never retried in a loop.
- [ ] **P2.2** Wire the seam into `WP_MCP_AI_Fnb_Loader::load_file()`:
  - `should_offload()` — `fnb_use_media_worker` setting (`auto` default),
    `wp_mcp_ai_fnb_worker_enabled` filter, sidecar configured + reachable,
    extension in the heavy set (`xlsx|xls|ods|pdf|png|jpg|jpeg|webp`).
  - On any `WP_Error` from the worker: fall through to the existing local
    branch **unchanged** (including the photo OCR-pointer error).
  - `scan_sources()` keeps its local-only scan (unchanged) — it already
    skips unparseable/OCR files; add a follow-up task only if profiling
    shows scan time matters (not in this phase).
- [ ] **P2.3** Settings: `fnb_use_media_worker` on the F&B settings page
      (register alongside existing fields; description notes the data-transit
      caveat from proposal §7). Respect `WP_MCP_AI_BASE_VERSION` guards per
      repo convention.
- [ ] **P2.4** Docblock updates in `food-beverage/README.md` (Inputs section)
      documenting the cascade and the settings toggle.

**Gate:** with toggle off / no sidecar, the full existing F&B ingest PHPUnit
suite passes untouched; with a mocked sidecar (`pre_http_request` filter),
`load_file()` returns sidecar matrices and falls back on injected failures.

---

## Phase 3 — Photo & scanned-PDF loop closure (1 day)

**Goal:** photos and image-only PDFs become importable end-to-end on sidecar
sites; nothing changes on sidecar-less sites.

- [ ] **P3.1** Photo branch: when `should_offload()`, POST the image to the
      worker OCR path; pipe `data.text` through
      `WP_MCP_AI_Fnb_Structure_Matcher::best_table()` (headers inferred as a
      one-row-per-line CSV heuristic) so it lands in the same dry-run preview
      as any text import. Keep the current actionable error as the no-sidecar
      fallback, and return it on low confidence (`confidence` below a
      configurable floor, default e.g. 60) with the OCR text attached so the
      operator can still self-serve.
- [ ] **P3.2** Scanned PDFs: `extract_pdf_text()` — on empty text layer and
      sidecar available, chain render → per-page OCR (worker-side, Phase 1
      already implements the chain); on no sidecar, return the existing OCR
      pointer.
- [ ] **P3.3** Raw preservation: OCR-derived rows carry
      `raw_value` / `_fnb_raw` exactly like any imported row (no new
      preservation path) — the Observation layer already handles it.

**Gate:** fixture photo + fixture scanned PDF both produce a dry-run preview
via the dashboard's AJAX path on a mocked-sidecar test; error paths asserted.

---

## Phase 4 — Import bridge & dashboard integration (0.5 day)

**Goal:** the import flow treats sidecar-parsed matrices identically to local
ones, including paging and cache invalidation.

- [ ] **P4.1** `fnb_import_table` unchanged in logic; verify it consumes the
      sidecar matrix through the *same* normalisation path (it should —
      the seam lives in the loader, so this is a verification + test task,
      not new code).
- [ ] **P4.2** Paging: when the worker returns chunked NDJSON, the dashboard
      loop consumes `has_more`/`next_offset` per 500-row batch (reuse the
      existing recursion; add the `next_offset` source field to the loop).
- [ ] **P4.3** Cache invalidation parity: after any import whose parse came
      from the sidecar, the existing transient invalidation (`:cpt` + source
      keys) runs exactly as today (assert in tests — regression risk if the
      seam bypasses the normal write path).

**Gate:** end-to-end PHPUnit: sidecar-mocked 15k-row fixture → import →
`native_count === source_total` and tail-row assertions from the 078 fix.

---

## Phase 5 — Tests, lint, manifests (1 day)

- [ ] **P5.1** PHPUnit: new `tests/test-fnb-worker-offload.php` —
      should-offload matrix (setting × constant × health), fallback-on-error,
      error-code mapping, photo path, chunked paging, zero-HTTP convention
      (`pre_http_request`, no live sockets).
- [ ] **P5.2** Regression: run the full F&B suite + `test-fnb-native-storage.php`
      + `test-fnb-data-ingestion*` in the Docker test environment
      (`mcp-ai-wpoos-test-suite` skill).
- [ ] **P5.3** `phpcs` both standards on touched PHP; `npm test` + eslint in
      the worker; `composer run ci:all`-relevant subset.
- [ ] **P5.4** Coverage manifest + `TOOLS_LIST.txt`: no new tool slugs —
      annotate the manifest's F&B entry with a "worker-seam" note if the
      manifest format allows, otherwise leave untouched and note in the PR.
- [ ] **P5.5** Worker README: document `/api/ingest/parse`, the ODS gate,
      and the queue processor (follow the crawl/ocr README sections).

**Gate:** CI green; no production-code drift in `plugins/nvoos-content-graph-pro`
(this phase touches only `addons/pro` + `addons/media-worker`).

---

## Phase 6 — Docs, release notes, follow-on backlog (0.5 day)

- [ ] **P6.1** Update `mcp-ai-wpoos-toolkit-ingestion` skill: add the sidecar
      cascade to §2 (multi-format loader) and a "worker offload" note with
      the toggle + fallback discipline.
- [ ] **P6.2** Update `design-media-workflow` skill's worker-endpoint table
      with `/api/ingest/parse`.
- [ ] **P6.3** Changelog + release-note entries (Pro + worker version bump).
- [ ] **P6.4** File the Appendix B follow-on items as GitHub issues
      (document-generation PDF paths; image-production batch ops; CG-Pro
      mirror port; provider-vision rung) with this proposal linked.

**Gate:** skill + changelog PRs merged alongside the code PR(s).

---

## Rollout & safety notes

- **Default `auto`, toggle `off` available.** Sites without a sidecar see
  zero change. Sites with a sidecar gain offload on the next parse.
- **Ship order:** worker route first (backwards-compatible addition), plugin
  seam second (falls back to local when the route is absent on older
  workers) — either order is safe, this order is testable in isolation.
- **Monitoring:** worker logs per-format parse counts + durations; the F&B
  audit-log tool records `source: sidecar|local` per import so drift is
  diagnosable from within the product.
- **Data governance:** reiterate in the settings description that enabling
  offload sends owner file contents to the worker container (proposal §7).
