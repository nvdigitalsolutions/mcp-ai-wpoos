# Laravel production-toolkit parity (Proposal 164)

Reference for porting a **Pro toolkit** onto the Laravel runtime (`lib/core` +
`lib/laravel-adapter` + `docker/laravel/app-skeleton`). This is a *different*
loop from the base+pro → Content Graph port (`mcp-ai-wpoos-ecosystem-port`):
that one copies files byte-identical into `plugins/nvoos-content-graph-pro`;
this one re-expresses a capability against framework-neutral contracts and
serves it from the package registry. The source of record is
`docs/project/proposals/164-laravel-production-toolkit-parity-proposal.md` —
its addenda (§164.10 →) carry the measured detail for each slice.

## Where a ported tool lives

| Layer | Path | Rule |
|---|---|---|
| Domain contracts | `lib/core/src/Domain/Contract/*Interface.php` | A capability seam, not a WP excerpt. |
| Domain services | `lib/core/src/Domain/**`, `Application/**` | State machines, composers, formatters. |
| Tools | `lib/core/src/Tool/<Family>/*Tool.php` | One class per slug, `@since` = **package** version (2.x/3.x), never the plugin version. |
| Host adapters | `lib/laravel-adapter/src/Adapter/*` | Only implementation that touches the app/worker/FS. |
| Readiness | `lib/core/resources/tool-readiness.php` | A tool whose engine is unbound is **not servable** — leave it out. |
| Parity baseline | `lib/core/resources/toolkit-parity-baseline.json` | Generated (`bin/toolkit-parity.php --write`), lists the served set per toolkit. |
| Decisions | `lib/core/resources/toolkit-parity-decisions.json` | `declined` vs `deferred`, each with a reason. |

The package registry is its **own** registry. Nothing here registers in
`includes/class-wp-mcp-ai-tool-registry.php`, so base/Pro counts never move
(the dual-layer rule; the catch-up states "described, not counted").

## Honesty rules (non-negotiable, §164.4.8)

1. A tool whose engine is unbound is **not servable** — it stays out of the
   readiness manifest, exactly as the quarantined prompt-builders do.
2. A tool that needs an approval the operator does not hold returns a **named
   capability error**, not a generic failure.
3. Every output is a `StoredFile` from the file registry. A tool that
   "generated a PDF" but left no file is the prompt-builder defect in a new
   costume.
4. Fidelity class is declared by the caller; a portable renderer never silently
   substitutes for a browser renderer.
5. No tool claims a platform capability the registry does not declare.

A stub on WordPress is not a spec. Several Pro tools are stubs
(`optimize_for_platform` did nothing); the port implements the capability and
records the deliberate deviation rather than reproducing the stub.

## The register: declined vs deferred

Two different non-ports, and conflating them corrupts the remaining count:

- **`declined`** — not ported and **not intended**. Subtracted from remaining.
  The usual reason is "it is a loop over primitives a caller can write", and the
  reason must say why a prerequisite would not make it more than a loop.
