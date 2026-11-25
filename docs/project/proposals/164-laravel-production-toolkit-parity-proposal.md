# 164 — Laravel production-toolkit parity: documents, image, video, outreach, CMS

**Status:** Proposed (2026-11-24)
**Surfaces:** `lib/core/src/Domain/Contract/` (renderers, processors, `MediaWorkerClientInterface`), `lib/core/src/Application/` (capability registry reader, worker health probe, job poller), `lib/core/resources/production-capabilities/` (new data), `lib/laravel-adapter/src/Adapter/` (engine + worker bindings), `includes/class-wp-mcp-ai-media-worker-config.php` (existing contract to mirror), `docker/laravel/app-skeleton/app/Filament/` (toolkit screens already exist), `bin/toolkit-parity.php` (new gate)
**Related:** [090-M](./090-m-tool-registration-and-readiness.md) (readiness manifest and the port loop), [096](./096-astro-forge-wp-generator-mcp-proposal.md) (Astro Forge — the CMS target), [095](./095-astro-design-system-mcp-proposal.md) / [092](./092-astro-storefront-layer-proposal.md) (design tokens, storefront), [141](./141-generative-media-capability-registry-proposal.md) (generative-media capability registry — this extends its idea to *processing*), [162](./162-file-storage-and-visibility.md) (every output lands in the file registry), [129](./129-outreach-crm-sellbird-hardening-proposal.md) (outreach CRM), [028](./028-media-worker-phase3-proposal.md) / [074](./074-media-worker-local-model-providers-proposal.md) (media worker), [143](./143-expert-service-exchange-proposal.md) (estate provisioning, referenced as out-of-scope)

## 164.1 The problem, with evidence

The Laravel runtime serves the Base tool surface. The Pro *toolkits* are largely absent, and the two axes are easy to confuse, so both are measured here.

**Console parity is already done.** The 21 toolkit manifests are byte-identical on both runtimes:

```
$ diff <(ls addons/pro/includes/toolkits/manifests/ | sort) \
       <(ls docker/laravel/app-skeleton/resources/pro-toolkits/ | sort)
(no output)
```

Laravel has the full `/pro/toolkits` REST surface (settings, entities, dashboard, research drafts) and Filament `ToolkitsPage` / `ToolkitPage`.

**Tool parity is not.** Counting declared `get_slug()` values in each Pro toolkit and intersecting them with `lib/core/resources/tool-readiness.php` (measured 2026-11-24):

| Toolkit | WP tools | Ported | Ported slugs |
|---|---:|---:|---|
| `eca-management` | 48 | **13** | create/get/update/delete ECA + student, enrol, withdraw, attendance, waitlist, bulk |
| `project-management` | 19 | **9** | projects + tasks (CRUD), dependencies |
| `document-generation` | 38 | **1** | `generate_email_template` |
| `image-production` | 22 | **1** | `convert_image_format` |
| `social-media` | 32 | **0** | — |
| `site-creator-toolkit` | 32 | **0** | — |
| `video-production` | 21 | **0** | — |
| `media` | 9 | **0** | — |
| `email-marketing` | 7 | **0** | — |

Two of these differ from the rest and the difference matters:

- `site-creator-toolkit` has **no manifest either**, so it is missing on *both* axes — the only family with no Laravel surface at all.
- `social-media`, `email-marketing` and the media families have console screens but no executable tools, which is the "rendered control with no reader" failure this project treats as a defect at tool level: the console implies the capability exists.

**Generation is not the gap; production is.** The AI generation tools are *Base* tools and already served — verified: `generate_openai_image`, `generate_gemini_image`, `generate_sora_video`, `generate_veo_video`, `generate_music`, `generate_post_excerpt`, `analyze_image`, `analyze_video` all live in `includes/tools/` and are `ready`/`port`. What is missing on Laravel is everything that happens *around* a generated asset: rendering a document, transcoding a video, removing a background, scheduling a social post, building a site.

So the honest statement of the gap is: **an agent on Laravel can generate an image and cannot then resize it for a platform, transcode the video it generated, produce the invoice that describes it, publish it, or build the site it belongs on.**

## 164.2 What WordPress does, and where we deliberately diverge

| Domain | WordPress implementation | Divergence |
|---|---|---|
| Documents | `TCPDF` bundled in `addons/pro/vendor/tecnickcom/tcpdf`, plus FPDI for merge/watermark | We do **not** adopt TCPDF as the primary engine. It is a parser-based renderer with limited CSS; it is kept only as the no-dependency fallback. Rendered documents should look like modern HTML because they are modern HTML. |
| Image | `GD` / `Imagick` via PHP | Prefer **libvips** through the media worker for throughput and memory; Imagick remains the compatibility tier for exotic delegates. GD is the last resort, not the default. |
| Video | Local `ffmpeg` when present, discovered by `WP_MCP_AI_Process_Service::is_command_available()` | Same binary, but the Laravel side does not run it in the request. Long work becomes a job with progress, matching the async contract already used for generation (141). |
| Outreach | `social-media` and `email-marketing` toolkits, per-platform code paths | Platform **limits, approval gates and text caps are data**, not branches. The 2026 platform landscape changes faster than tool code should. |
| CMS | `site-creator-toolkit` targets **Elementor** (`create_hero_section`, `elementor`, `install_and_activate_theme`, `export_template_kit`) | We do **not** port Elementor tooling to a runtime with no Elementor. The Laravel target is the repo's own generator stack: **Astro Forge** (096) for the site, **design tokens** (095) for the look, and Content Graph blocks for the content. This is the one place where parity is explicitly *not* the goal — a faithful Elementor port would be a tool that cannot work. |

## 164.3 Standards applied

Researched 2026-11-24; each standard drives a specific decision below.

**HTML→PDF.** The 2026 consensus sorts engines into three families — browser-based (Gotenberg, Puppeteer, Playwright) which render exactly like Chrome; layout engines (WeasyPrint) which implement print CSS without JavaScript; and parser-based (dompdf, mPDF) which handle simple documents and Unicode (mPDF) but not modern CSS. `wkhtmltopdf` is retired and should not be introduced. → **Tiered renderer**, see 164.4.3.

**Image processing.** libvips/Sharp stream and hold a fraction of the memory that ImageMagick needs for the same spatial operation; ImageMagick remains the right choice for unusual formats and delegate-heavy effects; GD is lightweight and limited. → **Engine preference order** with an explicit capability probe, see 164.4.4.

**Video.** For VOD, a 4–6 rung ladder with capped CRF, keyframes aligned to segment length, and CMAF/fMP4 packaging; Apple's HLS authoring guidance recommends ~6-second segments with a keyframe every 2 seconds. Progressive output needs `+faststart`. → **Presets, not knobs**, see 164.4.5.

**Outreach/publishing.** In 2026 the platforms differ in *access model* before they differ in limits: X is pay-per-use; Meta is free but gated by App Review (Instagram 100 posts/day, Facebook Page 25); LinkedIn's Community Management API needs partner approval; TikTok requires a sandbox audit and caps a creator at roughly 15 direct posts/day shared with the app itself; YouTube is quota-priced. Text limits span 280 (X) to 63,206 (Facebook). → **A capability registry with an approval status per platform**, see 164.4.6.

**Email deliverability.** Gmail and Yahoo bulk-sender requirements are enforced together: SPF and DKIM passing, DMARC published and aligned to the From: domain, and RFC 8058 one-click unsubscribe (`List-Unsubscribe` plus `List-Unsubscribe-Post`). → **Preflight, not hope**, see 164.4.6.

**Provenance and storage.** Generated and processed files go through the file registry designed in 162 — id, visibility, owner, checksum, MIME policy — so a document a tool produced is the same kind of object as a media upload.

## 164.4 Design

### 164.4.1 Three principles

1. **A capability ladder, not a single engine.** Every domain declares tiers: *portable* (pure PHP / in-process), *service* (the media worker, an HTTP renderer), *hosted* (a paid provider). A host binds what it has; a tool asks for a capability and reports honestly which tier answered. This is the same shape 141 applies to generation, extended to processing.
2. **Declared, not inferred.** Engine availability, platform limits and approval status are data with a schema validated in `lib/core` — the pattern `ToolkitManifest` already establishes — so both runtimes reject the same bad documents.
3. **Long work is a job.** Anything over a few seconds returns an operation id and a status contract, exactly as `WP_MCP_AI_Higgsfield_Client` already does. No synchronous transcode behind a chat turn.

### 164.4.2 The media worker is the processing tier

The repo already operates a **media worker** — a sidecar HTTP service (Proposals [025](./025-media-worker-cloud-deployment-security-implementation-plan.md)–[031](./031-media-worker-crawl4ai-integration-plan.md), [074](./074-media-worker-local-model-providers-proposal.md), [079](./079-fnb-ingest-media-worker-offload-proposal.md)) that owns the heavy binaries. It is not a new component in this plan. Studying its surface **changed the shape of this proposal**: most of the "engines" that would otherwise be built are already HTTP endpoints on it.

**Its auth and routing contract already exists** in `WP_MCP_AI_Media_Worker_Config`, and `lib/core` should mirror it rather than invent a second one:

| Concern | WordPress (today) | `lib/core` (this plan) |
|---|---|---|
| Base URL | `WP_MEDIA_WORKER_URL` → `wp_mcp_ai_media_worker_url` | `MEDIA_WORKER_URL` env → config |
| Token | `WP_MEDIA_WORKER_TOKEN` → per-blog option → site option → `wp_hash( home_url() )` | `MEDIA_WORKER_TOKEN` env → config. **No salted fallback** — a Laravel host has no WP auth salts, and inventing one would silently mint a token no worker accepts |
| Auth headers | `X-Site-Token`, `X-Site-Url` | same headers, same meaning |
| On/off switch | URL configured | URL configured |
| Not configured | `WP_Error( 'wp_mcp_ai_worker_not_configured' )` | typed capability error, **same code string**, so logs read the same across runtimes |

The contract is one interface:

```php
interface MediaWorkerClientInterface {
    public function isConfigured(): bool;
    /** @return array<string,mixed> Decoded JSON body. */
    public function request( string $endpoint, string $method = 'GET', array $body = array(), int $timeout = 5 );
    /** @return array<string,bool> capability => available, from /api/health/full */
    public function capabilities(): array;
}
```

**Endpoints the worker already exposes** — verified by enumerating endpoint strings across `includes/**` and `addons/pro/includes/**`; these are the worker's own, not third-party clients:

