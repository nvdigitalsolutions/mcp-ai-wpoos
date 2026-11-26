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

Superseded for `fade` and `slide` by §164.23; the `zoom` refusal and the
missing audio argument still stand.

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

## 164.23 — Transitions: `xfade` and `acrossfade` (2026-11-25)

Both tools already declared a transition argument and then refused every value
but `none` (§164.19, §164.22). That is now false: `merge_videos` implements
`fade` and `dissolve`, and `create_video_from_images` implements `fade` and
`slide`. `zoom` is still refused, and still for the reason §164.22 gave.

### A transition cannot copy, so it re-encodes

Every frame `xfade` emits during an overlap is a blend of two frames. There is
nothing to copy, so `transition: none` and a transition are two different paths
on the same operation, not one path with a flag:

| | `none` | `fade` / `dissolve` / `slide` |
|---|---|---|
| encode | `-c copy` via the concat demuxer | `libx264` (or `libvpx-vp9` into WebM) |
| preconditions | identical frame, rate, codec, audio layout | only a readable duration |
| mismatches | refused, first difference named | normalised into the first clip's frame |
| cost | none | one generation |

`none` stays the default even though the WordPress schemas default to `fade`.
A default that silently re-encodes would change the output of every existing
pipeline; opting in costs one argument.

### The offsets are cumulative, and that is the whole difficulty

`offset` is measured on the **first** input's timeline, and after chaining the
first input is the previous result. So each cut is the running sum of the clips
behind it, less every overlap already removed:

```
offset_i = (d₁ + … + d_i) − i × T
```

Three independent write-ups give the same formula, and it was checked against
the ffmpeg in the worker image (8.1.2) rather than trusted:

| inputs | T | offsets | measured |
|---|---|---|---|
| 2 + 3 + 4 s | 0.5 | `[1.5, 4]` | **8.000000 s** video-only; 8.024–8.056 s with AAC |
| 10 + 10 + 10 s | 1 | `[9, 18]` | 29 s |
| three 2 s stills | 0.5 | `[1.5, 3]` | **5.000000 s** exactly |

A non-positive offset means a clip is shorter than the transition, so the cut
would have to start before that clip has a frame. The route refuses that with
`transition_duration >= shortest` and `xfadeOffsets()` returns `null` as the
second line of defence — never a clamp, because clamping changes the timing of
every later cut.

Audio costs an extra frame of container time: AAC primes its first frame, so a
run asserted to be 8 s reads 8.024–8.056 s from `ffprobe`. Assert the duration
the route reports, and allow a tolerance only when a track is present.

### Normalise, then cross-fade

`xfade` refuses mismatched inputs rather than adapting them, so the transition
path scales and pads each clip into the first clip's frame (`decrease` + `pad`
is `contain` — a crop would silently lose edges), sets `setsar=1`, forces the
first clip's `fps`, and `aformat`s the audio to `fltp`/stereo/48 kHz before
`acrossfade`. This is deliberately the opposite of the copy path, which refuses
a mismatch: re-encoding makes adaptation free, copying makes it impossible.

Mixed audio presence is still **refused**: `acrossfade` needs a track on both
sides of every boundary, and both available answers — drop the tracks, or invent
silence — change the result in a way the caller should choose, not the route.

### Names are looked up, not relayed

`slide` is the tool's word; ffmpeg calls the filter `slideleft`. `XFADE` is the
table that maps one to the other, and a name outside it never reaches a filter
argument. `zoom` has no entry on purpose: Ken Burns motion is `zoompan` applied
**within** each still — a different filter on a different timeline — so
answering `zoom` with a cross-fade would be a different video from the one asked
for. The enum keeps `zoom` (the WordPress contract has it); the tool refuses it
and names `zoompan` in the message. (Superseded by §164.24, which implements
that drift.)

Per-operation allowlists, because the two WordPress enums differ:

- `merge` → `none`, `fade`, `dissolve`
- `images` → `none`, `fade`, `slide`

The overlap is a caller's knob on both operations: `transition_duration`,
bounded to 0.05–5 s. `merge` defaults to the 1 s its WordPress schema declares.
`create_video_from_images` has no such field in its WordPress contract, so the
tool adds one and defaults it to 0.5 s — a half second reads better against a
2–3 s still than a merge's 1 s. The field only travels when a transition is
asked for, so a copy-merge and a plain slideshow send exactly the field set they
always sent, and it is reported back in the envelope only when it applied.

### A latent WebM bug fell out of this

The §164.22 slideshow path hardcoded `-c:v libx264` while `resolveFormat()`
accepts `webm`. H.264 cannot be written into WebM, so `output_format: webm`
would have failed at the ffmpeg call and surfaced as a 500. Both re-encoding
paths now choose the encoder from the container (`videoEncoder()`), and the
handler smoke asserts a `webm` merge really is `vp9`.

### Where the work lives

| file | change |
|---|---|
| `addons/media-worker/src/routes/video.js` | `XFADE`, `TRANSITIONS`, `resolveTransition()`, `xfadeOffsets()`, `videoEncoder()`; `probeMedia()` now reports `duration`; merge split into copy and transition paths; slideshow gained the chain |
| `lib/core/src/Tool/MergeVideosTool.php` | `transition_duration` added to the schema; the refusal removed; transition reported in `data` |
| `lib/core/src/Tool/CreateVideoFromImagesTool.php` | `fade`/`slide` accepted; `transition_duration` added (default 0.5 s); `zoom` refused with the `zoompan` reason |
| `lib/laravel-adapter/src/Adapter/MediaWorkerVideoComposition.php` | per-operation transition allowlist; `transition`/`transition_duration` fields; both surfaced in the result |
| `lib/core/resources/tool-readiness.php` | both `reason` entries describe the two paths |

### Measurement

`bin/audit-tool-readiness.php`: **282 rows / 263 servable / 0 violations**
(unchanged — no slug added). `bin/toolkit-parity.php`: **61 of 854**,
`video-production` **12 of 21** (both unchanged: transitions fulfil arguments
those tools already advertised, they do not add tools).

`lib/core` 2,466 / 17,575 (unchanged); adapter 359 / 1,923 (unchanged);
`VideoCompositionAdapterTest` **29** (was 20 — two refusal tests replaced by
nine that pin the new behaviour, plus two for the slideshow duration knob);
worker pure tests **30** (was 20).

On top of those, a handler-level smoke boots the real router, posts real
multipart bodies and probes real output with ffmpeg 8.1.2 — **29 checks**, in
one container, covering: merge-with-fade duration and frame, dissolve with the
default 1 s, the `slide`-on-merge refusal, the copy path still refusing a
mismatch and still copying, mixed audio, a transition longer than the shortest
clip, `webm` → VP9, a `slide` slideshow at 5.000 s, the plain slideshow
unchanged at 6 s, `zoom` refused, a 0.4 s image too short for a 0.5 s
transition, a 0.25 s transition making three 2 s stills 5.5 s, an explicit
duration equal to the image length refused, an out-of-range duration refused,
and a single still reporting `none`.

## 164.24 — `zoom` becomes Ken Burns motion (2026-11-25)

§164.23 shipped `fade` and `slide` and refused `zoom`, naming `zoompan` as the
missing filter. This is that filter. `create_video_from_images` now accepts all
four of the WordPress enum's values.

### The one enum value that is not a boundary

`fade`, `slide` and `dissolve` join two inputs and therefore remove their
overlap from the timeline. `zoom` does not: it is motion **inside** one still,
so the joins stay hard cuts and the timeline keeps its full length. Two stills
of 2 s are 4 s with `zoom`, and 3.5 s with a 0.5 s `fade`.

That also makes `transition_duration` inert for `zoom`. It is not refused — the
render is correct either way — but it is not sent, and the route's overlap
arithmetic is gated on `XFADE[ name ]` rather than on `name !== 'none'`.

A caller who wanted a zoom *transition* between two stills asked for something
this tool does not do; the schema description says so rather than quietly
substituting a cross-fade. Combining the drift with a cross-fade would need a
separate `motion` field: one enum cannot express two axes, and inventing a
second name inside `transition` would be worse than stating the limit.

### Two traps, both measured

`zoompan` has two behaviours that produce a file which `ffprobe` calls correct
and a viewer calls broken.

**`d` is a frame count per input frame.** With `-loop 1 -t N` the branch already
carries N × fps frames, so `d` must be 1. Anything larger multiplies the clip's
length instead of setting it. Verified: 2 s at 25 fps with `d=1` gives exactly
50 frames and 2.000 s.

**`zoom` restarts at 1 for every input frame.** The usual tutorial expression —
`z='min(zoom+0.0015,1.5)'` — never moves on a looped still, because each of the
50 input frames starts the accumulator again. The expression is driven by `on`,
the output frame number, instead:

```
z='min(1.12,1+0.1200*max(on-1,0)/49)'
```

`max(on-1,0)` holds the first frame at exactly 1.0 whichever of 0 or 1 `on`
begins at, and `min()` removes the overshoot if it begins at 1. Verified with
frame-to-frame PSNR: frame 0 against frame 49 reads **7.27 dB**, where an unmoving
still would read `inf`.

### Upscale, or the pan shimmers

`zoompan` computes `x`/`y` in integer source pixels, so a source the same size
as the output steps a whole output pixel at a time and the drift shimmers.
`kenBurnsUpscale()` doubles the branch first — but only while the doubled frame
fits an 8.5-megapixel budget, so 720p and 1080p are doubled and 4K is not (a
33-megapixel intermediate would buy nothing visible). Both branches are
exercised in the smoke, at 1920x1080 and 3840x2160.

The motion is a centred drift; there is no pan. A pan needs a direction, and a
direction is a second parameter with no home in the WordPress contract.

### Where the work lives

| file | change |
|---|---|
| `addons/media-worker/src/routes/video.js` | `zoom` added to `TRANSITIONS.images`; `KEN_BURNS_*`; `kenBurnsUpscale()`, `kenBurnsZoompan()`; the slideshow's overlap maths gated on `XFADE[ name ]` |
| `lib/core/src/Tool/CreateVideoFromImagesTool.php` | `zoom` accepted; `BOUNDARY_TRANSITIONS` added so `transition_duration` travels only with a join |
| `lib/laravel-adapter/src/Adapter/MediaWorkerVideoComposition.php` | `zoom` on the `images` allowlist; `BOUNDARY_TRANSITIONS` gates the duration |
| `lib/core/resources/tool-readiness.php` | the `reason` describes three graph shapes, not two |

### Measurement

`bin/audit-tool-readiness.php`: **282 rows / 263 servable / 0 violations**
(unchanged — no slug added). `bin/toolkit-parity.php`: **61 of 854**,
`video-production` **12 of 21** (unchanged).

`lib/core` 2,466 / 17,575 and adapter 359 / 1,923, both unchanged;
`VideoCompositionAdapterTest` **30** (was 29 — the zoom refusal became a
forwarding test, plus one pinning that `zoom` is not a merge transition); worker
pure tests **35** (was 30 — five pin the `on`-driven expression, `d=1`, the
output frame and the upscale budget).

The handler smoke grows to **21 checks** and is the only place the drift is
proven rather than described: two 2 s stills with `zoom` are 100 frames and
4.000 s, frame 0 against frame 49 reads 7.27 dB, the second still starts its own
drift, 1080p and 4K both keep their frame, WebM still encodes VP9, and a single
still reports `none` because one still has no boundary — and no motion to add.

## 164.25 — `extract_video_frames`, and the many-file return shape (2026-11-25)

§164.19 grouped this slug under "the route has the operation and no slug asks for
it". That was half right: the route had the operation since it was written, but
nothing could return its answer. `/process` is one file in, one file out, and
four tools read that meaning. A contact sheet is N files.

### A contract, not a widened return type

```php
VideoFrameExtractionInterface::extract( string $localPath, array $options ): array
// { frames: list<{path, bytes}>, frame_count: int, source_duration: float }
```

The same reasoning that gave composition its own contract for N *inputs*
(§164.20) gives extraction one for N *outputs*. Widening `process()` would have
changed a return shape that eight tools destructure — `trim_video`,
`compress_video`, `transcode_video`, `resize_video_resolution`,
`convert_video_format`, `adjust_video_speed`, `optimize_for_platform` and
`generate_video_thumbnails`.

### The three modes are alternatives

`timestamps` names the moments, `interval` asks for a cadence, `frame_count`
asks for an even spread. They are not additive — "every 5 s and also at these
moments" is a list, and the list is `timestamps`. Sending `timestamps` and
`interval` together is **refused** rather than resolved by a precedence the
caller has to guess; a contact sheet at the wrong moments looks exactly like one
at the right ones.

### What the route was missing

The operation existed, but it overrode two fields the WordPress tool declares:
`-q:v` was hardcoded to 2, and `interval` was not read at all. Silently ignoring
a declared parameter is the one thing this family refuses to do, so the route now
reads both. `interval` is expanded to stamps from the duration ffprobe reports —
multiplication rather than accumulation, because `0.1 + 0.2 + 0.3` is
`0.6000000000000001` and a seek time that drifts is a frame at the wrong moment.

Two details fell out of writing it:

- the route was probing the duration **inside** the count branch; one probe now
  answers both questions;
- the answer gained `source_duration`, which the tool reports so a caller can
  reason about `interval` without a second call.

### Measured, not assumed

A handler smoke posts real multipart bodies and inspects the files:

| request | result |
|---|---|
| `interval: 2` on a 10 s clip | **5 frames**, `source_duration: 10` |
| `timestamps: 1,5,9` | 3 frames |
| `quality: 31` vs `2` at the same timestamp | **3,254 B vs 9,190 B** — the field reaches `-q:v` |
| `width: 160` | a 160×120 frame |
| `count: 4` | 4 names, 4 files |
| `interval: 0.01` | 400, "between 0.1 and 3600" |

The first answered name downloads with JPEG magic bytes `ffd8`, and
`MediaWorkerRemoteName` validates the nested `frames_<ts>/frame_000.jpg`
per segment — a single-filename regex would have passed `../../etc/passwd`.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/VideoFrameExtractionInterface.php` | new contract |
| `lib/core/src/Tool/ExtractVideoFramesTool.php` | new tool; three modes refused when combined |
| `lib/laravel-adapter/src/Adapter/MediaWorkerVideoFrames.php` | upload once, download N times, write content-addressed frames |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | binding |
| `addons/media-worker/src/routes/video.js` | `frameTimestamps()`, `quality`, `interval`, `source_duration` |
| `lib/core/resources/tool-readiness.php` | 283rd row |

### Measurement

`bin/audit-tool-readiness.php`: **283 rows / 264 servable / 0 violations** (was
282 / 263). `bin/toolkit-parity.php`: **62 of 854** (was 61); `video-production`
is **13 of 21** (was 12) — the baseline records the new slug.

`lib/core` 2,468 / 17,585 (was 2,466 — the class-derived tests pick the tool up
on their own); adapter 359 / 1,925; the app gains `VideoFramesAdapterTest`
(**13** tests / 61 assertions); worker pure tests **40** (was 35).

### What is left, and why each stays

Eight, not nine:

- **A renderer.** `create_remotion_video` is React rendering, not ffmpeg.
- **Speech models.** `transcribe_video` and `generate_video_captions` are
  provider calls with no worker route to wrap.
- **A table that does not exist.** `get_queued_videos`,
  `get_videos_without_thumbnails`, `get_videos_without_transcripts` and
  `upload_video_batch` read WordPress's attachment and queue tables. Inventing
  one so a slug can answer `[]` is parity theatre, the objection §164.18 raised
  to porting a stub.
- **A duplicate.** `extract_video_metadata` is the same ffprobe report as
  `get_video_metadata` behind different flags, and its row already says so.

`extract_audio` — which no WordPress slug requests — remains the one additive
tool §164.19 called "available": the route has the operation, so it needs a tool
and nothing else.

## 164.26 — `extract_audio`, and copying instead of re-encoding (2026-11-25)

That last line is now done. `extract_audio` ships as an **additive** tool: no
WordPress slug in the toolkit requests it (§164.17), so `video-production` stays
at 13 of 21 and the total stays at 62 of 854. It is the first slug in this arc
that moves no parity number at all.

### The route was throwing away quality

Before this slice the codec was chosen from the **output container**:

```js
const codec = 'mp3' === format ? 'libmp3lame'
  : ('m4a' === format || 'aac' === format ? 'aac' : 'copy');
