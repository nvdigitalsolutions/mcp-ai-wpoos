# Proposal 079: F&B Ingest × Media Worker Offload — Sidecar Parsing & OCR for Owner Data

**Date:** 2026-10-18
**Status:** IMPLEMENTED (core Phases 0-6 shipped: worker route + plugin seam + settings + tests). Outstanding: WP 7.1 dual-matrix validation run, Appendix B follow-on backlog.
**Estimated Effort:** ~5-6 developer-days (Phases 0-6).
**Priority:** MEDIUM (capability + hosting-footprint improvement; the local pipeline already works, this removes its memory ceiling and closes the photo-OCR loop).

> Scope: route the F&B toolkit's **file parsing** (XLSX/XLS/ODS, PDF text,
> photo/scan OCR) through the existing **Media Worker sidecar** when one is
> configured, with the current in-PHP path (PhpSpreadsheet, Smalot PdfParser,
> actionable OCR pointer) as the always-available fallback. The write path —
> `fnb_import_table` CPT upserts, dedupe, retention, banding, dashboard — stays
> in WordPress untouched. This proposal is **parse-only offload**: nothing
> about the matrix contract, the structural matcher, or native storage
> changes.

> Related in-repo work:
> [`078-fnb-data-ingestion-open-format-proposal.md`](078-fnb-data-ingestion-open-format-proposal.md)
> (the pipeline this offloads),
> [`031-media-worker-crawl4ai-integration-plan.md`](031-media-worker-crawl4ai-integration-plan.md)
> (the media-worker capability-addition precedent),
> [`media-worker-sidecar-proposal.md`](media-worker-sidecar-proposal.md)
> (the sidecar cascade architecture this plugs into),
> [`018-unlimited-ocr-integration.md`](018-unlimited-ocr-integration.md)
> (the repo's OCR provider track — future quality rung, out of scope here).

---

## 1. Executive Summary

The F&B open-format ingestion pipeline (Proposal 078) parses owner files
inside the WordPress PHP process:

- **XLSX/XLS/ODS** via PhpSpreadsheet — the one genuinely heavy path.
  PhpSpreadsheet holds every cell as a PHP object (~1.6 KB/cell on 64-bit
  PHP per the project docs); a 15,405-row workbook is hundreds of MB of
  PHP-FPM memory and can fatal on shared hosting with a 256 MB limit.
- **PDF** via Smalot PdfParser text layer — moderate, fine in PHP.
- **Photos/scans** — not parsed at all; the loader returns an actionable
  error pointing at the MCP vision tools (`analyze_image`,
  `extract_image_text`), leaving a manual two-step loop (agent OCRs → owner
  re-imports the text as CSV/JSON).
- **CSV/TSV/JSON/JSONL** — trivial PHP string work, correctly local.

The Media Worker sidecar already exists, is already wired into the plugin
(six cascade services + QR/translate handlers, admin settings page, health
checks, multipart upload helper), and already ships the two heavy
capabilities F&B needs — **tesseract.js OCR** (`/api/ocr/recognize`) and
**PDF text extraction + rasterisation** (`/api/pdf/extract`, `/api/pdf/render`).
What is missing is (1) a **spreadsheet-parse route** in the worker and (2) a
**cascade seam in `WP_MCP_AI_Fnb_Loader`** that routes `load_file()` through
the sidecar when configured, falling back to the in-PHP parsers otherwise.

This proposal adds exactly those two pieces — reusing the worker's existing
ExcelJS dependency (already used for `/api/document/excel` generation) for
XLSX/XLS reads, its OCR/PDF routes for documents and photos — and closes the
photo-OCR loop end-to-end (photo → worker OCR → matrix → import), all behind a
default-off settings toggle with graceful fallback. Parsing quality is
unchanged: the same `{columns, rows}` matrix contract, structure matcher,
dedupe, dry-run and import semantics as today.

**Outcome:** F&B ingest stops consuming PHP-FPM memory for workbooks, gains a
no-manual-step photo/scan path on sidecar-configured sites, and stays fully
functional on sites without a sidecar. The new worker route is toolkit-neutral
and is explicitly positioned as shared infrastructure for the follow-on
"toolkit offload sweep" (Appendix B).

---

## 2. Problem Statement

1. **Workbook parsing is the memory ceiling.** `WP_MCP_AI_Fnb_Loader::load_file()`
   (`addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-loader.php`)
   calls `load_spreadsheet()` → PhpSpreadsheet for every XLSX/XLS/ODS. The
   real-world source is a 15,405-row workbook; on a shared host this single
   parse can exceed `memory_limit` even though every other F&B operation is
   light. The dashboard's 500-row AJAX batching mitigates *write* time but not
   *parse* memory — the parse happens before the first batch.
2. **Photo/scan ingestion is a manual loop.** `load_file()` returns
   `wp_mcp_ai_fnb_photo_needs_ocr` for images. The operator (or an agent) must
   OCR externally and re-import the text. On sites with a sidecar, the worker's
   tesseract route could do this automatically — the capability exists one hop
   away and is not used.
3. **Scanned PDFs are only half-handled.** `extract_pdf_text()` returns an OCR
   pointer when the text layer is empty. The worker can already chain
   `/api/pdf/render` → `/api/ocr/recognize`; the plugin never does.
4. **The sidecar cascade architecture exists but the F&B loader is not wired
   into it.** Every heavy NPM operation the plugin knows about (OCR, PDF,
   video, email, code formatting, language detection) already routes through
   the sidecar with local fallback; F&B ingestion is the remaining local-heavy
   parser. There is no worker route for spreadsheet parsing at all.

Non-goals, stated explicitly so scope stays surgical:

- **No change to the matrix contract, structure matcher, dedupe, retention,
  banding, import bridge, or dashboard logic.** Only `load_file()`'s parse
  step gains an optional sidecar path.
- **No offload of the write path.** CPT upserts are WordPress-native and must
  stay there; Action Scheduler batching already governs their queue behaviour.
- **No CSV/TSV/JSON offload.** Parsing those is microseconds of PHP; shipping
  them over HTTP is strictly worse. The worker's `/api/data/csv-parse` exists
  but this proposal deliberately does not route F&B CSV through it.
- **No image *generation* offload.** The image-production toolkit
  (`generate_image_ai`, variations, inpainting, upscale…) is provider-API
  backed (DALL·E / Midjourney / Stable Diffusion / Gemini / Cloudflare) — its
  compute is already off the WP host, and routing it through the worker would
  add a hop without relieving load. See Appendix A.

---

## 3. Research: Industry Standards & Best Practices

Web research (2026-10-18) plus in-repo precedent. Three pillars: **spreadsheet
parsing memory behaviour**, **OCR tiering**, and **WP async/offload discipline**.

### 3.1 Spreadsheet parsing — where the memory actually goes

| Source | Finding |
|---|---|
| PhpSpreadsheet docs, "Memory saving" (phpspreadsheet.readthedocs.io) | ~1 KB/cell (~1.6 KB on 64-bit PHP) held as PHP objects; large workbooks exhaust memory quickly. Cell caching via a PSR-16 implementation (APCu/Redis/disk) is the sanctioned mitigation, at a speed cost, with a strict no-TTL caveat (cached cells cannot be regenerated). |
| SheetJS docs, "Data Import" (docs.sheetjs.com) | `XLSX.read()`/`readFile()` parse from a Buffer; **true streaming of XLSX is impossible** — the ZIP central directory sits at the end of the file, so the whole file must be buffered. Even in Node, a "large file" strategy means buffering with a compact in-memory representation, not streaming rows. |
| Media worker `src/routes/document.js` (in-repo) | **ExcelJS is already a worker dependency** (used for `/api/document/excel` generation). ExcelJS reads XLSX workbooks and offers a streaming worksheet reader (`workbook.xlsx.read`), so the new parse route needs **zero new npm packages** for XLSX/XLS. ExcelJS does not read ODS. |
| SheetJS format matrix (docs.sheetjs.com) | SheetJS reads ODS + legacy XLS; it would be the only new dependency, and only if ODS-in-worker parity is required. |

**Implication:** offloading workbook parsing to the worker does not get
"streaming" (impossible per the file format), it gets (a) a ~10–50× smaller
per-cell memory footprint in Node's compact representation vs PHP objects,
(b) memory pressure moved off the PHP-FPM pool that serves the website, and
(c) an optional job-queue path so large parses never sit in a web request.
The pure-PHP alternative — PhpSpreadsheet cell caching to Redis — is viable
and becomes the **fallback path** on sidecar-less hosts (see Option A).

### 3.2 OCR tiering — free/local first, vision API second

| Source | Finding |
|---|---|
| tesseract.js repo (github.com/naptha/tesseract.js) | Pure-JS/WASM OCR, 100+ languages, Node 16+ (v7). v6 fixed memory leaks and reduced memory/runtime; v4+ supports rotation/grayscale/binarisation preprocessing. Does not modify the model; no PDF input (Scribe.js fills that). Best on clean scans; weaker on skewed phone photos. Already installed in the media worker and already exposed at `/api/ocr/recognize` (15 MB multipart limit). |
| Google Cloud Document AI (cloud.google.com/document-ai) | The industry-standard invoice/receipt extraction service: Enterprise Document OCR at **$1.50 / 1,000 pages**; structured Custom Extractor / Form Parser at **$30 / 1,000 pages**; Gemini-powered custom extractors fine-tunable from ~10 documents. Correctly positioned as the *premium* rung for high-volume/high-accuracy invoice processing — not the default for a small F&B operator. |
| Gemini API, "Image understanding" (ai.google.dev/gemini-api/docs/vision) | Multimodal vision with **structured output schemas** (`response_format`), 20 MB inline-image limit, Files API for larger files, 258 tokens/768²-tile cost model. The plugin already ships Gemini providers and vision tools (`extract_image_text`, `analyze_image`) — so the high-quality rung already exists in-product; the worker route only needs to *reach* the same provider when asked. |
| Proposal 018 (in-repo) | The repo's OCR provider track (Unlimited-OCR / DeepSeek-OCR, self-hosted GPU) is a future quality rung; out of scope for this proposal but the seam must not preclude it. |

**Implication:** the worker OCR route is the free, local, privacy-preserving
first rung and the correct default for F&B photos; the provider-vision tools
remain the advertised high-quality alternative (the current error message
keeps pointing at them as a fallback when the sidecar path yields low
confidence or is absent).

### 3.3 WP-side offload and queue discipline

| Source | Finding |
|---|---|
| Action Scheduler (actionscheduler.org) | The WordPress standard for background job queues: batches of 25 actions per process, yields at 90% memory or 30 s, resumes via async loopback, logs every action. Proven on 50k-job queues at 10k actions/hour. The F&B import write path already fits this model (500-row AJAX batches); parsing offload must feed the *same* batch loop, not replace it. |
| Media worker sidecar proposal (in-repo, `media-worker-sidecar-proposal.md`) | The repo's own cascade discipline: service classes try **local Node → sidecar HTTP → local PHP/JS fallback**, each stage failing fast into the next. Six services already follow it; `WP_MCP_AI_OCR_Service` adds a multipart `sidecar_upload()` helper. F&B must adopt the identical pattern rather than invent a new routing scheme. |
| Proposal 031 (in-repo) | The media-worker capability-addition precedent: new route + queue processor registration (lazy processors per job type, `X-Site-Token` auth reconstructed from worker config, never stored in job data) + contract-preserving fallbacks. |
| Plugin admin settings (in-repo) | `WP_MCP_AI_Media_Worker_Settings` + `wp_mcp_ai_sidecar_json_post()` + health transient already standardise URL/token config (`WP_MEDIA_WORKER_URL` constant wins, then option) and reachability checks. The F&B toggle rides this config — no new connection plumbing. |

**Implication:** the design is a *cascade seam*, not a routing rewrite. When
the sidecar is absent, unreachable, or fails a parse, the loader behaves
exactly as today — same errors, same fallbacks, same dry-run output.

---

## 4. Options Evaluated

| # | Option | Feasible | Practical | Verdict |
|---|---|---|---|---|
| A | Keep everything in PHP; add PhpSpreadsheet PSR-16 cell caching (Redis) + bump limits | ✅ | ✅ | Keep as the **fallback/hardening path** — cheap insurance for sidecar-less hosts; does nothing for photo OCR |
| B | Move all F&B parsing to the worker (CSV included) | ✅ | ❌ | Rejected — CSV/JSON parsing is trivial PHP; moving it adds latency and a failure surface for no gain |
| C | **Sidecar cascade for the heavy formats only** (XLSX/XLS/ODS, PDF, photo) + shared worker `ingest/parse` route + photo-OCR loop closure | ✅ | ✅ | **Do this (Phases 1–6)** |
| D | Offload the import write path (CPT upserts) to the worker | ❌ | ❌ | Rejected — storage is WordPress-native; the worker cannot write CPTs, and the 500-row AS batch loop already governs write load |

---

## 5. Target Architecture

```
Owner file (Drive export, upload, source folder)
                    │
                    ▼
   WP_MCP_AI_Fnb_Loader::load_file( $path )
        │                          │
        │ sidecar configured?      │ no / unreachable / route missing
        ▼                          ▼
  POST {sidecar}/api/ingest/parse   local path (unchanged today):
  (multipart file or source path)     · CSV/TSV/JSON/JSONL → in-PHP parse
  · xlsx/xls  → ExcelJS read          · xlsx/xls/ods    → PhpSpreadsheet
  · ods       → SheetJS (if enabled)  · pdf             → PdfParser text
  · pdf       → /api/pdf/extract      · photo           → actionable OCR
  · image     → /api/ocr/recognize      pointer (MCP vision tools)
  · scanned pdf → /api/pdf/render
      │         → /api/ocr/recognize
      │
      ▼
  {columns, rows} matrix — identical contract
      │
      ▼
  Structure matcher → dry-run → (ACT) fnb_import_table
      │                              │
      │                              ▼
      │                    CPT upserts (unchanged,
      │                    AS batch loop, 500-row dashboard)
      ▼
  Ingest & Preview dashboard (same tool path, unchanged)
```

**Contract (worker → plugin):** the existing matrix —

```json
{ "success": true, "format": "xlsx", "columns": ["Date","Qty","…"], "rows": [["2026-09-01","12","…"], …], "meta": { "engine": "exceljs", "warnings": [] } }
```

Large parses may return chunked NDJSON with `has_more`/`next_offset`; the
plugin side reassembles or pages it into the existing import batches.

**Settings:** new F&B setting `fnb_use_media_worker` (`auto` | `off`, default
`auto`) — `auto` means "use the sidecar when the global media-worker URL is
configured and its health check passes". No new connection UI; the existing
Media Worker settings page stays the single source of truth. A
`wp_mcp_ai_fnb_worker_enabled` filter overrides for tests and deploys.

---

## 6. Scope Decision: What Is (and Is Not) Covered

### 6.1 In scope (this proposal)

1. **Worker:** `POST /api/ingest/parse` (auto-detect by extension/content;
   XLSX/XLS via the existing ExcelJS dependency; PDF and images delegated to
   the existing `/api/pdf/*` and `/api/ocr/recognize` internals; optional ODS
   via SheetJS behind an env flag; size limits matching the existing 50 MB
   PDF / 15 MB image caps). Optional async `ingest` queue job with callback,
   following the Proposal 031 lazy-processor pattern.
2. **Plugin:** cascade seam in `WP_MCP_AI_Fnb_Loader::load_file()` +
   `extract_pdf_text()` + the photo branch, using the existing
   `sidecar_upload()` multipart helper pattern; graceful fallback to the
   current in-PHP parsers on any sidecar failure; `fnb_use_media_worker`
   setting + filter.
3. **Photo/scan loop closure:** photo files OCR via the worker when enabled;
   scanned PDFs chain render → OCR; result text routed through the same
   header normalisation as any CSV import so it lands in the dry-run preview.
   The current actionable OCR-pointer error remains the fallback (and stays
   the only path on sidecar-less sites).
4. **Tests + docs** per Phase 5/6.

### 6.2 Shared infrastructure, not duplicated (no new work here)

- The worker's OCR/PDF routes are already used by the document-generation
  toolkit's `WP_MCP_AI_OCR_Service`. The new `/api/ingest/parse` route is
  deliberately toolkit-neutral; document-generation inherits spreadsheet
  parsing for free once it chooses to call it (follow-on, Appendix B).

### 6.3 Explicitly out of scope

- **Image generation toolkit** — provider-API compute, nothing to offload
  (Appendix A).
- **Import writes, matcher, dedupe, dashboard logic** — WordPress-native.
- **CSV/TSV/JSON/JSONL offload** — no benefit (Option B rejected).
- **Document-generation's own local parser paths** — Appendix B follow-on.
- **CG-Pro mirror parity** — the F&B classes exist in
  `plugins/nvoos-content-graph-pro` under the ecosystem-port loop; the seam
  must be ported there once merged (Appendix B, item B4).

---

## 7. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Sidecar round-trip adds latency to small parses | Size/format gate: route only XLSX/XLS/ODS/PDF/photo to the worker; keep text formats local. Timeout 120 s with local fallback on `WP_Error`. |
| Owner data transits to the worker | Documented as a settings-page note; worker is on-prem/container in the standard deployment (Proposal 026 multi-tenancy already scopes per-site data); data is held in memory/scratch only, never logged. Provider-vision path (when used) already exists today, so no new exposure class. |
| Worker route missing on older deployments | Capability probe: `GET /api/status` or route-404 → permanent per-request fallback to local parsing (the existing `class_exists` / health-check discipline). |
| ODS parity regression on sidecar sites | ODS stays on the local PhpSpreadsheet path unless SheetJS is explicitly enabled; loader falls back per-format, not per-file-batch. |
| Big matrix payloads over HTTP | Chunked NDJSON + `has_more`; plugin pages it into the existing 500-row import batches rather than buffering the whole matrix. |

---

## 8. Success Criteria

1. On a sidecar-configured site, a 15,405-row XLSX import completes with
   PHP-FPM peak memory below the local-parse baseline (measured via a
   fixture benchmark; see Phase 0).
2. On a sidecar-less site, all existing F&B ingest tests pass unchanged —
   zero behavioural drift when the toggle is off or unreachable.
3. A photo of a supplier invoice, dropped into the source folder, appears in
   the Ingest & Preview dry-run on a sidecar site without any manual OCR
   step.
4. Worker route ships with jest coverage (following `crawl.test.js`
   conventions) and the plugin seam with PHPUnit fixture tests using the
   repo's zero-HTTP (`pre_http_request`) convention.
5. `phpcs` (both standards) clean; coverage manifest updated; no new tool
   slugs (this is a parsing-seam change, not a tool change).

---

## Appendix A — Why the image-generation toolkit is not in this proposal

`addons/pro/includes/tools/image-production/` tools (`generate_image_ai`,
`generate_image_variations`, inpainting, background removal, upscale, style
transfer, compression) execute against **provider APIs** (DALL·E, Midjourney,
Stable Diffusion, Gemini, Cloudflare) or local, small, single-image operations.
The WP host's work is assembling requests and sideloading results; there is no
heavy local compute to take off. The worker's `/api/image/generate` and
`/api/image/optimize` (Sharp) are alternate *provider* and *batch* paths —
their adoption by the image toolkit is a media-workflow/optimisation decision
(`design-media-workflow`, `design-image-optimization` skills), separate from
ingest offload.

## Appendix B — Follow-on backlog (toolkit offload sweep)

1. **Document-generation toolkit:** DONE (2026-10-18) — `extract_pdf_text` was
   already sidecar-first; `WP_MCP_AI_OCR_Service::extract_text_from_pdf()` now
   routes auto/tesseract scans through the worker ingest chain with local
   fallback. Remaining: Word/Excel *reads* (no current consumer).
2. **Image-production batch operations:** DONE (2026-10-18) —
   `/api/image/batch` hardened (per-file b64, jpeg/png/original formats,
   `files[N]` naming, per-file error tolerance) and `optimise_images_batch`
   routes its non-dry-run batch through it; GD remains the fallback.
3. **Provider-vision OCR rung:** DONE (2026-10-18) — opt-in F&B
   `ocr_vision_fallback` setting (temporary attachment + `extract_image_text`,
   then deleted; failures keep the pointer with `vision_error` data).
4. **Sweep methodology:** run `mcp-ai-wpoos-toolkit-audit` per toolkit for
   remaining local-heavy parsing (still open — next sweep target).
5. **CG-Pro mirror:** port the F&B loader seam + Appendix B wiring to
   `plugins/nvoos-content-graph-pro` per the ecosystem-port byte-identity
   rules once merged to `alpha-working` (still open).