- **`deferred`** — not ported, **blocked on a named prerequisite**. Stays in
  remaining, and the entry names the prerequisite (e.g. "a Google Classroom
  client (OAuth + API) and the course-to-ECA link store").

`ToolkitParityDecisions` reads the JSON in the runtime (so a console or a tool
can explain a gap), and `bin/toolkit-parity.php` fails only when a **served**
slug disappears from the baseline. Regenerate the baseline after a port; never
hand-edit it. Measure the toolkit **before** building the first slug (§8298) so
"2 of 23" is a measured number, not an estimate.

## Documents

- **Tiered renderer, declared fidelity.** `PdfDocumentComposer` picks a tier;
  the caller declares the fidelity it needs. A portable renderer never silently
  stands in for a browser renderer, and adopting Gotenberg everywhere was
  rejected because it forces a container on every host (§164.5).
- `HtmlFormatter` is the shared HTML → document-tree layer; the invoice path is
  a composer over it, not a second formatter.
- Word (`.docx`) and Excel (`.xlsx`) are real writers; the Excel route can
  **freeze** panes and **compute** on export. Archive is a **state** on the
  record, not a delete.
- OCR is a ladder: extract text layer → render pages → per-page recognise, with
  a `clampPageBudget` page budget. `pro_batch_ocr` loops the ladder; the vision
  path must hand the model **real page images**, not a thumbnail.

### Controlled documents (QMS)

`DocumentLifecycle` writes the state machine **once**:

```
draft ──▶ review ──▶ approved ──▶ released ──▶ superseded ──▶ obsolete
  ▲          │                                        │
  └──────────┘  (rejected)                            ▼
                                                    obsolete
```

Ten `qms_*` tools read from it; they do not re-derive the states. Grounding:
ISO 9001 §7.5.2 (review/approval for suitability and adequacy), §7.5.3
(versioning and prevention of unintended use), 21 CFR Part 11 for signatures.

## Docs corpus and OKF

- `DocsCorpusInterface` + `FilesystemDocsCorpus` back five `docs_hub_*` tools
  (list, read, write, delete, rebuild).
- OKF needed a **bundle-name → root** seam, not a writer (the writer already
  existed): `OkfBundleLocatorInterface` + `FilesystemBundleLocator`, with the
  bundle name validated as **one path segment**.
- `okf_enrich_site_content` writes one concept per published record;
  `HybridKnowledgeRouter` + `route_knowledge_query` decide which store answers a
  knowledge question first. A stale "OKF has no writer" note was corrected —
  check the code before trusting a programme note.

## Files, uploads and memory

- **Every stored artifact goes through the file registry** (see v1.2.23's
  `nvoos_files`). `FileDerivativeStoreInterface` records derivatives;
  `produced file → source` links are explicit.
- **Resumable uploads run over tus core** (`nvoos_upload_sessions`), not a
  bespoke offset protocol.
- **`ext-fileinfo` is required** — the store sniffs the type; a filename is
  never a type and never a path.
- **A package-only install has no app skeleton.** The adapter's Testbench suite
  is exactly that install: any column the app skeleton's migrations added must
  also exist in a package migration
  (`2026_11_26_100000_add_file_identity_columns_to_nvoos_files` added the five
  file-identity columns after `store()` threw on an unknown column).
- **Provenance is one nullable JSON column, not a column per concept.** The
  vocabulary is open (Zep `valid_at`/`invalid_at`, mem0 `metadata`, a toolkit
  capture policy); `NULL` means "nothing recorded" and must read back as `[]`.
  Silently dropping fields under `success: true` is worse than refusing.

## Audio and video

Video is a **two-step contract**: the tool asks the worker, then polls or
collects the result; the envelope carries the job id. Rules learned in the
family:

- `extract_audio` **copies** the stream (no re-encode) and reports
  `reencoded: false`; `probeMedia()` may not be able to answer every question,
  so the copy path is the honest default.
- `xfade`/`acrossfade` cannot copy; they re-encode. The offsets are
  **cumulative**: `offset_i = Σ(duration_j − transition_j)`. Normalise the pair
  before the cross-fade, look names up rather than relaying them, and note that
  a WebM input exposes a different bug than MP4.
- `atempo`'s range is a **version** question, not a constant; `setpts` inverts
  the factor while `atempo` does not; pitch is a separate decision.
- Ken Burns `zoom` **upscales before it pans** — a 1:1 pan shimmers.
- `extract_video_frames`' three modes are alternatives; many-file returns use a
  contract (a list of `StoredFile`), not a widened single-file type.

## Image

libvips first with an Imagick **compatibility** tier: ImageMagick's memory
profile at 50 MP is roughly 400 MB per image, which is a per-request OOM on a
small box. `resize_image_smart` closes the image-production toolkit; a batch
over primitives is declined rather than ported.

## Validation

- `lib/core` PSR-12 gate (`phpcs-laravel.xml.dist`) — 0 errors/0 warnings.
- `lib/core/tests/Unit/Tool/<Family>…` for the tool contract;
  `lib/laravel-adapter/tests/Feature/…` for the adapter against a real worker
  or a fake; `ToolProbesTest` asserts each new slug is registered.
- `bin/toolkit-parity.php` (no `--write`) must pass; regenerate the baseline
  with `--write` only after the slug is served.
- Run the app + adapter + core suites; a filtered run can hide a cross-suite
  registration failure. See the test-suite skill for the Docker runner.

## Recording the slice

Each slice appends an addendum to proposal 164 with: what shipped, the
**measurement** (counts before → after), the deliberate deviations from
WordPress and why, and what is still open **with the reason**. A count without
a measurement is a claim; the proposal's measurement blocks are what later
passes verify instead of trusting the tracker prose.