```

So extracting an AAC track into `m4a` — the one case where a copy is blindingly
obvious — re-encoded it. Every m4a extraction was a generation loss for nothing.
The codec now comes from the **source** stream, which the route probes:

| source | target | codec | `reencoded` |
|---|---|---|---|
| AAC | `m4a` | copy | **false** |
| AAC | `aac` | copy | **false** |
| MP3 | `mp3` | copy | **false** |
| AAC | `mp3` | libmp3lame | true |
| anything | anything else | encode | true |

The rule is `audioExtractionCodec()` — a pure function, because a decision this
consequential should be tested without an encoder.

### `probeMedia()` could not answer it

`probeMedia()` returns null when a file has no **video** stream, and an
audio-only file is a legitimate input to an extraction tool. Reusing it would
have meant silently re-encoding every MP3 → MP3 request. `probeAudioCodec()`
probes `a:0` alone and distinguishes three answers that matter:

- a codec name — the stream exists and may be copied;
- `''` — the probe succeeded and there is no audio, which is a **400**
  ("This file has no audio track to extract.") instead of an ffmpeg failure
  four steps later;
- `null` — the probe could not be read, so nothing is claimed, and the route
  encodes rather than copying something ffmpeg would reject.

### `reencoded`, and why it is in the envelope

A caller of an extraction tool has one question: *did this cost me a
generation?* The route answers `reencoded` only for this operation — every other
operation re-encodes by definition — and the tool lifts it into the envelope
alongside a `lossless` boolean. When the engine does not say, the tool reports
`reencoded: true`: "it did not say" is not the same as "it was lossless".

`audio_bitrate` (128k by default) travels only when a copy is not possible; the
route drops it otherwise, because a bitrate describes an encoder.

Containers are limited to `mp3`, `m4a` and `aac`. The route can write others,
but a video container cannot carry an extracted track, and `wav` is not in the
route's format allowlist — so both are refused with the list rather than left to
the muxer.

### Measured

A handler smoke with real ffmpeg 8.1.2, **12 checks**:

| request | result |
|---|---|
| AAC video → `m4a` | `reencoded: false`, codec `aac`, no video stream, 39.5% smaller |
| AAC video → `mp3` @128k | `reencoded: true`, codec `mp3` |
| same, @320k | stream bitrate **320000 vs 128000** — the field reaches `-b:a` |
| **audio-only** MP3 → `mp3` | `reencoded: false`, **73,395 bytes in, 73,395 out** |
| silent video → any | 400, "no audio track to extract" |
| `format: wav` | 400, "Unknown output format" |

The byte-identical MP3 case is the one that proves the audio-only probe: with
`probeMedia()` the source would have looked unreadable and been re-encoded.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Tool/ExtractAudioTool.php` | new tool; containers, bitrate, `reencoded`/`lossless` |
| `lib/laravel-adapter/src/Adapter/MediaWorkerVideo.php` | sends `audio_bitrate` for the operation; forwards `reencoded` |
| `addons/media-worker/src/routes/video.js` | `audioExtractionCodec()`, `probeAudioCodec()`, the copy rule, `reencoded` in the answer |
| `lib/core/resources/tool-readiness.php` | 284th row |

### Measurement

`bin/audit-tool-readiness.php`: **284 rows / 265 servable / 0 violations** (was
283 / 264). `bin/toolkit-parity.php`: **62 of 854**, `video-production` **13 of
21** — both unchanged, which is the point of an additive tool.

`lib/core` **2,470 / 17,595** (was 2,468 / 17,585); adapter **359 / 1,926**;
the video group **80 / 306** (was 75 / 285 — five new tests in
`VideoProcessingAdapterTest`); worker pure tests **43** (was 40).

## 164.27 — `transcribe_video` and `generate_video_captions`, and what a stub was hiding (2026-11-25)

The two slugs that were left are the two that looked like they needed a service.
They did not: `transcribe_openai_audio` was already `ready`, and `extract_audio`
(§164.26) supplied the missing half — a video becomes audio, audio becomes text.
So `video-production` moves **13 → 15 of 21** and the total **62 → 64 of 854**.

### Neither WordPress tool does the work

This is the part worth recording, because "port" was the wrong verb for both.

| slug | what the WordPress tool actually does |
|---|---|
| `transcribe_video` | stamps `_mcp_video_transcribe_status = pending` on each attachment and answers "queued" |
| `generate_video_captions` | `return array( 'success' => true, 'message' => '… executed successfully. Note: Video processing requires FFmpeg.' )` |

There is no transcription queue in this runtime and no attachment meta to stamp,
so a faithful port of the first would be a tool that takes money-shaped arguments
and does nothing, and the second was already doing nothing in WordPress. The
ports do the work instead. That is a **capability** port, not a behaviour port,
and the manifest `reason` for each says so.

### `verbose_json`, and the model constraint that follows

OpenAI can format subtitles itself — `whisper-1` accepts `srt` and `vtt`. It is
the *only* model that can: `gpt-4o-transcribe` and `gpt-4o-mini-transcribe`
answer `json` and `text` only, and no model emits ASS. Asking the provider to
format would therefore have locked captions to one model and left `ass`
unserved forever.

The provider is asked for `verbose_json` — `{text, language, duration,
segments[]}` — and every format is built locally from the segments. Two
consequences:

- `OpenAiTranscription` refuses any model but `whisper-1` **up front**, naming
  the reason, instead of letting the provider answer 400 after the upload;
- `CaptionFormatter` is a pure function over a cue list, so SRT, VTT and ASS are
  tested to the character with no network and no disk.

### The formats disagree about escaping, so the formatter does not share one

- **SRT** has no escaping rules; text is text.
- **WebVTT** cue text may not contain `&`, `<` or `>`, and may not contain
  `-->`. All three are written as character references, which also neutralises
  `-->` because it contains `>`. A transcript that says "R&D" produced a file
  parsers disagreed about before this.
- **ASS** treats `{…}` as an override block, so a transcript containing braces
  could otherwise inject `\pos` or a drawing command into a player. Braces are
  escaped and a real line break becomes `\N`, because a literal newline would end
  the `Dialogue:` record and lose the rest of the cue.

A blank line inside a cue is the cue separator in SRT and VTT, so a paragraph
break in a transcript is collapsed rather than allowed to split one cue into two.
A zero-length cue is widened by a millisecond and a backwards cue is swapped:
players drop both, and the words are the part worth keeping.

### `dry_run` defaults to false — a deliberate deviation

WordPress defaults `transcribe_video`'s `dry_run` to **true**. That default
exists because the tool cannot process anything, so "do nothing" is the only
honest default it has. Here the work is real, and a default that silently
performs no work reads as success to an agent: it passes `video_ids`, gets
`success: true`, and believes it has transcripts.

The parameter is still honoured, and a dry run is now worth running: it probes
each source and reports duration, size and whether an audio track exists,
without uploading to a provider or extracting anything. A stored **audio** file
is describable on a host with no media worker at all, so the probe is skipped
there and `duration` is honestly `null` rather than invented.

### `auto_sync` means something in both positions

- `true` uses the provider's segment boundaries — the cues land on the words.
- `false` ignores them and re-flows the transcript one sentence per cue, each
  given a share of the measured duration **proportional to its length**. That is
  a reading pace, not speech alignment, and it is documented as such. The cost
  is that `Mr. Smith` splits into two cues.

The envelope reports `timings: "provider" | "proportional"`, so a caller never
has to guess which one it got. `"proportional"` is also the honest answer when
`auto_sync` is true but the provider returned no segments.

### The 25 MB ceiling is the provider's

OpenAI refuses an audio file over 25 MB. The extraction runs at **64 kbps**, which
keeps roughly fifty minutes inside the limit; speech is downmixed to 16 kHz mono
by the provider anyway, so a higher bitrate buys nothing and costs headroom. The
size is still checked before the upload, because a lossless copy of a high-bitrate
MP3 can exceed it — and a caller deserves the limit rather than a finished
transfer followed by a rejection.

### A stored subtitle needs a type the registry will believe

Measured, not assumed — `finfo` inside the app container:

| file | libmagic says | in `FileStore::TYPES` before | stored as |
|---|---|---|---|
| `.srt` | `application/x-subrip` | no | refused |
| `.vtt` | `text/vtt` | no | refused |
| `.ass` | `text/plain` | yes | **`.txt`** |

So SRT and WebVTT were unstorable, and ASS was storable only under an extension
no player treats as a subtitle. `application/x-subrip` → `srt` and `text/vtt` →
`vtt` were added to the map (both `document`, so the 25 MiB cap applies). ASS is
**not** added: an ASS file is byte-indistinguishable from plain text, and the
registry types a file from its bytes rather than trusting a caller's extension —
teaching it otherwise to serve one format would break the rule that makes the
rest of it safe. `generate_video_captions` therefore returns ASS in `captions`
and reports `media_skipped` with that reason.

### One multipart builder, not two

`OpenAiTranscription` is an adapter, so it uses the framework client (it needs a
per-call timeout the `HttpClientInterface` contract does not carry — the same
precedent `MediaWorkerClient` set; `oos.transcription_timeout`, 300 s). The
core-side tool `transcribe_openai_audio` still has to build multipart by hand,
and it had that logic inline. It now calls `MultipartBody`, which also closes a
real hole: the file *name* and *filename* are header-interpolated, so a `"` or a
CRLF in either could inject a header into a request signed with a provider key.
Field *values* are sent verbatim — escaping them would corrupt the transcript.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/AudioTranscriptionInterface.php` | new contract: `isAvailable()`, `transcribe()` returning text plus timed segments |
| `lib/core/src/Application/Media/CaptionFormatter.php` | new; pure SRT / WebVTT / ASS writer |
| `lib/core/src/Infrastructure/Http/MultipartBody.php` | new; one multipart builder, with header sanitisation |
| `lib/core/src/Tool/TranscribeVideoTool.php` | new tool; batch contract, per-item failures, `dry_run` preview |
| `lib/core/src/Tool/GenerateVideoCaptionsTool.php` | new tool; provider or proportional cues, optional storage |
| `lib/core/src/Tool/TranscribeOpenAIAudioTool.php` | inline multipart replaced by `MultipartBody` |
| `lib/core/src/Tool/AbstractVideoTool.php` | `srt`, `vtt`, `ass` in the container → MIME map |
| `lib/laravel-adapter/src/Adapter/OpenAiTranscription.php` | new; `verbose_json`, model and size refusals |
| `lib/laravel-adapter/src/Adapter/FileStore.php` | the two sniffable subtitle types |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | binds `AudioTranscriptionInterface` |
| `lib/core/resources/tool-readiness.php` | rows 285 and 286 |

### Measurement

`bin/audit-tool-readiness.php`: **286 rows / 267 servable / 0 violations** (was
284 / 265). `bin/toolkit-parity.php --check`: **64 of 854**, `video-production`
**15 of 21** — both moved, and the baseline diff is the two slugs and nothing else.

`lib/core` **2,500 / 17,658** (was 2,470 / 17,595); adapter **359 / 1,929** (was
359 / 1,926); the new `VideoTranscriptionTest` **17 / 85**; worker pure tests
**43** (unchanged — the route did not change).

### What is left in this toolkit

`video-production` is **done as far as this runtime can take it**. The six
remaining slugs need things that do not exist here, and inventing them would be
parity theatre:

| slug | why not |
|---|---|
| `create_remotion_video` | a React/Remotion renderer, not an ffmpeg call |
| `get_queued_videos` | a WordPress attachment queue |
| `get_videos_without_thumbnails` | attachment queries over a media library this host does not index that way |
| `get_videos_without_transcripts` | the same, plus the transcription queue above |
| `upload_video_batch` | WordPress attachment creation at batch scale |
| `extract_video_metadata` | a deliberate duplicate of `get_video_metadata`, which is already `ready` |

## 164.28 — `attachment_id` never resolved, and the audio the store could not type (2026-11-25)

§164.27 left one defect named and unfixed: `transcribe_openai_audio`
advertised `attachment_id` and never resolved it. The argument was read only to
decide whether *something* had been supplied, so this path

```php
execute( array( 'attachment_id' => 7 ) )
```

built a multipart body with **no `file` part at all** and the provider answered
400. Every call that passed an id without a URL failed, and nothing in the tests
covered it: the one test for the tool used `audio_url`.

A grep for the whole class of bug found exactly one instance — `attachment_id`
is a schema key in one tool, and the other two files that mention it do so in
prose — so this is a single fix rather than a sweep.

### It now resolves, and it resolves the way images do

`AudioInput` (Proposal 162's registry, 164's audio) reads the file and returns
bytes, type and name, or an error value. The rules are the ones the vision tools
already enforce, because they are the same question:

- **Ownership.** `userCanAccess()` is the registry's own rule, not a second copy.
  A caller who cannot download a file must not be able to send it to a provider.
  A refused file answers **not-found**, exactly like a missing one, so the response
  cannot be used to learn whether a row exists.
- **Type.** The registry sniffed it; a non-audio file is refused, and a *video*
  is refused with a pointer to `transcribe_video`, which extracts the track.
- **Size.** The endpoint's 25 MB ceiling is checked before the bytes are read.
- **Readability.** A row whose bytes have gone is `file_unreadable`, not an empty
  upload.

`file_id` joins `attachment_id` in the schema: they are the same id, and the
WordPress name is kept because it is published. Supplying two *different* values
is refused rather than resolved by a hidden precedence, the same way
`ImageInput` refuses `file_id` together with `image_data`.

### The part had to start describing the bytes

The filename and type were the literals `audio.mp3` and `audio/mpeg` on every
call. A `.wav` URL was therefore posted as an mp3, and a stored `.m4a` had no
correct answer available at all. Both now come from the source: the registry's
sniffed type for a stored file, the URL's own extension for a fetched one — with
the old `audio.mp3` default kept only for a signed link that carries no extension.

### The store could not type two of `extract_audio`'s three formats

§164.26 shipped `extract_audio` with `mp3`, `m4a` and `aac`. The registry's type
map knew `audio/mpeg`, `audio/wav`, `audio/x-wav` and `audio/ogg` — so a result
in **m4a or aac could not be stored at all**, and the tool reported `media_failed`
for two of its own documented options. Measured with ffmpeg 8.1.2 output rather
than assumed:

| file | libmagic | in the map before | after |
|---|---|---|---|
| `.m4a` | `audio/x-m4a` | no — refused | `m4a` |
| `.aac` (ADTS) | `audio/x-hx-aac-adts` | no — refused | `aac` |
| `.flac` | `audio/flac` | no — refused | `flac` |
| `.webm` (audio only) | `video/webm` | yes | `webm` — already correct |

Both new sniff results and `audio/flac` were added; `audio/mp4` and `audio/aac`
went in beside them because other libmagic builds answer those for the same
bytes. The `audio` group already had a 100 MiB ceiling, so no cap changed.

The fixture in `FileStoreVisibilityTest` is a handful of hex bytes — the `ftyp`
box for an m4a, an ADTS frame header, a FLAC magic block — because libmagic keys
on exactly those signatures. A real recording would prove nothing further.

### `isAudio()` joined `isImage()` and `isPdf()`

Three tools ask the same question and two of them use the answer to decide
whether a file needs an extraction step. `StoredFile::isAudio()` replaced three
`str_starts_with( 'audio/mime' )` copies.

### Deliberately not done

- **`audio_url` is still fetched by this host**, which is an SSRF surface:
  `HttpClientInterface::send()` exposes no redirect or size controls, so a
  redirect into a private range walks past any guard. That is pre-existing
  behaviour with a published test, and removing it is a breaking change that
  belongs in its own decision. What this slice adds is the size ceiling, so an
  oversized body is refused before it is uploaded.
- **The upload allowlist still has no audio or video.** `FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES`
  is images and documents only, so a user cannot upload an mp3 or a video through
  the media library even though the registry would file and serve one. Widening
  that changes what a browser may push into the estate and deserves its own
  review; the store-side gap was the half that broke a tool, so it was the half
  fixed here.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Application/Media/AudioInput.php` | new; the 25 MB ceiling, the extension → type map, and registry resolution with ownership |
| `lib/core/src/Tool/TranscribeOpenAIAudioTool.php` | `file_id` + `attachment_id` resolution, a real filename and type on the part, a size ceiling on the fetched body |
| `lib/core/src/Tool/TranscribeOpenAIAudioValidatedTool.php` | passes the new collaborator through |
| `lib/core/src/Domain/Entity/StoredFile.php` | `isAudio()` |
| `lib/core/src/Tool/TranscribeVideoTool.php`, `GenerateVideoCaptionsTool.php` | use `isAudio()` |
| `lib/laravel-adapter/src/Adapter/OpenAiTranscription.php` | shares `AudioInput::MAX_BYTES` and `mimeFor()` instead of a second copy |
| `lib/laravel-adapter/src/Adapter/FileStore.php` | the measured audio types |
| `lib/core/resources/tool-readiness.php` | the row's `reason` now says what it does |

### Measurement