| Domain | Endpoints |
|---|---|
| Documents | `/api/pdf/generate`, `/api/pdf/extract`, `/api/pdf/merge`, `/api/document/word`, `/api/document/excel` |
| OCR / ingest | `/api/ocr/recognize`, `/api/ingest/parse` |
| Image | `/api/image/batch`, `/api/image/edit`, `/api/image/enhance`, `/api/image/optimize`, `/api/image/upscale`, `/api/image/vectorize` |
| Video | `/api/video/info`, `/api/video/process`, `/api/video/download/{id}` |
| Email | `/api/email/send`, `/api/email/compile-mjml`, `/api/email/verify` |
| Render / data | `/api/data/render-chart`, `/api/data/render-math`, `/api/data/qrcode`, `/api/data/translate`, `/api/data/generate-ics`, `/api/data/language-detect`, `/api/data/phone-format`, `/api/data/analyze-geospatial` |
| Code | `/api/code/format`, `/api/code/check-syntax` |
| Health | `/api/health`, `/api/health/full` |

**What that changes.** Phase 1 does not need to *build* a PDF renderer to reach parity — it needs to **bind** `/api/pdf/generate` behind `HtmlToPdfInterface` and keep dompdf as the no-worker fallback. The same holds for Word/Excel (`/api/document/*`), OCR (`/api/ocr/recognize`), image operations (`/api/image/*`), video (`/api/video/process`) and email (`/api/email/send` + `verify`). The work shifts from *implementing engines* to *declaring, binding and verifying a capability* — which is why this plan is shorter than the gap table suggests.

**The mapping is already written down.** `WP_MCP_AI_Pro_Settings::WORKER_ROUTED_PACKAGES` maps each engine package to its endpoint — `pdfkit` → `/api/pdf/generate`, `pdf-lib` → `/api/pdf/merge`, `docx` → `/api/document/word`, `exceljs` → `/api/document/excel`, `pdf-parse` → `/api/pdf/extract`, `sharp` → `/api/image/optimize`, `fluent-ffmpeg` → `/api/video/process`, `katex` → `/api/data/render-math`, and so on. Its companion constant, `SIDECAR_ONLY_PACKAGES`, records the packages with **no local path at all** (`ics` today), which must not be reported as installed when the worker is unreachable.

That distinction is precisely the one this plan needs, and it already exists in code: it is the difference between *"a portable tier exists"* and *"the worker is required"*. The design below reuses it rather than re-deriving it, which is why the honesty rule in 164.4.8 can be enforced instead of merely stated.

**`/api/health/full` is the capability probe.** It answers which binaries and providers this worker actually has, so the runtime can report `documents: worker`, `video: unavailable` instead of discovering the truth per call. The probe runs once per boot, is cached, and is surfaced in diagnostics and `site_status`.

**What stays local.** Anything cheap and deterministic stays in-process so a host without a worker still has a tool surface: slug/uuid/token generation, chart *data* shaping, text truncation, CSV parsing, and the parser-based PDF/Word/Excel tiers. The worker is the tier for *quality and heavy binaries*, never a hard dependency for the whole toolkit.

**Honesty rule.** If the worker is not configured and no portable tier satisfies the requested fidelity, the tool is **not servable** — it does not silently degrade to a worse renderer (164.4.8).

### 164.4.3 Documents

New contracts in `lib/core`:

```php
interface HtmlToPdfInterface { public function render( HtmlDocument $doc ): StoredFile; }
interface PdfMutatorInterface { public function merge( array $files ): StoredFile;
                                public function watermark( StoredFile $file, WatermarkSpec $spec ): StoredFile; }
interface OfficeWriterInterface { public function docx( DocumentSpec $spec ): StoredFile;
                                  public function xlsx( SheetSpec $spec ): StoredFile; }
interface TextExtractorInterface { public function fromPdf( StoredFile $file ): string; }
```

**Tier ladder for `HtmlToPdfInterface`:**

| Tier | Engine | Satisfies | When |
|---|---|---|---|
| 1 | **dompdf** (pure PHP) | `portable` | always available; simple CSS, no JS, no grid |
| 2 | **mPDF** | `portable` | no worker, and the document is Unicode / CJK / RTL |
| 3 | **media worker** `/api/pdf/generate` | `print`, `browser` | worker configured — the default when it is |
| 4 | **Gotenberg** (HTTP) | `print`, `browser` | no worker, but a Gotenberg is reachable |

The **selection rule is declared in the request, not guessed**: a caller asks for `fidelity: 'portable' | 'print' | 'browser'`, and the tool fails with a named capability error if no bound tier satisfies it. That is what stops a document from silently rendering badly — the failure mode TCPDF gives WordPress today.

**First ports (ordered by value ÷ cost):** `generate_pdf`, `html_to_pdf`, `generate_word`, `generate_excel`, `excel_data_import`, `excel_data_export`, `extract_pdf_text`, `merge_pdfs`, `add_watermark_to_pdf`, `generate_invoice_pdf`, `generate_invoice_batch`.

**Then, as their own slices:** Docs Hub (`docs_hub_*`, seven tools — needs a document collection; likely Content Graph or Paper Store rather than a new store), OCR (`ocr_pdf_text`, `pro_document_ocr`, `pro_unlimited_ocr` — defer to the worker, which already owns OCR per 102/109), and QMS (`qms_*`, nine tools — a document lifecycle state machine: draft → review → approved → released → obsolete → superseded, with an audit trail and e-signature. This is *better* than WordPress for free if it reuses the estate audit log and the existing approval-ticket machinery).

**Why this is on par or better:** WordPress renders with TCPDF and therefore cannot use a modern layout. A tiered renderer can produce a byte-simple PDF on a bare host and a pixel-accurate one wherever Gotenberg exists, with the same template.

### 164.4.4 Image

```php
interface ImageProcessorInterface {
    public function convert( StoredFile $file, string $format, array $options = array() ): StoredFile;
    public function resize( StoredFile $file, ResizeSpec $spec ): StoredFile;      // responsive set
    public function transform( StoredFile $file, ImageOp $op ): StoredFile;       // trim/rotate/watermark
}
```

**Engine preference**, probed once via `/api/health/full` and reported by `site_status`/diagnostics:

1. **media worker** — `/api/image/optimize`, `/api/image/batch`, `/api/image/edit`, `/api/image/enhance`, `/api/image/upscale`, `/api/image/vectorize` (libvips/Sharp under the hood, out of process, bounded memory)
2. **Imagick** — in-process, the compatibility tier for unusual formats and delegate-heavy effects
3. **GD** — last resort on a bare host

A tool declares the minimum engine it needs. `remove_image_background`, `image_inpainting`, `apply_artistic_style` and `colorize_image` are **model-backed**, so they are declared in the 141 registry as worker/hosted providers rather than as deterministic-processor operations.

**First ports:** `compress_image`, `convert_image_format` (done), `resize_image` (done), `rotate_image` (done), `generate_responsive_images`, `generate_video_thumbnails` (shares the video path), `apply_watermark_batch`, `optimise_images_batch`, `remove_image_background`, `upscale_image_ai`, `colorize_image`, `enhance_image_quality`, `generate_image_variations`.

**Read-only audits last** (`get_images_without_alt`, `get_unoptimised_images`, `get_unwatermarked_images`) because they are only meaningful once the writes exist.

### 164.4.5 Video

One worker-side operation set, each operation a typed value object and each result a `StoredFile`:

`trim` · `transcode` · `compress` · `resize` · `extract_frames` · `thumbnail` · `merge` · `watermark` · `adjust_speed` · `audio_replace` · `hls`

On a worker-configured host these route to **`/api/video/process`**, with **`/api/video/info`** as the probe (duration, codecs, dimensions) and `/api/video/download/{id}` for retrieval. Without a worker the tool is unservable rather than silently missing (164.4.8).

**Presets, not knobs.** Named profiles (`web_1080p`, `social_vertical_9x16`, `archive_mezzanine`) resolve to a fixed codec/CRF/GOP/segment configuration so an agent cannot invent an unplayable combination:

- VOD: capped CRF, `+faststart`, 4–6 rungs.
- HLS: 6-second segments, keyframe every 2 seconds, CMAF/fMP4.
- Vertical/social: crop-and-pad policy declared per platform in the same registry as 164.4.6.

`analyze_video` and `generate_video_caption` already exist and are served; `transcribe_video` is a small addition on the same path (Whisper provider or the Cognition app).

**§29 is the reason this is a phase and not a line item:** the Laravel image has no `ffmpeg`. The decision recorded there — bundle it, delegate to the media worker, or use a hosted service — is a deployment choice, and this proposal takes the **worker** as the default because the worker already exists and §29's other two options cost more in image size or data egress.

### 164.4.6 Outreach (social + email)

**A capability registry, one file per platform**, validated by the same schema mechanism as toolkit manifests:

```json
{
  "platform": "instagram",
  "auth": "oauth2_page_token",
  "approval": "app_review_required",
  "limits": { "posts_per_day": 100, "text": 2200, "media": ["image/jpeg", "video/mp4"] },
  "aspects": ["1:1", "4:5", "9:16"],
  "features": { "scheduling": "native", "first_comment": true, "carousel": 10 }
}
```

Tools read the registry; they never branch on the platform name. Publishing becomes: resolve platform → check `approval` → validate against `limits`/`aspects` → enqueue with an **idempotency key** (platform + account + content hash) → honour a per-platform rate-limit bucket → record the result.

**Email** is the same shape with a compliance preflight that refuses to send rather than sending badly:

- SPF **and** DKIM must pass; DMARC must be published and aligned to the From: domain.
- Every bulk send carries `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058).
- A **suppression list** and a **consent record** are mandatory inputs, not optional flags.
- Sending is one transport contract (`EmailTransportInterface`) with SMTP, API provider, worker (`/api/email/send`), and *dry-run* adapters, mirroring how `analyze_file_suitability` refuses without the real resource. `/api/email/verify` is the preflight, so the compliance gate exists even on a host with no local mailer.

**First ports:** email (`send_group_email` exists — add suppression, consent, RFC 8058 headers, DMARC preflight), then one social platform end-to-end (X or LinkedIn, chosen by whichever approval the operator already holds), then the remainder as registry files rather than code.

**Why this is on par or better:** the WordPress toolkits embed platform knowledge in PHP, so a limit change is a release. Here a limit change is a JSON edit, and an unapproved platform is *visibly* unapproved instead of failing at publish time.

### 164.4.7 CMS

**Not Elementor.** The Laravel equivalent of "build me a site" is a **plan → blocks → kit** pipeline on the stack the repo already ships:

1. `generate_site_plan` — a structured `SitePlan` value object (pages, sections, nav, tone, target platform). Pure, testable, no I/O.
2. Section generators (`create_hero_section`, `build_about_page`, `create_cta_section`, `generate_feature_section`, `build_navigation_menu`, …) — each emits a **Content Graph block set / Astro component tree**, not page-builder JSON.
3. `export_template_kit` / `import_site_template` — reuse Astro Forge's kit format (096) and the design tokens (095), so a generated site is portable to the velocity app and the Content Graph.
4. `research_site_best_practices` / `suggest_template_patterns` / `analyze_competitor_sites` — the read-only research tools, which are the cheapest to port and the most useful to an agent.
5. **Provisioning is out of scope** (164.7): creating the estate/hosting is 143's job.

**Why this is better than WordPress:** the output is not locked into one page builder. The same plan renders to a static Astro site today and to a WordPress block theme tomorrow, because the intermediate representation is ours.

### 164.4.8 Honesty rules (non-negotiable)

1. A tool whose engine is unbound is **not servable** — it stays out of the readiness manifest, exactly as the quarantined prompt-builders do.
2. A tool that needs an approval the operator does not hold returns a **named capability error**, not a generic failure.
3. Every output is a `StoredFile` from 162. A tool that "generated a PDF" but left no file is the prompt-builder defect in a new costume.
4. Fidelity class is declared by the caller; a portable renderer never silently substitutes for a browser renderer.
5. No tool claims a platform capability the registry does not declare.

## 164.5 Decisions and rejected alternatives

| Decision | Rejected alternative | Why |
|---|---|---|
| Tiered renderer with a declared fidelity request | Adopt Gotenberg everywhere | Forces a container on every host, including shared PHP where it cannot run. |
| Tiered renderer | Keep TCPDF for parity | Ships a visibly worse document and calls it parity. |
| libvips first, Imagick compatibility tier | Imagick only | ImageMagick's memory profile at 50MP is ~400 MB per image; that is a per-request OOM on a small box. |
| Worker executes ffmpeg | Bundle ffmpeg into the app image | Larger image on every host, including those that never transcode; §29 recorded the trade-off. |
| Capability registry (JSON) per platform | Per-platform PHP branches | A 2026 limit change should not require a release, and approval status must be visible. |
| Astro Forge as the CMS target | Port the Elementor tools | They cannot work on a runtime with no Elementor; a faithful port would be a tool that always fails. |
| Refuse to send without DKIM/DMARC/8058 | Send and let the operator discover bounces | The failure is silent and expensive; refusing is the honest behaviour. |

## 164.6 Implementation plan

Ordered by value ÷ cost. Every phase ends with a proposal addendum, tests, and a readiness-manifest update — the loop 090-M established.

**Phase 0 — Foundations (prerequisite for everything else)**
- `MediaWorkerClientInterface` in `lib/core` mirroring `WP_MCP_AI_Media_Worker_Config`: `MEDIA_WORKER_URL` / `MEDIA_WORKER_TOKEN`, `X-Site-Token` / `X-Site-Url`, and the **same `wp_mcp_ai_worker_not_configured` code** so logs agree across runtimes.
- `/api/health/full` probe, cached once per boot, surfaced in diagnostics and `site_status`.
- `ProductionCapabilityManifest` value object + JSON schema in `lib/core`, shared by both runtimes (the `ToolkitManifest` pattern).
- Job contract for long work: submit → poll → fetch result, with cancel and a server-side deadline.
- All outputs through the 162 file registry.
- **Acceptance:** with no worker configured the runtime reports `documents: portable`; with a worker it reports `documents: worker` and the probed capabilities; the not-configured error code matches WordPress; a job round-trips in a feature test.

**Phase 1 — Documents, portable first** *(highest value, lowest risk)*
- `HtmlToPdfInterface` with the tier ladder; `fidelity` in the request. Worker tier binds **`/api/pdf/generate`**.
- `generate_pdf`, `html_to_pdf`, `generate_word` (PHPWord, else **`/api/document/word`**), `generate_excel` / `excel_data_import` / `excel_data_export` (PhpSpreadsheet, else **`/api/document/excel`**), `extract_pdf_text` (**`/api/pdf/extract`**).
- `merge_pdfs`, `add_watermark_to_pdf`, `archive_documents`.
- **Acceptance:** a template renders on a host with no Gotenberg and says so; the same template renders identically under Gotenberg in CI; a PDF merge preserves page count and metadata.

**Phase 2 — Image**
- Engine probe + `ImageProcessorInterface` (worker `/api/image/*` → Imagick → GD).
- `compress_image`, `generate_responsive_images`, `apply_watermark_batch`, `optimise_images_batch`.
- Model-backed ops via the 141 registry: `remove_image_background`, `upscale_image_ai`, `colorize_image`, `enhance_image_quality`, `generate_image_variations`.
- **Acceptance:** a 50MP image converts within a memory budget the test asserts; an unsupported format names the engine that lacks it.

**Phase 3 — Video**
- Worker operation set + presets (`web_1080p`, `social_vertical_9x16`, `archive_mezzanine`), routed to `/api/video/process` with `/api/video/info` as the probe.
- `trim_video`, `compress_video`, `transcode_video`, `resize_video_resolution`, `merge_videos`, `add_watermark_to_video`, `extract_video_frames`, `generate_video_thumbnails`, `transcribe_video`, `create_video_from_images`.
- HLS output per the authoring guidance (6 s / 2 s), `+faststart` for progressive.
- **Acceptance:** a preset round-trips to a playable file and a keyframe/segment assertion passes; a missing worker fails with a named capability error.

**Phase 4 — Outreach**
- Platform registry files + approval states; `EmailTransportInterface` with SMTP / API / worker (`/api/email/send`) / dry-run. `/api/email/verify` is the preflight.
- Email compliance preflight (SPF/DKIM/DMARC, RFC 8058 headers, suppression, consent).
- Idempotency keys and per-platform rate-limit buckets; scheduling via the existing scheduler.
- **Acceptance:** a send without DKIM is refused with a readable reason; a duplicate publish with the same idempotency key is a no-op; an unapproved platform is rejected before a request is made.

**Phase 5 — CMS**
- `SitePlan` value object; section generators emitting Content Graph / Astro blocks.
- Kit export/import through Forge (096) + tokens (095).
- Research tools (`research_site_best_practices`, `suggest_template_patterns`, `analyze_competitor_sites`).
- **Acceptance:** a plan generates a site that builds; the kit imports into the velocity app; no Elementor-shaped JSON is produced.

**Phase 6 — The measurement (parallel with every phase)**
- `bin/toolkit-parity.php` — prints the 164.1 table from the tree, and fails CI only on **regression** (a previously ported tool disappearing), not on a ratio. A ratio gate would tempt someone to port a tool badly to move a number.

## 164.7 Out of scope, named

- **Estate/site provisioning** (143) — creating hosting, DNS, or a new estate.
- **Elementor-specific tooling** — deliberately not ported; mapping stated in 164.2.
- **QMS, Docs Hub and OCR beyond Phase 1's extractor** — each is a slice of its own; QMS in particular is a document-lifecycle product, not a formatter.
- **Platform app review / partner applications** — an operator action with lead time; the registry records the outcome, it cannot obtain it.
- **Video delivery/CDN** — publishing an HLS ladder to a streaming platform is a deployment concern; we produce the ladder.

## 164.8 Risks

| Risk | Mitigation |
|---|---|
| Media worker unreachable, so "documents" quietly means dompdf | `fidelity` is declared per request and logged; the probe result and the bound tier are reported in diagnostics |
| Worker version skew (an endpoint the runtime expects is missing) | capabilities come from `/api/health/full`, not from a version constant; an absent capability is a named error, and `/api/health` is part of the smoke test |
| Gotenberg/worker unavailable in production | portable tier still serves a document; the response says which tier produced it |
| libvips absent and Imagick OOMs on large images | engine probe at boot; memory bounded per operation and asserted in tests |
| ffmpeg absent on the target host | named capability error, never a silent no-op |
| Platform APIs change their limits/approval | limits live in the registry; the read-only capability tool reports them |
| Email compliance regressions | preflight is a gate, not a warning |
| Scope creep into a page builder | the CMS target is defined as blocks + kits, and Elementor parity is explicitly refused |

## 164.9 What "better than WordPress" means here, concretely

| Domain | WordPress today | This plan |
|---|---|---|
| Documents | TCPDF; no modern CSS | Tiered fidelity (dompdf/mPDF ↔ worker `/api/pdf/generate` ↔ Gotenberg), declared per request, byte-simple to pixel-accurate |
| Image | GD/Imagick in-request | worker `/api/image/*` first; bounded memory; model-backed ops behind the same contract |
| Video | local ffmpeg in-request, no presets | worker `/api/video/process`, named presets, HLS per authoring guidance |
| Outreach | platform logic in PHP | platform limits/approval as data; worker `/api/email/verify` preflight; idempotent publish |
| CMS | Elementor-locked | plan → blocks → kit on the repo's own Astro/token stack |
| All | — | one media-worker contract mirrored by both runtimes; every artefact is a registered file with visibility, owner and checksum (162) |

## 164.10 Addendum — the first slice shipped (2026-11-24)

**What landed: one vertical, end to end.** `html_to_pdf` is the first ported document tool and the proof that 164.4.2's ladder is real rather than aspirational. It is deliberately narrow: one interface, one adapter, one tool, one manifest row.

| Layer | File | What it does |
|---|---|---|
| Contract | `lib/core/src/Domain/Contract/HtmlToPdfInterface.php` | `render()`, `isAvailable()`, `getSupportedPageFormats()` |
| Adapter | `lib/laravel-adapter/src/Adapter/MediaWorkerHtmlToPdf.php` | `POST /api/pdf/generate` → bytes on disk, content-addressed |
| Transport | `lib/laravel-adapter/src/Adapter/MediaWorkerClient.php` | new `postJson()` beside `postFile()`, same error contract |
| Binding | `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | `HtmlToPdfInterface` → `MediaWorkerHtmlToPdf` |
| Tool | `lib/core/src/Tool/HtmlToPdfTool.php` | slug `html_to_pdf`, parity with the WordPress five args |
| Manifest | `lib/core/resources/tool-readiness.php` | `ready` / `port` / `call`, evidence test named |

### Three defects found while making it honest

These are worth recording because each one is a class of defect, not an incident.

1. **`stringParam()` destroys HTML.** The house helper applies `strip_tags()`, so the obvious implementation of this tool renders an empty page and reports success. `strip_html` already reads its input raw for the same reason. `HtmlToPdfTool::htmlParam()` does too, and `test_the_markup_reaches_the_renderer_intact` pins it.
2. **A declared flag on a class that does not implement the interface is dead metadata.** `getCapabilityFlags()` only reaches `ReadOnlyToolGuard` and the necessity gate through `ToolCapabilityFlagsInterface`; without it the declaration is read by nobody. The tool now implements it.
3. **`local-only` would have been false here.** The WordPress original declares it because DomPDF and wkhtmltopdf run in-process. The worker tier sends the document off the host, so this tool declares `write` and `state-changing` and omits `local-only` — the same reasoning 164.4.8 applies to fidelity.

### What is deliberately still open

- Phase 0: `MediaWorkerClientInterface` in `lib/core` (the adapter's `MediaWorkerClient` is the only client today), the cached `/api/health/full` probe, `ProductionCapabilityManifest`, and the job contract. The worker-health surface is the next prerequisite, because every later phase needs `documents: worker | portable` reported truthfully.
- Phase 1 remainder: `generate_pdf`, `generate_word`, `generate_excel`, `excel_data_import` / `_export`, `extract_pdf_text`, `merge_pdfs`, `add_watermark_to_pdf`, `archive_documents`, `generate_invoice_pdf`, `generate_invoice_batch`.
- Phases 2–6 untouched: image, video, outreach, CMS, and `bin/toolkit-parity.php`.
- The portable tier named in 164.4.3 (dompdf/mPDF) is not bound yet. Until it is, a host with no worker answers `no_pdf_engine` — the honest behaviour 164.4.8 requires, but it means the ladder currently has one rung on this runtime rather than three.

### Measurement

`bin/audit-tool-readiness.php`: **249 manifest rows, 230 servable, 0 violations** (was 248 / 229). `toolkit-parity` still does not exist; the phase-6 gate remains the only way to stop a regression being invisible.

## 164.11 Addendum — Phase 0's capability probe (2026-11-24)

**What landed: the tier report every later phase reads.** `/api/health/full` is now probed once, cached, and reported on both diagnostic surfaces, so a domain's tier is answered before a request is spent.

| Layer | File | What it does |
|---|---|---|
| Contract | `lib/core/src/Domain/Contract/MediaWorkerClientInterface.php` | `isConfigured()`, `health()`, `capabilities()` |
| Value object | `lib/core/src/Domain/ValueObject/MediaWorkerCapabilityReport.php` | pure: ten declared domains → `worker` / `unavailable` / `unknown` |
| Transport | `lib/laravel-adapter/src/Adapter/MediaWorkerClient.php` | `capabilities()` reads `/api/health/full`; now implements the contract |
| Probe | `lib/laravel-adapter/src/Adapter/MediaWorkerCapabilityProbe.php` | one cached report per TTL, never throws |
| Surfaces | `DiagnosticsController`, `DiagnosticsPage`, `MediaCcController::health` | `media_worker` block and per-domain tiers |

### Four decisions worth recording

1. **`unknown` is a third tier, not a synonym for `unavailable`.** A managed Node host can be up and still lack `ffmpeg`. Collapsing a probe timeout into `unavailable` would tell an operator to install a binary when the real fix is the network — two different repairs behind one word.
2. **The transport stays uncached; the probe owns the TTL.** The first draft memoised the matrix on the client, and the `report( fresh: true )` test caught it: the probe holds one client instance, so a memoised matrix outlives every refresh and the refresh becomes a lie. A method that says "fresh" must be fresh.
3. **A cache outage falls back to a direct probe.** The class promises a probe never fails the page; `Cache::remember` could still throw on a Redis outage and break that promise. The cache is an optimisation, not a dependency.
4. **`configured()` is kept as a delegate to `isConfigured()`.** The interface's spelling is canonical, but four existing call sites predate the contract; a rename for a spelling is churn with no behaviour behind it.

### What is deliberately still open

- **`portable` is absent from the tier vocabulary.** Nothing on this runtime can enter it until a dompdf/mPDF binding exists, and a state that can never be reported is a control with no reader. It arrives with the binding.
- **Phase 0 remainder**: `ProductionCapabilityManifest` (the platform/limits registry 164.4.6 needs) and the job contract for long work. Neither has a consumer until Phase 3/4, so both wait for the phase that needs them.
- **Phase 1 remainder** is unchanged from §164.10: `generate_pdf`, `generate_word`, `generate_excel`, the Excel import/export pair, `extract_pdf_text`, `merge_pdfs`, `add_watermark_to_pdf`, `archive_documents`, and the invoice pair.

### Measurement

Manifest unchanged at **249 rows / 230 servable / 0 violations** — this slice adds no tool. `lib/core` 2,313 tests; adapter 359; the probe adds 9 feature tests plus 10 unit tests.

## 164.12 Addendum — the rest of the document family (2026-11-24)

**What landed: five tools and three contracts on the same processing tier.**

| Contract | Adapter | Tools | Worker endpoints |
|---|---|---|---|
| `PdfTextExtractorInterface` | `MediaWorkerPdfTextExtractor` | `extract_pdf_text` | `POST /api/pdf/extract` |
| `PdfMutatorInterface` | `MediaWorkerPdfMutator` | `merge_pdfs`, `add_watermark_to_pdf` | `POST /api/pdf/merge`, `/api/pdf/watermark` |
| `OfficeWriterInterface` | `MediaWorkerOfficeWriter` | `generate_word`, `generate_excel` | `POST /api/document/word`, `/api/document/excel` |

A shared `MediaWorkerOutput` now owns the decode → content-address → write step that four adapters were about to duplicate, and `MediaWorkerHtmlToPdf` was folded onto it.

### Four deviations from the WordPress tools, each deliberate

1. **`docx()` takes no options.** The worker's word route reads `content` only — it derives document properties from it — so accepting `creator`/`title` there would be an option nothing reads. `xlsx()` does take them, because the excel route reads them.
2. **The watermark takes text only.** The WordPress tool offers `opacity` and `position`; `/api/pdf/watermark` draws one fixed mark (48pt, grey, 30%, centred, −45°) and takes no style arguments. Rendering a different watermark than the caller asked for is worse than not offering the knob, so the tool's description says what it does.
3. **`file_ids`, not `attachment_ids` and not `urls`.** A stored file id is the only input that resolves to bytes on this host through an authorisation check. A URL would be a fetch this runtime would have to vet, and a provider file id names bytes in someone else's storage.
4. **`extract_pdf_text` has no OCR fallback.** The WordPress tool falls back to OCR providers when the text layer is empty. Here a scanned PDF returns an empty string and its page count, so a cheap read never silently becomes a paid vision call. OCR is §164.7's own slice.

### Two defects fixed on the way

- **`save_to_media` was advertised and ignored** by `html_to_pdf` (§164.10). `GeneratedMediaPersister::parameters()` puts it in the schema, and nothing read it. Every document tool now honours it, including the one that shipped without.
- **Four readiness rows named a class that no longer exists.** `convert_image_format`, `crop_image`, `resize_image` and `rotate_image` all recorded `App\Support\Media\MediaWorkerImageProcessing … bound in AppServiceProvider`; the class is `Nvoos\Laravel\Adapter\MediaWorkerImageProcessing` and the binding is in `NvoosServiceProvider`. A `reason` is what an operator reads when a tool is not served, so a wrong one is a wrong instruction.

### What this does not do

- **Nothing portable.** Every one of the five tools answers `no_pdf_engine` / `no_document_engine` on a host with no worker. That is 164.4.8 working, but it means the document family is worker-only on this runtime until a dompdf/PHPWord binding exists.
- **No Office *import*.** `excel_data_import` / `excel_data_export` read and write existing workbooks; the worker has no route for either, so they wait for one rather than being faked on top of `generate_excel`.
- **No `archive_documents` or the invoice pair.** Those need a document collection and an order source respectively — §164.7's territory.

### Measurement

`bin/audit-tool-readiness.php`: **254 manifest rows, 235 servable, 0 violations** (was 249 / 230). `lib/core` 2,355 tests; adapter 359; the document adapter test adds 13 feature tests plus 32 unit tests across the two tool suites.

## 164.13 Addendum — worker-side enhancements and the two tools they unlock (2026-11-24)

**Three of the four remaining gaps turned out to want the worker, and one did not.** §164.12 recorded that `excel_data_import` needed a worker route; it did not — `/api/ingest/parse` already reads `xlsx · xls · csv · tsv · json · jsonl · ndjson` with ExcelJS. The gap was an adapter.

### Worker

| Route | Change | New capability key |
|---|---|---|
| `POST /api/pdf/ocr` | new: pdfjs rasterisation at `dpi` + tesseract.js, one worker per call, per-page text and confidence | `pdf_ocr` |
| `POST /api/pdf/watermark` | new optional `opacity` (0–1) and `position` (`diagonal`/`center`/`top`/`bottom`) | `pdf_watermark_style` |
| `POST /api/ingest/parse` | new `maxRows`, with `meta.truncated` and `meta.source_total` | — |

The watermark defaults are the old hard-coded mark **exactly** (`diagonal` reproduces the previous `x-150 / y=h·½ / −45°` coordinates rather than re-deriving them), so a caller that sends no style keeps byte-identical output.

### Runtime

`TabularReaderInterface` (adapter over `/api/ingest/parse`) and `PdfOcrInterface` (adapter over `/api/pdf/ocr`) complete the family, and `excel_data_import` / `ocr_pdf_text` are served. `PdfMutatorInterface` grew `$options` and `watermarkStyleSupported()`.

### What the research changed

1. **300 DPI, 150–600 clamp.** The published guidance converges on 300 DPI as the working default (600 for small type or archival work; below ~200 accuracy falls off sharply), while high densities hit engine pixel caps and memory. So the default is 300 and the clamp is explicit rather than a magic number in a docblock.
2. **The page budget is contract, not implementation.** OCR is seconds per page; a 200-page scan behind one request is a timeout that reads like a crash. 20 pages per call, 50 hard, `truncated` + `total_pages` on the response so a caller loops instead of guessing.
3. **CSV/formula injection is an *export* problem.** OWASP's guidance is about cells a recipient opens in a spreadsheet. `exceljs` writes plain strings as text cells, so the generated workbooks are not the vector — but an object cell (`{formula: …}`) passed through from an agent would be. The import tool therefore normalises every cell to a scalar, and the export path's rows stay plain values.
4. **`maxRows` is bounded on both sides.** The worker slices the response; the adapter slices again. An older worker that ignores the argument cannot hand a 100 000-row matrix to a prompt.

### The capability rule, made concrete

§164.12 stated that a worker enhancement must be advertised and treated as absent by the runtime. That is now two working examples:

- `MediaWorkerPdfOcr::isAvailable()` asks the matrix for `pdf_ocr`. A configured worker that predates the route answers `no_ocr_engine`, not a 404 mid-run.
- `AddWatermarkToPdfTool` asks `watermarkStyleSupported()` before honouring `opacity`/`position`. A **non-default** style on an old worker is `watermark_style_unavailable`; a caller who asks for nothing still gets the classic mark.

### Deliberate deviations from WordPress

- **`sheet` is a name or a 1-based index**, matching the worker. WordPress's `sheet_index` is 0-based, and accepting both spellings would make "sheet 1" mean two different things.
- **`max_rows` defaults to 500, not "all".** Unbounded is right for a PHP array and wrong for a context window.
- **`.xls` and `.ods` are refused**, not silently retried: the worker answers 503 for both, and pretending the file was empty would be the worse failure.

### What is still open

- **`excel_data_export`** — `/api/document/excel` already writes a workbook, so this is an adapter over `OfficeWriterInterface`, but its WordPress schema is a different shape (CSV/XLSX from an arbitrary dataset) and is worth its own slice.
- **`archive_documents` stays in the runtime.** No zip dependency exists on the worker, and PHP's `ZipArchive` bundles local files without any bytes leaving the host — adding `archiver` to a Node sidecar to do what PHP does natively is the wrong trade.
- **`generate_invoice_pdf` / `_batch` stay out.** The missing piece is the order source, not the renderer; an invoice is `/api/pdf/generate` plus a template.
- **A portable OCR tier** does not exist, so `ocr_pdf_text` is worker-only, like the rest of the document family.

### Measurement

`bin/audit-tool-readiness.php`: **256 rows / 237 servable / 0 violations** (was 254 / 235). `lib/core` 2,376 tests; adapter 359; the worker suite gained 13 route/style tests and 2 ingest cases (`node --test`, run against the worker image).

## 164.14 Addendum — the image pipeline family and the Phase 6 gate (2026-11-24)

Ten of the twenty-two `image-production` slugs now run on this runtime, and the
regression gate that protects every port so far is wired into CI.

### Worker

Four additive changes, each advertised as its own capability key because a
runtime has to be able to tell that a route **argument** is absent, not just that
the route is:

| Change | Route | Key |
|---|---|---|
| `position` on the transform resize op | `POST /api/image/transform` | `image_smart_crop` |
| `lossless` encoder flag | `POST /api/image/batch` | `image_batch_encoding` |
| `progressive` encoder flag | `POST /api/image/batch` | `image_batch_encoding` |
| `enhance` / `upscale` / `batch` / `edit` declared | existing routes | `image_enhancement`, `image_upscaling`, `image_batch`, `image_editing` |

`position` is an allowlist of Sharp's own anchor and saliency spellings, with
`center` normalised to `centre`, so an arbitrary string never reaches the image
library. `lossless` **refuses JPEG** with a 400 rather than pretending: there is
no lossless JPEG, and the alternative is returning a larger file that is still
not bit-identical while reporting success. `lossless` with `format: original` is
refused for the same reason — the source encoding is not known until the bytes
are read.

`image_editing` is derived from the three provider keys the `/edit` route
actually resolves (`GEMINI_API_KEY`, `OPENAI_API_KEY`, `REPLICATE_API_KEY`), so a
worker without one reports the capability as unavailable and the tools refuse
with `no_image_provider`.

### Runtime

`ImageProductionInterface` is a **new contract**, not an extension of
`ImageProcessingInterface`. Adding `batch()` there would have forced every host
with GD to implement a worker protocol it does not speak; the WordPress adapter
implements `ImageProcessingInterface` and nothing else, which is exactly what
interface segregation is for.

`MediaWorkerImageProduction` implements it over `MediaWorkerOutput` (shared
decode/write/fingerprint) and `MediaWorkerCapabilityProbe`. `supports()` answers
per operation so a caller can distinguish "this worker cannot edit images" from
"this host has no worker".

Ten tools, all registered as `ready` in the manifest with evidence in
`ImageProductionToolsTest`:

`compress_image` · `optimize_for_web` · `enhance_image_quality` ·
`upscale_image_ai` · `optimise_images_batch` · `generate_responsive_images` ·
`colorize_image` · `apply_artistic_style` · `get_unoptimised_images` ·
`get_images_without_alt`

`optimize()` and `batch()` both ride `/api/image/batch`: it is the only route
that reports **both** dimensions *and* accepts AVIF, and asking `/optimize` for
a height it does not return would be a guess followed by a second round trip.

### Three defects found while making it honest

- **The adapter defaulted `sharpen` to `true`.** An enhance call that asked only
  for denoising would have sharpened as well, and `enhancements` would have
  reported an operation nobody requested. The default is now off.
- **A full-strength sharpen collided with the route's boolean spelling.** The
  route treats the bare strings `true` and `1` as flags, and `1` is also a
  legitimate 0-1 amount. Numeric amounts are now sent as `1.00`, which keeps them
  on the scalar path where `strength` applies.
- **Argument validation ran after the capability probe.** `scale_factor: 3` and
  an unknown style preset both reported `image_pipeline_unavailable` /
  `no_image_provider`, sending the operator to the wrong problem. Both tools now
  validate first — the same ordering rule `dryRunPlan()` documents.

### Deliberate deviations from WordPress

- **`model` and `denoise` are absent from `upscale_image_ai`.** The WordPress
  tool declares both and its own description admits it ignores them. A schema
  that teaches an orchestrator that `model: anime` changes the output, when it
  does not, is worse than a smaller schema. `kernel` is the only resampling
  control and the response names the method used.
- **`style_image` is absent from `apply_artistic_style`** for the same reason: an
  image-to-image reference is a different route, not a flag.
- **`use_remote` is absent from `enhance_image_quality`** and
  **`strip_metadata` from `optimize_for_web`.** A port has no local Sharp to fall
  back to, and the worker always strips EXIF on re-encode; declaring a `false`
  that cannot be honoured is the failure `AddWatermarkToPdfTool` already refused.
- **`method: lossless` is honoured, not ignored.** The WordPress tool accepts the
  value and calls `save()`, which has no lossless path.
- **`max_size_kb` reports a miss.** Quality steps down by five to a floor of ten,
  at most ten passes; if the target is still unmet the tool returns
  `target_size_unmet` with the size achieved instead of a success whose number is
  wrong.
- **`dry_run` stays `true` by default** in `optimise_images_batch`. A bulk rewrite
  of a library should be asked for twice.
- **`optimize_for_web` resolves `auto` to WebP, not AVIF.** AVIF encodes several
  times slower and is unevenly supported in mail clients and older Safari; a
  silent pick of the slowest, least-portable option is not a helpful default.

### What is still open, with the reason

| Slug | Why it is not ported |
|---|---|
| `apply_watermark_batch` | Needs image compositing; the worker has no `composite` op. Its own slice (text-to-SVG overlay + gravity) is a genuine worker feature. |
| `batch_process_images` | Chains ordered ops including `remove_background`; depends on the two rows below. |
| `remove_background`, `remove_image_background` | Need `rembg` or a Clipdrop-style provider. No route exists; `auto`/`free`/`paid` would collapse to one real option. |
| `image_inpainting` | Needs a mask channel and a provider that accepts one. `/edit` has neither. |
| `generate_image_ai`, `generate_image_variations` | Provider-backed **generation**; `nvoos/core` already serves the `generate_*` family under its own slugs, and duplicating the toolkit slug would mean two tools doing the same work. |
| `get_unwatermarked_images` | No watermark concept exists in the file registry, so there is nothing to audit. |
| `resize_image_smart`, `optimize_image_sharp` | Reachable — `position: attention` and the multi-op route both exist — but each is a thin alias over `resize_image` / `compress_image`. Deferred to a naming decision, not a capability one. |
| `text_to_image_prompt_optimizer` | A prompt builder that performs no work; it belongs in `quarantined`, not `ready`. |

### Phase 6 — the regression gate

`bin/toolkit-parity.php` extracts `get_slug()` statically from
`addons/pro/includes/tools/<toolkit>/*.php`, intersects it with the servable rows
in `lib/core/resources/tool-readiness.php`, and compares against
`lib/core/resources/toolkit-parity-baseline.json`.

Modes: no flag (markdown table), `--json`, `--write` (regenerate the baseline),
and `--check`, which **fails only on regression** — a slug the baseline records
as served and that is served no longer.

It deliberately does not gate on a ratio. A ratio gate rewards porting a tool
badly to move a number; a regression gate protects the ones already ported. The
`toolkit-parity` job in `.github/workflows/tool-readiness.yml` runs `--check`.

The extractor skips `abstract class` and `interface` files, which legitimately
declare no literal slug — without that skip the report counted them as
unreadable.

### Measurement

`bin/audit-tool-readiness.php`: **266 rows / 247 servable / 0 violations** (was
256 / 237). `bin/toolkit-parity.php`: **46 ported of 854 across 52 toolkits**
(was 36); `image-production` is **11 of 22** (was 1).

`lib/core` 2,376 tests / 17,255 assertions; adapter 359 / 1,907; the app suite
gains 25 tests in `ImageProductionToolsTest`; the worker suite gains 2 batch
cases and 2 transform cases (`node --test`, run against the worker image).

## 164.15 Addendum — social read-only capability and the approval registry (2026-11-24)

This slice started as "port the read-only half of the social toolkit". It found a
safety defect first, and the defect is the part worth recording.

### The defect: four publishers declared themselves read-only

`post_facebook_instagram`, `post_linkedin_update`, `post_tiktok_video` and
`post_google_business_update` each call the platform's write API from
`execute()`. Each declared:

```php
'read-only',            // Only reads data, does not modify state.
'local-only',           // No external API calls.
```

Both comments are false. The consequence was not cosmetic:

- `NecessityGate::isSafeTool()` returns true for any flag list containing
  `read-only` and none of the dangerous ones, so `decide()` short-circuits to
  `VERDICT_ALLOW` **before** classifying necessity. No approval, no warning.
- MCP tool annotations are derived from the same flags, so every client was told
  `readOnlyHint: true` — "safe to auto-call".

`post_to_multiple_platforms` and `publish_to_social` were quieter and the same
shape: `external-api` without a mutation flag derives `IRREVERSIBILITY_MODERATE`,
which a helpful call treats as **WARN** — it runs.

The flag blocks were copy-pasted from a read tool template. Nine declarations
were corrected:

| Tool | Was | Now adds |
|---|---|---|
| 4 × `post_*` posters | `read-only, local-only` | `social-media, external-api, external-communication, irreversible, state-changing, write` (removes `read-only`, `local-only`) |
| `post_to_multiple_platforms` | `content-publishing, external-api` | `external-communication, irreversible, requires-capability, state-changing, write` |
| `publish_to_social` | `database-write` | `external-communication, irreversible, state-changing, write` |
| `generate_post_ideas`, `monitor_mentions_replies`, `social_listening_trends` | no `read-only` | `read-only` |

`auto_respond_messages` and `moderate_comments` were **checked and left alone**:
neither reaches a remote write API (no `wp_remote_post`), so `database-write` plus
`external-api` is accurate and `WARN` is the right verdict for a queued draft.

`SocialPublishingSafetyTest` pins the policy, not the file: it reads the generated
catalogue and asks the gate. A future edit that re-adds `read-only` to a poster
fails there rather than in production.

### The second defect: the console printed the worker's answer under the host's label

`DiagnosticsPage` rendered `Social → worker` whenever the worker declared
`social_publishing`, which it does whenever a platform key is set. The runtime
served **zero** social tools. An operator read "this host can publish".

Two changes:

- `social_publishing` is its own domain, separate from `social` (which now maps
  to `social_accounts` + `social_composition`). Publishing is no longer
  indistinguishable from composition.
- `ToolCoverage` intersects the worker's matrix with the manifest's servable set
  and adds two tiers: **`served`** (worker declares it *and* a tool reaches it)
  and **`worker_only`** (worker declares it, nothing here does). The page now
  reads `Social: served (1 tool)` and `Social publishing: worker available, no
  tool here`.

The domain→tool map is declared data, not a slug-prefix rule, because
`send_group_email` is email, `generate_email_template` is a document, and
`generate_music` is neither. Two domains map an **empty** list on purpose:
`video` and `browser` are declared by the worker with no tool here driving them,
which is the state an operator needs to see.

### The read-only half

`SocialComposerInterface` (`accounts()`, `compose()`) over
`GET /api/social/accounts` and `POST /api/social/generate-content`.
`MediaWorkerSocialComposer` implements it and asks the matrix per operation, so a
worker older than the routes answers `social_unavailable` instead of a 404.

Three tools, all read-only: `get_social_accounts`, `generate_social_captions`,
`generate_post_ideas`. `social-media` parity moves **0 → 2 of 32** (plus one
additive slug with no WordPress equivalent).

Publishing is **not** in this contract. A `SocialPublisherInterface` will be its
own contract, and it will be approval-gated in the registry rather than by
convention in an adapter — which is now possible because the flags are honest.

### Grounded in current guidance

- **MCP tool annotations** reserve `destructiveHint: true` for "deletes,
  overwrites, revocations, irreversible sends, financial actions". A social post
  is an irreversible send, and a tool that publishes must not carry
  `readOnlyHint: true`.
- **Human-in-the-loop approval** is a pending ticket, a human decision, and a
  single-use consume that fails closed — which is exactly what
  `ApprovalTicketInterface` already specifies (bound to the payload hash,
  single-use, expiring, fail-closed). The guard's message already tells the truth
  when a ticket is pending rather than implying the action ran.
- **OAuth scope separation** (`tweet.read` vs `tweet.write`,
  `pages_read_engagement` vs `pages_manage_posts`) is the same principle one
  layer down: the token that reads must not be able to publish. The capability
  keys now mirror it — `social_accounts` and `social_composition` are separate
  from `social_publishing`.

### Deliberate deviations from WordPress

- **`generate_social_captions` takes `topic`, not `post_id`.** This runtime has no
  `wp_posts` to resolve.
- **`generate_post_ideas` folds `industry`, `topics`, `target_audience` and
  `content_types` into the single subject the composer accepts**, and echoes the
  subject it sent. Dropping four of five inputs was the alternative; the caller
  would have received ideas aimed at nobody and no way to tell.
- **`pinterest`, `tiktok` and `youtube` are accepted for idea generation and
  reported as non-composable.** An idea for Pinterest is still an idea; a
  character limit for it does not exist here.
- **The template fallback is surfaced as `prompts`, never as `posts`.** The
  worker returns `prompt_templates` when no model key resolves. Rendering one as
  a caption would be success reported for work not done.

### What is still open

- **`publish_to_social` port** — now a safe thing to build, because its flags
  require approval. Next slice, with a test that a publish without a ticket is
  refused and a second call with one succeeds exactly once.
- **Scheduled publishing** (`schedule_social_post`, `bulk_schedule_posts`) —
  marked `state-changing` + `write` + `reversible`, which derives `WARN`. A
  schedule *becomes* an irreversible publish when it fires, so the worker's
  scheduled-publish processor is where a second gate belongs.
- The remaining 28 social slugs need provider read APIs (Meta Graph, LinkedIn,
  TikTok, Google Business) that the worker does not proxy.

### Measurement

`bin/audit-tool-readiness.php`: **269 rows / 250 servable / 0 violations** (was
256 / 237). `bin/toolkit-parity.php`: **48 of 854** (was 46); `social-media` is
**2 of 32** (was 0).

`lib/core` 2,436 tests / 17,427 assertions (34 new: 26 policy, 8 coverage);
adapter 359 / 1,910; the app suite gains 10 tests in `SocialComposerToolsTest`;
the worker suite is unchanged at 4 social route tests, with `index.js` gaining
two capability keys.

## 164.16 Addendum — the publisher, and video as a two-step contract (2026-11-24)

§164.15 closed with "publishing is **not** in this contract". This addendum adds
it, and with it the last family the worker already knew how to run: video.

### The publisher costs a decision, not a keystroke

`SocialPublisherInterface` (`publish()`, `supports()`, `isAvailable()`) is
deliberately **not** part of `SocialComposerInterface`: composing copy and
sending it to a platform are different privileges, and a runtime that binds the
first should not thereby gain the second.

`MediaWorkerSocialPublisher` posts to `/api/social/post` and spends most of its
code refusing before it spends a request:

- an **unknown platform** is refused naming the worker's own list, because the
  caller has almost certainly typo'd it;
- an **uncredentialed channel** is refused *before* the call, naming the
  platform, rather than relaying a 500 afterwards;
- **over-length copy** is refused with the limit the composer already knows;
- **`schedule` is refused outright.** A scheduled post fires when no human is
  present, which is the one thing an approval gate exists to prevent. The worker
  still supports it; this runtime does not route to it.

On a worker error the message appends *"the platform token may be expired or
lack write scope; the worker log carries the provider response"* rather than
inventing provider detail it cannot see.

`PublishToSocialTool` declares `external-api`, `external-communication`,
`irreversible`, `requires-capability`, `state-changing` and `write`, and requires
`publish_posts`. It **defaults `dry_run` to true**, so the safe call is the one
you get by accident. `InvalidArgumentException` becomes `validationFailed` (422)
and any other throwable `publish_failed`, because a bad argument and a broken
platform are different things to a caller.

`link_url` is appended to the copy and reported as `link_appended: true` — the
worker has no link field, and silently dropping a URL is worse than saying where
it went.

The proof is a store that can be approved once and then refuses
(`TicketOnceStore`): a call with no ticket is refused and queues one; a retry
with it runs; a third is refused and queues afresh. **An approval that can be
replayed is not an approval.**

### Video is two steps, so the contract is too

`/api/video/process` writes to the **worker's** disk and answers a filename;
`/api/video/download/<name>` is how a host gets the bytes.
`VideoProcessingInterface` has one `process()` with an `operation` discriminator
mirroring the route — and it performs *both* steps, so no intermediate filename
ever reaches a caller.

`extract_frames` is deliberately absent from `operations()`: it answers many
files, which is a different return shape and a separate tool.

Six tools: `get_video_metadata` (read-only), `trim_video`, `compress_video`,
`convert_video_format`, `resize_video_resolution`, `generate_video_thumbnails`.
Resolution presets (`480p`…`2160p`) map to a height and preserve aspect ratio; a
preset **and** a dimension together are refused rather than silently preferring
one. Thumbnails request `jpg` — ffmpeg picks the encoder from the output
extension, and a "thumbnail" asked for as `mp4` comes back as a one-frame video.

### Five worker defects found while making the route honest

Reading the route in order to wrap it exposed problems that had nothing to do
with this host:

| Defect | Fix |
| --- | --- |
| **`eval?.()` on ffprobe's `r_frame_rate`** — a value read out of an uploaded file was executed; remote code execution reachable by uploading a video | `parseRational()` divides numerator by denominator |
| **Shell-string ffmpeg commands** interpolating `start`, `width` and `format` | `execFile('ffmpeg', argv)` — no shell exists to interpret anything |
| **`format` interpolated into the output filename** — path traversal from a request field | `PROCESS_FORMATS` allowlist; `jpg`/`jpeg`/`png` added so a thumbnail is an image |
| **No `+faststart`** — a browser must download the whole file before frame one | `-movflags +faststart` for `mp4`/`mov` |
| **`trim` used input-side `-ss`** — keyframe-approximate, so a cut landed seconds off | output-side `-ss`, frame-accurate |

### The sixth defect was ours: a filename is not a path

The route resolves `path.resolve(base, name)` and checks containment, so it was
safe. This host built `'/api/video/download/'.$remote` and checked the name
against `'#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#'` — which **`../../etc/passwd`
passes**, because `.` is an allowed character. A compromised worker, or a future
route echoing a caller-supplied value, could have made this host normalise the
URL out of `/api/video/download/`.

Every segment is now validated *by name* as well as by charset: empty, `.` and
`..` are refused. The test pins eight hostile names — parent traversal, nested
traversal, a dot segment, an absolute path, a doubled separator, a trailing
separator, percent-encoding and an empty answer.

The lesson worth writing down: a containment check that **resolves** a path and
a charset check that **does not** are not interchangeable.

### Deliberate deviations from WordPress

- **`compress_video` has no `quality` argument.** The route encodes at a fixed
  CRF 28; a knob whose only effect is a constant is a lie with a number on it.
- **`tiktok` is absent from the publish enum.** The worker has no TikTok
  publisher.
- **`link_url` is appended to the copy**, per above.
- **`extract_video_frames` is not ported.** It is not a rename of
  `generate_video_thumbnails`: many files, a different envelope.
- **The named presets (`web_1080p`, `social_vertical_9x16`) live above the
  contract**, in the tool rather than the interface — they are a product
  decision, not a capability of ffmpeg.

### What is still open

- **`merge_videos`, `add_watermark_to_video`, `adjust_video_speed`,
  `create_video_from_images`** need ffmpeg filter work the route does not
  implement (concat, drawtext, setpts/atempo, image sequence).
- **`extract_audio` exists on the route and has no tool.** The cheapest
  remaining win.
- **`extract_video_frames`, `get_videos_without_thumbnails`,
  `..._without_transcripts`** — the last two are audits and only become
  meaningful now that someone can write a thumbnail; transcripts still have no
  route.
- **Scheduled social publishing** (`schedule_social_post`, `bulk_schedule_posts`)
  still derives WARN. A schedule *becomes* an irreversible publish when it
  fires, so the second gate belongs in the worker's scheduled-publish
  processor, where no human is present.

### Measurement

`bin/audit-tool-readiness.php`: **276 rows / 257 servable / 0 violations** (was
269 / 250). `bin/toolkit-parity.php`: **55 of 854** (was 48);
`video-production` is **6 of 21** (was 0) and `social-media` **3 of 32** (was 2).

`lib/core` 2,454 tests / 17,515 assertions; adapter 359 / 1,917; the app suite
gains 18 tests in `VideoProcessingAdapterTest` and 9 in `PublishToSocialToolTest`;
the worker gains 7 in `video.test.js`.

## 164.17 Addendum — `adjust_video_speed` (2026-11-25)

### Why this one first

The 15 unported `video-production` slugs mostly need ffmpeg work the route does
not implement: `merge_videos` is a concat across several uploads,
`add_watermark_to_video` is an overlay, `create_video_from_images` is an image
sequence. Speed is the smallest of that family, and it needs no multi-input
upload — it reuses the single-file machinery the trim/compress tools already
have.

`extract_audio`, which §164.16 named as the cheapest win, turns out **not to be
a parity item at all**: the WordPress `video-production` toolkit has no such
tool. The route has the operation; no WordPress slug asks for it. It stays
available as an additive tool, not as parity.

### `setpts` inverts the factor; `atempo` does not

Halving the timestamps doubles the speed, so the video filter is
`setpts=PTS/<factor>` — a 2× request divides by two. Audio is the opposite
problem: `atempo` takes the speed itself, so the same request sends
`atempo=2`. Getting one of these inverted produces a file whose sound and
picture run at different rates, which is why both are written from the same
`$factor` in one place.

### `atempo`'s range is a version question, not a constant

Most published recipes still say `atempo` accepts 0.5–2.0 and that 4× needs
"two stages". That upper bound was raised in ffmpeg 4.1; the worker image runs
**8.1.2**, where `ffmpeg -h filter=atempo` reports **0.5 to 100**. The code
chains by 0.5 downwards rather than assuming either, because that chain is
valid on both: a 0.25× request becomes `atempo=0.5,atempo=0.5` and a 3× request
stays a single stage. The tests assert both properties — every stage inside
0.5–100, and the stages multiply back to the requested factor.

### Pitch is a separate decision, not a side effect

`maintain_pitch: true` (the default) routes audio through `atempo`, which
preserves pitch by design. `false` uses `asetrate=<rate × factor>` followed by
`aresample=<rate>`, which shifts the pitch with the speed — the "chipmunk"
effect. That is the only reason to ask for it, so it is an explicit argument
rather than a hidden default.

### The file may have no audio at all

`-af` fails outright when there is no audio stream. One `ffprobe` call answers
both questions the operation needs — whether audio exists, and (only for the
non-pitch-preserving branch) its sample rate — so `-vf` is always sent and
`-af` only when there is something to filter.

### Two deliberate deviations from WordPress

- **`speed_factor` is required, and `1.0` is refused.** The WordPress tool
defaults it to 1.0, which re-encodes the file without changing anything: a
transcode spent and a new file stored for no effect. The refusal names
`compress_video` and `convert_video_format`, which are the tools for those two
intents.
- **`maintain_pitch` is honoured rather than assumed.** Same default as
WordPress, but the `false` branch is implemented rather than accepted and
ignored.

### A note on flags, for whoever touches this family next

The video tools declare `getCapabilityFlags()` but do **not** implement
`ToolCapabilityFlagsInterface`. Two consumers read the method differently:
`ToolRouterHarness` falls back to `method_exists`, so routing sees the declared
flags; `CapabilityFlagResolution` (the necessity gate) requires the interface,
so it resolves these tools through `ToolCapabilityFlagCatalogue` instead. The
catalogue entry for every video slug is `media, video_editing`, which derives
**MODERATE** irreversibility and therefore `WARN` for a well-formed call.

That is the current, verified behaviour — but it is an accident of two lookup
paths disagreeing, not a decision. Making the family implement the interface
would change the rung: the declared `external-api` puts these tools in
`hasOutboundMutation`, which derives **HIGH** and would push a routine re-encode
to `APPROVAL_REQUIRED`. A deliberate change would drop `external-api` (the media
worker is estate-internal) and declare `reversible`, deriving **LOW** —
`ALLOW` instead of `WARN`. That is a product decision about warnings, so it
belongs in its own change with its own tests, not folded into a tool port.

### Measurement

`bin/audit-tool-readiness.php`: **277 rows / 258 servable / 0 violations** (was
276 / 257). `bin/toolkit-parity.php`: **56 of 854** (was 55);
`video-production` is **7 of 21** (was 6).

`lib/core` 2,456 tests / 17,525 assertions; adapter 359 / 1,918; the app suite
gains 5 tests in `VideoProcessingAdapterTest`; the worker gains 5 in
`video.test.js` (12 total).

## 164.18 Addendum — `optimize_for_platform`, and a stub worth beating (2026-11-25)

### The WordPress tool does nothing

`class-wp-mcp-ai-tool-optimize-for-platform.php` ends with:

```php
// TODO: Implement optimize_for_platform logic.
return array( 'success' => true, 'message' => __( 'Optimize for Platform executed successfully. Note: Video processing requires FFmpeg.' ) );
```

It reports success without reading the file, and it declares no frame sizes at
all. Porting it faithfully would mean shipping a lie, so this one is
implemented. That is the "on par or better" bar the toolkit ask set, and it is
recorded here rather than passed off as a straight port.

### `resize` cannot do this

`resize_video_resolution` scales with `scale=W:-1`, which preserves the aspect
ratio. A 16:9 source asked for 1080×1920 comes back **1080×607** — a
letterbox-shaped speck in a vertical feed. A platform preset has to fill the
frame, which is a different filter chain, so the route gains one operation.

### The route's new `fit` operation

| mode | filter |
| --- | --- |
| `cover` (default) | `scale=W:H:force_original_aspect_ratio=increase,crop=W:H` |
| `contain` | `scale=W:H:force_original_aspect_ratio=decrease,pad=W:H:(ow-iw)/2:(oh-ih)/2` |

`force_original_aspect_ratio` is the whole point: it scales to the covered (or
contained) size and then lets `crop`/`pad` land exactly on the requested frame.
Both `crop` and `pad` centre by default, so a wide source loses equal amounts
left and right rather than everything on one side. `contain` pads with black.
Neither mode is guessed: an unknown value is refused at the route, again because
it becomes an ffmpeg argument.

### The frames, and where they came from

| platform | feed | story | reel | short |
| --- | --- | --- | --- | --- |
| youtube | 1920×1080 | — | — | 1080×1920 |
| instagram | 1080×1350 (4:5) | 1080×1920 | 1080×1920 | — |
| tiktok | 1080×1920 | 1080×1920 | 1080×1920 | 1080×1920 |
| facebook | 1280×720 | 1080×1920 | 1080×1920 | — |
| twitter | 1280×720 | — | — | — |

Three sizes cover nearly every surface: 16:9 landscape, 9:16 vertical, and 4:5
for a feed post. 4:5 is used for the Instagram feed because it is the tallest
ratio the feed accepts — a square still works but fills less of the screen.

**Only real pairs are listed.** Twitter has no story, YouTube has no story, and
Instagram does not call anything a short; those pairs are refused with the
placements the platform *does* have rather than substituted with something
plausible. A silent substitution is how a vertical video ends up pillar-boxed
in a feed nobody checked.

### The supported list lives in the message, not only in the field errors

The Laravel error factory keeps `$message` and drops the `$errors` array
(`ErrorFactory::validationFailed()` builds a `RuntimeException` from the message
alone). A refusal that listed the supported placements only in the field errors
would therefore be invisible to every caller in this runtime, so the list is
repeated in the message.

### Measurement

`bin/audit-tool-readiness.php`: **278 rows / 259 servable / 0 violations** (was
277 / 258). `bin/toolkit-parity.php`: **57 of 854** (was 56);
`video-production` is **8 of 21** (was 7).

`lib/core` 2,458 tests / 17,535 assertions; adapter 359 / 1,919; the app suite
gains 4 tests in `VideoProcessingAdapterTest`; the worker gains 1 in
`video.test.js` (13 total).

## 164.19 Addendum — `transcode_video`, and where `video-production` stands (2026-11-25)

### A real WordPress implementation this time

Unlike §164.18's stub, `class-wp-mcp-ai-tool-transcode-video.php` is a real
fluent-ffmpeg implementation, so this is a port. It carries one deliberate
deviation (below) and one narrowing.

### Allowlists on both sides of the wire

Codec names reach `-c:v` / `-c:a` and bitrates reach `-b:v` / `-b:a`. Both are
validated in the tool **and** in the route — not because one distrusts the
other, but because a direct route caller exists too. Bitrates are shaped
(`^[0-9]{1,6}[km]?$`), which is not cosmetic: a value beginning with a dash is
the shape that could be read as another ffmpeg option, and that shape cannot
match the pattern.

### Container ↔ codec compatibility is checked, not discovered

| container | encoders |
| --- | --- |
| mp4, mov | libx264, libx265 |
| mkv | libx264, libx265, libvpx, libvpx-vp9 |
| webm | libvpx, libvpx-vp9 |
| avi | libx264, libx265 |
| flv | libx264 |

The two traps this closes: **VP8/VP9 in an MP4** (ffmpeg refuses late, and the
message is about a stream rather than a container) and **H.264 in a WebM**
(ffmpeg accepts, and writes a file no browser reads). An incompatible pair is
refused with the encoders that container does take.

### `resolution` is a height, not a pair of numbers

The WordPress enum is six `WxH` strings. Applying both numbers to a 9:16 source
stretches it, so the tool maps each pair to its height and sends only that; the
route derives the width with `scale=-2:H`. `-2` rather than `-1` keeps the
derived side **even**, which x264 and x265 require — an odd width is a hard
encoder failure, not a soft quality loss.

`avi` and `flv` joined the route's container allowlist so the WordPress enum is
covered rather than trimmed, and `AbstractVideoTool::mime()` gained their types
so a stored `.avi` is not filed as `application/octet-stream`.

### `preset` is deliberately absent

The WordPress tool accepts a platform preset that duplicates
`optimize_for_platform`. Two tools with a preset table are two chances to
disagree about a frame size, and the caller cannot tell which one won. Transcode
is for encoder control; the platform frame belongs to the other tool.

### Where `video-production` now stands — 9 of 21

| status | slugs |
| --- | --- |
| ported | compress_video, convert_video_format, generate_video_thumbnails, get_video_metadata, resize_video_resolution, trim_video, adjust_video_speed, optimize_for_platform, transcode_video |
| remaining | add_watermark_to_video, create_remotion_video, create_video_from_images, extract_video_frames, extract_video_metadata, generate_video_captions, get_queued_videos, get_videos_without_thumbnails, get_videos_without_transcripts, merge_videos, transcribe_video, upload_video_batch |

The remaining twelve are not one backlog with one difficulty. Grouped by what
actually blocks them:

- **A second input.** `merge_videos` needs several uploads, `add_watermark_to_video`
  needs an image, `create_video_from_images` needs a sequence. Each is a route
  *contract* change — `upload.single` to `upload.array`, or a second field — not a
  filter tweak. Doing one establishes the shape for the other two.
- **Not video editing at all.** `create_remotion_video` is a React renderer.
  `generate_video_captions` and `transcribe_video` are speech models: a provider
  call, not ffmpeg. Note `generate_video_caption` (singular) already exists over
  a provider and is catalogued separately.
- **Audit and queue surfaces with nothing to read.** `get_queued_videos`,
  `get_videos_without_thumbnails`, `get_videos_without_transcripts` and
  `upload_video_batch` all read WordPress's attachment/queue tables. This runtime
  has no equivalent table, and inventing one so a slug can return `[]` would be
  parity theatre — the same objection §164.18 raised to porting a stub.
- **Overlaps a tool already ported.** `extract_video_metadata` is the same
  ffprobe payload as `get_video_metadata` behind different flags.
- **The route has the operation and no slug asks for it.** `extract_video_frames`
  is a many-file return shape, and `extract_audio` (which no WordPress slug
  requests at all — see §164.17) is available as an additive tool.

### Measurement

`bin/audit-tool-readiness.php`: **279 rows / 260 servable / 0 violations** (was
278 / 259). `bin/toolkit-parity.php`: **58 of 854** (was 57);
`video-production` is **9 of 21** (was 8) — from 0 when this arc began.

`lib/core` 2,460 tests / 17,545 assertions; adapter 359 / 1,920; the app suite
gains 5 tests in `VideoProcessingAdapterTest`; the worker gains 2 in
`video.test.js` (15 total).

## 164.20 Addendum — the second-input contract, and `add_watermark_to_video` (2026-11-25)

### The contract changed rather than the method

`VideoProcessingInterface::process()` is one file in, one file out, and every
video tool depends on that meaning. A watermark is two inputs and a merge is N,
so composition is its **own** contract — the same split as
`SocialComposerInterface` versus `SocialPublisherInterface` in §164.15:

```php
VideoCompositionInterface::compose( string $operation, array $inputs, array $options ): array
```

`$inputs` is ordered, base first, and an adapter must not reorder it. A swapped
pair does not fail — it overlays the video onto the mark and returns a
successful, useless file.

### One endpoint for the family

`POST /api/video/compose` takes an ordered `files` array plus `operation`.
`merge` will join it rather than inventing a third contract. The route uses
`upload.any()` and sorts by the index in `files[n]`, mirroring the PDF merge
route, because `MediaWorkerClient::uploadFiles()` already sends indexed parts
and **arrival order is not a contract**.

### The overlay recipe, and why each step exists

```
[1:v]format=rgba,scale=<fraction of video width>:-2,colorchannelmixer=aa=<opacity>[wm];
[0:v][wm]overlay=x=main_w-overlay_w-<gutter>:y=main_h-overlay_h-<gutter>:format=auto[v]
```

- **`format=rgba` comes first** because a JPEG mark has no alpha channel and
  `colorchannelmixer` would have nothing to multiply.
- **The mark's pixel width is resolved from the video's width.** `overlay` can
  compute positions from its own inputs, but not the `scale` that precedes it —
  so the route probes the video first. That is also what makes the same
  `scale: 0.2` look identical on a 4K clip and a 720p one instead of being
  invisible on one and enormous on the other.
- **`-map 0:a?`** maps the audio when there is one and stays quiet when there is
  not. Without the `?`, a silent video fails the whole run.
- **`-c:v libx264 -crf 20 -pix_fmt yuv420p`** and audio `aac` (`libopus` for
  webm). Watermarking re-encodes video by definition; re-encoding the audio too
  is what keeps the output valid when the container changes.

Proven against real ffmpeg in the worker image, not only in unit tests: a silent
`testsrc` clip marked with a PNG, and the same clip marked with a **JPEG** (no
alpha), both produced valid 640×360 H.264 output.

### One filename rule, two adapters

`safeRemoteName()` left `MediaWorkerVideo` and became `MediaWorkerRemoteName`,
so the single-input and composition adapters cannot drift about what an output
name may look like. §164.16's lesson — a containment check that *resolves* a
path and a charset check that does not are not interchangeable — now lives in
one place with the hostile-name tests still proving it through the tool.

### Another stub, implemented

`class-wp-mcp-ai-tool-add-watermark-to-video.php` is a `// TODO` followed by
`return array( 'success' => true, … )`, exactly like §164.18's
`optimize_for_platform`. It is recorded as a port with that stated, not as a
faithful copy of a tool that never drew a pixel.

### Measurement

`bin/audit-tool-readiness.php`: **280 rows / 261 servable / 0 violations** (was
279 / 260). `bin/toolkit-parity.php`: **59 of 854** (was 58);
`video-production` is **10 of 21** (was 9).

`lib/core` 2,462 tests / 17,555 assertions; adapter 359 / 1,921; the app suite
gains 10 tests in `VideoCompositionAdapterTest`; the worker gains 2 in
`video.test.js` (17 total).

### What this unblocks

`merge_videos` and `create_video_from_images` now reuse `/api/video/compose` —
only their filter graphs are new. `merge` still owns the hard part: inputs that
differ in resolution, frame rate or audio presence need normalising before
`concat`, which is a graph of its own rather than a second upload route.

## 164.21 Addendum — `merge_videos`, and the precondition that stops a silent corruption (2026-11-25)

### The third stub

`add_watermark_to_video` and `optimize_for_platform` were stubs; so is this one
— `// TODO` then a success envelope. It is the least honest of the three,
because it declares `transition: fade` as its **default** and then does nothing
at all.

### A copy-merge is the right merge

The concat demuxer with `-c copy` joins streams without re-encoding: no quality
loss, and sub-second. The concat *filter* re-encodes every frame. When the clips
already match, re-encoding them buys nothing, so copy is the path taken here.

### But the demuxer does not fail on a mismatch — measured

This is the finding the whole design hangs on. Joining a 25 fps, 2 s clip to a
30 fps, 2 s clip with `-c copy`:

```
copy path duration:       5.000000      # matching pair, correct
mismatched-fps duration:  4.800000      # wrong, and no error
streams:                  h264,25/1,110  # still claims 25/1
```

The merge **succeeded** and produced a file whose duration is wrong. That is
worse than a refusal, so the route probes every clip and compares the
properties a copy cannot change:

| property | why |
| --- | --- |
| frame size | the decoder would resize mid-stream |
| frame rate | the case measured above |
| video codec | a copy cannot transcode |
| presence of audio | the demuxer expects the same streams in every file |
| audio codec, sample rate | same reason, one layer down |

A mismatch is refused naming **which clip** and **which property**, with the fix
in the message (`transcode` normalises an outlier). The output container is
gated the same way, from the probed codec rather than the caller's wish: h264
and hevc copy into mp4/mov/mkv, VP8/VP9 into webm/mkv.

### Transitions are a different operation, not a flag

`fade`/`dissolve` need `xfade` and `acrossfade`, which re-encode both clips and
require cumulative offsets computed from every clip's duration. That is a
re-encode operation with its own preconditions, so it is recorded as the
follow-up rather than half-built. The default here is `none`; asking for a fade
is refused **with the reason**, not silently ignored.

### Errors now carry the right class — without moving the old one

A first attempt made **400 and 422** an `InvalidArgumentException` outright. CI
caught what that broke: `ImageProcessingAdapterTest` expects a `RuntimeException`
with the `Media worker error:` prefix, and it is right to — that has been the
contract for every media adapter.

The refusal is therefore `MediaWorkerRefusedException`, a **subclass** of
`RuntimeException` carrying the identical message, so nothing that catches the
base class changes. The composition adapter translates it to
`InvalidArgumentException`, which is what `VideoCompositionInterface` documents
and what lets merge and watermark answer 422 rather than reporting a bad
argument as a broken worker.

The lesson is the one §164.16 recorded about filenames, one layer up: a rule
that is right for the caller you are working on is not automatically right for
every caller of a shared helper. The base class stayed; the refinement is
opt-in.

### Measurement

`bin/audit-tool-readiness.php`: **281 rows / 262 servable / 0 violations** (was
280 / 261). `bin/toolkit-parity.php`: **60 of 854** (was 59);
`video-production` is **11 of 21** (was 10).

`lib/core` 2,464 tests / 17,565 assertions; adapter 359 / 1,922; the app suite
gains 5 tests in `VideoCompositionAdapterTest` (15 total); the worker gains 3 in
`video.test.js` (20 total).

### What is left in this group

`create_video_from_images` — the same endpoint and the same contract; the work
is a per-image duration and the `image2`/`loop` handling.

## 164.22 Addendum — `create_video_from_images`, and the group closes (2026-11-25)

### The fourth stub

`class-wp-mcp-ai-tool-create-video-from-images.php` is `// TODO` followed by a
success envelope, and it declares `transition: fade` as its default. Same call
as §164.18 and §164.20: implemented, with that stated.

### Each still becomes a looped input

```
-loop 1 -t <seconds> -i <file>     (per image)
[i:v]scale=W:H:force_original_aspect_ratio=decrease,pad=W:H:(ow-iw)/2:(oh-ih)/2,
     setsar=1,fps=25,format=yuv420p[vi];
[v0][v1]…concat=n=N:v=1:a=0[v]
```

The obvious alternative — a concat-demuxer list with a `duration` line per
image — needs the **last file repeated** (the demuxer ignores a trailing
duration) and assumes every still has the same dimensions. Explicit looped
branches have neither problem, and the normalisation is the same recipe `fit`
and `merge` already use.

### `contain`, not `cover`

A portrait photograph in a landscape frame is letterboxed, not cropped.
Cropping a still loses the part someone chose to show; bars are honest about
the shape mismatch. `optimize_for_platform` remains the tool for *filling* a
frame, including its `cover` mode.

### Verified against real ffmpeg

Three stills at 2 s each — 1920×1080, a **portrait** 1080×1920, and 800×600 —
produced **6.000 s exactly**, 1280×720, 150 frames at 25 fps. The portrait one
was letterboxed rather than stretched or cropped, which is the whole point of
the `pad` branch.

### Two deliberate omissions

- **Only `transition: none`.** `fade`/`slide`/`zoom` are `xfade`/`zoompan`: a
  chain that re-encodes and needs cumulative offsets from every segment's
  duration. Declared, refused with the reason, recorded as the follow-up.
- **No background-audio argument.** The compose route reads one ordered file
  list; mixing "N images then a track" into it makes the boundary ambiguous. A
  silent slideshow that says it is silent beats an `audio` argument that is
  accepted and dropped.

### One ordering rule, now in the base

`inputPaths()` moved from a private method on `MergeVideosTool` into
`AbstractVideoTool`, because both multi-input tools need it and the rule is the
same one `sourcePath()` has always used: **ids win over URLs**, and the caller's
order is preserved — never sorted.

### Measurement

`bin/audit-tool-readiness.php`: **282 rows / 263 servable / 0 violations** (was
281 / 262). `bin/toolkit-parity.php`: **61 of 854** (was 60);
`video-production` is **12 of 21** (was 11).

`lib/core` 2,466 tests / 17,575 assertions; adapter 359 / 1,923; the app suite
gains 5 tests in `VideoCompositionAdapterTest` (20 total); the worker stays at
20 — this slice added no new pure helper, because the graph is built inline from
validated fields.

### The second-input group is closed

All three slugs that needed more than one upload now exist on one endpoint and
one contract: `merge_videos`, `add_watermark_to_video`,
`create_video_from_images`. `video-production` is **12 of 21**, from 0 when
this arc began. The nine remaining are the groups §164.19 described — a
renderer, speech models, audit surfaces with no equivalent table, one duplicate,
and two operations no WordPress slug asks for.