`bin/audit-tool-readiness.php`: **286 rows / 267 servable / 0 violations** —
unchanged, which is the point of a correctness fix.
`bin/toolkit-parity.php --check`: **64 of 854**, `video-production` **15 of 21** —
also unchanged.

`lib/core` **2,510 / 17,679** (was 2,500 / 17,658); adapter **359 / 1,929**
(unchanged); `VideoAndAudioToolsTest` **+10 tests**; one new test in
`FileStoreVisibilityTest`.

## 164.29 — The library could not accept the media the tools produce (2026-11-25)

§164.28 fixed the registry so an `extract_audio` result in m4a or aac could be
*stored*. It did not fix the other half: `FileUploadServiceInterface::DEFAULT_ALLOWED_TYPES`
was images and documents only, so **a user could not upload an mp3 or a video at
all** — the console's own guidance said so ("Images, PDFs, plain text, CSV, JSON
and Word documents"). Every audio file in the library could only arrive from a
tool, which made `transcribe_openai_audio`'s `file_id` path unreachable for a
human and left the media library short of the one thing WordPress does out of
the box.

### Two lists decide, and they had drifted

| list | question it answers |
|---|---|
| `DEFAULT_ALLOWED_TYPES` | what a caller may pick |
| `FileStore::TYPES` | what can actually be filed |

They drifted in *both* directions during this arc: the allowlist carried no audio
or video at all, while the registry learned `audio/x-m4a` and
`audio/x-hx-aac-adts` only when §164.28 measured them. Each asymmetry is a
different defect — validation passes and the write fails, or the library can
serve a file nobody can add — so both are now asserted rather than described:

`FileUploadAllowlistTest` checks that the allowlist is a subset of
`FileStore::acceptedTypes()`, that **every** audio and video type the registry
files is uploadable, and that every uploadable type has an extension (a host with
no registry bound would otherwise store `.bin`).

That needed two accessors that did not exist: `FileStore::acceptedTypes()` and
`FileUploadService::extensionFor()`. Both are thin, both are about a map that was
private, and both exist so the two lists cannot drift again silently.

### Thirteen types, by sniff and not by family

Ten audio and three video types were added, and the reason they are listed
individually is the measurement from §164.28: libmagic answers `audio/x-m4a` for
an m4a and `audio/x-hx-aac-adts` for an ADTS AAC — **not** `audio/mp4` or
`audio/aac`. A family wildcard in this allowlist would be a promise the sniff
cannot keep, so every type the registry files is named.

| fixture | libmagic |
|---|---|
| MP3 frame header (`fffb9064`) | `audio/mpeg` |
| RIFF/WAVE header | `audio/x-wav` |
| ISO-BMFF `ftyp` `M4A ` | `audio/x-m4a` |
| ADTS frame header | `audio/x-hx-aac-adts` |
| ISO-BMFF `ftyp` `isom` | `video/mp4` |
| ISO-BMFF `ftyp` `qt  ` | `video/quicktime` |

An audio-only WebM still sniffs as `video/webm`, which is in the list and stores
as `.webm` — the right extension either way.

`Phase3dTest` now uploads an mp3 and an mp4 through `/api/v1/media` and asserts
the stored type and extension, that `media_type=audio` filters to it, and that a
video reports `width: 0` rather than being handed to `getimagesize()`.

### The console had to stop lying too

The page delegates validation to the service (§162.7 — one writer, no weaker
second gate), so no gate changed in the panel. What changed is that its guidance
now names audio and video, and its filter gained `All video` and `All audio`:
the filter is a `mime_type LIKE 'audio%'` prefix, so a user who uploads an mp3
can now find it.

### The size ceiling did not move, deliberately

`DEFAULT_MAX_FILE_SIZE` (10 MiB) is a **request-size** limit applied before the
type is known, not a per-type cap, and this slice leaves it alone. The
consequence is stated rather than hidden: an audio upload is generous at that
size (about eleven minutes at 128 kbps), and a **video** upload is not — raising
`oos.upload_max_bytes` is the operator's decision, and the registry's group caps
(audio 100 MiB, video 512 MiB) and PHP's `upload_max_filesize` / `post_max_size`
are the hard ceilings above it. The contract docblock now says all of that, so
the next person to hit the limit finds the lever instead of guessing.

### One policy, two adapters

The constant is shared, so the WordPress adapter inherits the wider list. That is
consistent rather than risky: WordPress's own media library accepts audio and
video natively, and that adapter validates against the **declared** type
(`$file['type']`) with `wp_handle_upload` as the real gate — widening a list it
never sniffed against adds no new weakness. Its constructor docblock was updated
to describe the list it now gets.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/FileUploadServiceInterface.php` | ten audio and three video types; the two invariants documented on the constant |
| `lib/laravel-adapter/src/Adapter/FileUploadService.php` | the same types in the no-registry extension map; `extensionFor()` |
| `lib/laravel-adapter/src/Adapter/FileStore.php` | `acceptedTypes()` |
| `docker/laravel/app-skeleton/app/Filament/Admin/Pages/MediaLibraryPage.php` | the video and audio filters |
| `docker/laravel/app-skeleton/resources/views/filament/admin/media-library.blade.php` | the guidance sentence |
| `lib/wordpress-adapter/src/Adapter/FileUploadService.php` | docblock only |

### Measurement

`lib/core` **2,510 / 17,679** (unchanged — the contract change is data, and its
parse is covered by the adapter test); adapter **363 / 1,957** (was 359 / 1,929 —
the four allowlist assertions); `Phase3dTest` **+2 tests**. Audit and parity are
untouched: **286 / 267 / 0** and **64 of 854** — the allowlist is not a tool.

## 164.30 — Resumable uploads, and a limit the console could not keep (2026-11-25)

§164.29 widened the allowlist and left the size cap alone, saying the operator
could raise `oos.upload_max_bytes`. Measuring the shipped image made that advice
incomplete, and the console dishonest:

| directive | value |
|---|---|
| `upload_max_filesize` | **2 MB** |
| `post_max_size` | **8 MB** |

A single-request upload is therefore refused by PHP at **2 MB**, long before the
application's own 10 MiB. The page printed the configured number regardless — a
promise the interpreter would not keep — and a 3 MB mp3 could not be added at all.

### Raising the limits is the wrong lever

Setting `upload_max_filesize=512M` would let one enormous body through per
request: a memory and bandwidth spike, no resumability, and a dropped connection
costs the whole file. So the *request* stays small and the *file* gets its own
ceiling. Two numbers instead of one, which is the shape every serious upload
system has (tus `Upload-Length` vs chunk size; S3 part size vs object size).

### tus 1.0.0 core, not a dialect

The protocol is the specification: `POST` with `Upload-Length` to create, `HEAD`
for the offset, `PATCH` with `application/offset+octet-stream` to append,
`DELETE` to discard, `Tus-Resumable: 1.0.0` on every response, and `Tus-Max-Size`
on `OPTIONS`. A client written against tus — Uppy, `tus-js-client`, `tusd` — works
unmodified, and so does `curl`.

The rule that earns the protocol its keep is the offset: a `PATCH` whose
`Upload-Offset` is not the one the server holds is **409**, never a best-effort
append, and the 409 carries the current offset so a client resumes without another
round trip. Two clients resuming one id, and one client retrying a chunk whose
response was lost, are indistinguishable from the server's side; appending at a
guessed offset would corrupt a file that otherwise looked fine.

Two additive extensions, both documented on the controller: the completing
`PATCH` answers `X-Nvoos-Attachment-Id` and `X-Nvoos-Url`, and `OPTIONS`
advertises the per-file ceiling.

### Completion reuses the rules instead of restating them

When the last chunk lands the assembled file is sniffed with
`FileUploadService::detectMimeType()` (the *same* sniff the multipart path uses,
now public), checked with `isMimeTypeAllowed()` (the *same* allowlist), and
written through `FileVisibilityStoreInterface` (the *same* registry, which sniffs
again and applies the per-group cap).

What it deliberately does **not** call is `upload()`, because that enforces the
per-**request** cap — the very number chunking exists to escape. The per-**file**
ceiling is the session's `max_bytes` (default 512 MiB, the registry's video group
cap), so neither path can be used to smuggle a file past the other's policy.

A refusal at completion leaves the chunks in place and the session at
`received === total`, so the client can retry (an empty `PATCH`) or abort instead
of re-sending a file the network already carried.

### State, chunks and expiry

A row in `nvoos_upload_sessions` is a claim about bytes that are owed; the chunks
are scratch on the **private** disk under `chunked-uploads/{id}/{offset}`. A
session belongs to one caller *and* one estate — unknown and not-yours answer
`404`, so ids cannot be probed. Assembly streams chunk to chunk (`readStream` →
`stream_copy_to_stream`), because `file_get_contents` on a 500 MiB video would
need more memory than the image has.

Abandoned sessions expire after 24 hours, and the sweep runs on `begin()`: the
one moment a client has already paid for a write, and the one place no scheduled
job has to be deployed for it to happen.

### The console now tells the truth about its own limit

`MediaLibraryPage::maxBytes()` returns `min(config, upload_max_filesize,
post_max_size)` — an unlimited directive (`0`, `-1`) reads as `PHP_INT_MAX` so the
next number decides. The page says "the smaller of the app's allowance and PHP's
own limits", and a second input sends chunks with vanilla `fetch` under the CSP
nonce the panel already uses, resuming from whatever offset the server reports.

### Deliberately not implemented

The tus **concatenation**, **creation-defer-length** and parallel-chunk
extensions. Each has its own consistency rules, and a resumable upload that can
reorder bytes silently is worse than one that refuses to. Also absent: a scheduled
pruner (opportunistic is enough) and any signed-URL handoff to object storage
(there is one disk mode here).

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/ChunkedUploadInterface.php` | new; the state machine, no HTTP |
| `lib/core/src/Domain/Error/ConflictException.php` | new; 409 for an offset that is not the current one |
| `lib/laravel-adapter/src/Adapter/ChunkedUploadService.php` | new; sessions, chunks, streaming assembly, expiry |
| `lib/laravel-adapter/src/Adapter/FileUploadService.php` | `detectMimeType()` made public so both paths sniff alike |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | binding and config |
| `docker/laravel/app-skeleton/app/Http/Controllers/MediaUploadController.php` | new; tus core over HTTP |
| `docker/laravel/app-skeleton/routes/api.php` | five routes under `can:upload_files` |
| `docker/laravel/app-skeleton/database/migrations/…upload_sessions` | the session table (app and adapter) |
| `docker/laravel/app-skeleton/app/Filament/Admin/Pages/MediaLibraryPage.php` | `min()` ceiling, chunk helpers, `iniBytes()` |
| `docker/laravel/app-skeleton/resources/views/filament/admin/media-library.blade.php` | the second input and its script |

### Measurement

`lib/core` **2,510 / 17,679** (unchanged — a contract and an exception are not
tools); adapter **363 / 1,958** (was 363 / 1,957 — the binding resolves); the new
`MediaChunkedUploadTest` **13 / 89**. Audit and parity untouched: **286 / 267 / 0**
and **64 of 854**.

A 12-byte mp3 in three 4-byte chunks becomes a media row with `audio/mpeg`, and
the chunk directory is gone afterwards; a wrong offset answers 409 with the
offset the server holds and writes nothing; an SVG is refused at completion with
its chunks retained.

## 164.31 — The document cluster, and a tool that could not have worked (2026-11-25)

§164.30 gave the runtime somewhere trustworthy to put bytes. The next largest
block of unported tools is the one that *makes* them: `document-generation`
declares **38** tools and serves **9**, so 29 are open — more than any other
toolkit, and the only cluster whose work was blocked on the file layer rather
than on an endpoint choice.

Reading them before porting any turned up something that changes the order.

### `generate_pdf` could never have worked

The WordPress tool is four lines of delegation:

```php
$pro_arguments = array(
    'operation' => 'generate',
    'content'   => $arguments['content'] ?? '',
    'title'     => $arguments['title'] ?? 'Document',
);
return $this->pro_pdf_tool->execute( $pro_arguments, $context );
```

But `pro_pdf_document`'s `generate` operation opens with:

```php
if ( empty( $arguments['description'] ) ) {
    return new WP_Error( 'wp_mcp_ai_missing_description', … );
}
```

It requires `description`. It is sent `content`. So every call to `generate_pdf`
returns `wp_mcp_ai_missing_description` — the slug whose whole purpose is
"content in, PDF out" has never produced a PDF. `format` has the same shape but
accepts *either* field, which is why the error went unnoticed: the sibling
operation works when you call it directly.

The port does not reproduce that. `pro_pdf_document` accepts `content` for
`generate`, because content is what the operation actually renders; and a call
that supplies only `description` is refused with a named error rather than
silently doing nothing.

### The renderer is deterministic, so no model is claimed

`pro_pdf_document` declares `consumes-tokens` and `requires-model` in WordPress
because `generate` and `format` can hand a one-line description to a model and
ask it to write the document body.

This runtime will not do that, for the reason already recorded on
`GenerateWordTool`: the bound engine is the media worker's `POST
/api/pdf/generate` — puppeteer rendering HTML — which is a **deterministic
formatter**. Declaring a model dependency would route every call through a cost
gate for a cost it never incurs, and a `description`-only call cannot be honoured
without inventing copy.

So the port refuses `description`-only input with `no_content_generator`, naming
the fix (send `content`, or bind a model writer). The `model` parameter is
accepted and ignored rather than silently dropped, and the tool declares
`requires-capability`/`write`/`state-changing` — not `requires-model`.

That divergence is recorded here because it is the third time the WordPress
flags have over-declared a dependency (§164.4.8, and `generate_word` /
`generate_excel` before it). The pattern is: **port the behaviour that renders,
not the flag that describes the intent.**

### The composition is pure, so it goes in core

`convert_to_html()` in the WordPress tool does no I/O: it takes `sections` or
`content`, hands them to `WP_MCP_AI_Html_Formatter`, and wraps the result in a
complete document with base CSS, meta tags, a page-width table and print
rules. That formatter (543 lines) is pure except for three WordPress helpers —
`wp_parse_args`, `esc_attr`, `absint` — all of which have trivial equivalents.

So `HtmlFormatter` is ported to `Nvoos\Core\Domain\Service\Document` and both
tools share it. A renderer receives a self-contained HTML document and knows
nothing about sections, headings or page widths.

The writer contract is not new: `HtmlToPdfInterface` already exists and
`MediaWorkerHtmlToPdf` already implements it over the worker route the
WordPress `pro_pdf` tool has used since before this port. Page-size names are
resolved against `getSupportedPageFormats()` so an unsupported size is a
validation error rather than a silently wrong page — the rule `HtmlToPdfTool`
already follows.

Persistence reuses `GeneratedMediaPersister::attachFile()`, which takes the
renderer's **path** (not a base64 payload) and hands it to the registry, so a
generated PDF gets an id, an owner and a visibility exactly like an uploaded one.

### The remaining 29, sequenced

A cluster this size is not one port. The slices are ordered so each one stands
alone and none depends on a later decision:

| # | slice | tools | why in this position |
|---|---|---|---|
| A | PDF write path | `pro_pdf_document`, `generate_pdf` | The linchpin: `generate_pdf` delegates to the former, and both are served by a route that already exists. |
| B | Tabular export | `excel_data_export` | Pairs with the already-ported `excel_data_import`; rows in, workbook out, no new engine. |
| C | Archive | `archive_documents` | Needs a zip writer over registry files; the only new low-level capability in the cluster. |
| D | Docs Hub | `docs_hub_*` (5) | A content store with read/write/list/delete/rebuild — a subsystem, not a formatter. |
| E | QMS control | `qms_*` (10) | A document lifecycle (draft → review → approve → release → supersede) with signatures and an audit trail. The largest single slice and the only one that needs a state machine. |
| F | Office/OCR variants | `pro_word_document`, `pro_excel_document`, `pro_pdf_document`… (6) | Thin variants of B and A; several are the same call with different defaults, so they are cheap *after* A and B and expensive before. |
| G | Data-dependent | `get_expired_documents`, `get_uninvoiced_orders`, `generate_invoice_pdf`, `generate_invoice_batch`, `docgen_capture_style_memory` | Each needs a data source this host may not have (orders, expiry policy, style memory). Same class as `run_openai_external_action` in §164.27: a decision about a source, not a port. |

Slice **A** ships here. The rest are recorded so the next session does not have
to re-derive the order.

### Where the work lives (slice A)

| file | change |
|---|---|
| `lib/core/src/Domain/Service/Document/HtmlFormatter.php` | new; the ported pure formatter |
| `lib/core/src/Application/Document/PdfDocumentComposer.php` | new; compose → render → persist, shared by both tools |
| `lib/core/src/Tool/ProPdfDocumentTool.php` | new; `generate`, `structure`, `format` |
| `lib/core/src/Tool/GeneratePdfTool.php` | new; the two-parameter wrapper, working this time |
| `lib/core/resources/tool-readiness.php` | two rows |
| `lib/core/tests/Unit/Domain/Service/Document/HtmlFormatterTest.php` | new; the formatter, including the stylesheet refusals |
| `lib/core/tests/Unit/Tool/GeneratePdfToolTest.php` | new; the tool that could not have worked |
| `lib/core/tests/Unit/Tool/ProPdfDocumentToolTest.php` | new; three operations, page geometry, formatting |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

No new contract, no new adapter, no new worker route: the engine and the store
both already exist, which is the point of doing this slice after §164.29/§164.30
rather than before them.

### Measurement (slice A)

| suite | before | after |
|---|---|---|
| `lib/core` | 2,510 / 17,679 | **2,574 / 17,863** |
| adapter | 363 / 1,958 | **363 / 1,959** |
| audit | 286 / 267 / 0 | **288 / 288, servable 269** |
| toolkit parity | 64 of 854 | **66 of 854** |
| `document-generation` | 9 of 38 | **11 of 38** |

The adapter gain is a single assertion, not a test: the two manifest rows resolve
from the container, which is what `ToolReadinessRegistrationTest` proves. Sixty of
the sixty-four new core tests are the formatter, the composer and the two tools;
the remaining four are the schema and flag assertions.

`generate_pdf` renders text into a stored PDF and reports
`attachment_id`; `pro_pdf_document` renders sections, applies colour and line
height, and refuses a `description`-only call with `no_content_generator`. A
`font_family` of `Arial} body{display:none` falls back to the default instead of
closing the rule.

Not attempted here, and deliberately: slices B–G in the table above. Slice D
(Docs Hub) and slice E (QMS) are each larger than this one and are subsystems
rather than formatters — a content store and a signed document lifecycle
respectively — so neither belongs in a PDF PR.

## 164.32 — The write half of the spreadsheet pair (2026-11-25)

§164.31 slice B. `excel_data_import` has shipped since the tabular reader landed;
`excel_data_export` is the half that writes, so a caller can now round-trip a
table without leaving the runtime. The engine is the same office writer
`generate_excel` already uses — no new contract, no new route.

Three decisions are worth recording, because each is a way a workbook can be
wrong while still opening cleanly.

### Rows are bound by column key, not by position

The worker accepts positional rows and simply calls `addRow(row)`. That is fine
when every row is the same width and a trap when one is not: a short row shifts
every cell after the gap one column left, and the file opens, displays, and is
quietly incorrect. Each cell is addressed by column key here, so a ragged row
leaves `null` where it stopped instead of moving its neighbours.

### A header row appears only when one was asked for

ExcelJS writes a header row for every column that declares a `header`. The
labels are generated (`Column 1`, `Column 2`) when the caller supplied fewer
than the data needs, so a header-less export must therefore declare **no**
`header` at all — otherwise an invented row displaces the caller's first data
row by one. The payload reports `has_header` so a caller can see which shape it
got.

### No silent downgrade to CSV

The WordPress tool tries PhpSpreadsheet and falls back to CSV, returning
whatever it managed. That changes the format of a file whose name still says
`.xlsx` — the kind of surprise a caller only discovers downstream. This refuses
with `no_document_engine` and names the fix, in line with §164.4.8.

### Sheet names are sanitised on this side

Excel rejects a worksheet name longer than 31 characters, forbids
`[ ] : * ? / \`, and will not accept a leading or trailing apostrophe. The worker
hands the name straight to `addWorksheet()` with no check, so an unsanitised
title fails at the far end of the request, after the rows have been serialised.
Titles are reduced here: illegal characters become spaces, whitespace runs
collapse (so `Q1/Q2: report` does not become `Q1 Q2  report`), the result is
truncated to 31 and falls back to `Export` when nothing survives.

### Non-scalar cells are refused, not coerced

A cell must be a string, number, boolean or null — the four types the schema
already declares. A nested array reaches the writer as `"[object Object]"` and
produces a file that looks like a success. The refusal names the row and the
column so the caller can find it.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Tool/ExcelDataExportTool.php` | new; `excel_data_export` |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/core/tests/Unit/Tool/ExcelDataExportToolTest.php` | new; 23 tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,599 / 17,943** (was 2,574 / 17,863); adapter **363 / 1,961**
(was 363 / 1,960 — the manifest row resolves from the container); audit
**289 / 289, servable 270**; toolkit parity **67 of 854**;
`document-generation` **12 of 38**.

A three-cell row followed by a one-cell row keeps its values in the columns it
named, an export without `headers` declares no header, and `Q1/Q2: report*`
becomes the sheet name `Q1 Q2 report`.

## 164.33 — The Pro Word tool, and a correction to the remaining slices (2026-11-25)

§164.31 slice F. `pro_word_document` is the first tool in this cluster that adds
structure `generate_word` cannot express, so it earns its slot; the same cannot be
said for most of what is left, and reading them properly changed the plan.

### What it adds, and why that is enough

`generate_word` takes a fixed shape — title, paragraphs, one table, header,
footer. This adds an ordered section list with heading **levels**, a body of
styled **runs** (bold, italic, colour, size, font), and five template scaffolds.
All three are pure structure; none needs a model.

`format` maps the WordPress tool's five scalars onto a single run, which is what
that schema describes. `formatting.color` is validated as a six-digit hex before
it reaches the writer, because a value like `red; background: url(x)` would
otherwise be interpolated into a document property — the same class of hole
§164.31 closed in the PDF stylesheet.

The template operation is a scaffold, not prose: it names the headings a document
of that kind carries and leaves the caller to fill them. A flat `content` lands
under the scaffold's own `Body` heading when it has one (`business_letter`,
`memo`) and is appended after the scaffold otherwise, rather than being dropped
into a heading chosen on the caller's behalf. Sections are matched to headings by
name and anything unmatched is appended, so no content is lost.

### What it refuses to declare

The WordPress schema offers `orientation`, `page_margins` and `model`. The bound
writer emits one default-geometry section and calls no model. Declaring either
geometry field would repeat the exact defect this cluster keeps finding — a field
that is accepted and then ignored — so both are **absent** from the schema, and
`additionalProperties => false` turns a ported request that sends them into a
loud validation error instead of a silent no-op. `model` is accepted and ignored,
so a verbatim request is not rejected on a field this runtime cannot use.

A `description`-only call is refused with `no_content_generator`, as in §164.31.

### The correction: most of the remaining slices are not ports

Reading the remaining 25 tools instead of trusting the labels changed the plan.

| tool(s) | what it actually needs | verdict |
|---|---|---|
| `archive_documents` | moves `mcp_ai_doc_tpl` posts to an archive status and writes `_archived_*` meta | **not a zip tool** — needs a document-template store |
| `docs_hub_*` (5) | the standalone docs-hub plugin's filesystem corpus, a 2 MB per-file cap and `NV_oOS_Docs_Hub_Rebuild_Job` | needs a docs-hub corpus contract **and** a format decision |
| `qms_*` (10) | a controlled-document lifecycle: draft → review → approve → release → supersede, with signatures and an audit trail | needs a data model (migrations) and signature semantics |
| `pro_excel_document` | `auto_filter`, `freeze_header`, `formulas`, `chart` | the worker writes values and columns only — **worker first**, or it duplicates `excel_data_export` |
| `pro_document_ocr` | tesseract via the worker is available; `unlimited_ocr` / `deepseek_ocr` are services | partial port at best |
| `pro_batch_ocr`, `pro_unlimited_ocr` | the Unlimited OCR / DeepSeek-OCR services | **service dependency** |
| `generate_invoice_pdf`, `generate_invoice_batch`, `get_uninvoiced_orders` | order data | **no orders exist on this runtime** |
| `get_expired_documents` | document data plus an expiry policy | needs a store and a policy |
| `docgen_capture_style_memory` | a style-memory store | needs a store |

§164.31 recorded `archive_documents` as "needs a zip writer over registry files".
That was wrong: it never touches bytes. It is a state transition on a post type
this runtime does not have, which puts it with `docs_hub_*` rather than with the
formatters. Recording the mistake here because the corrected grouping — **one
document store serving C and D**, then **one lifecycle serving E** — is the plan
the next session should follow, and it is only visible after reading the code.

`pro_excel_document` is the one remaining formatter worth doing, and it is worth
doing *after* the worker gains filters, freeze panes, formulas and charts. Ported
today it would be `excel_data_export` with a second name and four parameters that
do nothing — the shape §164.4.8 forbids.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Tool/ProWordDocumentTool.php` | new; `pro_word_document` |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/core/tests/Unit/Tool/ProWordDocumentToolTest.php` | new; 26 tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,627 / 18,032** (was 2,599 / 17,943); adapter **363 / 1,962**
(was 363 / 1,961 — the manifest row resolves from the container); audit
**290 / 290, servable 271**; toolkit parity **68 of 854**;
`document-generation` **13 of 38**.

A `memo` template with a flat body puts that body directly under its `Body`
heading, a `resume` appends it after `Skills`, a section named `skills` fills the
`Skills` heading instead of repeating at the end, and
`color: 'red; background: url(x)'` is dropped rather than written into the
document.

## 164.34 — One document store, five tools, and the guard that matters (2026-11-25)

§164.31 slices C and D, done together because §164.33's correction showed they
were one subsystem. The five `docs_hub_*` tools are readers and writers of a flat
corpus of Markdown and plain-text files with YAML frontmatter — a docs site's
source, not a database.

### Why one contract instead of five path checks

Every one of the five tools takes a caller-supplied relative path. Five copies of
a traversal check is five chances to drop a layer, so the corpus owns the rules
and the tools never learn where it lives.

`DocsCorpusInterface` takes a corpus-relative path and is responsible for
refusing anything that is not one. The tools ask, and translate the answer.

### The guard is ported, not re-derived

`FilesystemDocsCorpus` transcribes the two checks from
`WP_MCP_AI_Docs_Hub_Helper`, because they were written against real attacks:

- **Segment validation.** Separators are normalised to `/` *before* validating, so
  `..\secret.md` is judged by the same rule as `../secret.md`. A drive letter and
  any absolute or self-referencing prefix are refused, then every segment must
  match `^[A-Za-z0-9][A-Za-z0-9 _.-]*$` — which rejects `..` and `.` without a
  special case for either.
- **Realpath containment.** `realpath()` of the target must stay inside
  `realpath()` of the root, so a symlinked file cannot be read through. An
  unwritten path walks up to its deepest existing ancestor and checks *that*, so a
  write cannot create its way out either.

The 40-test adapter suite exercises thirteen escaping shapes — parent segments,
nested parents, Windows separators, absolute Unix and Windows paths, dot segments,
dotfiles, empty segments, unserved extensions, an over-long path — plus both
symlink cases: a link that points out of the root, and a symlinked directory the
scan must not descend.

### The two layers fail differently, on purpose

A path that breaks a *segment* rule is refused: the caller can see the rule, and
`validation_failed` tells it what to fix.

A path that passes validation but *resolves* outside the root is reported as
**not found**. That distinction is deliberate: a corpus that answered "refused"
for `escape.md` and "not found" for `absent.md` would be an oracle for whether an
arbitrary file exists. Writes are the exception — a write that cannot be placed
inside the root is refused, because there is nothing to disclose and the caller
needs to know why nothing was written.

### What the port drops, and why

| WordPress | here |
|---|---|
| `docs_hub_delete_doc` `rebuild` flag | absent — there is no index to rebuild |
| `docs_hub_rebuild` `mode: async\|sync` | absent — a scan is bounded and runs in the call |
| `docs_hub_rebuild` `reset` | absent — nothing to clear |
| `include_content` on list | absent — `read_doc` exists, and a listing that inlines every document is a token bomb |

Declaring any of those would be the accepted-then-ignored defect this cluster has
now found four times (§164.31 `color`/`line_height`, §164.32 `requires-model`,
§164.33 `orientation`/`page_margins`).

`write_doc` gains one thing the WordPress tool lacks: the payload reports
`created`, so an insert can be told from an overwrite. A tool that silently
replaces a document is a data-loss path.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/DocsCorpusInterface.php` | new; the corpus contract |
| `lib/laravel-adapter/src/Adapter/FilesystemDocsCorpus.php` | new; the ported path guard |
| `lib/core/src/Tool/DocsHub{List,Read,Write,Delete,Rebuild}…Tool.php` | new; five tools |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | singleton binding on `oos.docs.corpus.*` |
| `docker/laravel/app-skeleton/config/oos.php` | `docs.corpus.root`, `max_bytes`, `extensions` |
| `lib/core/resources/tool-readiness.php` | five rows |
| `lib/laravel-adapter/tests/FilesystemDocsCorpusTest.php` | new; 40 tests, the traversal suite |
| `lib/core/tests/Unit/Tool/DocsHubToolsTest.php` | new; 15 tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

An empty `docs.corpus.root` disables all five with the named `no_docs_corpus`
error rather than answering an empty listing that reads like "no documents yet".

### Measurement

`lib/core` **2,652 / 18,121** (was 2,627 / 18,032); adapter **403 / 2,025** (was
363 / 1,962 — the corpus suite and the new binding); audit **295 / 295, servable
276**; toolkit parity **73 of 854**; `document-generation` **18 of 38**.

`../secret.md`, `..\secret.md`, `C:/Windows/win.ini`, `/etc/passwd`, `.env`,
`guides//a.md` and a 250-character name are all refused before the filesystem is
touched; a symlink out of the root reads as not-found; a 128-byte body against a
64-byte cap is refused with nothing written.

## 164.35 — What the standards actually require of the remaining 20 (2026-11-25)

The last four areas are not all the same kind of work, and reading the standards
they belong to changed what each one should be.

| area | governing standard | what it requires |
|---|---|---|
| QMS (10 tools) | **21 CFR Part 11**, **ISO 9001 §7.5.2/7.5.3** | attributable signatures, an append-only timestamped audit trail, controlled lifecycle |
| `archive_documents` | **ISO 9001 §7.5.3**, ISO 15489 | obsolete information clearly marked, prevented from unintended use, retained |
| `pro_excel_document` | OOXML via ExcelJS | freeze panes, auto-filter and formulas are all supported — the blocker was never the engine |
| service-dependent (6) | — | a service to stand up, not code |

### 21 CFR Part 11: three things, and the one this runtime cannot claim

The regulation's substantive requirements are narrow and specific:

- **§11.10(e)** — "use of secure, computer-generated, time-stamped audit trails to
  independently record the date and time of operator entries and actions that
  create, modify, or delete electronic records. Record changes shall not obscure
  previously recorded information."
- **§11.50** — a signature must carry the signer's name, the date and time, and
  **the meaning** of the signature (review, approval, responsibility, authorship).
- **§11.100 / §11.200** — signatures must be unique to one individual and **not
  transferable**, and where a signature is required it must use **two distinct
  components** — identification plus a re-entered credential.

The first two are implementable here. The third is not, and the difference
matters: this runtime has a user id in the execution context and **no
re-authentication ceremony**, so a record it writes can be *attributed* but not
*verified*. Calling that a Part 11 e-signature would be false.

So the port records an **attributed approval** — signer id, timestamp, meaning,
and the document revision it applies to — and says in the schema and the payload
that it is not a Part 11 e-signature unless a `SignatureVerifierInterface` is
bound. A caller in a regulated environment then knows exactly what it has.

ALCOA+ is the practical test inspectors apply to those records, and it is a good
checklist for the design: **A**ttributable, **L**egible, **C**ontemporaneous,
**O**riginal, **A**ccurate, plus **C**omplete, **C**onsistent, **E**nduring,
**A**vailable. An append-only log with timestamps and no in-place edits satisfies
all nine; a status field that is overwritten in place satisfies none of the last
four.

### ISO 9001 §7.5.3: archive is a state, not a deletion

Clause 7.5.3 requires documented information to be controlled for availability,
access, **version**, storage, **retention** and **disposition**, and requires that
**obsolete information be prevented from unintended use**. Practice that follows
from it: a superseded document is moved to an archived/obsolete state, **clearly
marked as no longer current**, and **retained** for a defined period (commonly
3–7 years) so it can still answer an audit or a dispute.

That is the opposite of a delete, and it is why `archive_documents` was never a
zip tool. Over the corpus from §164.34 the honest implementation is a **frontmatter
lifecycle**: the file stays where it is, and its frontmatter gains `status:
archived`, `archived_at`, `archived_by` and `archive_reason`. The body is
untouched, so a superseded revision remains readable — which is the whole point of
retention.

### ExcelJS: the `pro_excel_document` blocker was never real

The worker's `excel` route writes values and columns only, which is why §164.33
deferred the tool. Reading the library's surface shows all four missing features
are native to it:

| feature | ExcelJS |
|---|---|
| freeze panes | `worksheet.views = [{ state: 'frozen', ySplit: 1 }]` |
| auto-filter | `worksheet.autoFilter = 'A1:D1'` (range string, or `{from, to}`) |
| formulas | a cell value of `{ formula: 'SUM(B2:C2)' }` |
| charts | supported, but not needed for parity here |

So `pro_excel_document` is a **worker change plus a port**, not a blocked port.
It is the one remaining formatter and belongs after the worker gains the first
three.

### The service-dependent six

`pro_unlimited_ocr` and `pro_batch_ocr` call the Unlimited OCR / DeepSeek-OCR
services; `generate_invoice_pdf`, `generate_invoice_batch` and
`get_uninvoiced_orders` need order data this runtime does not have. Both groups
are a service to stand up, not code to port — the same verdict §164.27 reached for
`run_openai_external_action` and `vision_product_search`. They stay recorded and
unported.

### What this section changes

`archive_documents` ships here, over the corpus, as an ISO 9001 §7.5.3 archive
state with Part 11-shaped attribution. That closes the document store the corpus
opened. QMS, the Excel worker enhancement, and the six service-dependent tools
remain, in that order, each with the standard it answers to now named.

### Where the work lives (archive)

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/DocsCorpusInterface.php` | `archive()` — the lifecycle transition |
| `lib/laravel-adapter/src/Adapter/FilesystemDocsCorpus.php` | frontmatter split/merge; archive state |
| `lib/core/src/Tool/ArchiveDocumentsTool.php` | new; `archive_documents` |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/laravel-adapter/tests/FilesystemDocsCorpusTest.php` | eight archive tests |
| `lib/core/tests/Unit/Tool/ArchiveDocumentsToolTest.php` | new; ten tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,664 / 18,161** (was 2,652 / 18,121); adapter **411 / 2,050** (was
403 / 2,025); audit **296 / 296, servable 277**; toolkit parity **74 of 854**;
`document-generation` **19 of 38**.

Archiving a document prepends `status: archived`, `archived_at` (ISO 8601),
`archived_by` and `archive_reason` to its frontmatter and leaves the body
readable; archiving it again returns the original timestamp and signer rather
than re-stamping them; a document with no frontmatter gains one and keeps its
heading as its title; and `../victim.md` is refused before the filesystem is
touched.

### The remaining cluster, with its standard named

| what | status |
|---|---|
| `qms_*` (10) | **next** — needs the lifecycle data model; Part 11 attribution shape is settled above |
| `pro_excel_document` | worker first: freeze panes, `autoFilter`, formulas — all native to ExcelJS |
| six service-dependent | recorded and not portable |

## 164.36 — The Excel route grows the three things it was missing (2026-11-25)

§164.35 found that §164.33's reason for deferring `pro_excel_document` was wrong:
the worker wrote values only, but ExcelJS supports freeze panes, auto-filter and
formulas natively. So the worker changed first and the tool followed, which is the
order that keeps the tool's parameters real.

### The route now applies three things

| argument | ExcelJS |
|---|---|
| `sheetDef.views` | `worksheet.views = [{ state: 'frozen', ySplit: 1, topLeftCell: 'A2' }]` |
| `sheetDef.autoFilter` | `worksheet.autoFilter = 'A1:D1'` (or `{from, to}`) |
| `sheetDef.formulas` | `cell.value = { formula: '…' }` |

Each is optional, so a caller that sends none gets the workbook it always got.

### Formulas are a separate channel, and that is a security decision

OWASP's spreadsheet guidance puts its strongest recommendation on **typed output**:
a string cell cannot become a formula, so writing data as strings avoids the class
entirely. The other half of the guidance is that a formula must be something the
caller asked for.

Both halves are implemented. A value arrives through `rows` and ExcelJS writes it
as a string cell, so `=1+1` in a data cell is the *text* `=1+1`. A formula exists
only in `sheetDef.formulas`, addressed by cell reference, which the route
validates against `/^[A-Z]{1,3}[1-9][0-9]{0,6}$/` before use, refuses when empty or
carrying control characters, and writes with a leading `=` stripped so both
spellings work.

`document.test.js` decodes the written workbook rather than asserting on the
request, because "this cell is a string" is a property of the file. Seven tests:
a `=1+1` data value stays a string, `+SUM(A1)` and `@cmd` likewise, the explicit
channel produces a real formula, an invalid reference and a control-character
formula are both `400`, a frozen view round-trips, an auto-filter round-trips, and
a sheet with none of the new arguments still writes.

### The tool, and the one operation still absent

`pro_excel_document` offers `generate`, `table` and `multi_sheet`. `chart` is
**not** offered: the route draws none, and accepting it would be the
accepted-then-ignored defect this cluster has now corrected five times. A caller
that asks for `chart` gets a validation error that lists the three supported
operations, which is louder than a silent no-op and cheaper than a redundant
`generate_excel`.

`freeze_header`, `auto_filter`, `formatting.header_bg` and `formulas` are now
honoured. `header_bg` is validated as 6–8 hex digits before it reaches the
stylesheet, the same rule §164.31 and §164.33 applied elsewhere.

### Where the work lives

| file | change |
|---|---|
| `addons/media-worker/src/routes/document.js` | `views`, `autoFilter`, validated `formulas` |
| `addons/media-worker/src/routes/document.test.js` | new; 7 tests, workbook decoded |
| `lib/core/src/Tool/ProExcelDocumentTool.php` | new; three operations |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/core/tests/Unit/Tool/ProExcelDocumentToolTest.php` | new; 16 tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,682 / 18,222** (was 2,664 / 18,161); adapter **411 / 2,051**;
worker `document.test.js` **7 / 7**; audit **297 / 297, servable 278**; toolkit
parity **75 of 854**; `document-generation` **20 of 38**.

A data cell reading `=1+1` is written as text and carries no formula; `D2` with
`SUM(A2:B2)` in the formula map is a live formula after a round-trip; `not-a-ref`
is a `400`; and `freeze_header` with `auto_filter` over two columns produces
`views[0].state === 'frozen'` and `A1:B1`.

## 164.37 — Controlled documents: the lifecycle, and what a signature can honestly say (2026-11-25)

The last ten tools of `document-generation`, and the only slice that is a
*record* system rather than a formatter. §164.35 established what the standards
require; this is the implementation.

### The lifecycle is a closed table

ISO 9001 §7.5.2 requires review and approval "for suitability and adequacy", and
§7.5.3 requires control of version, availability and the prevention of unintended
use. Together those describe a state machine, and it lives in one place
(`DocumentLifecycle`) rather than being re-derived in ten tools:

```
draft ──▶ review ──▶ approved ──▶ released ──▶ superseded ──▶ obsolete
  ▲          │                                        │
  └──────────┘  (rejected)                            ▼
                                                    obsolete
```

Three decisions are load-bearing:

- **Approval is not release.** Only a *released* document is current. Separating
  them is what lets an organisation approve a revision now and issue it later — or
  not at all — without the register claiming it is in use.
- **Rejection is a transition, not a deletion.** `review → draft` records the
  reason, so a rejected revision has a history rather than a gap.
- **`obsolete` is terminal.** A document that must return is a new revision, not a
  reopened record. Reopening one would let a withdrawn document re-enter use, which
  is precisely what §7.5.3 forbids — so `obsolete → released` is refused by the
  table, and the test suite asserts it.

An unlisted transition is refused with the states that *would* have worked, which
is a bad request (`validation_failed`), not a system error.

### The audit trail is append-only, and the contract has no way to change that

21 CFR Part 11 §11.10(e) requires a computer-generated, time-stamped trail, and
that changes "shall not obscure previously recorded information". So the trail is
JSONL, one file per document under the audit root, appended with
`FILE_APPEND | LOCK_EX` — and `ControlledDocumentInterface` offers no update and
no delete. A trail you can edit proves nothing, so the operation does not exist
rather than being discouraged.

Every entry carries `event`, `at` (ISO 8601 UTC), `actor`, `from`, `to` and the
reason or meaning as applicable. The registration test asserts the stronger
property: after a further transition, **every earlier entry is byte-identical**.

### What a signature can honestly say

§11.50 requires a signature to carry the signer, the date and time, and **the
meaning** — so `qms_sign_document` takes a meaning from a closed list (authored,
reviewed, approved, released, witnessed, withdrawn) and a signature never changes
the lifecycle state. Evidence attached to a state, not a lever on it.

§11.100/§11.200 require two distinct components — identification plus a re-entered
credential — and this runtime has no re-authentication ceremony. So the record is
an **attributed action**, the payload says `attributed: true`, and nothing in the
schema describes it as a Part 11 e-signature. §164.35 recorded the reasoning; this
is where it is enforced.

### Periodic review is a date, not a state

§7.5.2 expects review at planned intervals. A pending review is not a lifecycle
state — a released document stays current while its review is outstanding — so
`qms_schedule_review` writes `qms_review_due` and leaves the state alone. Release
*clears* the date, because the review just happened as part of approval and a
stale date would misreport the next obligation. An unparseable date is refused:
it would make "is this overdue?" unanswerable, which is the field's only purpose.

### `qms_status`, not `status`

`archive_documents` (§164.36) already writes `status: archived`, meaning "withdrawn
from the content corpus". A controlled record's lifecycle is a different question —
an *approved* document can be archived as content and still be an approved record —
so sharing one key would let the two subsystems overwrite each other's meaning.
This one is namespaced.

### Composition, not duplication

`FilesystemControlledDocuments` takes a `DocsCorpusInterface` rather than
re-implementing read/write/paths. The corpus owns sanitisation, containment and the
frontmatter reader, so the ten tools inherit §164.34's hardened guard instead of
restating it. One primitive was added to that contract — `setFields()`, which merges
frontmatter fields and leaves the body alone — because every lifecycle write needs
it and `archive()` was already doing it privately.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Qms/DocumentLifecycle.php` | new; the closed state machine |
| `lib/core/src/Domain/Contract/ControlledDocumentInterface.php` | new; the register |
| `lib/core/src/Domain/Contract/DocsCorpusInterface.php` | `setFields()` added |
| `lib/laravel-adapter/src/Adapter/FilesystemDocsCorpus.php` | implements `setFields()` |
| `lib/laravel-adapter/src/Adapter/FilesystemControlledDocuments.php` | new; lifecycle + JSONL trail |
| `lib/core/src/Tool/AbstractQmsTransitionTool.php` | new; the five transitions share it |
| `lib/core/src/Tool/Qms*.php` (10) | new; the ten tools |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | singleton binding on `oos.qms.audit_root` |
| `docker/laravel/app-skeleton/config/oos.php` | `qms.audit_root` |
| `lib/core/resources/tool-readiness.php` | ten rows |
| `lib/laravel-adapter/tests/FilesystemControlledDocumentsTest.php` | new; 24 tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

An empty `oos.qms.audit_root` — or an empty corpus root — disables all ten with the
named `no_qms_register` error rather than reporting an empty register.

### Measurement

`lib/core` **2,702 / 18,322** (was 2,682 / 18,222); adapter **435 / 2,108** (was
411 / 2,051 — the register suite and the new binding); audit **307 / 307, servable
288**; toolkit parity **85 of 854**; `document-generation` **30 of 38**.

A draft cannot be released; `obsolete → released` is refused with the states that
would have worked; a rejected revision returns to draft carrying its reason; the
trail grows with earlier entries byte-identical; a signature records its meaning
and leaves the state alone; releasing clears a stale review date; and a corpus file
that was never created through the register is not listed as a controlled record.

### What is left

Eight `document-generation` tools, all of them service-dependent:
`pro_unlimited_ocr` and `pro_batch_ocr` need the Unlimited OCR / DeepSeek-OCR
services,`pro_document_ocr` can only partially port (tesseract is available, the
hosted providers are not), and `generate_invoice_pdf`, `generate_invoice_batch`,
`get_uninvoiced_orders`, `get_expired_documents` and `docgen_capture_style_memory`
need order or document data this runtime does not have. None of them is a
formatter; each is a service to stand up or a source to connect.

## 164.38 — Retention is a schedule, and detecting is not disposing (2026-11-25)

§164.37 called the last eight tools "service-dependent". Re-reading the media
worker's routes showed that was too pessimistic, and reading ISO 15489 changed the
design of the one that needs no service at all.

### What the worker already serves, and what it does not

The OCR ladder exists: `/api/pdf/extract` (text layer), `/api/pdf/render`
(rasterise), `/api/pdf/ocr` (per-page tesseract, `clampPageBudget`),
`/api/ocr/recognize` (single image). That is *extract → render → recognise*, which
is what a document-OCR pipeline is.

| tool | verdict |
|---|---|
| `pro_batch_ocr` | **worker-fillable** — loop the existing ladder over N sources |
| `pro_unlimited_ocr` | **worker-fillable in function** — the same ladder bounded by a page budget instead of a vendor cap |
| `pro_document_ocr` | **partial** — `tesseract` works today; `openai`/`gemini`/`anthropic`/`ollama` are model calls, which belong in the vision path, not the worker |
| `generate_invoice_pdf` / `_batch` | **half** — the worker renders the PDF; the gap is the order source. Re-scoping to accept invoice data as arguments makes it portable, and that is a product decision, not a blocker |
| `get_uninvoiced_orders` | **not fillable** — a query against order tables |
| `docgen_capture_style_memory` | not a worker problem — needs a store |

So the honest re-count is: three fillable by the worker, one partially, one
fillable with no new service, two genuinely data-blocked.

### ISO 15489 changes the expiry design

A retention schedule is not a date. The standard's model is **trigger event +
retention period → disposition**, counted from the event that stopped the record
being current. So a superseded procedure is kept for N months *from supersession*,
not from the day it was written — which is why the corpus now understands three
shapes, in order of authority:

1. `expires_at` — stated outright, nothing computed;
2. `retention_months` plus the record's own lifecycle stamp (`qms_withdrawn_at`,
   `qms_released_at`, `archived_at`) — derived, and the **latest** stamp wins
   because retention runs from the most recent event;
3. neither — excluded. A document with no rule is not "in date"; it is unmanaged,
   and those are different answers with only one defensible one.

Every row reports `source` (`stated` or `derived`), `trigger` and `period_months`,
because an auditor has to be able to reconstruct a derived date. A schedule that
names no disposition defaults to `review` — silence must not imply destruction.

### Detection is not disposal

ISO 15489 keeps **disposal approval, execution and verification** apart.
`get_expired_documents` is the first of the three and only the first: it is
read-only, it changes no frontmatter, and it deletes nothing. A report that
disposed as a side effect would collapse three reviewable steps into one nobody can
audit, and `archive_documents` (§164.36) or `qms_mark_obsolete` (§164.37) remain a
deliberate second call by a person.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/DocsCorpusInterface.php` | `expiring()` |
| `lib/laravel-adapter/src/Adapter/FilesystemDocsCorpus.php` | stated + derived expiry, latest-stamp trigger |
| `lib/core/src/Tool/GetExpiredDocumentsTool.php` | new; read-only report |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/laravel-adapter/tests/FilesystemDocsCorpusTest.php` | nine retention tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,704 / 18,332** (was 2,702 / 18,322); adapter **444 / 2,125** (was
435 / 2,108); audit **308 / 308, servable 289**; toolkit parity **86 of 854**;
`document-generation` **31 of 38**.

A document withheld on 2026-01-15 with `retention_months: 12` is reported as
`expires_at 2027-01-15, trigger withdrawn, source derived`; a released stamp
earlier than a withdrawn one does not win; `expires_at 2030-01-01` is not reported
at 2027; a document with no rule never appears; and `disposition` defaults to
`review`.

### What is left

Seven: the three OCR tools (worker first — the ladder is there, the batch loop and
the page budget are not), `docgen_capture_style_memory` (a store), the two invoice
tools (re-scope to accept invoice data), and `get_uninvoiced_orders` (order data).

## 164.39 — The batch is a loop, because the engine was already there (2026-11-25)

§164.38 closed with "the three OCR tools (worker first — the ladder is there, the
batch loop and the page budget are not)". The second half of that sentence was
wrong, and it was cheaper to read the contract than to write the route.

### The correction

`PdfOcrInterface::recognize()` already accepts the whole page-budget vocabulary:

```
@type int       $dpi        Rasterisation resolution. 300 is the working default.
@type int       $max_pages  Page budget for this call.
@type string    $language   Tesseract traineddata code, e.g. 'eng'.
@type list<int> $pages      Explicit 1-based page numbers to read.
```

and returns `total_pages` and `truncated` alongside the text. `MediaWorkerPdfOcr`
maps all four onto `POST /api/pdf/ocr`, whose `clampPageBudget()` *is* the budget.
**No worker change is needed for any OCR tool.** A batch is a loop over an engine
that already exists, so the loop belongs in the tool: a `/api/pdf/ocr/batch` route
would duplicate the ladder and give the two runtimes two places to disagree about
page budgets — the same class of drift §164.4.8 exists to prevent.

That retires the "worker first" framing for this family. What is left is tool work.

### A failing file must not cost the batch

`continue_on_error` defaults to **true**. A twenty-document batch that aborts on the
third has already paid for nineteen OCR runs and returns nothing, and OCR is the
slowest operation in the toolkit — so aborting is the most expensive way to fail.
Each result carries its own outcome:

| field | meaning |
|---|---|
| `results[].ok` | did this file OCR |
| `results[].error` | why not, including "no stored file with id N" |
| `processed` / `failed` / `requested` | the batch's own count |
| `total_pages` | pages actually read across the batch |
| `stopped_early` | `continue_on_error: false` and a failure occurred |

`continue_on_error: false` remains for a caller treating the batch as one unit of
work. Both paths are tested: the default continues past a broken file, and the
strict path stops *before* attempting the next one — the test asserts the engine was
called twice, not three times.

### A vendor's name is not a synonym for tesseract

The WordPress tool offers `unlimited_ocr` and `deepseek_ocr`. This host runs
tesseract against the worker. Running it under a vendor's name would let a caller
believe it got a service it did not, and the text would still look plausible — the
worst kind of wrong. Both names are refused with `ocr_provider_unavailable`, naming
the provider and saying what this host runs. They stay in the schema's `enum` on
purpose: a caller migrating from WordPress gets a *reason*, not a schema rejection
that reads like a typo.

### Small decisions

- **`read`, not `edit_posts`**, matching `ocr_pdf_text`. A batch is several reads;
  OCR writes nothing.
- **`MAX_FILES = 25`** is a timeout guard as much as a courtesy, and it is public so
  the test asserts against it rather than a literal.
- **Duplicate ids are one run.** `intListParam()` de-duplicates, so `[1, 1]` is one
  OCR pass and `requested` reports 1 — the caller is not charged twice for the same
  page image.
- **Options reach every file.** `dpi`, `max_pages` and `language` are forwarded per
  file. Applying them once would OCR the rest at the wrong resolution in the wrong
  language and still return plausible text, which is the accepted-then-ignored
  defect wearing a different coat.
- **Published defaults** (`DEFAULT_DPI = 300`, `DEFAULT_MAX_PAGES = 20`) mirror
  `ocr_pdf_text`, so either tool can be read on its own.

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Tool/ProBatchOcrTool.php` | new; the loop, the per-file result, the provider refusal |
| `lib/core/resources/tool-readiness.php` | one row |
| `lib/core/tests/Unit/Tool/ProBatchOcrToolTest.php` | fifteen tests |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

No worker file changed. That is the point of the section.

### Measurement

`lib/core` **2,721 / 18,406** (was 2,704 / 18,332; +15 from the file above, the other
+2 are catalogue-driven cases that grow with the tool set); adapter **444 / 2,126**
(was 444 / 2,125); audit **309 / 309, servable 290**; toolkit parity **87 of 854**;
`document-generation` **32 of 38**.

Fifteen tests cover: every file OCRed with pages summed; options reaching every
file; the published defaults; a broken file reported while the batch carries on;
`continue_on_error: false` stopping before the third file; an unknown id becoming a
per-file error; both vendor names refused *and* never reaching the engine; the
`no_file_store` and `no_ocr_engine` gates; the 25-file cap; the empty batch; and a
duplicate id costing one run.

### What is left

Six:

| tool | what it needs |
|---|---|
| `pro_unlimited_ocr` | the same engine with a page budget instead of a vendor cap — tool work, no worker change |
| `pro_document_ocr` | the `tesseract` path only; the hosted providers are vision model calls, so they are refused by name |
| `docgen_capture_style_memory` | a store — corpus frontmatter is the likely home, to be decided before coding |
| `generate_invoice_pdf`, `generate_invoice_batch` | a re-scope to accept invoice data as arguments; the worker renders, the order source is the gap, and that is a product decision |
| `get_uninvoiced_orders` | order data — genuinely blocked, recorded and unported |

## 164.40 — The page window was a lie, and the rest of the OCR family (2026-11-25)

§164.39 left six tools and a claim that the OCR family needed no worker change.
The claim held. Reading the route to build the last two found that one of the
OCR parameters had never worked at all.

### The parameter that did nothing

`ocr_pdf_text` has accepted `pages` since §164.7, documented as "explicit
1-based page numbers to read". It never took effect.

`MediaWorkerPdfOcr` sends multipart text fields, so `pages` reaches the worker as
the string `"1,2,3"`. The route tested `Array.isArray( body.pages )`, which is
false for a string, and fell through to the empty list — which the route reads as
"every page". A caller asking for pages 51–60 got the first *budget* pages
instead, silently, with plausible text and no error. That is the
accepted-then-ignored defect in its purest form, and it had been live since the
contract landed.

It was found only because `pro_unlimited_ocr` needs a resume protocol, and a
resume protocol built on an ignored parameter would have re-read page 1 forever.

The fix is a pure helper. `parsePageList()` accepts an array, a comma-joined
string, or a single number, keeps the caller's order, and collapses duplicates
(recognising one page twice is paid work for one answer). The route applies the
document bound itself, because that needs the page count and the helper
deliberately does not. Six worker tests pin it, and an adapter test pins that the
adapter really does send `"51,52,53"` for a three-page window.

### The ceiling is 50 pages, not unlimited

`clampPageBudget( value, fallback = 20, max = 50 )` is the route's real limit,
and OCR runs at seconds per page behind one request timeout. So "parse 500 pages
in a single pass" — the WordPress tool's premise, on a GPU vLLM box — is not
reachable by raising a schema number. A tool that accepted 500 would have the
worker read 50 and let the caller believe otherwise.

`pro_unlimited_ocr` keeps the name and changes what it means, plainly: **the
document is unlimited, the call is not.**

| field | meaning |
|---|---|
| `start_page` | first page of this window (pass the previous `next_page`) |
| `pages_read` / `total_pages` | what this window covered, and how long the document is |
| `next_page` | where to continue, or `null` when finished |
| `complete` | `next_page` is `null` |

`next_page` is derived from the **last page actually read**, not from the budget:
a window that failed part-way must not tell the caller to skip the pages it never
got.

There is deliberately no `truncated`. The route's `truncated` means "this response
is not the whole document", which is true of *every* window of a long document,
including the last one — beside `complete: true` it reads as a contradiction. One
truth, in the caller's terms.

None of the WordPress tool's `model_type`, `image_mode`, `output_format`,
`preserve_layout` or `extract_tables` appear in the schema. They describe the
vLLM models' `<|det|>` block markers, gundam tiling and bounding boxes, and
tesseract produces none of them.

### For `pro_document_ocr`, the ladder is the port

The WordPress tool dispatches OpenAI, Gemini, Anthropic, Ollama, tesseract and two
vLLM models with a circuit breaker and a fallback chain. This host has one of
those, so a provider chain would be eight names resolving to the same engine.

What is worth porting is the part that is a *decision*: **a PDF with a text layer
should never be OCRed.** Reading a text layer is effectively free and exact;
rendering and recognising is seconds per page and probabilistic. The guidance
converges on checking for extractable text first and reaching for OCR only on
scans, and this tool does it automatically rather than making the caller choose
between `extract_pdf_text` and `ocr_pdf_text` and know which they need.

`source` reports which rung answered — `text_layer` or `ocr` — so an exact read is
distinguishable from a recognised one. The threshold is 32 characters per page: a
scanned page carries a stamp, a header or the scanner's own watermark; a
born-digital page carries hundreds. It is a constant rather than a parameter, so
callers do not have to tune it per document.

Two consequences fall out of the ordering, and both are tested:

- **A rich text layer on a host with no OCR engine still succeeds.** `no_ocr_engine`
  is raised only after the layer has been tried and found wanting, because OCR
  being absent only matters when OCR is needed.
- **A failing or unavailable extractor is not a failure of the tool.** It means
  "try the next rung", and the next rung is OCR — which is exactly the case OCR
  exists for.

The hosted providers are refused by name with a reason. Vision-model OCR of a PDF
needs a page renderer this runtime does not expose — `/api/pdf/render` returns
paths on the *worker's* filesystem, which the calling host cannot read — so it is
a future slice with a new contract, not a rename.

### Two smaller corrections, both surfaced by this slice

- **`pro_batch_ocr` declared no upper bound** on `max_pages`, which §164.39 left
  open-ended. It now says 50 and clamps, matching what the route will actually
  read.
- **A half-built canvas was a 500.** Both PDF routes map `ERR_MODULE_NOT_FOUND`
  and `ERR_DLOPEN_FAILED` to the named `capability_unavailable` refusal, but a
  canvas that was installed without a build toolchain throws `MODULE_NOT_FOUND`
  for its own `canvas.node` — a plausible production state after an upgrade
  without a rebuild — and fell through to a generic 500. Both routes now treat it
  as unavailable, which is what it is.

### Research grounding

| finding | source | where it landed |
|---|---|---|
| 300 DPI is the working default for OCR; below ~200 accuracy falls off; very high densities hit engine pixel caps | Tesseract documentation, *Improving the quality of the output* | the `dpi` default and its 150–600 range, unchanged from §164.7 |
| large-document OCR is bounded by a timeout and a page/megapixel guard, not by document length | OCRmyPDF *Advanced features* (`--tesseract-timeout`, `--skip-big`) | the 50-page window plus `next_page` protocol |
| check for a text layer first; send page images to vision models only for scans | *Document Intelligence with LLMs*; *Using LLMs for OCR* | the `pro_document_ocr` ladder |
| vision-model OCR is per page, then synthesised | *Process Documents with Vision Models* | recorded as the shape of the deferred renderer slice |

### Where the work lives

| file | change |
|---|---|
| `addons/media-worker/src/utils/pdf-style.js` | `parsePageList()` — arrays, comma strings, single numbers |
| `addons/media-worker/src/routes/pdf.js` | the OCR route parses a page window; both routes name a half-built canvas |
| `addons/media-worker/src/utils/pdf-style.test.js` | six parser tests |
| `lib/core/src/Tool/ProUnlimitedOcrTool.php` | new; the window protocol |
| `lib/core/src/Tool/ProDocumentOcrTool.php` | new; the text-layer-first ladder |
| `lib/core/src/Tool/ProBatchOcrTool.php` | `MAX_PAGES`, schema maximum, clamp |
| `lib/core/resources/tool-readiness.php` | two rows |
| `docker/laravel/app-skeleton/tests/Feature/TabularAndOcrAdapterTest.php` | one test pinning the `pages` field |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,757 / 18,543** (was 2,721 / 18,406; +32 from the three test files,
+4 from catalogue-driven provider cases); adapter **444 / 2,128** (was 444 /
2,126); app-skeleton OCR adapter tests **13** (was 12); worker **19** in the two
touched files (was 13); audit **311 / 311, servable 292**; toolkit parity **89 of
854**; `document-generation` **34 of 38**.

The new tests cover: the first window and its `next_page`; a resume sending an
explicit `range()`; the final window reporting `complete`; a document that fits
one budget; a `start_page` past the end reading nothing and saying so; the
per-page array only on request; the budget clamped to 50; a rich text layer read
without OCR; a scan falling through; `prefer_text_layer: false` forcing OCR; a
rich layer succeeding with no OCR engine; a thin layer with no OCR engine
refused by name; an unavailable and a throwing extractor both falling through;
and all six hosted/vLLM provider names refused without reaching either engine.

### What is left

Four, and one recorded follow-up:

| item | what it needs |
|---|---|
| `docgen_capture_style_memory` | a store — corpus frontmatter is the likely home, to be decided before coding |
| `generate_invoice_pdf`, `generate_invoice_batch` | a re-scope to accept invoice data as arguments; the worker renders, the order source is the gap, and that is a product decision |
| `get_uninvoiced_orders` | order data — genuinely blocked, recorded and unported |
| *follow-up* | vision-model document OCR: a `PdfPageRendererInterface` plus a worker render route that returns bytes, then a per-page vision loop |

## 164.41 — The last three that could be built (2026-11-25)

§164.40 left four. Reading them again corrected one, and the memory tool needed
less than expected.

### `generate_invoice_pdf` was never blocked

§164.40 recorded "the two invoice tools need a re-scope to accept invoice data —
a product decision". That was wrong for one of them. `generate_invoice_pdf`
already takes `invoice_number`, dates, `bill_to`, `items`, `tax_rate`, `currency`
as arguments and queries nothing at all. It needed no decision — only reading.

Its `requires-model` and `consumes-tokens` flags are also false: the file builds
HTML and hands it to dompdf or the pro PDF service, and never calls a model. They
are not declared here.

Only `generate_invoice_batch` genuinely needed a decision, and the answer was
option (b): the invoices are given, not looked up.

### The arithmetic, corrected

An invoice is a financial document, so the arithmetic is the part that matters.
Four things in the WordPress tool are wrong in ways a customer notices:

| WordPress | here |
|---|---|
| sums `items[].amount` and ignores `quantity`/`rate` — while printing them, so "3 × 20.00" without an `amount` prints 0.00 and adds nothing | a line is `amount` when given, otherwise `quantity × rate`, and the printed number is the summed number |
| accepts `subtotal` and `total`, then silently recomputes both | a supplied figure is a **cross-check**: a disagreement is refused with both numbers named |
| float arithmetic throughout | integer minor units; rounding happens once per line and once for tax, nowhere else |
| accepts `bill_to` and prints nothing | the recipient is printed, as a string or a map |

The cross-check is the opinionated part. Silently correcting a caller's total
means the caller never learns their arithmetic disagreed with ours — and on a
financial document, either answer being wrong is worth stopping on.

### One new entry point on the composer

`PdfDocumentComposer::compose()` builds a body from prose and cannot express a
table. The alternative to extending it was for the invoice tools to reimplement
page-size resolution, filename stemming, visibility and the registry row —
exactly the drift the class says it exists to prevent.

So `compose()` and `document()` were each split at their waist into `wrap()` and
`render()`, and `documentFromHtml()` exposes the same pipeline for a body the
caller composed itself. `compose()` and `document()` keep their signatures and
their output; the 84 existing PDF tests pass unchanged, which is what makes the
refactor safe to trust.

### The batch, re-scoped

`generate_invoice_batch` requires `order_ids`, plus a `template_id` (a document
template CPT) and `send_email`. None of the three has a counterpart here. Shipping
the schema anyway would produce a tool whose only possible answer is "no such
order".

Option (b) is what shipped: **give the invoices, get the PDFs.** Up to 25 per
call, each built by the same `InvoiceDocument` the single tool uses, each with
its own `ok`/`error`, and `continue_on_error` true by default for the same reason
as `pro_batch_ocr` — a batch that aborts on invoice three has rendered two
documents and returned nothing.

### The memory tool needed no store

§164.40 guessed "corpus frontmatter is the likely home". It is not: the runtime
already binds `MemoryStoreInterface`, and `store_agent_context` has used it since
2.0.

The verbatim discipline ports as two records — the exact text at `tier=archival`,
an optional `summary` as the `tier=recall` representative. The archival write
goes **first**, because a summary without its original defeats the thing the tool
exists for; a failed archival write therefore stores nothing.

When no summary is supplied, **one** record is written holding the verbatim text.
The WordPress service summarises on its own, and this tool holds no model, so a
second archival copy of identical bytes would double the storage to reproduce a
distinction that does not exist in that case.

Six WordPress fields are absent from the schema — `verbatim`,
`allow_summarisation`, `sensitivity`, `consent_basis`, `subject_refs`,
`attachments`, and the bi-temporal `recorded_at`/`valid_from`/`valid_until`.
`MemoryStore::store()` writes fixed columns and drops unknown keys, so offering
them would let a caller believe provenance and validity were recorded when
nothing was. `tier` is absent too, deliberately: the verbatim record is archival
and the representative is recall by construction, and a knob that let the
original move out of archival would defeat the discipline.

Carrying provenance and validity honestly needs a metadata column on the store.
That is the recorded follow-up below, not something to fake here.

### Research grounding

| finding | source | where it landed |
|---|---|---|
| money should be summed in integer minor units, not floats | the standard accounting-software practice behind every ledger schema | `InvoiceDocument` converts on the way in and renders once at the end |
| agent memory separates a searchable representative from the verbatim record | the mem0 / MemPalace tier model already recorded in §164.40's research | archival + recall records with the original first |

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Application/Document/InvoiceDocument.php` | new; arithmetic, escaping and body HTML |
| `lib/core/src/Application/Document/PdfDocumentComposer.php` | split into `wrap()`/`render()`; added `documentFromHtml()` |
| `lib/core/src/Tool/GenerateInvoicePdfTool.php` | new |
| `lib/core/src/Tool/GenerateInvoiceBatchTool.php` | new; the re-scoped premise |
| `lib/core/src/Tool/DocgenCaptureStyleMemoryTool.php` | new; two-record discipline |
| `lib/core/resources/tool-readiness.php` | three rows |
| `lib/core/tests/Unit/Application/Document/InvoiceDocumentTest.php` | twenty-six arithmetic and printing tests |
| `lib/core/tests/Unit/Tool/GenerateInvoicePdfToolTest.php` | eleven |
| `lib/core/tests/Unit/Tool/GenerateInvoiceBatchToolTest.php` | ten |
| `lib/core/tests/Unit/Tool/DocgenCaptureStyleMemoryToolTest.php` | sixteen |
| `lib/core/resources/toolkit-parity-baseline.json` | regenerated (`--write`) |

### Measurement

`lib/core` **2,826 / 18,756** (was 2,757 / 18,543; +63 from the four files above,
+6 from catalogue-driven provider cases); adapter **444 / 2,131** (was 444 /
2,128); audit **314 / 314, servable 295**; toolkit parity **93 of 854**;
`document-generation` **37 of 38**.

Parity moves by four for three tools: `generate_invoice_pdf` belongs to both
`document-generation` and `ecommerce`, and `ecommerce` had no ported tool before,
so the baseline now records `1` there.

The tests cover: a line computed from quantity × rate; a supplied amount winning;
cents summing without drift; tax rounded once; a matching supplied total accepted
and a disagreeing one refused; every builder refusal; the recipient printed; a
description escaped; the currency rendered with each total; the tool refusing by
name with no engine; `save_to_media` writing through the store and `false`
skipping it; a batch continuing past a bad invoice and stopping on request; the
memory tool writing archival before recall, writing nothing when the archival
write fails, and storing content containing markup unchanged.

### What is left

One tool:

| item | what it needs |
|---|---|
| `get_uninvoiced_orders` | order data — genuinely blocked, recorded and unported |

And two recorded follow-ups, both of which are their own slice:

| follow-up | what it needs |
|---|---|
| memory metadata | a metadata column on the memory store, so provenance (`subject_refs`, `attachments`) and bi-temporal validity can be carried rather than dropped |
| vision OCR | a `PdfPageRendererInterface` and a worker render route returning bytes, then a per-page vision loop |

## 164.42 — Memory provenance had nowhere to live (2026-11-25)

The previous section added those tools; this one changes the store they
write to. The two touch disjoint code.

### What was being dropped in silence

The WordPress capture base exposes ten fields the `nvoos_memories` columns cannot
express — `subject_refs`, `attachments`, `recorded_at`, `valid_from`,
`valid_until`, `verbatim`, `sensitivity`, `consent_basis` and two more. On
WordPress they reach a capture service with its own tables, so they work. On this
runtime the record reached `MemoryStore::store()`, the columns it had were
written, and the rest vanished — under `success: true`.

That is worse than refusing. A caller that reads `success` believes its
provenance was recorded, and nothing later contradicts it.

Reading the contract while fixing it turned up a second, smaller version of the
same problem: `MemoryStoreInterface::search()` documents a `metadata` key in
each result, and `record()` never returned one. The contract promised a field the
implementation did not have.

### One column, not one per concept

Provenance vocabulary is open: Zep's `valid_at`/`invalid_at`, mem0's `metadata`
dict, a host's own keys, a toolkit's capture policy. A column per concept would
mean a migration per concept. So the column is a single nullable JSON map.

Nullable and with no default, so a record with no provenance stores `NULL` rather
than `{}` — "nothing was recorded" is then visible in the database instead of
looking like an empty answer. It reads back as an empty array either way, so a
caller still never has to tell "absent" from "empty".

### Merge, not replace

`update()` treats `metadata` as a JSON Merge Patch, RFC 7386:

| patch value | effect |
|---|---|
| a scalar or an array | replaces the key |
| an object | merges recursively |
| `null` | **removes** the key |

The reason is when it is used. Provenance is added later — set `valid_until` when
a record is superseded, append a `subject_ref` when a document is attached. A
replace would force every caller into a read-modify-write to keep the keys it did
not mean to touch, which is a race, not a feature.

Every other field is still replaced outright, `tags` included. That asymmetry is
deliberate and is stated in the contract rather than left to be discovered.

### A ceiling that belongs to the contract

`MAX_METADATA_BYTES = 65536`. An unbounded JSON column is how a memory store
quietly becomes a file store, so the limit sits on the interface where both
adapters inherit it, not in one adapter's head. `store()` and `update()` refuse
with a message that says where bytes belong instead: the file registry,
referenced from here by id.

### A no-op patch is not a missing record

`update()` used to derive success from the affected-row count. A database reports
zero rows changed when a patch writes the values already present, so re-sending a
record's own content answered `Memory not found.` for a record that was right
there. Existence is now checked before the write, and "nothing to do" is
reported as success because that is what it is.

### The WordPress adapter needed nothing

It forwards the whole `$record` to `WP_MCP_AI_Memory_Capture_Service::store()`,
which already persists these fields. Only the fixed-column adapter dropped them
— which is a useful reminder that "the contract has a field" and "every adapter
stores it" are different claims, and only the second one is testable.

### Research grounding

| finding | source | landed as |
|---|---|---|
| a merge patch removes a member by setting it to null; objects merge, arrays replace | RFC 7386, *JSON Merge Patch* | the `update()` semantics for `metadata` |
| memory systems separate the searchable representation from provenance and validity | the mem0 / Zep models already cited in §164.40 and §164.41 | an open map rather than a fixed vocabulary of columns |

### Where the work lives

| file | change |
|---|---|
| `lib/core/src/Domain/Contract/MemoryStoreInterface.php` | `metadata` in the record shape, `MAX_METADATA_BYTES`, merge-patch semantics |
| `lib/laravel-adapter/database/migrations/2026_11_25_130000_add_metadata_to_memories.php` | new; one nullable JSON column |
| `lib/laravel-adapter/src/Adapter/MemoryStore.php` | write, merge-patch, read back; existence-based `update()` |
| `docker/laravel/app-skeleton/app/Models/Memory.php` | a `metadata` accessor, so the read-only browser is handed a map |
| `docker/laravel/app-skeleton/tests/Feature/MemoryMetadataTest.php` | fifteen tests |

### Measurement

`lib/core` **2,757 / 18,543**, unchanged: the contract extension is additive, so
no existing implementor or test had to move. Adapter **444 / 2,128**. The new
app-skeleton tests are **15**; the memory consumers together (`AdminMemory*`,
`MemorySettings`, and the eight memory tools) are **32**, all passing.

One honest gap: the full app-skeleton suite could not be completed in this
environment. Two attempts — the second with nothing else running and a 40-minute
budget — produced no result, where the same suite has taken a couple of minutes.
Every slice that touches this change passes, and CI runs the whole thing; the
timeout is recorded rather than papered over.

The tests cover: a map surviving a round trip including a stored `null`;
absent metadata reading back as an empty map; the column storing `NULL` rather
than `{}`; `search()` and `listByAgent()` carrying the map; the Eloquent accessor
decoding it; a patch adding a key without disturbing the others; `null` removing
exactly one key; a nested object merging; an array replacing wholesale; a
non-metadata patch leaving metadata alone; a no-op patch succeeding while an
unknown id still fails; and the ceiling enforced on both `store()` and `update()`
without writing anything.

### What is left

| item | what it needs |
|---|---|
| wiring the capture tool | `docgen_capture_style_memory` (in #8270) still offers only the fields it could store when it was written; once both land, a small PR adds `subject_refs`, `attachments`, `recorded_at`, `valid_from`, `valid_until` and `verbatim` as a metadata map |
| vision OCR | a `PdfPageRendererInterface` and a worker render route returning bytes, then a per-page vision loop |

## 164.43 — The capture tool can finally carry what it was told (2026-11-25)

§164.42 gave the store a `metadata` map. §164.41's `docgen_capture_style_memory`
was written against the store that could not hold one, so it refused seven
WordPress fields rather than pretend to keep them. This closes that loop, and it
is the last item in the `document-generation` cluster.

### Seven parameters that now reach storage

`subject_refs`, `attachments`, `recorded_at`, `valid_from`, `valid_until`,
`sensitivity` and `consent_basis`. Each is written **only when supplied**, so a
capture with no provenance stores `{source, verbatim}` rather than a map of empty
values — the same "nothing recorded is not recorded as empty" rule the store's
column follows.

### `verbatim` is derived, not asserted

The WordPress tool takes `verbatim` as a parameter. Here it is computed per
record, because the tool knows which record holds what:

| record | `verbatim` | why |
|---|---|---|
| archival, holding the original | `true` | it is the exact text |
| recall, holding a summary | `false` | it is not |
| lone recall, holding the original | `true` | there was no summary to stand in |

A caller-supplied flag would let a record claim something the tool knows better
than the caller does. `tier` stays derived for the same reason, and
`allow_summarisation` stays absent because there is no model here to summarise
with — a flag describing behaviour the host does not have is the same defect in
the other direction.

### Validated before it becomes permanent

Provenance is hard to correct once stored, so the obvious mistakes are refused at
the edge:

| field | rule |
|---|---|
| `recorded_at`, `valid_from`, `valid_until` | a date or a date-time with an optional zone |
| `subject_refs` | trimmed, de-duplicated, at most 50 |
| `attachments` | each must be an **object** — ids and hashes, not blobs — at most 25 |
| `sensitivity`, `consent_basis` | at most 64 characters |

The date check is a regex rather than an RFC 3339 parser on purpose: the job is
to stop `next Tuesday` from becoming permanent provenance, not to normalise every
spelling. The store's 64 KiB ceiling remains the backstop for total size, and its
refusal already says where bytes belong.

### Measurement

`lib/core` **2,840 / 18,793** (was 2,829 / 18,771; +11 tests). Audit **314 / 314,
servable 295** and toolkit parity **93 of 854**, both unchanged: this slice adds no
tool, it makes an existing one whole. phpcs clean on all three touched files.

The new tests cover: every record naming its source; `verbatim` derived across the
three record shapes; the caller's seven fields reaching `metadata` intact; both
records sharing the same provenance; a capture with nothing supplied producing
`{source, verbatim}` and nothing else; and the four refusals — a bad date
(store untouched), too many refs, an attachment that is not an object, and an
over-long label.

### What is left

One recorded follow-up, and no unported tool in this cluster:

| item | what it needs |
|---|---|
| vision OCR | a `PdfPageRendererInterface` plus a worker render route returning bytes, then a per-page vision loop |

## 164.44 Vision OCR — pages the host can actually read

**On the numbering:** §164.43 (agent-memory provenance, #8278) is the sibling
immediately before this slice, and this section sits after it. The follow-up that
§164.42 and §164.43 each recorded at their end — "a `PdfPageRendererInterface`
and a worker render route returning bytes, then a per-page vision loop" — is
what this section closes. Those sections are left as merged rather than edited.

### The dead end this closes

`POST /api/pdf/render` has rasterised pages for a while. It wrote the PNGs to
*the worker's* filesystem and answered with their **paths**. A host cannot read
those paths, so the only possible consumer was something running on the worker
itself — that is, nothing. This is why `pro_document_ocr` refused `openai`,
`gemini`, `anthropic` and `ollama` **by name** in §164.40: not because the
providers were absent, but because there were no bytes to show them.

The refusal was honest. Honouring the names needed a renderer, not a rename.

### The worker answers with the images

`inline` makes the route answer
`{pages: [{page, mime_type, image, bytes}], count, total_pages, truncated,
mime_type}` with base64 page images.

| decision | value | why |
|---|---|---|
| inline ceiling | 24 MB | base64 inflates by 4/3 and the body is held in memory on both sides; past this the honest answer is "ask for less", not a 500 |
| over the ceiling | **413** `payload_too_large`, prose in `error` | a request the caller can fix — fewer pages, lower dpi, `jpeg` — so it is a refusal, not a server fault |
| `dpi` | viewport scaled by `dpi / 72` | the route's old `scale` was a bare multiplier; dpi is the unit an operator reasons in |
| `format` / `quality` | `png` default; `jpeg` with quality 1–100 | the escape hatch when a page will not fit |
| pages named, all invalid | **400** | silence would render the whole document instead — the worst kind of helpful |
| `maxPages` | applied for inline *always* | the caller asked for images, so the budget is part of the request, not an optimisation |

**A separate capability key, not a version check.** The route predates the
`inline` argument, so a worker can honestly report `pdf_rendering: true` and
still answer with paths this host cannot read — a 200 that looks like success.
`pdf_render_inline` is a second key in the worker's matrix, and
`MediaWorkerCapabilityProbe` reads it, so version skew becomes a named refusal
before a request is spent. Same reasoning as `MediaWorkerPdfOcr` in §164.40.

### `PdfPageRendererInterface` — bytes at the edge, base64 on the wire

The contract's `render()` returns
`{pages: [{page, mime_type, data, bytes}], total_pages, truncated}` where `data`
is **base64**. Bytes would be more natural in PHP, but the images cross a JSON
boundary in the adapter and every vision provider takes a base64 data URI, so
one representation at the edge removes a second encoding rule from each caller.
`VisionRequest::dataUriFromBase64()` wraps the renderer's string rather than
decoding and re-encoding it — a 20-page document is tens of megabytes, and the
round trip changes nothing.

The default is **150 DPI**, deliberately lower than `PdfOcrInterface`'s 300.
Vision models downsample to their own patch grid long before 300 DPI pays for
itself, so the accuracy ceiling here is the model's, not the scanner's, and the
cost is bytes per page.

`isAvailable()` asks the worker for the narrower capability key rather than
`pdf_rendering`, for the reason above.

### `VisionPdfOcr` — render, ask, assemble

The engine lives in `Application/Media` beside `ImageInput` and `VisionRequest`,
because it is the same kind of thing: a collaborator that owns one awkward
question for every tool that needs it.

**Per page, then concatenate.** Asking about the whole document at once is not
an option: 20 pages at 150 DPI is tens of megabytes, past every provider's
single-request image budget, and models degrade on multi-page input long before
that. Published OCR practice is recognise-per-page then assemble — which is also
the only shape that lets one bad page be reported instead of failing the
document.

**No synthesis pass.** A model asked to "combine these transcriptions" invents
connective prose. OCR means the words as they were printed, so pages are joined
with a blank line and the per-page text stays in the result.

**Empty is a finding.** A drawing, a cover, a signature page: `''` records it.
Only a provider *error* is an error. A document where **every** page errored
throws; a partial read returns the pages that worked plus `failed_pages`, and
`truncated` is true. Silence about a gap would be the accepted-then-ignored
defect in its most expensive form.

**Not a `PdfOcrInterface` implementation,** though it would fit. The tool needs
*both* engines and two parameters of one interface type resolve to the same
binding. It is a `final` concrete class instead — and the tool builds it from
two interfaces (`PdfPageRendererInterface`, `ProviderRouter`) rather than taking
it as a parameter, which is the `ImageInput` seam and also the only shape the
readiness harness can construct, since it can double an interface but not a
`final` class.

### The ladder, and the one place this deliberately differs from WordPress

| `provider` | order |
|---|---|
| `auto` | text layer → tesseract → vision → `no_ocr_engine` |
| `tesseract` | text layer → tesseract → `no_ocr_engine` (never widens) |
| `openai`/`gemini`/`anthropic`/`ollama` | text layer → vision with that provider |
| `unlimited_ocr`/`deepseek_ocr` | `ocr_provider_unavailable` |

The text layer still wins for every provider: reading an exact layer is free and
correct, and §164.40's whole point was that a born-digital page is never
rendered.

**`auto` is "the cheapest engine that works", which is not what WordPress does.**
The WordPress tool tries vision models first and recommends them for accuracy.
This host orders tesseract first, then vision. The reason is that `auto` is what
a caller who named no engine gets, and vision is billed per page while tesseract
is free and local — so the default must not spend money on the caller's behalf.
The caller who wants the better model names it, one argument away. That is
strictly more capable than refusing the names (before this slice), and it is not
a silent cost regression.

**Two refusals, not one.** `no_page_renderer` sends an operator to the media
worker; `no_vision_model` sends them to provider configuration. One message
covering both would send half of them to the wrong panel.

`source` stays `'ocr'` for both engines — it answers "exact read or recognised
read", which is what it was documented to answer — and `ocr.engine` now says
`tesseract` or `vision`, with provider, model, aggregated `usage` and
`failed_pages` on the vision path.

### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 2,840 / 18,793 | **2,848 / 18,806** |
| adapter PHPUnit | 444 / 2,131 | **444 / 2,131** |
| worker (`pdf-style` + `routes/pdf`) | — | **21 / 21** |
| `PdfPageRendererAdapterTest` | — | **10 / 10** |
| tool readiness audit | 314/314, servable 295 | **314/314, servable 295** |
| toolkit parity | 93 of 854 | **93 of 854** (no row moved) |

The before column is post-§164.43, because that section merged while this one was
in review; the earlier figures were measured against §164.42's tree and replaced
rather than kept, since a base that no longer exists is not a comparison.

Ten tests were added to `lib/core` (the vision path) and two removed: the
blanket "hosted and vLLM providers are refused" case became "vLLM providers are
refused", with the hosted names now covered by the paths above.

### Validation, honestly

- The **adapter suite ran in the `oos-laravel-app` container** from a scratch
  package tree (runtime skill §19), not on this Windows host: the CLI has no
  `fileinfo`/`gd`/`zip`, and the committed adapter lock resolves to Laravel 13 /
  Symfony 8, which requires PHP ≥ 8.4. `composer update` inside the container
  resolves against its PHP 8.3. A host-side `composer update
  --ignore-platform-reqs` installed a Symfony that does not parse on 8.3 — the
  container is the only working runner here.
- From the app skeleton, **only the new test class** was run. The full suite
  times out in this environment (>40 min, twice, once with nothing else running,
  against a normal ~2 min); that gap is reported rather than papered over.
- `ProjectManagementToolParityTest` reports `Empty data set provided by data
  provider` on any filtered run unless both `NVOOS_PM_*` fixtures are passed —
  known-benign, and it fires at collection time even when `--filter` excludes
  the class.
- The container serving the dev stack is bound to a **different worktree**
  (§6 of the runtime skill), so the app-skeleton run mounted this tree at
  `/workspace/oos` and bound the one new test file over `/app/tests/Feature/`.
  Nothing in another agent's tree was written.

### What is left

Nothing in this cluster. `document-generation` is **37 of 38**; the remaining
tool is `get_uninvoiced_orders`, which is blocked on order data rather than on
code.

## 164.46 The registry learns relationships, and the inventory reads them

**On the numbering:** there is no §164.45. This section covers both halves of one
capability — the contract that records a derivative, and the inventory that reads
it — because the second is unanswerable without the first. The contract's own
docblocks point here, and it landed in #8284; the inventory is this slice.

### The gap that could not even be measured

The WordPress tools ask a question the media library can answer for free: which
videos lack `_thumbnail_id`, and which lack transcript meta. This runtime had no
relationship at all. `generate_video_thumbnails` stored a poster and recorded
nothing about where it came from, so **"this video has no poster" and "this video
has a poster that nothing recorded" produced the same answer**.

A tool built on that would have reported false gaps, which is worse than
reporting none: a false "missing" costs a regenerated poster, and a regenerated
poster costs money. So the inventory waited for the relationship.

### The contract

`FileDerivativeStoreInterface` is an **optional capability**, exactly like
`FileVisibilityStoreInterface`: a separate interface, because `FileStoreInterface`
has three adapters and many test doubles and adding a method there breaks all of
them for something most hosts never need.

The vocabulary was not invented here. Adobe's XMP specifies `xmpMM:DerivedFrom`
for the **immediate** source an asset was made from — not its ultimate ancestor,
so a chain stays walkable one step at a time — and digital asset management calls
the result a *rendition*. The link is two metadata keys on the existing
`nvoos_files.metadata` column, so **no migration**.

| role | producer |
|---|---|
| `poster` | `generate_video_thumbnails` |
| `caption` | `generate_video_captions` (the `.srt`/`.vtt` sidecar) |
| `frame` | `extract_video_frames` |

Every role has a producer. A role nothing writes would be a flag describing
behaviour the host does not have, so roles are added when their producer lands.

Three refusals carry the safety argument, because a wrong link is *trusted* while
a missing one is merely missed: a file may not derive from itself, the source must
exist, and the role must be in the vocabulary. Recording the same pair twice is
idempotent — the link is a fact — and the write merges rather than replaces.

**The source id is an integer, and that is load-bearing.** `findByMetadata()`
compares a string criterion with `stripos`, so `'7'` also matches file `17`. An
integer is compared strictly. A test creates sources `1` and `10`, links a poster
to each, and asserts a query for `1` returns exactly one row; it fails the moment
someone "simplifies" the id to a string.

### The read half

`sourceIdsWithDerivative( array $sourceFileIds, string $role ): array` answers the
inventory question once — *of these videos, which already have a poster?* — instead
of one registry scan per video. It is on the contract rather than assembled from
`findByMetadata()` by each caller because the batch form is the only version that
is not quadratic.

**Why the answer is trustworthy inside the window.** Ids are monotonic: a
derivative is created *after* its source, so it always has a higher id. If a video
is inside the scanned window, its poster is inside it too. That is what makes "not
in the derivative set" mean "has no poster" rather than "the scan did not reach
that far" — and it is why both reads share one `SCAN_LIMIT` constant. Two
different bounds would let the poster lookup stop one row earlier than the source
lookup and report a poster as missing.

Outside the window nothing is claimed: a video older than the scan is **not listed
at all**, never listed as missing a poster it may well have.

### The two tools

`get_videos_without_thumbnails` and `get_videos_without_transcripts` are the same
query with a different role. The shared logic is `Application\Media\VideoAssetGaps`
— two scans, one report — and each tool is a schema plus its own wording.

Two deliberate differences from WordPress, both recorded rather than silently
dropped:

- **No `platform` filter.** WordPress accepts `youtube`, `vimeo`, `local`. Nothing
  in this runtime records a target platform — its only writer would be
  `upload_video_batch`, which is deferred — so the parameter is absent rather than
  accepted and ignored.
- **A transcript here is a stored caption file.** `generate_video_captions` writes
  an `.srt`/`.vtt` sidecar and records the link; `transcribe_video` returns text in
  its payload and stores nothing. So a transcript that was never saved is not a
  record, and the tool will list the video again — which its description says,
  because a caller who has just transcribed will otherwise read the repeat as a
  bug. WCAG 2.2 SC 1.2.2 (captions, Level A) is why the gap is worth measuring at
  all.

Both are bounded and say so: at most `limit` videos (default 50, ceiling 100 —
the registry's own cap), and `truncated` when the page was full.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,872 / 18,882** (10 tests added by this slice) |
| adapter PHPUnit | **448 / 2,142**, OK |
| `FileDerivativeTest` (app, SQLite) | **11 / 21** (3 added by this slice) |
| tool readiness audit | **316 / 316**, servable **297** (+2) |
| toolkit parity | **95 of 854**; `video-production` **17 of 21** |

`video-production` now reads 21 declared, 17 ported, 1 declined, 3 deferred,
**0 remaining** — closed, without claiming 100%.

### A finding, deliberately not fixed

The adapter's package migration `2026_01_01_000006_create_nvoos_files_table`
predates `visibility`, `slug`, `checksum`, `tenant_id` and `metadata`, all of which
`FileStore` writes. A package-only install cannot store a file at all. The app
skeleton has the columns, so no deployment is affected; it needs its own migration
and its own slice rather than a half-fix here.

## 164.47 resize_image_smart, and the arguments the WordPress tool never read

### The reference behaviour is not the reference implementation

The WordPress tool with this slug documents itself as “smart content-aware image
resizing” that “uses seam carving and other algorithms to maintain visual
quality”. It offers four `mode` values and a `focus_area` enum that includes
`face`. What `execute()` actually does is:

| declared | executed |
|---|---|
| `mode: crop` | `resize( $w, $h, true )` — a centre crop |
| `mode: fit` | `resize( $w, $h, false )` — a fit |
| `mode: fill` | `resize( $w, $h, false )` — the same fit |
| `mode: smart` | `resize( $w, $h, false )` — the same fit again |
| `focus_area` | nothing — the string is never read |

Three of the four modes are one engine call, and the parameter that sounds most
capable is unused. Porting that schema would have produced a tool that returns
success for an edit it did not perform, which is the recurring defect this
programme keeps naming: **accepted-then-ignored**.

### What the port offers instead

The Laravel engine has a real operation for one of those four modes, so the port
keeps exactly that one and refuses to pretend about the rest:

- **Cover-crop to exact dimensions**, with the crop centred by `position`:
  `attention` (Sharp's smartcrop saliency map), `entropy`, `centre`, or a fixed
  edge. Seven values — only the ones `ImageProductionInterface::resizeSmart()`
  documents, because a host implementing the contract is not obliged to know the
  adapter's extra corner aliases.
- **No `mode`.** `fit` and centre-crop already live on `resize_image`, which has
  had them since 2.0.0. A second slug for the same two calls is a second way to
  ask one question.
- **No `focus_area`, no `face`.** Sharp's attention strategy is edge-and-skin-tone
  saliency. It is not face detection and it is not seam carving, and the tool
  description says so — a caller who believes either will mis-read a result it
  cannot inspect.
- **No `quality`.** `resizeSmart()` takes no quality argument (the adapter pins
  90), so offering the knob would have been the same defect in a new place.

### One dimension is enough, and that is the honest part

The WordPress usage notes promise the missing dimension is derived from the
aspect ratio. That promise is worth keeping, and it is the one behaviour that is
strictly better here than in WordPress: deriving needs the source size, so it goes
through `ImageProcessingInterface::getInfo()` from the geometry engine. A host that
bound the production half and not the geometry half refuses with
`no_dimension_source` rather than rounding the unknown to a square.

### One round trip, two capability refusals

`resizeSmart()` posts one `POST /api/image/transform` call with
`fit: cover` and the resolved `position`; the result metadata is read from that
same response, so a smart crop is one worker request, not two. The tool asks
`supports( 'resize_smart' )` — which maps to the worker's `image_smart_crop`
capability — **before** spending the request, and refuses by name when the worker
does not advertise it. The feature test asserts both halves: that `fit` and
`position` reach the engine, and that a worker without the capability never sees a
transform request at all.

### The other ten slugs on this toolkit

`image-production` is now closed at 22 declared — 12 ported, 6 declined, 4
deferred, **0 remaining**. The register carries the full reasons; in short:

| disposition | slugs |
|---|---|
| declined | `apply_watermark_batch`, `batch_process_images`, `generate_image_ai`, `generate_image_variations`, `remove_image_background`, `text_to_image_prompt_optimizer` |
| deferred | `get_unwatermarked_images`, `image_inpainting`, `optimize_image_sharp`, `remove_background` |

Two of the declines are worth naming because they are not aliases: `batch_process_images`
would need a multi-operation `/transform`, because each geometry call takes a path
and returns a persisted path, so a chain is one round trip and one intermediate
file per step — a worker contract change, not a tool. `apply_watermark_batch` is a
loop over a per-image edit, and the video counterpart is already served.

The four deferrals are all blocked on a named prerequisite rather than on effort:
a watermark role on the derivative store (the store's `ROLES` are still poster,
caption and frame), a mask argument on the worker edit route, a multi-operation
transform, and a matting engine (`rembg` or a `removebg_api_key`), none of which is
bound. A deferral is not a decision to do less; it is a statement about what would
have to exist first.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,901 / 18,994** (14 tool tests, plus 2 manifest data sets) |
| adapter PHPUnit | **448 / 2,143**, OK |
| `ImageProductionToolsTest` (app, SQLite) | **27 / 27**, 138 assertions (2 added) |
| tool readiness audit | **317 / 317**, servable **298** (+1), no violations |
| toolkit parity | **96 of 854**; `image-production` **12 of 22**, 0 remaining |

### A pre-existing defect, reported not repaired

The tail of §164.46 is corrupted on `alpha-working`: a paragraph and the
measurement table are spliced together (`| gate | value |e tool will list the video
again …`), which is what a bad conflict resolution looks like. It predates this
slice and repairing it means reconstructing text, so it is left for its own change
rather than folded into a slice whose diff should be insertions only.

## 164.48 The Paper Store learns to leave the building

**On the numbering:** §164.47 is the `image-production` slice, which merged ahead
of this one; the two are independent, so this section simply takes the next free
number.

### The substrate was already paid for

`PaperStoreInterface` and its `JsonPaperStore` implementation shipped in 1.3.0,
and `NvoosServiceProvider` binds the store as a singleton rooted at
`oos.paper_store_root` (falling back to `storage/paper-store`). The layout is
`{root}/{collection}/{id}.json`, chosen so the same records can be read by either
runtime. What was missing was only the pair of tools that moves records in and
out: `paper-store` sat at 0 of 2 ported while the thing it needed was bound and
tested. That is the cheapest kind of gap to close, which is why it came next.

### Export: a page, not a promise of everything

The WordPress tool returns `$repo->query()->get()` — the whole collection, with no
bound. That is fine against a database and wrong here, because
`JsonPaperStore::query()` scans the collection directory on **every** call (it
keeps no index, deliberately). Assembling "everything" by looping pages would
therefore read the same files once per page: a 1,000-record export would cost five
full scans. So the tool returns one page of at most 200 — the contract's
documented ceiling — plus `total`, `truncated` and `next_offset`. A caller that
wants the rest asks again; a caller that does not, is told there is more.

Two smaller decisions in the same spirit:

- **An unusable collection name is refused, not answered.** `query()` treats an
  invalid name as "no records" and returns an empty page, so `../secrets` would
  have been reported as an empty collection. An empty export is a *finding*; a
  malformed argument is a *refusal*, and they must never look alike.
- **Order is pinned to `id`.** The reference tool inherits the repository default,
  which sorts by `updated_at` — a mutable column. Paging by a mutable column can
  serve a record twice or skip one when something is saved mid-export. Order is
  not part of the data; the set is. Offset paging is still sensitive to concurrent
  creates and deletes, which shift every later page; the store has no snapshot, so
  that residual drift is named rather than hidden.

`cacheable` is deliberately **not** declared, although the WordPress tool declares
it: this is a read against a store that can change between two identical calls,
which is exactly the case `CapabilityFlagVocabulary::LOCAL_READ` excludes it for.

### Import: three things the reference left unfinished

The loop, the `overwrite` default and the counters follow the WordPress tool. Three
details were tightened, each because the reference is silent or inconsistent
rather than because the port wanted a change:

- **A title is checked here, not left to the store.** The WordPress schema says
  every item needs `id` *and* `title`, but `execute()` only checks `id`; the title
  failure then arrives as a repository exception whose wording depends on the
  store. Both are now checked before any write, so the documented precondition is
  the enforced one.
- **A record with no id is a failure, not a silent skip.** The reference
  increments `skipped` and says nothing, which makes "malformed" and "already
  existed" the same counter. They are different facts, and a caller reconciling a
  migration needs to tell them apart.
- **The report reconciles.** `imported + skipped + failed === total`, always,
  including when the store throws mid-batch. This is the discipline
  `BulkImportTool` established: a batch report a caller cannot reconcile is worse
  than no report.

`dry_run` defaults to **false**, unlike `optimise_images_batch`. The difference is
intent: that tool infers a bulk action over a library the caller did not
enumerate, so it asks twice. An import is the opposite — the caller wrote every
record out in the arguments — and the reference tool writes immediately, which a
migrating prompt depends on. The flag is offered, not imposed, and a rehearsal
still refuses malformed records so it is a real rehearsal.

Import is an **upsert, not a merge**: `save()` replaces the whole record and
preserves `created_at`, so a field absent from the incoming record is removed. The
tool says so rather than promising a partial update the store cannot deliver.

### Why this pair matters beyond two slugs

Together they are the backup and migration path for curated knowledge, and they
work between runtimes because the JSON layout is shared: an export from the
WordPress site imports into this one without a translation step, and back again.
That is a capability the runtime did not have at all, not merely a name that was
missing.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,931 / 19,110** (26 tests, plus 4 manifest data sets) |
| adapter PHPUnit | **448 / 2,145**, OK — both new rows construct |
| tool readiness audit | **319 / 319**, servable **300** (+2), no violations |
| toolkit parity | **98 of 854**; `paper-store` **2 of 2**, 0 remaining |
| licence boundary | intact |
| phpcs (PSR-12, laravel dist) | clean on both tools, the test and the manifest |

No app-skeleton feature test was added for the pair: the adapter suite already
proves every servable row is constructible, `PaperStoreTest` exercises the bound
singleton through the REST API, and the unit suite runs against the real
`JsonPaperStore`. A third test would duplicate those three rather than add to them.

### Still not repaired

The tail of §164.46 remains spliced on `alpha-working` (a paragraph and the
measurement table run together). It predates both this slice and §164.47, and
repairing it means reconstructing text, so it stays a finding for its own change.
