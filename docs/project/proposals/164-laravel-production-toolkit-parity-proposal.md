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
and its own slice rather than a half-fix here. **Repaired in §164.52**, which adds
the five columns from the package side, guarded so a deployment sees a no-op.

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

### A reported defect, checked and not found

This section originally recorded the tail of §164.46 as corrupted on
`alpha-working` — a paragraph and the measurement table spliced together, which is
what a bad conflict resolution looks like. Checked again on `da9a658447`: §164.46
is byte-identical to the version #8285 introduced, every measurement table in the
document has a valid separator row, and no line glues prose to a table row. The
report was wrong, or the splice never reached the file; either way there is nothing
to reconstruct, and saying so is better than leaving a defect on the record that
cannot be found.

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

## 164.49 The four near-closed toolkits, and the one that was not an alias

**On the numbering:** §164.48 is the `paper-store` slice, which merged ahead of
this one; the two are independent, so this section simply takes the next free
number.

### What a sweep is for

Four toolkits were each one or two slugs from closing, and the cheap assumption
was that all of them were aliases of something already served. That assumption
was wrong once, in the way that matters.

### `toolkit_cpt` is a capability, not a synonym

The first reading of the WordPress tool was "generic CPT CRUD, and this runtime
has no CPTs", which would have made it a decline. It is not: the Pro toolkits'
entities live here as **typed content records** through `ContentStoreInterface` —
`mcp_ai_project`, `mcp_ai_task` and the rest are the toolkit tables, exactly as
the CPTs are on WordPress. `save_post` even takes `post_type` plus arbitrary
`meta_input`, so the *shape* of the data was never the gap.

The gap was in what could be asked. Measured against the served tools, five of
the eight actions already existed:

| action | already served by |
|---|---|
| `get_schema` | `get_post_type_schema` |
| `create_item` / `update_item` | `save_post` (`post_type` + `meta_input`) |
| `get_item` | `get_post` |
| `delete_item` | `delete_post` |

Three did not, and they are the reason the tool was built:

- **`list_types`** — nothing enumerates the registered types. `get_post_type_schema`
  *requires* a type, so an agent that does not already know the slug has no way to
  discover it. This is the discovery step the whole workflow starts from.
- **`list_items` without a search term** — `search_content` requires `query`,
  hard-codes `publish`, and cannot filter by meta. "Tasks assigned to 42" is not
  expressible there.
- **`bulk_create` for any type** — `bulk_import` handles tasks and projects only.

Keeping all eight actions rather than only the three is deliberate: the tool's
value is one discoverable surface for an agent that learned the WordPress one, and
a surface with holes in it sends that agent back to guessing.

### Where this port refuses and the reference normalises

This is the part worth reading. The WordPress tool coerces silently at four
points, and each coercion answers a different question than the caller asked:

| reference behaviour | this port |
|---|---|
| unknown `compare` → `=` | refused, naming the 14 operators that exist |
| unknown meta `type` → `CHAR` | refused, naming the 9 types |
| meta key stripped to `[A-Za-z0-9_]` (`my-field` → `myfield`) | refused — a renamed field is a different field |
| unknown `orderby` → `date` | refused; `menu_order` and `rand` are absent from the schema because the store cannot sort by them |

The last one is the same rule the image tools follow: a parameter that is accepted
and then ignored is worse than one that is not offered. `date` is refused for the
same reason — this runtime stamps record dates from the store and has no way to
set them, so accepting it would be a silent drop.

### The three that are genuinely blocked

| toolkit | slug | prerequisite |
|---|---|---|
| `architectural-design` | `generate_architectural_drawing` | a shared image-generation seam — its value is the structured-prompt compiler, and porting it today means a fourth copy of provider plumbing plus a second raster→SVG step the image tools already serve |
| `vector-storage` | `prepare_file_for_vector_store` | an office-document reader — the runtime has the OCR and PDF half, but nothing parses XLSX/PPTX |
| `document-generation` | `get_uninvoiced_orders` | an order model with an invoiced marker; there is no Order or Invoice entity here at all |

Each is deferred rather than declined because the capability is worth having once
the prerequisite exists. A deferral is a statement about ordering, not a decision
to do less.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,961 / 19,242** (28 tests, plus 2 manifest data sets) |
| adapter PHPUnit | **448 / 2,146**, OK — the new row constructs |
| tool readiness audit | **320 / 320**, servable **301** (+1), no violations |
| toolkit parity | **99 of 854**; four toolkits closed, three deferrals recorded |
| licence boundary / phpcs | intact / clean |

`infrastructure` closes at 1 of 1 ported. `architectural-design`,
`vector-storage` and `document-generation` close at 0 remaining through their
recorded deferrals, which is the arithmetic the register is designed to make
visible: a deferred slug stays counted until someone builds the prerequisite, and
these three are now documented rather than merely unexamined.

## 164.50 OKF gains a locator, an enrichment writer and a router

**On the numbering:** §164.49 (`toolkit_cpt`) merged ahead of this one and
§164.51 (`capture`/`vault`/`remote-connections`) after it; the three are
independent, so this section keeps the number it was written with and sits
between them.

### A stale finding, corrected

An earlier note on this programme said "OKF has no writer". That is no longer true
and had not been for a while: `OkfWriter` exists with `writeConcept()`,
`deleteConcept()`, `appendLog()` and `regenerateIndex()`, and `OkfReader` parses
what it writes. What was actually missing was smaller and structural: **nothing
maps a bundle *name* to a bundle *root*.** On WordPress that mapping belongs to
`WP_MCP_AI_OKF_Bundle_Manager`; here every caller would have had to know that a
bundle is a directory under some base.

So the slice is three pieces and a binding:

| piece | why |
|---|---|
| `OkfBundleLocatorInterface` + `FilesystemBundleLocator` | the name → root seam, with bundle names validated as one path segment |
| `OkfEnrichSiteContentTool` | crawl published records, write one concept each |
| `HybridKnowledgeRouter` + `RouteKnowledgeQueryTool` | decide which store answers a knowledge question first |

### Creating a bundle is explicit

`rootFor( $bundle, $create )` creates a directory only when the write path asks.
A read path that silently created an empty bundle would answer "no matches" for a
typo — the same failure the Paper Store export avoids — so a missing bundle is
`null` on the read path and a directory on the write path.

### Enrichment: identity is the idempotence

A concept's id is `{type}/{slug}`, so re-running the tool overwrites the same file.
That is the whole of the reference's idempotence claim; there is no diff and no
merge, and a changed description simply wins.

**The one thing this host cannot supply is a URL.** WordPress fills `resource` and
`sources[].resource` from `get_permalink()`. A `ContentItem` here carries a slug
but no public URL, and a guessed permalink shape would put a wrong link in an
authoritative document. So the caller may pass a `resource_template`
(`https://example.com/{type}/{slug}`, with `{type}`, `{slug}` and `{id}`
substituted), and when it does not, both the `resource` key and the `# Related`
section are **omitted** and the summary reports `resource_links: false`. Related
links are omitted with it rather than written pointing at nothing.

Descriptions are deterministic — the excerpt, or the first 40 words of the body.
The WordPress tool exposes a filter that replaces it with a model summary; that is
a deliberate absence here rather than a missing feature, because one model call per
record is a different cost profile and a caller who wants it can summarise first.

Taxonomy terms follow the reference's shape (`terms/{taxonomy}/{slug}`, `type:
Term`), enumerated through `SchemaStoreInterface::listTaxonomies()` and
`ContentStoreInterface::listTaxonomyTerms()`.

### Routing: a keyword table, not a model

`HybridKnowledgeRouter::classify()` is the WordPress signal table ported
literally: three lists of substrings, matched against the lowercased query, then an
ordered plan. Routing happens on every knowledge turn, so spending a completion to
decide where to spend a completion is the wrong trade; a caller that wants a
smarter decision can implement its own router.

The order matters and is not alphabetical: matched sources come first in
`okf, paper, vector` precedence, then the unmatched ones fill in as
`okf, vector, paper`. When nothing matches, the plan is still produced — curated
knowledge first, semantic second, structured last — and `signals` is empty, which
says "nothing fired" rather than hiding it.

**A missing bundle is a note, not a failure.** If the plan says OKF and the bundle
does not exist, the routing decision the caller asked for is still correct. The
tool returns the plan intact with the empty `results` explained, instead of
discarding the useful half.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,980 / 19,331** (15 tests, plus 4 manifest data sets) |
| adapter PHPUnit | **448 / 2,148**, OK — both new rows construct |
| tool readiness audit | **322 / 322**, servable **303** (+2), no violations |
| toolkit parity | **101 of 854**; `okf` **2 of 2**, 0 remaining |
| licence boundary / phpcs | intact / clean |

Re-measured on the rebased tree, after §164.49 and §164.51 merged: the parity
figure now includes `toolkit_cpt`, and the suite counts include its rows and
tests. The `(+2)` and the 15 tests are this slice's own delta; §164.51 changes
no runtime code, so it moves none of these gates.

### What is still queued from the same recommendation

`capture` (2 slugs) needs a browser contract — the media worker already exposes
`/api/browser`, but nothing in core can reach it. `vault` (2) needs an encrypted
store; there is no vault backend of any kind here. `remote-connections` (2) is the
largest of the four and has a partial analogue already (`query_remote_site` plus
`RemoteSiteStore`), so it needs a coverage comparison before a port or a decline.

## 164.51 The last three near-closed toolkits, and where they actually stop

**On the numbering:** §164.49 (`toolkit_cpt`) and §164.50 (OKF) merged ahead of
this one. This section keeps the number it was written with rather than
renumbering.

### A delivery gap that looks like a capability gap

`capture` was the most promising of the three, because the media worker already
renders pages: `/api/browser/screenshot` is a hardened Puppeteer call behind
`validatePublicUrl()` — a real SSRF guard — and it accepts a URL, a viewport,
`fullPage` and a format. That is the whole of `capture_webpage_screenshot`.

What it does not do is hand the bytes back. The route writes the image on the
worker's own volume and answers `{output_path, size, format}`, while every other
binary route in the worker (`/api/image/*`, `/api/pdf/*`) returns `b64`, and there
is **no download route for `/browser`**. A tool built on it today would return a
path that its own host cannot open.

That is worth recording precisely, because it is a small fix wearing the costume
of a large one: either `b64` on the response or a `/api/browser/download/:name`
route, plus a `BrowserCaptureInterface` over it. Until then the slug is deferred
rather than built into a lie.

`web_browser` stops one step earlier still: six of its seven actions are a
*sequence* on one page (`navigate` then `click`, `type`, `submit`, `extract`), and
the worker's route is a one-shot render with no session. A scripted step list the
worker executes inside one request would satisfy it; nothing today can.

### The connection store exists, but not where a tool can reach it

`remote_wp_connection` looked like the largest job of the three — 2,900 lines and
14+ named queries. The substrate is better than expected:
`App\Support\RemoteSites\RemoteSiteStore` already has `all()`, `find()`,
`create()`, `update()`, `delete()`, `credentialsFor()` and `recordTest()`, and
`query_remote_site` is already served.

The gap is a seam and a vocabulary. That store is **app-local**, not a core
contract, so a portable tool cannot depend on it; and `query_remote_site` takes a
site URL and an endpoint *per call*, so it offers neither the named queries
(`get_posts`, `get_wc_orders`, …) nor stored credentials — an agent must already
know each REST path. A port needs a core contract over that store plus an
action-to-endpoint map; the transport is the only part already done.

`remote_shopify_connection` has no analogue at all: nothing here speaks Shopify.

### `vault` has nothing to port onto

Both `vault_access` and `vault_manage` read and write encrypted records — passwords,
secure notes, cards, identities — in folders. Core has no vault contract, the app
skeleton registers no vault model or table, and there is no key-management story.
Building the tool on plain options would defeat the point of the tool, so the pair
is deferred together: management without a store has nothing to manage.

### What this closes, and what it does not

All three toolkits now read **0 remaining** — through deferral, which the register
counts while still showing it in its own column. That is the distinction the
`Remaining` column exists to make: these slugs are *dispositioned*, not *done*, and
each carries the prerequisite that would unblock it.

| toolkit | slugs | disposition |
|---|---|---|
| `capture` | `capture_webpage_screenshot`, `web_browser` | deferred — bytes never reach the host; no browser session |
| `vault` | `vault_access`, `vault_manage` | deferred — no encrypted store |
| `remote-connections` | `remote_wp_connection`, `remote_shopify_connection` | deferred — app-local store, no core contract; no Shopify client |

### Measurements

| gate | value |
|---|---|
| toolkit parity | **99 of 854**; 7 declined, **16 deferred**, **732 remaining** |
| tool readiness audit | **320 / 320**, servable **301**, no violations (unchanged: this slice adds no tools) |
| `lib/core` / adapter PHPUnit | **2,961 / 19,254** and **448 / 2,146**, unchanged (two register files, no runtime code) |
| licence boundary | intact |

The parity gate was exercised again after the edit: an unknown decision word still
fails the check, so these six rows are being read by the same machinery that
validates the rest.

## 164.52 The file registry a package-only install could not write

**On the numbering:** §164.46 recorded this defect and deliberately left it for its
own change. This is that change, and the number is simply the next free one.

### What was broken

`2026_01_01_000006_create_nvoos_files_table` creates the table with six columns:
`path`, `disk`, `filename`, `mime_type`, `size_bytes`, `owner_id`. Five more
arrived later — `tenant_id`, `visibility`, `checksum`, `metadata`, `slug` — and each
landed in the **app skeleton's** migrations, because each shipped alongside an
app-side reader.

`FileStore::insertRow()` writes all five of them. On a deployment the skeleton's
migrations run and fill the gap, so nothing was ever visibly broken. On an install
that has only this package, nothing does, and the first `store()` throws on an
unknown column. The adapter's own Testbench app is exactly that install — it calls
`loadMigrationsFrom()` on this package and on nothing else — so the failure was
reproducible without a deployment, and had simply never been asserted.

### The repair

A second package migration,
`2026_11_26_100000_add_file_identity_columns_to_nvoos_files`, adds the five columns
and then the composite `(tenant_id, slug)` unique index. The definitions mirror the
skeleton's migrations — `visibility` defaults to `public`, `tenant_id` to `default`,
`checksum`/`slug`/`metadata` are nullable — so a table built by either path is the
same table, which is the property that made a package-side migration safe to add
rather than a second, divergent shape.

Every step is guarded by `hasColumn()`/`hasIndex()`. On a deployment the skeleton
has already added the columns, and this migration runs after it by timestamp
(`2026_11_26` against the skeleton's `2026_11_23`), so the guards make it a no-op
instead of a duplicate-column failure. On a package-only install it is the only
thing that runs, which is the point.

### The test that would have caught it

`FileRegistrySchemaTest` runs inside the package-only app. It asserts the five
columns and the index exist, then stores a real file through `FileStore` and reads
it back by id. With the migration removed, both tests fail — one on the insert, one
on the schema assertion — so the pair is not vacuous.

### Measurements

| gate | value |
|---|---|
| adapter PHPUnit | **450 / 2,164** (2 added), OK |
| `lib/core` PHPUnit | **2,980 / 19,331**, unchanged |
| tool readiness audit | **322 / 322**, servable **303**, unchanged (no tool lands here) |
| toolkit parity | **101 of 854**; no row moved |
| licence boundary / phpcs | intact / clean |

## 164.53 The render the host could not retrieve

**On the numbering:** §164.51 recorded this as a delivery gap and left it for its own
change; §164.52 (the package migration) merged ahead of this one. This is that
change, and the number is the next free one.

### What was missing, in one line

`POST /api/browser/screenshot` rendered the page, wrote the image to the worker's
own volume, and answered `output_path`. The worker exposes no download route for
`/browser`, so a host could ask for a picture it had no way to receive. That is why
`capture_webpage_screenshot` and `web_browser` were deferred rather than ported: a
tool that returns a path its own runtime cannot open is a lie with a URL attached.

### Four pieces, each small

| piece | why it exists |
|---|---|
| `inline-bytes.js` and two fields on the route | the screenshot answer carries `b64` and `mime`; past 24 MiB the route refuses with 413 rather than building a payload the host cannot hold |
| `BrowserCaptureInterface` | URL in, bytes out — deliberately the same shape as the route, so the seam maps onto the engine that exists instead of inventing a session |
| `MediaWorkerBrowserCapture` | one JSON POST per capture; the tool's seconds become both the navigation budget and the HTTP budget, so a slow page fails as a navigation timeout rather than a transport error |
| `CaptureWebpageScreenshotTool` | the port itself: eight arguments, five viewports, and the bytes always persisted through the file registry |

The helper lives outside `routes/browser.js` on purpose: that module imports
Puppeteer and Express, so a test that imported it would resolve both. Extracting
`inlineFile()` makes the part that can be wrong — the bytes, the MIME, the cap —
testable with `node --test` and no dependencies. The route wiring itself is still
only proven end to end against a live worker.

### What it refuses, and why each refusal is the point

Three WordPress behaviours are deliberately not reproduced, and all three are
refusals rather than silent drops:

- **`width`/`height` beside a preset.** WordPress accepts them and ignores them.
  Here the pair is refused with the rule in the message, in the same spirit as the
  sweep's `compare` and `orderby` refusals.
- **`quality` on PNG.** Puppeteer rejects the combination, and dropping it quietly
  would look like a quality setting that did nothing.
- **`save_to_media: false`.** WordPress can obey it because an unsaved screenshot
  is still an mshots URL. Here it would mean a multi-megabyte blob in a tool payload
  or nothing at all, so the tool says why it cannot.

There is also no mshots fallback. A third-party thumbnail service returns a
different picture, and calling that a fallback would make the tool's output depend
on a service nobody named.

### What is still not ported

`web_browser` stays deferred. Six of its seven actions act on a page *between*
calls, and a one-shot render cannot hold that page open; the screenshot and PDF
actions are now individually reachable, which is a capability the deferral note
said would arrive separately. The PDF action still has no `b64` on its route — it
gets one when a session-capable `web_browser` has a reason to ask.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **2,999 / 19,391** (17 tests, plus 2 manifest data sets) |
| adapter PHPUnit | **451 / 2,169** (1 probe added) |
| worker (`node --test inline-bytes`) | **3 / 3** |
| tool readiness audit | **323 / 323**, servable **304** (+1), no violations |
| toolkit parity | **102 of 854**; `capture` **1 of 2** ported, 1 deferred, 0 remaining |
| licence boundary / phpcs | intact / clean |

## 164.54 `eca-management`'s remaining 35, measured before they are built

**On the numbering:** §164.52 (the package migration) and §164.53 (page capture)
both merged ahead of this one, and §164.55 — its own register half — follows it.
The number is the next free one rather than a renumbering of anything. It is the coverage comparison `toolkit_cpt`
taught us to write first (§164.49): dispositions and a build order, no register
change, and the parity count untouched at 13 ported and 35 remaining.

### What the ported 13 already gave us

The first ECA batch was not a stub. `mcp_ai_eca` and `mcp_ai_student` are content
types on `ContentStoreInterface`, and the rows carry the whole activity shape:

| kind | keys already written and read |
|---|---|
| identity | `_eca_code`, `_eca_status`, `_eca_data` |
| time | `_eca_day`, `_eca_start_time`, `_eca_end_time`, `_eca_sessions` |
| place | `_eca_venue` |
| people | `_eca_teachers`, `_eca_max_students`, `_eca_current_enrollment`, `_eca_enrollments` |
| money | `_eca_cost`, `_eca_cost_period`, `_eca_currency`, `_eca_is_paid` |
| grouping | `_eca_term`, `_eca_booking_type` |
| attendance | `_eca_attendance_log` on the ECA row (`mark_eca_attendance`) |
| student | `_student_email` and the student row's own fields |

That table is the comparison's whole result: **fourteen of the thirty-five need no
new model**, because the data they read and write is already stored by the tools
that were ported. The remaining twenty-one need something that does not exist yet,
and each names what.

### Portable now — fourteen, and what each touches

| slug | reads or writes |
|---|---|
| `delete_eca` | `ContentStoreInterface::delete`; WordPress' soft "cancel" is a `_eca_status` write, which is the separate half of the same tool |
| `delete_student` | the same store; WordPress itself warns it does not unenroll, so neither does this |
| `get_eca_attendance_report` | `_eca_attendance_log`, filtered by date range and student |
| `get_eca_timetable` | `_eca_day`, `_eca_start_time`, `_eca_end_time`, `_eca_sessions`, `_eca_venue`, `_eca_teachers` |
| `get_student_participation_summary` | enrollments across ECAs plus each ECA's attendance log |
| `generate_eca_participation_report` | the same two sources, shaped as a report |
| `generate_eca_analytics` | participation, capacity and attendance aggregates over the same rows |
| `check_eca_conflicts` | overlap arithmetic over `_eca_sessions` / day / times / `_eca_venue` — no new storage |
| `set_eca_schedule` | writes `_eca_sessions` (the multi-session shape already exists) |
| `manage_eca_term` | terms are a small enumerated list in WordPress (an option, `wp_mcp_ai_eca_terms`); here they belong in `SettingsStoreInterface`, and the ECA link is the existing `_eca_term` meta |
| `export_eca_data` | CSV over ECAs, students, enrollments, attendance and the `_eca_cost`/`_eca_is_paid` fields; the writer is `fputcsv` and the file lands in the registry |
| `import_ecas_csv` | the same in reverse: a `file_id`, `fgetcsv`, create-or-update by `_eca_code` |
| `send_eca_notification` | `EmailServiceInterface`, which is bound; recipients are `_student_email` |
| `send_eca_parent_report` | the participation report plus the same sender |

Two divergences are named rather than discovered later. First, **there is no
separate guardian record** — WordPress' "parents" are extra addresses on a student
this runtime does not model, so both sending tools address the student email and
say so in their description. Second, **`export_eca_data`'s financial block is a
derivation**, not a payments table: cost, currency and paid flags exist; a
transaction ledger does not.

### Declined — one

`research_eca` is a provider prompt preset: it calls the model client with a
composed research prompt. The runtime already serves `deep_research` and
`web_search`, and the WordPress tool's own guidance points at them. A third name
for "research this topic" is the `text_to_image_prompt_optimizer` case — declined
because no prerequisite would make the duplicate worth a slot.

### Deferred — twenty, in three groups

| group | slugs | prerequisite |
|---|---|---|
| Google Classroom | `create_classroom_course`, `update_classroom_course`, `list_classroom_courses`, `list_classroom_guardians`, `list_classroom_submissions`, `create_classroom_coursework`, `post_classroom_announcement`, `manage_classroom_push_watch`, `classroom_course_analytics`, `link_classroom_course_to_eca`, `sync_classroom_courses_to_ecas`, `sync_classroom_roster_to_students` (12) | A Classroom API client with OAuth and a course↔ECA link store. `link_classroom_course_to_eca` is the keystone; every other Classroom slug assumes that link exists. |
| iSAMS / SOCS | `isams_query`, `sync_students_from_isams`, `sync_ecas_from_isams`, `sync_eca_enrollments_from_isams`, `sync_ecas_to_isams`, `sync_ecas_from_socs` (6) | A school-MIS client with per-institution credentials. SOCS is a different vendor from iSAMS, so it is a second client, not a flag on the first. `_eca_isams_sync_id` exists as a field, which makes the sync writable but not the transport. |
| Notification rules | `configure_eca_notifications`, `create_eca_workflow_rule` (2) | An event→action rule store with ECA events. The adapter has a `nvoos_triggers` table but no tool surface and no emitters for enrollment/attendance changes, so a rule could be stored and never fired — which reads as a silent no-op rather than as a missing capability. |

### The order the comparison implies

1. **The two deletes** — smallest, and they finish the CRUD the first batch left open.
2. **The reads over existing data** — timetable, attendance report, participation summary.
3. **The aggregates** — analytics, participation report, conflicts, export.
4. **The writers that name a store** — schedule, term (settings), import.
5. **The two senders**, which depend on the report shapes from 2–3.
6. The three deferred groups wait for their prerequisites, and the register records
   them there rather than counting them as unexamined work.

Steps 1–5 are one slice each if the earlier rhythm holds; step 4 is the one that
needs a decision (§`SettingsStoreInterface` for terms) before code.

## 164.55 The ECA dispositions reach the register

**On the numbering:** §164.54 (the comparison) is open in its own change, so this
section takes the next number; it is the register half of that section and changes
no code.

### What moved

Twenty-one entries, in the shape §164.51 established: one declined, twenty
deferred, each naming its own prerequisite rather than repeating a group label.

| toolkit | before | after |
|---|---|---|
| `eca-management` | 13 ported, 0 declined, 0 deferred, **35 remaining** | 13 ported, **1 declined**, **20 deferred**, **14 remaining** |
| all toolkits | 102 ported, 7 declined, 15 deferred, **730 remaining** | 102 / **8** / **35** / **709** |

The fourteen that remain are exactly the "portable now" set §164.54 identified:
the deletes, the reads over `_eca_attendance_log` and the schedule fields, the
aggregates, the CSV pair, the term and schedule writers, and the two senders. They
are now visible as the toolkit's remaining work instead of being hidden inside a
count that also contained two dozen external integrations.

### Why the one is declined and the twenty are not

`research_eca` composes a research prompt and calls the model client. `deep_research`
and `web_search` already serve that capability and the WordPress tool's own guidance
points at them, so no prerequisite would make a third name worth a slot.

The twenty are deferred because each *is* a capability with a named blocker: a
Classroom client and the keystone course-to-ECA link, two school-MIS clients
(iSAMS and SOCS are different vendors), and an event-to-action rule store with
emitters. Declining them would say the capability is unwanted, which is not what
§164.54 found.

### The gate was exercised

The register is read by `bin/toolkit-parity.php --check`, so the anti-vacuity check
was repeated after the edit: an injected `"decision": "soon"` fails the check, and
the restored file is byte-identical. `--write` then refreshed the baseline.

### Measurements

| gate | value |
|---|---|
| toolkit parity | **102 of 854**; 8 declined, 35 deferred, **709 remaining**; `eca-management` 13/1/20/**14** |
| `lib/core` PHPUnit | **2,999 / 19,433** — tests unchanged; assertions rise by 42 because the register checks now read 21 more rows |
| tool readiness audit | **323 / 323**, servable **304**, unchanged (no tool) |
| licence boundary | intact |

The adapter suite is not implicated: it reads the readiness manifest, not the
decisions register.

## 164.56 The two deletes, and the number WordPress leaves as prose

**On the numbering:** §164.54 (the comparison) is open in its own change and
§164.55 (the register) merged ahead of this one; this is the first code slice the
comparison's order named.

### What was built

`delete_eca` and `delete_student`, the two slugs the first ECA batch left out of CRUD.
Both run on `ContentStoreInterface`'s existing types (`mcp_ai_eca`, `mcp_ai_student`),
so neither adds a model, a table or a contract.

### The cascade runs in one direction on purpose

`delete_eca` writes each student's side first and the activity last. A cascade that
fails halfway then leaves an activity listing students who no longer list it —
visible, and repairable by deleting again. The reverse order would leave students
enrolled in an activity that no longer exists, which nothing can repair from the data
that remains. `withdraw_student_eca` uses the same ordering, and the test that proves
it fails the second student write and asserts the activity is untouched.

The refusal is WordPress's rule: an activity with active enrolments answers
`confirmation_required` naming the count, before any write.

### Two divergences, both measured, neither silent

**Cancelling clears the roster and the counter.** WordPress removes each student's
side and leaves `_eca_enrollments` and `_eca_current_enrollment` on the cancelled
activity, so `list_ecas` reports twelve enrolled on a cancelled club whose students
are gone, and a later enrol refuses on capacity. Two values that contradict reality
are worse than one clear state, so this port writes `_eca_enrollments = []` and
`_eca_current_enrollment = 0` alongside `_eca_status = cancelled`.

**`delete_student` replaces a warning with a number.** WordPress deletes the record
and says, in prose, that it does not unenroll the student. This port keeps the
behaviour and reports what it left behind: `enrollments_left`, `eca_ids` and
`eca_names`, from the **union** of the student's own `_student_eca_enrollments` and
the rosters that still list them. Reporting one side would hide exactly the rows a
cleanup needs, because the two sides can disagree. The scan is one page of 200, the
same ceiling `list_ecas` uses, and `truncated` says when the count is a lower bound.

`_eca_cancelled_at` is written as UTC `Y-m-d H:i:s`. WordPress writes
`current_time( 'mysql' )`, a local-time string with no offset, which is not a
timestamp this runtime can compare against anything.

The capability is `delete_posts` for both, following WordPress's execute-time guard
rather than the declared `edit_posts` on its schema — a delete keeps the stricter one.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,012 / 19,480** (13 tests added) |
| adapter PHPUnit | **453 / 2,179** (2 probes added), OK |
| tool readiness audit | **325 / 325**, servable **306** (+2), no violations |
| toolkit parity | **104 of 854**; 8 declined, 35 deferred, **707 remaining**; `eca-management` **15 ported**, 1 declined, 20 deferred, **12 remaining** |
| licence boundary / phpcs | intact / clean (6 files) |

## 164.57 The three reads, and a roster key that was not the live one

**On the numbering:** §164.56 merged ahead of this one. This is step 1 of the order
§164.54 named.

### A defect in the slice that had just merged

`delete_eca` read the roster from `_eca_enrollments`. **Nothing in this family writes
that key.** `enroll_student_eca` writes `_eca_student_enrollments` on the activity and
`_student_eca_enrollments` on the student, and `EcaRow::breakdown()` documents the trap
in as many words and prefers the live key. On real data the confirmation still fired,
because it also reads `_eca_current_enrollment` — but the cascade found nobody: the
activity was removed and every student still pointed at it. That is precisely the
half-state the student-first ordering was built to prevent.

The unit test passed because its fixture wrote the key the tool read. A fixture that
agrees with the reader cannot find a reader that disagrees with the writer.

The fix reads through `EcaRow::enrollments()` with the legacy key as a fallback, clears
both names on cancel, and rewrites the fixture to the live key. Reverting the fix now
fails two tests — the confirmed delete and a new legacy-key case — so the fixture can
no longer pass a broken reader.

### The three reads

| tool | reads | adds |
|---|---|---|
| `get_eca_timetable` | day, times, venue, teachers, roster | four views, grouping and sorting, overlap detection |
| `get_eca_attendance_report` | `_eca_attendance_log` | totals, a rate, per-student rates, Chart.js series, per-session breakdown |
| `get_student_participation_summary` | `_student_eca_enrollments` + each log | per-activity attendance, overall rate, engagement score |

`AttendanceLog` is the one shared piece: date-keyed normalisation, attendee extraction
and the rate formula. Three copies of `(present + late) / total` is three chances to
drift the day one tool learns a new status.

### Interpretations worth naming

- **`week_of` labels, it does not filter.** The schedule is weekly and recurring; there
  is no dated occurrence to filter by. It is validated as a date and echoed, and the
  description says it is a label rather than implying a filter that does nothing.
- **Overlap is minute arithmetic.** `strtotime('15:30')` resolves against *today*, so
  the same timetable would compare differently tomorrow. Times are parsed to minutes
  since midnight instead.
- **Two different questions, both answered.** `overall_attendance_rate` is records-based;
  `engagement_score` is the mean of the per-activity rates, which weights a one-session
  club like a thirty-session one. WordPress asks both and they are not interchangeable,
  so the payload keeps them apart.
- **`skipped`.** An enrolment naming a deleted activity, or one outside the term filter,
  is counted, so a total of three against a map of five is explained rather than odd.
- **Filters are applied in PHP over one page of 200 activities.** A meta query cannot
  express `LIKE` over a serialised list; `truncated` marks the ceiling.

### A claim corrected before it shipped

An early draft of `AttendanceLog` and of the participation summary asserted that
WordPress divides attendee counts by session counts — a rate that could exceed 100%.
Re-reading both handlers, `present` and `sessions_recorded` are each counted **per
student**, so the formula is right and the divergence was mine. Both docblocks were
corrected before the slice shipped: the shared helper exists to keep one formula, not to
repair a bug that is not there. It is recorded because a wrong claim in a docblock
outlives the branch that made it.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,028 / 19,538** (15 tests added) |
| adapter PHPUnit | **456 / 2,198** (3 probes added), OK |
| tool readiness audit | **328 / 328**, servable **309** (+3), no violations |
| toolkit parity | **107 of 854**; `eca-management` **18 ported**, 1 declined, 20 deferred, **9 remaining** |
| licence boundary / phpcs | intact / clean (8 files) |

One tooling note for whoever writes the next slice: this terminal halves backslashes in
heredoc content, so a `	rim(` arrived as a tab followed by `rim(`. The suite caught it
as an undefined function; every new file was then scanned for stray control bytes and
the damaged call repaired.

## 164.58 The four aggregates, and three helpers that stopped drifting

**On the numbering:** §164.57 merged ahead of this one. This is step 2 of the order
§164.54 named, and it leaves `eca-management` at five remaining.

### What shipped

| tool | question it answers |
|---|---|
| `check_eca_conflicts` | would this slot collide, before anything is written |
| `generate_eca_analytics` | participation, capacity, financial, engagement |
| `generate_eca_participation_report` | one activity, one student, a year group or the school, as JSON or markdown |
| `export_eca_data` | activities, students, enrolments, attendance or financial rows as a CSV |

### Three helpers, because four tools ask the same questions

- **`Schedule`** — minutes since midnight, strict overlap, the seven days. The
  timetable and the conflict check now share it; the timetable's private copy is gone.
- **`EcaFilter`** — list / CSV / single-name matching and the live-key roster read.
  The timetable uses it too, so a teacher view and a teacher conflict check cannot
  disagree about who teaches what.
- **`AttendanceLog::forStudent()`** — the per-student reader, pulled out of
  `get_student_participation_summary` when the report needed the same numbers. The
  summary keeps WordPress's `sessions_recorded` key by mapping the shared `records`,
  rather than renaming a payload key two tools had already published.

### Decisions that are visible in the payloads

- **The export is private by default.** It names children. A public default would
  put attendance and financial rows on a web-served path, which is the one mistake
  an export tool must not make quietly.
- **Money is a projection and says so.** `financial` exports `projected_revenue`
  (cost × enrolments) and repeats the caveat in the payload: there is no payments
  ledger here, and a column called "revenue" that is really "expected" would be read
  as receipts.
- **`term` is free text.** WordPress restricts it to four values; `_eca_term` stores
  whatever `manage_eca_term` wrote, so an enum would refuse real terms the data holds.
- **The export is capped at 20,000 rows** with `truncated`, because exporting half a
  term's attendance as if it were all of it is the failure a flag exists to prevent.
- **The conflict check names its blind spot.** A clean answer means nothing this
  runtime stores occupies the slot; a room booking held elsewhere is invisible.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,040 / 19,589** (24 tests added across the two commits) |
| adapter PHPUnit | **460 / 2,234** (4 probes added), OK |
| tool readiness audit | **332 / 332**, servable **313** (+7), no violations |
| toolkit parity | **111 of 854**; `eca-management` **22 ported**, 1 declined, 20 deferred, **5 remaining** |
| licence boundary / phpcs | intact / clean (12 files) |

The `\t` half of the heredoc hazard recorded in §164.57 recurred — `\file` arrived as a
form feed — so every file in the slice is now scanned for stray control bytes before
gates run, not just the one that failed.


## 164.59 The five writers, and four refusals WordPress does not make

`eca-management` closes with this slice: **27 ported, 1 declined, 20 deferred, 0
remaining**, from 48 declared slugs. The five that were left were the ones with a
side effect — a timetable, a term state machine, a file import, two senders — and
each of them had at least one WordPress behaviour that is not worth reproducing.

| slug | class | what it writes |
|---|---|---|
| `set_eca_schedule` | `SetEcaScheduleTool` | `_eca_sessions`, plus the first session mirrored into `_eca_day` / `_eca_start_time` / `_eca_end_time` / `_eca_venue` |
| `manage_eca_term` | `ManageEcaTermTool` | the `eca_terms` setting; on transition, `_eca_term` and `_eca_attendance_log` on the outgoing activities |
| `import_ecas_csv` | `ImportEcasCsvTool` | activities through `ContentStoreInterface`, created or matched by `_eca_code` |
| `send_eca_notification` | `SendEcaNotificationTool` | email, then `_eca_notification_log` |
| `send_eca_parent_report` | `SendEcaParentReportTool` | email, then `_student_reports_sent` |

### The four refusals

**A timetable that collides with itself is refused.** WordPress validates one
session at a time, so a payload booking the same room twice on the same day is
saved and then reported by the timetable as a conflict the caller already knew
about. The port refuses it, naming both session numbers. Back-to-back is not a
collision: `Schedule::overlaps()` keeps the strict inequalities, so 09:00–10:00
and 10:00–11:00 save cleanly.

**An unknown recurrence is refused, not rewritten.** WordPress falls back to
`weekly` for anything outside the three it knows, so a caller that typed
`fortnight` gets a weekly activity and no signal. Here it is `validation_failed`
naming the accepted values. The schema already declares the enum; this refusal
only fires for a caller that bypassed schema validation, which is the caller that
needs to hear it.

**A caller-supplied URL is refused.** WordPress imports from `file_url` by
fetching it server-side. The port reads a `file_id` from the file registry under
an ownership check and refuses `file_url` with a message naming `file_id`. This
is the same SSRF surface the runtime declines everywhere else; an importer is not
a reason to open it.

**A duplicate code inside one file is refused.** WordPress processes rows in
order, so two rows sharing an `eca_code` either create two activities
(`update_existing` off) or update the same record twice (on) — silently
overwriting the first row. Here the second row is skipped and named in `errors`,
because a duplicate code is a defect in the file, not a precedence rule.

### Terms: the name is the join key

WordPress links an activity to a term twice: `_eca_term` (free text) and
`_eca_term_id` (the generated id). This runtime writes only `_eca_term`, so the
term **name** is what `get` counts and what `close` deactivates. Two consequences
are on the record: creating a second term with the same name is refused (two
terms joining to the same rows make every count ambiguous), and an operator
migrating a WordPress install copies the option array into the `eca_terms`
setting unchanged — the shape is identical.

### Rollover retags instead of snapshotting

WordPress copies each roster into `_eca_student_enrollments_{term_id}`, a key
nothing reads. The port does not create it. With `rollover_enrollments` the
activities carrying the outgoing term are retagged with the incoming term's name,
which is the thing the term filter in `generate_eca_participation_report`
actually reads.

### The attendance reset is scoped, counted and irreversible

`reset_attendance` keeps its WordPress default of true. WordPress deletes
`_eca_attendance_log` on **every** published activity with one boolean. The port
scopes the delete to the activities that belonged to the term being completed,
reports the count in `attendance_reset` and the scope in `attendance_scope`, and
when there is no outgoing term it says `all_activities` before doing it. The log
holds dated totals with no per-session provenance, so a cleared log cannot be
reconstructed — which is exactly why the scope must be visible in the result.

### The two senders

Both go through `EmailServiceInterface` and report per address. Three details
worth keeping:

- **An untyped roster row counts as confirmed.** WordPress requires
  `enrollment_type === 'confirmed'` and therefore skips a row written by an
  importer; the port reads an absent type as confirmed and says so.
- **The parent address is a second message, not a second header.** A parent with
  no address is reported as `no_parent_email` rather than dropped.
- **The notification body reads what is stored.** WordPress reads `_eca_time`, a
  key its own create tool never writes, so its Time row is always empty; the port
  reads `_eca_start_time` and `_eca_end_time`.

The log rows are additive over WordPress: `_eca_notification_log` gains
`failed_count`, and `_student_reports_sent` gains `recipient_role`. A log that
records only successes cannot answer whether the cancellation notice reached the
parent whose address bounced.

### The import's ceilings and reported substitutions

Reads are capped at 1 MiB and 500 data rows with `truncated` in the result.
Unknown `eca_type`, `status` and `cost_period` fall back to the same defaults
`create_eca` uses, and the substitution is reported per row — an importer that
quietly changes a value is indistinguishable from one that parsed the column
wrong. Unlike WordPress, a supplied description updates the body of a matched
activity, because an import carrying corrected text should correct it.

### Test-harness note

The adapter's `vendor/` carries a **copy** of `nvoos/core` (a path repository
resolved without a symlink), so a local Docker run against a fresh core tree must
refresh `vendor/nvoos/core` first or every tool added since that copy was made
reports "not registered". CI runs `composer install` per job and does not hit
this. The symptom is precise: every data set from a fixed index onward fails,
which is a stale classmap rather than a registry problem.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,072 / 19,708** (32 tests added) |
| adapter PHPUnit | **465 / 2,269** (5 probes added), OK |
| tool readiness audit | **337 / 337**, servable **318** (+5), no violations |
| toolkit parity | **116 of 854**; `eca-management` **27 ported**, 1 declined, 20 deferred, **0 remaining** |
| phpcs | clean (8 files, 1,208 automatic fixes applied before the gate) |

The `\t` / `\f` heredoc hazard recurred twice in this slice, including
inside a long file that truncated mid-token at line 353. Every file is now built in
short chunks and byte-scanned for 0x09/0x0B/0x0C/0x0D before any gate runs. The
paragraph above §164.58 that the transport had corrupted was repaired in the same
commit.


## 164.60 The Cloudways client, and the four reads that prove it

`cloudways` is the largest remaining toolkit: **60 declared slugs, none ported**.
It is also the most uniform — every tool is a path through the same API with the
same OAuth flow — so the order is inverted from ECA. Here the shared machinery
lands first and alone, and the tools follow it in slices.

This section covers the machinery and **four reads**: `cloudways_list_servers`,
`cloudways_get_server`, `cloudways_list_apps`, `cloudways_get_app`.

### Why the client comes first

The WordPress toolkit is a base class, a client and sixty subclasses. Porting the
subclasses without the client would have copied the credential exchange and the
error taxonomy sixty times. Instead:

| piece | location |
|---|---|
| `CloudwaysClientInterface` | `lib/core/src/Domain/Contract` — four verbs over a path |
| `CloudwaysApiException` | `lib/core/src/Domain/Error` — classified refusal with its HTTP status |
| `CloudwaysClient` | `lib/core/src/Infrastructure/Cloudways` — auth, token cache, error mapping |
| `CloudwaysTool` | `lib/core/src/Tool/Cloudways` — `manage_options`, one failure-to-envelope path |

The tools keep their own paths. That is deliberate: the WordPress tools already
encode the API surface, and a client method per endpoint would be a second copy
that drifts. The client answers "how do I talk to Cloudways", never "what are
the endpoints".

### Credentials and token are the WordPress keys

`cloudways_email`, `cloudways_api_key`, `cloudways_access_token`,
`cloudways_token_expires_at`, `cloudways_connected`, `cloudways_connection_time`.
On WordPress both clients write the same `wp_mcp_ai_settings` option, so a site
connected through either runtime is connected for both, and a site that migrates
between runtimes needs no credential work. The token refresh keeps the
sixty-second safety margin.

The adapter binds the client as a **singleton**: the token lives in settings, so
a per-resolution instance would exchange the credentials again for every tool
call in one turn. That is the same reasoning as the event dispatcher binding
recorded in the admin-parity plan.

### What the four reads decide

- **The projection is the contract, not the API.** Nine fields for the server
  list, eight for the single server (the list's set minus `app_count`, which the
  single-server response does not carry — a zero would read as "this server has
  no apps"), four for an app. The v2 payload carries more; widening it would
  change a shape that already has consumers, so a caller that needs more asks
  another tool.
- **`cloudways_get_app` requires both ids.** v2 has no per-app route, so the
  read is the collection endpoint with both ids in the query. A request with one
  id returns the whole app list, which reads as a successful lookup; both are
  refused locally instead.
- **An empty list is an answer; a missing list is not.** A PHP-only server has
  no apps, and that is a success with zero rows. A response with no `apps` key
  is `cloudways_invalid_response`, because an empty account and an API change
  are indistinguishable in a count and only one of them is the operator's.
- **A missing id is refused before the request.** WordPress lets Cloudways
  answer with a 404 that does not say which argument was wrong.
- **Rows are read tolerantly.** The WordPress projection calls `absint(
  $server['id'] )` on keys the API may omit, which emits a warning and yields
  zero; the port yields zero without the warning. `ram` stays text, as it is in
  the API and in the WordPress payload.

### Registration

No `includes/bootstrap/oos-bridge.php` change, for the same reason the classroom
family has none: the WordPress runtime already serves these slugs through the
legacy registry's anti-corruption layer, and the Pro implementations stay
authoritative there. The native ports exist for the Laravel runtime, which
builds every manifest `ready` row through the container — hence the binding.

### The remaining 56, in the order they will land

| slice | tools | why this order |
|---|---|---|
| services, operations, labels | 4 | smallest mutation surface; proves `post` against operations that return an operation id |
| servers: lifecycle | 6 | start, stop, restart, clone, delete, create — delete needs the destructive confirmation discipline |
| servers: settings and scale | 4 | settings get/update, scale, scale-volume |
| backups and monitoring | 4 | server/app backups, server/app monitor summaries |
| apps: lifecycle | 5 | create, clone, clone-to-server, delete, update-label |
| apps: settings | 8 | fpm, varnish, cname, cors, credentials, purge-cache |
| apps: restore and analytics | 6 | restore, restore-rollback, mysql, php, traffic, cron |
| ssh keys | 3 | create, list, delete |
| git | 6 | key, generate, clone, pull, branches, history |
| dns | 4 | domains, records, add, delete |
| cloudflare, addons, copilot, projects | 7 | independent of the above |

Sixty slugs is ten slices at the rhythm this programme has been merging at. The
slices are ordered by blast radius, not by count: the reads and the service
restarts land before anything that deletes.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,090 / 19,788** (18 tests added) |
| adapter PHPUnit | **90 / 898** on the two affected files, OK (4 probes added) |
| tool readiness audit | **341 / 341**, servable **322** (+4), no violations |
| toolkit parity | **120 of 854**; `cloudways` **4 ported**, 0 declined, 0 deferred, **56 remaining** |
| phpcs | clean (11 files) |


## 164.61 Services, operations and labels — four tools, no client change

The second `cloudways` slice is the first that needed **nothing added to the
client**: four tools, four paths, the shared base. That is the shape the rest of
the toolkit should have, and it is why the client landed first.

| slug | verb and path |
|---|---|
| `cloudways_service_status` | `GET /service/{server_id}` |
| `cloudways_restart_service` | `POST /service/{server_id}/restart` |
| `cloudways_get_operation_status` | `GET /operation/{operation_id}` |
| `cloudways_update_server_label` | `PUT /server/{server_id}/label` |

### Decisions

- **A service keeps its status string.** WordPress projects `name` and `status`,
  not a boolean. A restarting service is a state a caller polls through, and
  `running: false` would not distinguish "stopped" from "coming back".
- **The five restartable services are a closed list**, as in WordPress, and the
  refusal names all five. The API accepts other names on some stacks; a caller
  that needs `apache2` is on a stack this toolkit does not manage, and forwarding
  an unlisted name turns a capability question into a 500. The name is lowercased
  before the check, so `MySQL` restarts mysql; WordPress refuses the capitalised
  form, which is a spelling test rather than a capability one.
- **The restart payload is the API answer, unchanged.** The restart is
  asynchronous, and the body naming the operation is the only handle a caller has
  to poll. Projecting it into a fixed shape would risk dropping that id if
  Cloudways renames a key.
- **`is_completed` is added to the operation projection.** The v2 payload reports
  it as a tri-state integer — `0` running, `1` finished, `-1` failed — and the
  WordPress tool drops it, leaving a polling caller to string-match `status` with
  no way to see the failure flag. The port adds it as `?int`: the integer when
  reported, `null` when not. Every existing key keeps its meaning.
- **`progress` stays 0 when the API does not report it**, exactly as WordPress.
  The description points a caller at `status` and `is_completed` rather than at
  the number, because zero is "not reported" as often as it is "not started".
- **The operation id is a string and is url-encoded into the path.** Cloudways
  ids are not integers (`flex-79648230`), and an id containing a slash would
  otherwise become two path segments.
- **The label length rule is the API's.** WordPress declares only `minLength: 1`;
  guessing the endpoint's actual bound would refuse a name Cloudways accepts. An
  out-of-range label comes back as the API message with its status. Whitespace is
  trimmed first, so a label of spaces is refused locally instead of stored as a
  blank name.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,101 / 19,828** (11 tests added) |
| adapter PHPUnit | **94 / 918** on the two affected files, OK (4 probes added) |
| tool readiness audit | **345 / 345**, servable **326** (+4), no violations |
| toolkit parity | **124 of 854**; `cloudways` **8 ported**, 0 declined, 0 deferred, **52 remaining** |
| phpcs | clean, no fixes needed |


## 164.62 The order the owner asked for

Fourteen toolkits were named, in this order, as the ones to finish first. The
names are recorded with their manifest ids, because three of them are not the
folder name and a later session should not have to guess.

| # | asked for | toolkit id | declared | ported | declined | deferred | remaining |
|---|---|---|---|---|---|---|---|
| 1 | document generation | `document-generation` | 38 | 37 | 0 | 1 | **0** |
| 2 | image generation | `image-production` | 22 | 12 | 6 | 4 | **0** |
| 3 | crm | `crm` | 13 | 0 | 0 | 0 | **13** |
| 4 | email outreach | `email-marketing` | 7 | 0 | 0 | 0 | **7** |
| 5 | chat channels | `chat-channels` | 50 | 0 | 0 | 0 | **50** |
| 6 | remote sites | `remote-connections` | 2 | 0 | 0 | 2 | **0** |
| 7 | calendar booking | `calendar-booking` | 34 | 0 | 0 | 0 | **34** |
| 8 | food and beverage | `food-beverage` | 23 | 0 | 0 | 0 | **23** |
| 9 | places | `places` | 12 | 0 | 0 | 0 | **12** |
| 10 | regulatory registration | `regulatory-registration` | 59 | 0 | 0 | 0 | **59** |
| 11 | gtmetrix | `gtmetrix` | 23 | 0 | 0 | 0 | **23** |
| 12 | social media | `social-media` | 32 | 3 | 0 | 0 | **29** |
| 13 | orchestration | `orchestration` | 36 | 1 | 0 | 0 | **35** |
| 14 | ecommerce | `ecommerce` | 44 | 1 | 0 | 0 | **43** |

That is **328 of the 687 remaining slugs** — 48% of what is left, in eleven
toolkits that still have work. Three of the fourteen are already at zero.

### Three are already at zero, and one of those is not the same kind of zero

- **`document-generation`** is genuinely ported: 37 of 38, with a single
  deferral. The deferred slug is `get_uninvoiced_orders`, and its recorded
  reason is that this runtime has no order model and no invoiced flag to look
  for. Its prerequisite is the order model that arrives with `ecommerce`
  (§164.58's register), so item 1 closes the last slug when item 14 lands. The
  order is self-consistent: nothing needs to move.
- **`image-production`** is genuinely ported to its dispositions: 12 ported, 6
  declined (batch wrappers and duplicates of tools that already exist), 4
  deferred (local Sharp operations the media worker does not serve).
- **`remote-connections`** is at zero **by deferral**: both slugs are deferred,
  not ported. `remote_wp_connection` is a 2,900-line convenience layer over a
  stored connection; the recorded prerequisite is a core contract over the
  connection store plus the action-to-endpoint map, because `query_remote_site`
  takes a URL per call and offers neither named queries nor stored credentials.
  `remote_shopify_connection` needs a Shopify Admin client and a credential
  store, neither of which exists.

  So "remote sites first" has two readings. If it means *dispositioned*, it is
  done. If it means *working*, the deferral **is** the work, and it is worth
  doing: the store exists, and the missing piece is one contract plus a map, not
  a port. This section records the ambiguity rather than resolving it silently —
  the build is recommended when the owner says remote sites should actually
  answer queries.

### What this changes

- **`cloudways` pauses at 8 of 60.** The client and the eight tools are merged;
  the remaining fifty-two wait behind this list.
- **`crm` is first with real work.** It is also the one that needs measuring
  before it is ported: 13 declared slugs sit on a 38-file engine, the entities
  are custom post types (`mcp_ai_lead`, `mcp_ai_deal`, `mcp_ai_customer`,
  `mcp_ai_crm_activity`, plus companies), which maps to `ContentStoreInterface`
  the way the classroom family does. What needs measuring is not the storage but
  the helpers: two tools lean on `WP_MCP_AI_CRM_Engine` (lead scoring and stage
  history), two on `WP_MCP_AI_CRM_Relevance`, and one on `WP_MCP_AI_CRM_Gmail`.
  Those are logic seams, not tables — the signals table the toolkit ships is
  written by a support class no declared tool reads. The measurement slice
  decides which helpers travel into the port and which are deferred.
- **`email-marketing` is the smallest self-contained item** on the list: seven
  tools over three transactional APIs (Brevo, Mailjet, Mailgun), each an HTTP
  client plus a key. It is a natural pair of slices after the CRM survey if an
  early merge is wanted while CRM's engine is still being mapped.


## 164.63 `crm`, measured — and the company pair

`crm` is item 3 of the owner's order (§164.62) and the first of the fourteen
with real work: 13 declared slugs. This section measures the toolkit before it
is ported, the way §164.54 measured ECA, and lands the simplest pair.

### What the storage actually is

Every entity is a custom post type, so the family maps to
`ContentStoreInterface` the way the classroom family does:

| entity | type |
|---|---|
| company | `mcp_ai_company` |
| lead | `mcp_ai_lead` |
| deal | `mcp_ai_deal` |
| customer | `mcp_ai_customer` |
| activity | `mcp_ai_crm_activity` |
| contact (toolkit CPT) | `mcp_crm_contacts` |

Two things that are not obvious from the folder:

- **There are two contact stores.** `mcp_crm_contacts` is the toolkit CPT and
  `mcp_ai_lead` is the older lead CPT, and they are separate stores: the CPT
  data store's canonical slug map sends `contacts` to `mcp_crm_contacts` and
  `leads` to `mcp_ai_lead`. **Corrected in §164.65:** an earlier draft of this
  bullet claimed `manage_crm_contact` dispatches across both through a
  `get_storage_type` branch. It does not. The tool holds one store and calls
  `*_item` methods on it, so a lead id is not a contact id. Both type strings
  still have to stay reachable, but through the tools that own them, not
  through a dispatch inside this one.
- **The signals table is not on any tool's path.** The toolkit ships a
  `$wpdb` table for signals, but no declared tool reads it; the only `$wpdb` use
  in the thirteen is `esc_like` inside one email search. An earlier draft of
  this section claimed three tools read the table; that was wrong and is
  corrected here.

The helpers that *do* sit on tool paths are logic, not storage:
`CRM_Engine` (lead scoring and stage history, two tools), `CRM_Relevance`
(two), and `CRM_Gmail` (one).

### The thirteen, by slice

| slice | slugs | notes |
|---|---|---|
| 1 — companies | `create_company`, `get_companies` | **landed in this section** |
| 2 — interactions | `get_contact_interactions`, `get_crm_handover` | activity and deal meta, read-only |
| 3 — contacts | `manage_crm_contact`, `scan_duplicate_contacts` | dual-store dispatch, dedupe scan |
| 4 — hygiene | `archive_stale_contacts`, `recalculate_engagement_scores` | lead/customer/activity writes; scoring formula comes from the engine |
| 5 — email search | `crm_email_search_leads`, `…_accounting`, `…_correspondence` | reads `mcp_crm_contacts` plus the relevance helper |
| 6 — capture and research | `crm_capture_interaction`, `research_company` | engine write path; research service seam |

Slices 2–6 are provisional until each one is read in full. Slice 1 was read
line by line and is below.

### Slice 1: the company pair

`create_company` and `get_companies`, over `mcp_ai_company` and the
`_company_*` meta family, unchanged from WordPress — a WordPress export is
readable here and vice versa, which is what the duplicate scan and handover
tools will depend on.

Decisions:

- **`edit_url` is present and null.** WordPress returns an admin link; this
  runtime has no post edit screen. The key is kept so a shape-compatible
  consumer does not have to tell "absent because this runtime" from "absent
  because the tool changed".
- **`revenue` is stored as a number.** WordPress sanitises it as text, so a
  synced row holds `"250000"`. The port writes a float and the reader accepts
  either, because an amount compared numerically should not be a string whose
  ordering depends on its digit count.
- **`target_status` gets no invented default.** The schema declares `prospect`,
  but the WordPress handler only stores the field when the caller supplied it;
  the port does the same, and `get_companies` reports the absence as an empty
  string.
- **`tags` stays one string**, as WordPress stores it.
- **The filter semantics are frozen**: industry, city, state and country are
  substring matches; size and target status are exact; all ANDed; alphabetical;
  20 per page by default and 100 at most; `total_companies` is the whole
  matching set and `filters_applied` echoes the filters.
- **Capabilities follow WordPress per tool**, not the family default: create is
  `edit_posts`, the listing is `read`.

One test fixture along the way asserted that "Financial Services" contains
"Finance". It does not — they diverge at the eighth character — and the failure
was in the test, not the filter. The assertion now uses a substring that is
actually one.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,111 / 19,868** (10 tests added) |
| adapter PHPUnit | **96 / 936** on the two affected files, OK (2 probes added; the create probe writes and reads back through the adapter) |
| tool readiness audit | **347 / 347**, servable **328** (+2), no violations |
| toolkit parity | **126 of 854**; `crm` **2 ported**, 0 declined, 0 deferred, **11 remaining** |
| phpcs | clean, no fixes needed |

## 164.64 The interaction pair, and two vocabularies the toolkit never reconciled

Slice 2 is `get_contact_interactions` and `get_crm_handover`. Both were read
line by line first, as §164.63 promised. Reading changed the port.

### The finding: activities are written in one vocabulary and read in another

The toolkit's only activity writer is `create_crm_activity`. It stamps:

```php
update_post_meta( $activity_id, 'activity_type', $activity_type );
update_post_meta( $activity_id, 'related_type', $related_type );
update_post_meta( $activity_id, 'related_id', $related_id );
```

`get_contact_interactions` filters on `_activity_contact_id` and reads
`_activity_type`, `_activity_outcome` and `_activity_duration`. A grep across
`addons/pro/includes/tools/crm/` finds **three readers and zero writers** of
the underscore family (`archive_stale_contacts` and
`recalculate_engagement_scores` are the other two readers). Ported literally,
the tool could never return a row the toolkit itself created.

`get_crm_handover` reads the writer's vocabulary — `related_type` /
`related_id` for the activity join and `activity_type` for the label — so the
two tools in the *same slice* disagreed about how an activity links to a
contact. That is the dual-store problem from §164.63 one level down: the same
"keep both reachable" rule decides this port too.

### Decisions

- **The interaction tool reads both families.** The legacy keys are read first,
  so an already-migrated export keeps its shape, and the live keys are the
  fallback. The query links on `_activity_contact_id` **or** on a `related_id`
  whose `related_type` is contact-bearing (`lead` or `contact`; a legacy
  contact *is* a lead, per the dual store). `related_type = 'deal'` is
  deliberately not matched: that id is a deal id, and a test pins it.
- **This is a documented deviation from WordPress and an upstream bug
  candidate.** The alternative — a faithful port that always returns zero rows
  — would pass a parity check and be useless in the Laravel app.
- **The date range is inclusive and applied while walking pages.**
  `ContentQuery` has no date clause. Filtering after one page would silently
  drop in-range rows whenever the newest page is out of range, so the tool
  reads 200-row pages (the adapter's documented maximum) until it has `limit`
  rows or the results are exhausted, bounded at 100 pages. Results are newest
  first, and a row older than `date_from` ends the walk because the ordering
  guarantees nothing later can be in range.
- **A date that does not exist is refused.** WordPress hands `2026-02-31` to
  `date_query`; the core validates with `checkdate()` first (the classroom
  precedent), so a malformed range is a field error rather than an empty
  result.
- **The limit stays WordPress's**: 50 by default, 1–500, and the capability
  stays `read` — the tool is gated as WordPress gates it, not at the family
  default of `edit_posts`.
- **The handover keeps the WordPress section order**: lead, company, deals,
  history, activities, then the closing ask. WordPress captures `$lines` into
  `$sections` as it goes and only reads the keys; the port records a boolean
  per emitted section instead, which is observable-identical.
- **`_company_status` is pinned, not corrected.** `create_company` writes
  `_company_target_status`; the handover reads `_company_status`, so a company
  created by the toolkit renders an empty `Status:` line. Correcting it would
  change a bundle a consumer may already parse. Second upstream bug candidate in
  two slices, recorded rather than fixed.
- **A missing linkage omits its section.** No `lead_id`, no `company_id`, no
  activities — the bundle still returns. Only a bad `entity`, a bad `entity_id`
  or an unknown record is an error (404 on the record).
- **Structured meta is an array or a JSON string.** `bant_assessment` and
  `stage_history` are decoded from JSON when stored as text. PHP-serialised
  strings are **not** decoded: the core will not call `unserialize()` on stored
  data.
- **Currency formatting follows the engine.** An explicit per-deal currency
  wins; otherwise the `default_currency` setting (USD); an unknown code keeps
  the engine's `"CODE "` prefix. `number_format()` replaces
  `number_format_i18n()` — the core has no i18n layer — and `humanTime()`
  replaces `human_time_diff()` with the same units, untranslated.
- **CRM settings are an optional dependency.** `CrmTool` accepts a
  `SettingsStoreInterface`; without one the documented defaults apply, which is
  what a Laravel host gets until it binds the option table.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,128 / 19,942** (17 tests added) |
| adapter PHPUnit | **129**, 900 assertions — the two new probes pass; one unrelated failure remains (`export_eca_data`: this host's file store sniffs the exported CSV as `unknown` and refuses it) |
| tool readiness audit | **349 / 349**, servable **330** (+2), no violations |
| toolkit parity | **128 of 854**; `crm` **4 ported**, 0 declined, 0 deferred, **9 remaining** |
| phpcs | clean, no fixes needed |

Next is slice 3, the contact pair: `manage_crm_contact` (the dual-store
dispatch) and `scan_duplicate_contacts`.

## 164.65 The contact pair, a wrong dispatch, and two dead vocabularies

Slice 3 is `manage_crm_contact` and `scan_duplicate_contacts`. Reading them
corrected the measurement §164.63 committed.

### Correction: there is no dispatch inside `manage_crm_contact`

§164.63 described `manage_crm_contact` as branching on storage type and calling
`*_contact` methods for one store and `*_item` methods for the other. It does
not. The tool holds one store —
`WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'contacts' )` —
and calls `create_item` / `get_item` / `update_item` / `delete_item` /
`query_items` on it. The dual store lives one level down, in
`WP_MCP_AI_Toolkit_CPT_Store::generate_post_type_slug()`:

```php
'crm' => array(
    'leads'      => 'mcp_ai_lead',
    'contacts'   => 'mcp_crm_contacts',
    'deals'      => 'mcp_ai_deal',
    'activities' => 'mcp_ai_crm_activity',
),
```

So a lead id is **not** a contact id: the manager returns `item_not_found` for
one. That is also WordPress' behaviour, and a test pins it. A legacy lead is
reachable through `get_crm_handover`, `get_contact_interactions` and the lead
tools — not through this one.

### The dead vocabulary, again

`scan_duplicate_contacts` reads `_contact_email` and `_contact_phone`. Across
`addons/pro/includes/tools/crm/` there are **zero writers** of either key;
`create_lead` and `create_customer` store `email` and `phone` unprefixed
through the data store. Ported literally, two of the tool's three matchers
would be dead and it would compare names only.

This is the third instance of the same defect class (§164.64 found it for
`_activity_*`, and the dead key family there also had three readers and no
writer). The port reads the legacy key first, then the live key, and a test
proves both.

### Two more pinned quirks

- **A created contact has no title.** `create_item()` only sets a post title
  when the data carries `title`, and the declared `contact_data` schema has
  none. WordPress therefore writes untitled contacts, which `search` (a
  title/content/excerpt query) cannot find by name and the dedupe scanner (a
  title comparison) cannot match. The port keeps it, and honours a `title`
  supplied inside `contact_data` the way the store does.
- **An update never changes the title.** `update_item()` receives
  `contact_data`, which has no title, so renaming through `first_name` /
  `last_name` leaves the stored title alone.

Both are upstream bug candidates; either fix would change records WordPress
already writes.

### Decisions

- **The manager writes `mcp_crm_contacts` with the unprefixed meta family**
  (`first_name`, `last_name`, `email`, `phone`, `company`, `job_title`,
  `tags`) plus the store's `_toolkit_slug` / `_entity_type` markers, so a
  WordPress export round-trips. `storage_type` stays in every payload with
  WordPress' `cpt` value for shape compatibility; the core has one store, so
  the key does not distinguish backends here.
- **`tags` stays an array.** WordPress stores it serialised and
  `maybe_unserialize()`s it on read; the core stores typed meta, so the array
  survives as an array.
- **Phone validation is the toolkit's own heuristic** — at least seven digits,
  the rule `normalize_phone()` already applies — because the core has no
  libphonenumber. Email validation is `filter_var()`, which accepts the same
  ordinary addresses as `is_email()`.
- **List and search clamp to 1–200**, the adapter's documented range, with 20
  as the default. WordPress passes `per_page` straight to `WP_Query`.
- **The scanner keeps WordPress' scope**: `mcp_ai_lead` and
  `mcp_ai_customer`. It does **not** scan `mcp_crm_contacts`, so the two tools
  in this slice do not share a store. Widening the scan would report groups
  WordPress never reports; it is left to the owner as a follow-up, and a test
  holds the current scope.
- **The corpus is paged, not fetched "all".** `posts_per_page => -1` has no
  core equivalent (`perPage` must be non-negative and the adapter caps it at
  200), so the scanner reads 200-row pages and then performs the same O(n²)
  comparison. A large CRM costs what it costs in WordPress.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,149 / 20,028** (21 tests added) |
| adapter PHPUnit | **131**, 914 assertions — both new probes pass; the same unrelated `export_eca_data` host-MIME failure remains |
| tool readiness audit | **351 / 351**, servable **332** (+2), no violations |
| toolkit parity | **130 of 854**; `crm` **6 ported**, 0 declined, 0 deferred, **7 remaining** |
| phpcs | clean, no fixes needed |

Next is slice 4, the hygiene pair: `archive_stale_contacts` and
`recalculate_engagement_scores` — both readers of `_activity_*`, so the
vocabulary found in §164.64 is now on the critical path.

## 164.66 The hygiene pair, and a score with an unreachable 15%

Slice 4 is `archive_stale_contacts` and `recalculate_engagement_scores`. Both
were read line by line. The second one is the clearest instance yet of the
dead-vocabulary defect class.

### The score

The WordPress formula is four weighted components:

| component  | weight | rule |
|---|---|---|
| volume     | 40%    | `min(100, activities × 10)` |
| recency    | 25%    | `max(0, 100 − days_since_last × 2)` |
| diversity  | 20%    | `min(100, unique_types × 25)` |
| completion | 15%    | `completed / total × 100`, capped at 100 |

Public engagement-score write-ups agree on the shape — a recency decay plus
weighted signals, normalised to 0–100 — and disagree on the weights. The
weights here are therefore a product choice, ported as declared rather than
improved.

Three defects sit on the scoring path:

1. **The activity join is dead.** `get_contact_activities()` filters on
   `_activity_contact_id`, which nothing writes. The port uses the shared
   `contactActivityClauses()`, so the score sees the activities
   `create_crm_activity` actually records.
2. **Completion is structurally zero.** WordPress counts
   `_activity_outcome === 'completed'`. No tool writes `_activity_outcome`, and
   the live `disposition` vocabulary
   (`connected`, `voicemail`, `no_answer`, …, `needs_follow_up`) contains no
   `completed` value. The only completion marker the toolkit writes is
   `completed = '1'` from `complete_crm_activity`.

   Consequence, measured: with completion permanently 0, **no contact can score
   above 85** in WordPress — 40 + 25 + 20 — however engaged it is. The port
   accepts the live `completed` stamp, so the 15% is reachable again.
3. **`score_model` is a label, not a switch.** `calculate_engagement_score()`
   takes the argument and never reads it; `custom` and `default` produce the
   same number. The port keeps that, including storing the label in
   `_engagement_score_model`, because inventing a second model would invent a
   contract the schema never defined. A test pins the equality.

One non-behavioural change: WordPress fetches the same 200 activities twice per
contact (once for the score, once for the breakdown). The port fetches once and
derives both. Same output, half the reads.

### The archive

`archive_stale_contacts` has two candidate rules, and both are easy to lose:

- Only contacts **created** at or before the cutoff qualify (`date_query` with
  `before`, inclusive). A new contact with no activity is never archived.
- A candidate is stale when its newest activity is absent or older than the
  cutoff. The activity's own date is the clock.

The activity lookup uses the same dual-vocabulary clause, so a contact with a
`related_type` / `related_id` activity is correctly recent; without the bridge
every contact would read as stale and the tool would archive the whole CRM.

`dry_run` defaults to `true`. Archiving is a status change to `draft` plus
`_archived_date`, `_archive_reason` and `_archived_by` — nothing is deleted, and
the item keeps its content, so an operator can restore it by publishing.

### Decisions

- **The inactivity window is clamped to 30–3650 days**, as WordPress clamps it.
- **An unknown `contact_type` scans nothing** and returns an empty success,
  matching the WordPress loop that builds no post types.
- **`score_model` is stored, not interpreted.** Both values are accepted and
  the label is written to meta.
- **The breakdown is stored as a JSON string**, as WordPress stores it, so a
  WordPress-side reader that calls `json_decode()` keeps working.
- **Scores are ints.** WordPress' `round()` returns a float that serialises to
  the same number; the port casts it, so the meta value and the payload agree.
- **Both tools read a clock through `now()`**, a protected seam, so the
  recency arithmetic and the cutoff date are testable without sleeping.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,170 / 20,115** (21 tests added) |
| adapter PHPUnit | **133**, 932 assertions — both new probes pass; the same unrelated `export_eca_data` host-MIME failure remains |
| tool readiness audit | **353 / 353**, servable **334** (+2), no violations |
| toolkit parity | **132 of 854**; `crm` **8 ported**, 0 declined, 0 deferred, **5 remaining** |
| phpcs | clean, no fixes needed |

Next is slice 5, the email search trio: `crm_email_search_leads`,
`crm_email_search_accounting` and `crm_email_search_correspondence`.

## 164.67 The lead email search — a slice split, and a default that finds nothing twice

Slice 5 is three tools of roughly 1,000 lines each, plus the 542-line relevance
trait they share. That is three times any previous slice, so it is split:
**5a** is the shared ranker plus `crm_email_search_leads`; 5b and 5c follow.

### The shared ranker

`WP_MCP_AI_CRM_Relevance_Search` reads fields from posts by id. The port is a
record-based `RelevanceRanker` that takes a text provider, so the same maths
runs over content items or arrays. The formulas are unchanged: the TF-IDF token
length proxy, `min(100, round(…))` scaling, the BM25 `k1 = 1.5` / `b = 0.75`
with corpus IDF, and the stable tie-break on arrival order.

### A default that finds nothing, twice over

On a store written only by `manage_crm_contact`, a default
`crm_email_search_leads` call returns zero rows for **two independent reasons**:

1. `lead_status` defaults to `new`. The contact manager never writes
   `lead_status`; the lead tools write it to `mcp_ai_lead`, a different store.
2. `orderby` defaults to `lead_score`. WordPress sorts that through a
   `meta_key` INNER JOIN, so rows **without** the key are not in the result at
   all — a missing score is not a zero.

Both are WordPress behaviour and both are pinned. The working invocation is
`lead_status: all, orderby: date`, and the readiness note states it. Widening
the defaults here would change what the tool returns on data WordPress already
has.

The same INNER JOIN rule is applied to the `company` sort: a contact with no
`company` meta is excluded when sorting by company.

### Sorting the core cannot do natively

`ContentQuery` sorts on `date`, `title` and `id` only. Two consequences, both
documented:

- The `relevance` order and the two meta sorts (`lead_score`, `company`) fetch
a bounded 500-row window and sort in memory — the same cap the WordPress
relevance path uses. The window is filled in 200-row pages because that is the
adapter's documented page ceiling.
- Date ranges have no `ContentQuery` clause either, so the range is applied
while filling that window, at day granularity (`date_query`'s midnight
exclusivity is not reproduced).

### Cache and host services

Caching is the same cache-aside shape, keyed by an md5 of the sorted filter
set. The cache is an **optional** `CacheStoreInterface`: without it a search
runs live and the payload says `cached: false` rather than claiming a cache it
never wrote.

Four declared actions depend on services the core does not carry: `schedule`
and `unschedule` (WP-Cron), `import_from_gmail`, and `include_external` (the
Gmail client). They stay in the schema and answer with typed
`schedule_unavailable` / `external_search_unavailable` errors. No silent
no-op, no fabricated results.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,186 / 20,159** (16 tests added) |
| adapter PHPUnit | **134**, 942 assertions — the new probe passes; the same unrelated `export_eca_data` host-MIME failure remains |
| tool readiness audit | **354 / 354**, servable **335** (+1), no violations |
| toolkit parity | **133 of 854**; `crm` **9 ported**, 0 declined, 0 deferred, **4 remaining** |
| phpcs | clean, no fixes needed |

Next is 5b, `crm_email_search_accounting`, which reuses the ranker and the
cache/shape base from this section.

## 164.68 The accounting search — benign defaults over a key family with no writers

Slice 5b is `crm_email_search_accounting`. It reuses the ranker and the
cache/shape base from §164.67, so only the store vocabulary and the accounting
arithmetic are new.

### Another key family with no writers, but the opposite blast radius

A scoped grep finds **zero writers** of every key this tool reads:
`accounting_transaction_type`, `billing_status`, `service_category`,
`invoice_amount`, `invoice_due_date`, `invoice_currency`, `invoice_number`.
`manage_crm_contact` does not declare them.

Unlike the lead search, the defaults are **benign**: every accounting filter
defaults to `all` and the sort defaults to `date`, so a default call returns
contacts with empty accounting fields rather than nothing. A caller who passes
`transaction_type: invoice` on a manager-written store gets zero rows — which
is correct, because no record carries that key.

The key names are worth stating because they are not the argument names: the
`transaction_type` argument reads **`accounting_transaction_type`** in the
store. A test pins it.

### "Fiscal" quarters are calendar quarters

`fiscal_quarter_dates()` hardcodes `Q1 = Jan–Mar … Q4 = Oct–Dec`, and the
schema says so. A fiscal quarter is defined by the company's fiscal-year start
— public definitions are consistent that fiscal quarters "may or may not align
with the calendar year" — so for a July–June business this code labels the
wrong three months. Pinned as-is: inventing a fiscal-year-start setting is a
product decision, not a port decision.

### Four pinned behaviours

1. **`days_overdue` sorts the page, not the set.** WordPress paginates in SQL
   and only then `usort`s the returned page. The port keeps that shape on both
   the native and the in-memory path.
2. **An unparsable due date is null.** WordPress coerces `strtotime()`'s
   `false` to `0` and reports roughly 20,000 overdue days. The port returns
   `null` — a documented deviation, because a bad date is not an overdue
   invoice.
3. **`get_cached` is an error on a miss.** The lead search auto-falls back;
   this tool returns `not_found` with an empty `records` / `total` payload.
   Faithful, and a test pins the asymmetry.
4. **`invoice_amount` and `company` sorts exclude rows without the key**, the
   same `meta_key` INNER JOIN rule as §164.67.

### Core seams

The default currency is the CRM toolkit setting through `crmSettings()`; the
WordPress filter (`wp_mcp_ai_crm_default_currency`) has no core equivalent.
Audit metadata reports "No accounting integration detected" because the core
cannot know which accounting platform a host has installed — the WordPress
check is a `class_exists()` on a Pro tool that does not exist here.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,205 / 20,212** (19 tests added) |
| adapter PHPUnit | **135**, 950 assertions, **1 skipped, 0 failures** — the skip is `export_eca_data` on this host's missing `ext-fileinfo` (#8329), not a regression |
| tool readiness audit | **355 / 355**, servable **336** (+1), no violations |
| toolkit parity | **134 of 854**; `crm` **10 ported**, 0 declined, 0 deferred, **3 remaining** |
| phpcs | clean, no fixes needed |

Next is 5c, `crm_email_search_correspondence` — the last of the trio and the
last email tool in the `crm` toolkit.

## 164.69 The correspondence search — two defaults that cancel each other

Slice 5c is `crm_email_search_correspondence`, the last of the trio. It adds
the follow-up modes, SLA filtering, analytics and routing; the ranker, cache
and shape come from §164.67.

### The key family, and a default that finds nothing

Every correspondence key has **no writer** in the toolkit — `last_contacted`,
`follow_up_date`, `contact_count`, `correspondence_category`, `contact_status`
— except `tags`. On a store written only by `manage_crm_contact` the default
call therefore returns **zero rows**, for a reason that is worth spelling out:

- `needs_followup` matches `last_contacted < cutoff` **or NOT EXISTS**, so
  every record with no `last_contacted` matches.
- The default sort is `last_contacted`, a `meta_key` sort, which WordPress
  implements with an INNER JOIN — which drops exactly those records.

The working invocation is `correspondence_type: all, orderby: date` on such a
store, or data that carries `last_contacted`. The same collision makes
`correspondence_type: never_contacted` with the default sort return zero: the
mode selects only the rows the sort removes. A test pins both.

### Four more pinned behaviours

1. **The channel filter reads the stored meta.** The projection defaults an
   absent channel to `email`, but `channel: email` filters on the meta key, so
   an untracked record does not match its own default. Faithful, and pinned.
2. **`sla_breach_only` does not treat a never-contacted record as a breach.**
   WordPress only computes the breach when `last_contacted` is non-empty, so
   the empty case passes the filter. Pinned.
3. **Malformed dates yield `null` analytics** and are never reported as a
   breach. WordPress coerces `strtotime()`'s `false` to the epoch, reporting a
   huge day count and a breach; the port deviates deliberately, as §164.68 did
   for due dates.
4. **`get_cached` is a `not_found` error on a miss**, with a `contacts` /
   `total` payload, and the native path's `total` is the store's
   pre-post-filter count — a page can be shorter than `per_page` after the
   domain/tag/SLA filters, exactly as WordPress returns it.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,219 / 20,251** (14 tests added) |
| adapter PHPUnit | **136**, 959 assertions, **1 skipped, 0 failures** — the skip is `export_eca_data` on a host without `ext-fileinfo` |
| tool readiness audit | **356 / 356**, servable **337** (+1), no violations |
| toolkit parity | **135 of 854**; `crm` **11 ported**, 0 declined, 0 deferred, **2 remaining** |
| phpcs | clean, no fixes needed |

Slice 6 is the last pair: `crm_capture_interaction` and `research_company`.

## 164.70 The capture and research pair — `crm` complete at 13/13

Slice 6 closes the toolkit. Two tools, and neither is what the file names
suggest: the capture writes a memory drawer, and the research is a single
query plus a projection.

### A hypothesis this slice disproved

§164.69 recorded that the correspondence keys (`last_contacted`,
`contact_count`) have no writer. A hypothesis during slice 5c was that
`crm_capture_interaction` is that writer — it is named "capture interaction"
and it sits in the same toolkit. Reading it disproves the hypothesis: it writes
a MemPalace drawer in the wing `account/{account_id}` and nothing else. **No
part of `crm_capture_interaction` touches the contact store**, so §164.69's
finding stands unqualified. The correction is recorded here because a plausible
reading is how a dead key family survives an audit.

### Capture: the service's normalisation moved into the tool

The WordPress tool is a thin subclass of `WP_MCP_AI_Pro_Capture_Tool_Base`,
which builds a fourteen-field envelope and hands it to
`WP_MCP_AI_Memory_Capture_Service`. The service is where the checks lived, so
porting the tool without the service means porting the reachable part of
`normalise_envelope()` — the tool is now the last line before the store:

| service behaviour | port |
|---|---|
| `content` required (`mempalace_capture_missing_content`) | `validation_failed` "content is required." — a failure envelope is not a tool result |
| `importance` clamped to 0–1 | clamped |
| `ttl <= 0` becomes one day | floored at `MIN_TTL` |
| an unknown `tier` becomes `recall` | falls back to `recall` |
| array/object content is JSON-encoded | encoded |
| `subject_refs` filtered to non-empty scalars | filtered |

Two consequences are worth naming. **The wing slug is a key**: the Pro base
runs the account id through `sanitize_key()` before it forms `account/{id}`, so
`Acme Corp` addresses `account/acmecorp`; a port that trimmed would write a
record nothing else can find, and a test pins it. And **the service checked for
itself before it validated anything**, so a host with no memory store answers
`memory_store_unavailable` even to a malformed call — pinned rather than
tidied.

`MemoryStoreInterface` has no columns for the capture policy, so `sensitivity`,
`consent_basis`, `verbatim`, `source`, `context_type`, `subject_refs`,
`attachments`, `recorded_at`, `valid_from` and `valid_until` travel in
`metadata`, which the contract documents as the place for exactly that. Only
supplied provenance is written, as in `docgen_capture_style_memory`. WordPress
fills `recorded_at`/`valid_from`/`valid_until` from now and the expiry; here the
store timestamps the record and derives `expires_at` from `ttl`, so defaults in
`metadata` would be a second clock. The success payload keeps the WordPress
keys except one: `context_id` is `memory_id`, the store's name. `expires_at` is
restated because the WordPress tool returned it; the store derives the same
instant from the same `ttl`.

### Research: an argument that never arrived

The WordPress tool calls the base search tool with

```php
array( 'query' => $search_query, 'num_results' => 5 )
```

and the search tool it calls reads **`max_results`**. There is no
`num_results` parameter anywhere in that tool, so the request was silently
ignored and the provider used its own default — which is also five, so the
behaviour matched by luck and the defect was invisible. This is the
accepted-then-ignored class the port keeps finding. The port passes `count`,
the core tool's real parameter; the count now reaches the callee.

Three further measurements:

1. **`summary` and `sources` are always empty in WordPress too.** Its search
   tool returns `results` and nothing else, so `structure_research_data()`'s two
   keys are constants there. The port keeps the keys for shape compatibility and
   says so instead of pretending a summariser exists.
2. **Hit items are forwarded, not reshaped.** WordPress items carry `title` /
   `url` / `snippet` / `source` / `type`; the core's carry `title` / `url` /
   `description`. This tool counts the hits and reads no field, so the port
   forwards the search tool's own shape rather than inventing `source` and
   `type` values.
3. **The focus is not validated in `execute()`.** The schema has an enum; the
   code falls through a `switch` whose `default` is the general tail, and the
   argument is echoed back unchanged. `research_focus: vibes` therefore
   searches generally and reports `vibes`. Pinned, and a test asserts it.

The declared capability stays `edit_posts` while `execute()` re-checks `read`,
as the original does; the declared one is the effective gate because the
registry checks it first. The multisite membership check
(`is_user_member_of_blog`) and `do_action( 'wp_mcp_ai_company_researched' )`
have no core counterpart and remain the host's.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,251 / 20,340** (32 tests added) |
| adapter `ToolProbesTest` | **137**, 970 assertions, **1 skipped, 0 failures** — the skip is `export_eca_data` on a host without `ext-fileinfo` |
| adapter `CrmCaptureAndResearchTest` | **2**, 22 assertions — the capture is written and read back through the real `MemoryStoreInterface`; the research runs against a faked provider with a catch-all 500 |
| tool readiness audit | **358 / 358**, servable **339** (+2), no violations |
| toolkit parity | **137 of 854**; `crm` **13 ported**, 0 declined, 0 deferred, **0 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist` — `phpcs.xml.dist` excludes `*/lib/*`, so a run against it checks nothing and exits 0 |

### `crm` is complete

All thirteen declared slugs are ported: the company pair, the interaction pair,
the contact pair, the hygiene pair, the three email searches, and this pair.
The toolkit closes at **13 ported, 0 declined, 0 deferred, 0 remaining** — the
largest family in this proposal to reach its full declared count. It produced
four defect classes worth carrying into the next repository: **dead key
families** (three groups of meta keys nothing writes), **defaults that cancel
each other** (the correspondence search), **arguments that never arrive**
(`num_results`), and **labels that are not switches** (`score_model`). Each is
recorded, pinned by a test, and stated in the readiness row rather than quietly
corrected.

Next is the next repository in the queue — the toolkit order is the owner's to
choose; the register now reads 137 ported, 8 declined, 35 deferred, 674
remaining.

## 164.71 `food-beverage`, measured — and the table foundation

The owner chose the next four repositories: `food-beverage` (23 tools),
`orchestration` (36), `calendar-booking` (34) and `gtmetrix` (23). This section
is the measurement of the first, plus its foundation slice.

### What the toolkit is made of

`addons/pro/includes/tools/food-beverage/` holds **50 files**: 23 tools and 27
support classes (five CPTs, seven readers and writers, the metrics engine, the
report builder/exporter, the ingest-quality and retention helpers, the tenant
profile, the tool base). The toolkit does not use `$wpdb` and makes no direct
HTTP call from a tool: every figure comes from **tabular data**, addressed by
spec table id.

That is the shape that decides the port. `WP_MCP_AI_Fnb_Data_Source` is an
18-entry registry — the 17 spec tables and the `assumptions` sheet — each with
canonical column keys and the header spellings an operator's export uses, plus
a 77-entry alias dictionary. Rows are positional; a canonical key is resolved
to a column index at read time, so a spreadsheet whose headers drifted still
resolves.

### The dependency map, measured

| tool | reads | calls |
|---|---|---|
| `fnb_read_table` | — | `Data_Source::get_table` |
| `fnb_search_table` | — | `Data_Source::search_table` |
| `fnb_days_of_cover` | 3.0 | `m14_days_of_cover` |
| `fnb_weekend_demand` | 4.0 | `m12_expected_covers` |
| `fnb_duplicate_invoice_scan` | 14.0, 15.0 | `month_of` |
| `fnb_calculate_metric` | — | `Metrics::calculate` |
| `fnb_compare_periods` | — | `calculate`, `m01_revenue` |
| `fnb_budget_variance` | — | `m20_budget_variance` |
| `fnb_dish_margin` | — | `m21_dish_margin`, `menu_engineering` |
| `fnb_expected_use` / `fnb_stock_used` / `fnb_stock_variance` | — | `m16` / `m15` / `m17` |
| `fnb_price_change_impact` | — | `m19_price_change_impact` |
| `fnb_waste_analysis` | — | `m18_waste_pct` |
| `fnb_labour_analysis` | — | `m02`, `m09`, `m10`, `payroll_total` |
| `fnb_utilities_per_cover` | — | `m02`, `m11`, `utilities_totals` |
| `fnb_variance_split` | — | `m02`, `m07`, `m08`, `total_operating_cost` |
| `fnb_generate_report` / `fnb_export_report` | — | `Report_Builder::build` / `Report_Exporter` |
| `fnb_save_draft` / `fnb_list_drafts` | — | `Draft_Writer` |
| `fnb_import_table` | — | the ingestor (CPT reader, structure matcher, ingest quality) |
| `fnb_audit_log` | — | — |

Two consequences: the metric engine is the backbone (15 of 23 tools are a
wrapper around one or two `m*` functions), and only three tools read tables
directly.

### The storage decision

`TabularReaderInterface` already exists in this runtime and is already bound in
the Laravel adapter to `MediaWorkerTabularReader` (the media worker parses
`xlsx`, `csv`, `tsv`, `json` via ExcelJS and the repo's delimited parser). It
reads a **file**, not a table id, so the missing piece is the mapping — and the
mapping is configuration, not a contract:

```
wp_mcp_ai_fnb_toolkit_settings = array(
    'as_of_date' => '2026-06-30',
    'table_map'  => array(
        '3.0' => array( 'path' => '/srv/fnb/ingredients.csv' ),
        '6.0' => array( 'path' => '/srv/fnb/sales.xlsx', 'sheet' => 'Daily sales' ),
    ),
)
```

No new domain contract was added. What was dropped, and why:

- **The Drive connection cascade.** WordPress resolves a table through four
  levels ending in "the only enabled Drive connection". There is no Drive
  client here, and a host that wants one points `table_map` at a path.
- **`connection_id`.** The argument selected a connection. With one source there
  is nothing to select, so it is **absent** rather than accepted and ignored —
  the rejected-then-ignored shape this programme keeps finding. The
  `connection_id`/`connection_name` payload keys go with it.
- **`fnb_native_cpt` and the native-storage hint.** Five tables can be backed by
  WordPress posts; this runtime has no F&B post type, and the hint exists to
  tell an operator to flip a WordPress setting that does not exist here.
  `source` stays in the payload and always reads `fnb_data_source`, so a
  consumer that branches on it does not have to distinguish "absent" from
  "changed".

### The slice plan (provisional)

| slice | contents |
|---|---|
| **1** | catalogue + source + `fnb_read_table`, `fnb_search_table` |
| 2 | the metric engine and its dispatcher: `fnb_calculate_metric`, `fnb_compare_periods` |
| 3 | the table-reading metrics: `fnb_days_of_cover`, `fnb_weekend_demand`, `fnb_duplicate_invoice_scan` |
| 4 | the cost and variance wrappers: `fnb_dish_margin`, `fnb_price_change_impact`, `fnb_stock_used`, `fnb_stock_variance`, `fnb_expected_use`, `fnb_waste_analysis`, `fnb_budget_variance`, `fnb_variance_split`, `fnb_labour_analysis`, `fnb_utilities_per_cover` |
| 5 | reports and drafts: `fnb_generate_report`, `fnb_export_report`, `fnb_save_draft`, `fnb_list_drafts` |
| 6 | ingest and audit: `fnb_import_table`, `fnb_audit_log` |

Slices 2–6 stay provisional until each tool is read line by line, as with `crm`.

### Slice 1: a generated catalogue, not a transcribed one

The registry and the alias dictionary are the toolkit's contract with an
operator's spreadsheets — a column that stops resolving turns a metric into
zero — so they are **generated**, not copied by hand:
`bin/generate-fnb-table-catalogue.php` loads the Pro class with only `ABSPATH`
defined, reflects the two structures, and writes
`lib/core/resources/fnb-table-catalogue.php` (731 lines, 18 tables, 77 aliases).
`--check` fails when the core copy and the Pro class disagree, so the two cannot
drift silently.

Three behaviours were pinned from the Pro implementation rather than tidied:

1. **The column resolver has three passes** — canonical key, then the table's
   own aliases (`price_lkr` for `price`), then the 77-entry dictionary applied
   to each normalised header (which is how an operator's `inv no` becomes
   `invoice`). A test exercises all three and the miss.
2. **A numeric date is disambiguated by magnitude.** A Sheets/Excel serial is
   days since 1899-12-30 (46023 = 2026-01-01) and sits far below any modern
   epoch, so `< 100000` is a serial. Both are pinned with real values.
3. **`float_cell()` strips thousands separators**, so `"1,234.5"` is a number
   rather than zero — and `is_numeric("1,234.5")` is false, so this is
   load-bearing arithmetic, not formatting.

And two from the search:

- **A column with no value means "is present".** `column: item_id` without a
  `value` keeps the rows whose cell is non-empty; with a value it is a
  case-insensitive substring test.
- **A row whose date does not parse passes a date range.** The range is only
  compared when the cell parses, so an undated row is never excluded. This is
  the §164.69 shape again — a filter that admits "unknown" — and it is pinned
  with a test rather than corrected.

One flag does not carry over: the Pro base declares `local-only`, but here the
table is read through `TabularReaderInterface`, which the Laravel adapter binds
to an HTTP worker, so the port declares `read-only`, `idempotent` and
`cacheable` and says why. `cacheable` is true because `FnbTableSource` caches a
resolved table for the WordPress five minutes through the optional
`CacheStoreInterface`.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,277 / 20,420** (26 tests added) |
| adapter `ToolProbesTest` | **139 / 978**, 1 skipped, 0 failures — both probes expected `error`: no table is mapped in this environment |
| tool readiness audit | **360 / 360**, servable **341** (+2), no violations |
| toolkit parity | **139 of 854**; `food-beverage` **2 ported**, 0 declined, 0 deferred, **21 remaining** |
| capability flags / catalogue drift | in step (1,465 tools; 18 tables and 77 aliases) |
| phpcs | clean under `phpcs-laravel.xml.dist`, 9/9 files |

Next is slice 2: the metric engine and its dispatcher, which 15 of the remaining
21 tools are a wrapper around.

## 164.72 The metric engine, and money that rounds to zero

Slice 2 is `WP_MCP_AI_Fnb_Metrics` — the 1,625-line engine behind M-01…M-21 —
plus its dispatcher (`fnb_calculate_metric`) and the month-on-month comparison
(`fnb_compare_periods`). It lands as one slice because the dispatcher's schema
enumerates the metric catalogue: a partial engine would advertise ids it could
not answer, which is the shape this programme rejects everywhere else.

### The structural change: instance, not static

WordPress calls `WP_MCP_AI_Fnb_Data_Source::get_table()` from inside every
metric, so the source is global. The port injects `FnbTableSource` instead, which
is what lets a test hand in a fixture and a host decide where rows come from.
Three smaller changes follow from it:

- **Fluent accessors.** `float_cell( cell( '9.0', $headers, $row, 'opening' ) )`
  becomes `$this->num( '9.0', $headers, $row, 'opening' )`. Same coercion, and
  the formula you read is the formula.
- **`stock_used_lkr()` is `stockUsed()`.** The name carried a currency the
  toolkit has since made configurable; the formula is unchanged.
- **`r()` takes its precision from the tenant currency**, ported from
  `Fnb_Tenant_Profile::currency_decimals()`: `LKR`, `JPY`, `KRW` and `VND` round
to **zero** decimals and everything else to two.

### The finding that caught the port, not the code

The first fixture set `currency = LKR` — correct for the demo workbook — and six
assertions failed: average spend came back `6.0` instead of `5.75`, the volume
effect `333.0` instead of `333.3333`. The engine was right and the expectations
were wrong, because almost every `r()` call passes no decimal count, so the
currency decides the precision of every money figure in every metric. The test
fixture now runs in a two-decimal currency so the arithmetic is visible, and a
separate test pins LKR's zero-decimal rounding. That is the fourth time in this
programme a fixture was wrong rather than the port, and the check is the same
each time: *is the expectation or the code wrong?*

### Twelve pinned behaviours

1. **M-02 falls back to the utilities sheet's `total_covers`** when the covers
table is empty or totals zero, de-duplicating by date so several meter readings
for one day do not multiply the covers, and switching `source` to
`fnb_metrics:M-02:utilities`.
2. **M-14 takes the last matching count row in file order**, not the latest by
date — the loop overwrites as it walks. A workbook in date order behaves as you
would expect; an unordered one does not.
3. **M-15 answers from the first matching count row** and returns inside the
loop, so a period spanning two counts reports the first.
4. **M-17 derives its month from `from`** (`substr($from, 0, 7)`); without `from`
there is no expected-use comparison and the meter reads `0`.
5. **M-19 counts only price rises** (`$delta <= 0` is skipped) and attributes
quantity from the change date to the next change, or a year out.
6. **M-20 leaves `actual` and `variance` null** for a budget line with no actual:
"not recorded" is not "nothing spent".
7. **M-21 resolves the recipe cost in three steps** — the menu's precomputed
column, then the recipes' cost-per-portion, then quantity × the supplier's
latest unit price — and reports which one answered in `recipe_cost_source`.
8. **`menuEngineering()` calls M-21 once per menu item**, so it depends on the
table cache the way WordPress depends on its transient.
9. **A nested metric never fails the call**: errors ride inside the result array
and the dispatcher turns them into `metric_error` after the outer metric has
assembled what it could.
10. **An unknown id answers `unknown_metric`** with the nearest valid id
(Levenshtein, preferring a shared number) and the full list.
11. **The comparison drops a metric whose either month errors**, but not the Food
and Beverage split — its rows read `value` off a zeroed metric array, so an
unreadable sales table yields two zero rows. The adapter probe is a success for
exactly this reason.
12. **`change_pct` divides by `abs(prev)`**, so a negative baseline keeps the
sign of the change, and is `null` when the baseline is zero.

### Two demo artefacts replaced

`fnb_compare_periods` defaulted to **September 2026 against August 2026** — the
demo workbook's months — and labelled the split "Food revenue (LKR)". A host with
no arguments would compare two months that may not exist in its data, and one in
another currency would be mislabelled. The port defaults to the current month
against the previous one and puts the configured currency in the label; a caller
that passes months and an LKR venue sees the identical behaviour.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,311 / 20,536** (34 tests added) |
| adapter `ToolProbesTest` | **141 / 991**, 1 skipped, 0 failures — the metric probe refuses, the comparison succeeds on its two split rows |
| tool readiness audit | **362 / 362**, servable **343** (+2), no violations |
| toolkit parity | **141 of 854**; `food-beverage` **4 ported**, 0 declined, 0 deferred, **19 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, 12/12 files |

Next is slice 3: the three tools that read a table directly —
`fnb_days_of_cover`, `fnb_weekend_demand`, `fnb_duplicate_invoice_scan` — which
now need only their own arguments on top of the engine.

## 164.73 The three table-reading tools, and two descriptions that overpromise

Slice 3 adds the tools that read a table on their own account rather than
through an `m*` function. Each contributes one thing the engine cannot know: a
shelf-life cap, a three-day window with supplier cut-offs, and a
vendor/invoice/amount triple.

### `fnb_days_of_cover`

The cap is the ingredient's shelf life when the ingredients table carries one
and **fourteen days** otherwise — the long-life rule. Three details are pinned:

- **The flag is a string**, `''` when the cover is inside the cap, because
  WordPress callers test truthiness.
- **The shelf-life lookup is the first matching row** and an unreadable
  ingredients table leaves it at zero, so the flag falls to the 14-day cap
  rather than the call failing: the metric is worth returning even when the
  annotation around it is not.
- **`as_of` must be `Y-m-d` or it is dropped**, which re-applies the configured
  as-of date rather than today. A malformed date does not silently move the
  measurement.

### `fnb_weekend_demand`

Three days from a Friday, each with M-12, plus each supplier's cut-off and lead
time. Two things are recorded rather than invented:

- **The description promises expected portions and stock shortfalls; the code
  returns neither.** It computes covers per day and the supplier list, and
  points at the `R-03` report layout for the rest. `fnb_calculate_metric`
  answers M-13 for a dish; nothing here pretends to.
- **`report_hint` names `fnb_generate_report`**, which lands in slice 5. The
  string is kept because it is the WordPress contract and the tool will exist;
  until then it is a forward reference, and the readiness row says so.

The default Friday is derived — the next Friday on or after today — instead of
the demo's `2026-10-09`. A malformed date substitutes that default, which is
what the original does with its own.

### `fnb_duplicate_invoice_scan`

Three flags, and three pins:

1. **The amount is rounded to a whole unit** before it enters the key, so
   `1,250.40` and `1,250.00` are the same claim. That is `round()` with no
   precision, and it is deliberate rather than incidental — a cent of difference
   should not hide a re-entered invoice.
2. **The stock-purchase line is never "missing a budget line"**, because the
   budget deliberately omits it. The exception is hardcoded in the original and
   kept.
3. **`no_budget_line` is capped at 50 but the duplicate list is not**, because a
   duplicate is the finding and a cap would silently drop one.

The description also promises "large price jumps". The code does not attempt
them — that is M-19 — so the port does not either. A missing ledger returns the
source's typed error; a missing budget sheet only empties the match list.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,327 / 20,581** (16 tests added) |
| adapter `ToolProbesTest` | **144 / 1,014**, 1 skipped, 0 failures — two successes (M-14 and M-12 answer zeros on an unmapped corpus), one refusal (the ledger) |
| tool readiness audit | **365 / 365**, servable **346** (+3), no violations |
| toolkit parity | **144 of 854**; `food-beverage` **7 ported**, 0 declined, 0 deferred, **16 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, 15/15 files |

Next is slice 4: the ten metric wrappers — `fnb_dish_margin`,
`fnb_price_change_impact`, `fnb_stock_used`, `fnb_stock_variance`,
`fnb_expected_use`, `fnb_waste_analysis`, `fnb_budget_variance`,
`fnb_variance_split`, `fnb_labour_analysis`, `fnb_utilities_per_cover` — which
are now argument handling over an engine that already answers their metrics.

## 164.74 The ten metric wrappers, and a helper that had five copies

Slice 4 is argument handling over an engine that already answers the metric.
Ten tools, and only three of them own anything beyond a refusal and an echo:
`fnb_dish_margin` switches shape, `fnb_budget_variance` sorts, and
`fnb_variance_split` compares lines.

### What the probe run changed

The first draft read the metric's keys explicitly —
`'source' => $result['source']` — because that is the shape the successful
tests had pinned. On an unmapped corpus the metric's error shape has no
`source`, so three tools died with `Undefined array key "source"` (PHPUnit
promotes the notice to an exception) before returning anything.

WordPress does not read the keys. Every one of the ten originals ends in
`array_merge( array( 'success' => true, …echoed args… ), $result )`. The port
now merges too. That is both faithful and notice-free, and it preserves whatever
the metric chose to return — including its own `error` key when a table cannot
be read.

The consequence is worth stating plainly, because it is the opposite of what
the slice expected: **none of the ten propagates the unmapped table as a tool
error.** Each metric answers a zeroed or empty shape carrying its own `error`,
and the wrapper merges it. All ten adapter probes are therefore **successes**,
which matches the precedent set by `fnb_days_of_cover`, `fnb_weekend_demand`
and `fnb_compare_periods` in slice 3. A metric error is data here, not a tool
failure — the same conclusion `fnb_budget_variance`'s dead `is_wp_error()` check
reaches of its own accord.

### The wrappers that own a shape

- **`fnb_dish_margin`** has two shapes. With `item_id`, it is M-21 merged under
  the id; `recipe_cost_source` says whether the menu, the recipes or the
  supplier prices answered. Without it, the full Kasavana–Smith matrix, which
  needs a month because popularity is a share of that month's units — the one
  refusal in the family. An item without a month is allowed and reports
  `popularity_share: 0.0` rather than guessing a period.
- **`fnb_budget_variance`** sorts the rows itself, largest variance first with
  an unrecorded actual last (`PHP_FLOAT_MIN` as the key). It overwrites
  `source` with the tool name, and it pins the WordPress dead check: an
  unreadable budget sheet is an **empty success**. The metric returns an array
  with an `error` key, `is_wp_error()` is false, and the empty `rows` list is
  the answer.
- **`fnb_variance_split`** lists only this month's lines. A line new this month
  is compared against zero, so its whole change lands in the per-cover effect;
  `total_change` is the difference of the two months' operating totals. Covers
  are rounded for display only.
- **`fnb_waste_analysis`** adds a month-on-month change in **waste value**, not
  in the percentage. `change_pct` is `null` when the previous month logged no
  waste — dividing by a zero baseline would invent a number — and
  `month_on_month` is `null` when `prev_month` is not supplied.
- **`fnb_labour_analysis`** returns `justified` as a **sentence**, because
  WordPress surfaces it as text. A previous month with covers but no payroll row
  is a real comparison (overtime grew 15 hours against 10 covers) and answers
  "exceeds"; a zero-cover previous month also answers "exceeds" rather than
  dividing by zero. `m10_labour_pct` is a ratio despite its name — payroll ÷
  revenue at four decimals — and is pinned as-is.
- **`fnb_utilities_per_cover`** reports raw meters, the costed lines the
  assumptions table prices (tariff × meter plus fixed charge) and per-cover use
  against M-02 covers. `assumptions_source` is the literal string
  `'Assumptions table'`, not a resolved path, so a shape-compatible consumer
  sees the same value.
- **`fnb_stock_used`** merges M-15, so a hit carries `opening`, `bought`,
  `closing` and `period`, and a miss carries only the value. Present-but-
  malformed dates pass through, as in WordPress: the engine compares strings and
  an unparseable date matches nothing.
- **`fnb_stock_variance`** merges M-17. `from` also selects the month whose
  expected use is subtracted, so a variance without it has no expected-use term
  rather than a guessed one.
- **`fnb_expected_use`** and **`fnb_price_change_impact`** are the pure cases:
  both arguments are required and everything else is the metric's.

### A helper that had five copies

The new `FnbTool::scalar()` is protected, and five tools from slices 1–3 had
their own `private function scalar()` with the same body. PHP does not allow a
subclass to narrow visibility, so the runtime died on
`Access level to …::scalar() must be protected` — not at lint time, and not in
the earlier slices' tests, because those tools were only loaded when a test
touched them. The five copies are gone; the base helper is the one
implementation. This is the kind of collision that only shows up when the whole
family is loaded at once, which is exactly what the adapter suite does.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,348 / 20,652** (21 tests added) |
| adapter `ToolProbesTest` | **154 / 1,104**, 1 skipped, 0 failures — ten successes, as recorded above |
| tool readiness audit | **375 / 375**, servable **356** (+10), no violations |
| toolkit parity | **154 of 854**; `food-beverage` **17 ported**, 0 declined, 0 deferred, **6 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

Two hand-derived fixture figures were checked before trusting them:
`fnb_variance_split.total_change` is 4,050 because September's operating total is
4,030 and August's is −20 (no payroll, no ledger, but the electricity fixed
charge still applies), and the labour comparison's 15-hour overtime change
against 10 covers matches the fixture's stated intent. Both agreed with the
engine; neither needed a source edit.

Next is slice 5: the report path — `fnb_generate_report`, `fnb_export_report`,
`fnb_save_draft`, `fnb_list_drafts` — which brings `Report_Builder`,
`Report_Exporter` and `Draft_Writer` across.

## 164.75 The report path — R-01…R-14, CSV/PDF, and drafts

Slice 5 adds four tools and three helpers: `FnbReportBuilder`,
`FnbReportExporter` and `FnbDraftWriter`. Every figure still comes from the
metric engine; the builder only assembles sections, which is the same division
of labour the WordPress classes keep.

### The report library

The registry holds **fourteen** reports. The WordPress class docblock says
R-01…R-13, but its `REPORTS` constant and `build_r_14()` both carry R-14, and the
registry wins. R-14 is the operator's own "monthly sales report", addressed on
export as `client-r01`.

Four behaviours are pinned rather than corrected:

- **`money()` does not round to whole units.** The WordPress docblock claims it
  does; the code delegates to the tenant profile, so the currency decides the
  decimals — zero for LKR/JPY/KRW/VND, two otherwise. The port delegates to
  `FnbSettings::formatMoney()`, which follows the same rule.
- **The date filter counts undated rows.** `search_table()` filters the column
  by substring, then the parsed date by range, and never skips a row whose date
  does not parse. R-01 therefore counts an undated row on *any* date. That is
  the quirk `fnb_search_table` already pins, reproduced here.
- **R-01 does not fall back to `total_covers`.** M-02 does; the daily snapshot
  sums the three cover columns only.
- **A defaulted R-14 has a blank period in its draft title.** The month default
  is applied inside the report body, while `build()` composes the title from the
  argument it received — so `[R-14] Monthly sales report —  — Draft — for Owner`
  is what WordPress prints, and what the port prints.

Two expectations in the new suite were corrected against the code rather than
the other way round, both from the same fixture: **the unit price is the pack
price divided by the pack size** (30 ÷ 10 = 3.00, so the R-04 request totals
15.00, not 150.00), and **popularity share is within the item's own type** (both
fixture dishes show 100%, which makes the low-margin soda a Plowhorse rather
than a Dog).

### Export — CSV and PDF

- **CSV** flattens each section to `section,key,value` rows and stores the file
  through `FileVisibilityStoreInterface`. `file` is added only when the store
  also implements `FileStoreInterface`, so a key that would name a
  non-existent path is omitted rather than faked.
- **PDF** renders the report's HTML through `HtmlToPdfInterface`. The WordPress
  ladder — DomPDF, then the document-generation HTML→PDF tool, then an error —
  collapses to the bound renderer; an unbound or unavailable one is the same
  `no_pdf_engine` refusal with the same advice: export CSV instead.
- **No file registry is a named refusal.** WordPress always has a filesystem;
  a bare core host may not. Returning a temporary path would hand back a file
  that disappears.
- The three client aliases (`client-r01` → R-14, `client-r02` → R-05,
  `client-r03` → R-08) are resolved before the report is built.
- Export requires `edit_posts`, as the WordPress tool already does.

### Drafts — a Paper Store record, not a file

`fnb_save_draft` writes a record in the `fnb-drafts` collection: id from the
title's slug, `body.markdown`, `status: draft`. WordPress writes a `.md` file
or a Google Doc; this runtime has neither a drafts directory nor a Drive
connection, and the Paper Store exists to hold exactly this kind of document.
Re-saving the same title replaces the record, as the file write did. A title
with no usable characters is refused — WordPress would write `.md`, and the
store will not accept an empty id.

`fnb_list_drafts` reads the same collection newest-first and keeps the
WordPress keys: `name` is `<id>.md`, `path` is the record locator, `bytes` is
the stored markdown's length. `title`, `updated_at` and `total` are added so the
200-record page cap is visible rather than silent.

One deviation is recorded rather than copied: **the WordPress save tool inherits
`read` and `read-only` while writing a file.** Here it asks for `edit_posts` and
declares `state-changing`/`write`, so the read-only profile gate cannot be told
that nothing changed when a record was written.

### A return type the adapter caught

The first pass declared `: array` on `build()`, `toCsv()`, `toPdf()` and
`save()`. The core tests passed because the mocked error factory returns arrays.
In the adapter the error factory returns a `RuntimeException`, so every error
path became a `TypeError` — three of the five new probes failed on exactly that.
The four signatures are now `mixed`, matching `FnbTableSource::getTable()`: a
method that answers “the result or the platform's error object” cannot promise
an array. This is the second time the adapter suite has caught something the
unit suite structurally cannot.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,382 / 20,781** (34 tests added) |
| adapter `ToolProbesTest` | **159 / 1,135**, 2 skipped, 0 failures — the CSV probe skips without `ext-fileinfo` because the file store cannot sniff, the same reason `export_eca_data` skips |
| tool readiness audit | **379 / 379**, servable **360** (+4), no violations |
| toolkit parity | **158 of 854**; `food-beverage` **21 ported**, 0 declined, 0 deferred, **2 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

Next is slice 6, the last two: `fnb_import_table` — the CPT reader, structure
matcher and ingest quality — and `fnb_audit_log`.

## 164.76 Closing `food-beverage` — audit logging, and an import deferred

Slice 6 ports one tool and records a decision not to port the other. The
toolkit ends at **22 ported, 0 declined, 1 deferred, 0 remaining** — complete
against its own contract, with the deferral visible rather than laundered into
either a port or a decline.

### `fnb_audit_log`

Reads the host's queryable log through `SystemLogInterface` — the same seam
`get_system_logs` uses — rather than `WP_MCP_AI_Audit_Logger`. Four decisions
are recorded, each because copying WordPress would have been worse:

- **`manage_options`, not the WordPress `read`.** The F&B base gives every tool
  `read`, so any subscriber could read the audit trail. The trail is the
  operator's, cross-estate and already gated on the host; handing it to a `read`
  tool would widen access relative to the page that owns the data.
  `get_system_logs` made the same correction, and this tool follows it.
- **The tool filter keeps entries that do not name a tool.** The WordPress
  condition only rejects an entry when the field is *present* and does not
  match — an entry without `tool` passes. The port keeps that rule and also
  looks in `detail.tool`, because this runtime's entries carry structured
  detail rather than a top-level field. It never invents a match.
- **`act_tier_seen` is computed.** WordPress returns `false` without reading a
  single entry. Here it is true when a returned entry names
  `fnb_import_table` — the toolkit's only ACT-tier slug — and the `note` says
  exactly that. A flag that cannot be wrong is not a finding.
- **`cacheable` is dropped.** The base declares it because tables are cached
  for five minutes; a log that changes on every call must not claim that.

Two smaller edges: a non-array entry is skipped (the WordPress loop would pass
it through, but `SystemLogInterface` promises arrays), and an unbound service or
an empty `sources()` answers **“this host keeps no queryable audit log”**
rather than an empty list, which would read as “nothing happened”.

### `fnb_import_table` — deferred, and why not declined

The tool migrates transactional rows (6.0 sales, 8.0 purchases, 9.0 stock
counts, 10.0 waste, 16.0 bookings) from the Drive/CSV source into five
**native-storage CPTs**, with a structure-match report, ingest-quality flags,
idempotent upserts and offset pagination. It is the heaviest tool in the
toolkit and the one whose value depends entirely on a second backend existing.

This runtime has one table backend **by decision**: §164.71 deliberately dropped
the native-storage hint, the CPT-backed tables and the `fnb_native_cpt` source,
because every read goes through `TabularReaderInterface`. A faithful port today
would write rows that nothing reads — `fnb_read_table` and all 21 metric tools
read `FnbTableSource` — which is precisely the “free success” the adapter probe
gate exists to refuse.

It is **deferred rather than declined** because the import itself is sound the
moment something reads the store; the prerequisite is a native-table store plus
a read fallback in `FnbTableSource`, which changes the storage contract for the
whole toolkit and belongs in its own proposal. That is the same shape as
`get_queued_videos` in the register: a query (here, a write) that is fine once
the missing half exists. `lib/core/resources/toolkit-parity-decisions.json`
records it, and `bin/toolkit-parity.php --check` validates the entry against the
tree.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,392 / 20,807** (10 tests added) |
| adapter `ToolProbesTest` | **160 / 1,144**, 2 skipped, 0 failures — the audit probe reads the “no queryable log” path because the adapter binds no `SystemLogInterface` |
| tool readiness audit | **380 / 380**, servable **361** (+1), no violations |
| toolkit parity | **159 of 854**; `food-beverage` **22 ported**, 0 declined, **1 deferred**, **0 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

### What the toolkit proved

The 22 served tools share one table source, one metric engine and one settings
object; the report, export and draft paths read the engine rather than
recomputing anything; and the audit viewer reads the host's log rather than a
plugin-private option. The port kept the money rounded by the tenant currency,
the zeroed-but-answered metrics, the date filter that counts undated rows, and
the empty-success budget check — each pinned where it is surprising, and each
recorded in a readiness row with the deviation named.

The queue's next toolkit is `orchestration` (36 tools).

## 164.77 `orchestration`, measured — and four utilities that need no store

The toolkit declares **36 tools** in `addons/pro/includes/tools/orchestration/`.
One is already served (`verify_information`, ported with the research
work), so **35 are real work**. The `blueprint-installer` file in the same
directory is a helper, not a tool, and is not counted.

Measuring first was worth it: the 35 divide by the seam each group needs, and
only one group needs nothing.

| group | tools | seam it needs |
|---|---:|---|
| `schedules` | 10 | a schedule store and runner — the Laravel app skeleton already has `Schedule`, `RunSchedule`, `ScheduleRunner` and `ScheduleTiming`, so this is a contract over existing work rather than new machinery |
| `autonomous loop` | 8 | a session-state store (statuses, iterations, budget), which nothing here has yet |
| `templates and task plans` | 8 | a template/plan store; `create_execution_prompt` also reads a plan |
| `research and content` | 5 | a model client, the content store and the Paper Store |
| `pure utilities` | 4 | none |

Slice 1 takes the four utilities, because each is a pure function of its
arguments and can be proven now, before any contract is invented.

### `generate_password`

`random_int()` over a combined charset. Three behaviours are pinned:

- **No per-set guarantee.** A password can contain no digit with `numbers`
  enabled; the generator does not reserve one character per class. The strength
  score shows the consequence and is not corrected.
- **`avoid_ambiguous` filters letters and digits only.** The symbol set is fixed
  and happens to contain none of `0 O I l 1`.
- **The strength scale cannot reach 4.** Three points for length plus four for
  class diversity is a maximum raw score of 7, halved and floored to 3. The
  documented 0–4 top is unreachable, and a test pins that.

Length 12–128 and count 1–10 are refused by the tool. The vault service it
delegates to silently resets an out-of-range length to 20; the tool validates
first, so that path is not reachable. Flags are `read-only` and `local-only` —
**not** idempotent, because the same arguments deliberately produce a different
secret.

### `convert_html_to_markdown`

The description claims turndown.js; the code is a regex chain, and its own usage
guidance admits it. The port keeps the chain, with its order-dependent edges:
images match `src` before `alt` (an alt-first image loses its alt), links keep
only a double-quoted `href`, dropping links keeps the text while dropping
images removes them, and `compression` is a **signed** percentage string. One
edge is narrowed: WordPress's `empty()` check refuses the string `"0"`; here it
is a valid document.

### `extract_structured_data`

The description promises CSS selectors; the code does not implement them. A
selector shaped like `[a-z0-9-.#[]]+` is used as an **element name**, so
`.product-price` is searched for as `<.product-price>` and matches nothing.
Anything else is spliced into `/pattern/i` with no escaping — a pattern
containing `/` is an invalid regex and answers an empty field rather than
raising the warning WordPress would emit. `csv` output does not quote values.

### `analyze_data_patterns`

The four analyses share one summary. Non-numeric entries are dropped, but
numeric strings survive `is_numeric`, so `min`/`max` can return the string they
were given while the mean and median are numeric. Frequency buckets round to two
decimals, correlation is Pearson against the **position** (the tool has one
dataset), outliers use nearest-rank quartiles, and an unknown `analysis_type`
adds no block rather than erroring.

One crash is fixed: a one-element dataset divided by an empty second half in the
trend split and raised `DivisionByZeroError` on PHP 8. It answers `stable` now,
and the readiness row records the deviation.

### A bug the tests caught in the port, not the source

The first `byTag()` wrote `1 === preg_match_all(...)`. `preg_match_all` returns
the number of matches, not 1, so every multi-match extraction returned an empty
string. The WordPress condition is a plain truthiness test, and the port now
matches it. Two expectations were also corrected to the source rather than the
other way round: missing **and** empty selectors share one error code, and a
non-array selector is a different error from an empty map.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,427 / 20,889** (35 tests added) |
| adapter `ToolProbesTest` | **164 / 1,178**, 2 skipped, 0 failures — all four probes exercise real work |
| tool readiness audit | **384 / 384**, servable **365** (+4), no violations |
| toolkit parity | **163 of 854**; `orchestration` **5 ported**, 0 declined, 0 deferred, **31 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

## 164.78 `orchestration`, slice 2 — the autonomous loop

The eight loop tools describe one object: a session with a lifecycle status, an
iteration count against a cap, a token spend against a budget, a health verdict
and a breaker state. WordPress keeps it in an option transient with a CCT behind
it and reads it back in four different ways. The port keeps the shape and makes
the seam explicit.

### The contracts

`AutonomousSessionStoreInterface` is the session object: `create`, `find`,
`update`, `query`, with the documented statuses, health values and defaults.
`JsonAutonomousSessionStore` is one JSON file per id, atomic rename, a strict
single-segment id rule, and **hydration on read** — every reader can assume a
field exists, so a host that stores only what changed still satisfies the tools.
`ExecutionHistoryStoreInterface` is the append-only call log behind
`get_loop_metrics`: one JSON-lines file per session under `LOCK_EX`, read with a
cap, because an unbounded read of a runaway loop is a second bug on top of the
first. A host that records no history is not broken: the metrics tool binds the
contract optionally and answers the WordPress "no execution history" payload.

### The breaker, at industry defaults

The canonical three states — **closed / open / half-open** — are Fowler's, and
the defaults follow the production guidance collected for this slice (the
"Agent Circuit Breaker Specification"): open at a **50% error rate over a volume
window** (5 calls), a **consecutive-failure trip** (3), a **cooldown** (300 s)
before **one probe**, and **no fallback** — the caller decides what an open
breaker means. Retries belong *inside* the breaker; this class counts the final
outcome it is told about and does not retry.

The state lives on the session, not on the object, so a different process can
inspect it and a restart does not forget the verdict.

### The termination gate

`check_exit_conditions` implements the two-key rule: exit requires the completion
score at threshold **and** an explicit `exit_signal`, with hard limits
(iterations, tokens, expiry, breaker) able to force a guarded exit. That is the
shape the current guidance recommends — an independent signal plus a cap, never
a model's self-report alone ("Evidence-Carrying Termination"; agentpatterns
"Premature Completion"). `detect_completion_indicators` only *scores* text; it
never decides an exit.

### Twelve measured behaviours, fixed or pinned

**Fixed**

1. **Eight of ten completion regexes did not compile.** A non-capturing `(?:`
   was mangled to `( ? ( :` in the WordPress source, so `preg_match()` failed
   and the indicator never fired. The groups are restored; the test proves the
   corrupted literal does not compile and the restored one matches.
2. **The breaker opened one failure late.** `record_failure()` incremented the
   stored counter and then re-read the *stale* session to compare it with the
   threshold, so with `max_consecutive_errors = 3` it opened on the fourth. The
   port compares the incremented value and opens on the third.
3. **The `WP_Error` marker could not match.** The check lowercased the output
   and then searched for the literal `WP_Error`; the port searches `wp_error`.
4. **`analyze_current` counted sessions with a `$wpdb` scan** of
   `_transient_mcp_ai_session_*`. It is re-based on
   `AutonomousSessionStoreInterface`: active sessions from
   `query(statuses: [active])`, and λ/W from completed sessions whose
   `completed_at` is inside the last 24 hours — the window the transient TTL
   used to imply.
5. **`analyze_loop_health` used `ReflectionMethod` into the management tool** to
   read and write the session. The port uses the store and the breaker; a tool
   reaching into another tool's private methods is not a seam a second host can
   honour.
6. **The health analyser left the breaker half-written.** WordPress wrote the
   bare `circuit_breaker = 'open'` string, leaving `circuit_breaker_open` and
   `circuit_opened_at` stale, so the next `allowExecution()` half-opened
   immediately. `forceOpen()` writes the state, the flag and the stamp together.

**Pinned**

7. **A missing `circuit_opened_at` counts as elapsed.** Records written before
   the stamp existed must not block forever; kept.
8. **`completion_met` ignores the plan bonus** while `total_score` includes it,
   so the gate can read `false` beside a score at threshold. The plan reader is
   a later slice; the ordering is kept for it.
9. **`force_check` is read and never used.** Accepted and inert, and the schema
   says so rather than dropping a parameter callers send.
10. **Transitions are permissive.** `resume` turns a completed session back into
    `active`, `pause` and `stop` accept any state, and only an expired session
    is refused. A strict machine belongs in the store; changing it here would be
    a silent behaviour change. The readiness row names the gap.
11. **`update` writes only six metric keys** and always stamps `last_activity`.
    An invented key is ignored rather than stored, and the stamp keeps a running
    session out of the expiry sweep.
12. **The stuck-with-5-errors breaker rule cannot fire on its own.** A stuck
    pattern already makes health `critical`, which opens the breaker; the second
    test is subsumed. Kept for parity and named rather than quietly deleted.

### Per-tool notes

`get_session_status` derives everything and writes nothing; `include_plan` is
accepted but `task_plan` is omitted until a plan reader exists — an absent key
says "no reader", an empty object would say "the plan is empty".

`manage_autonomous_session` does not log itself to the history: the loop runner
writes `ExecutionHistoryStoreInterface`, so a management call does not
double-count the calls `get_loop_metrics` is measuring.

`get_loop_metrics` keeps three WordPress arithmetic quirks: the average duration
divides by **every** call (untimed records count as zero), a missing `success`
key counts as success (older rows predate the field), and the frequency and
error maps cap at 10 while the totals do not.

`configure_circuit_breaker` reports the values the breaker will actually use, so
a hand-edited 0% threshold shows as the 10% floor; `update` rejects unknown and
out-of-range keys **by name** without aborting the accepted ones; `reset` deletes
the row so the defaults come from code.

`analyze_loop_health` adds three guards the source did not have: a zero
`max_iterations` or `token_budget` reads as 0% pressure when nothing was used and
100% when something was, instead of dividing by zero; `error_rate` is always
present (WordPress omits it on short lists); and an action without a tool name
contributes nothing to the repetition or stuck tests rather than warning. One
quirk is kept: a session analysed with no actions reports `warning`, because a
0% velocity reads as "poor". Nothing acts on it — `should_pause` stays false and
the recommendation still says the loop is good.

`calculate_orchestration_capacity` guards the divisions the source trusted: a
zero cap falls back to 10, negative queue counts become their magnitudes (a
queue cannot be negative, and WordPress would have produced a negative wait),
and the "can handle Nx more load" line is omitted when L is 0, which float
underflow can reach even though positive λ and W cannot.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,479 / 21,132** (52 tests and 243 assertions added) |
| adapter `ToolProbesTest` | **172 / 1,230**, 2 skipped, 0 failures — eight new probes |
| tool readiness audit | **392 / 392**, servable **373** (+8), no violations |
| toolkit parity | **170 of 854**; `orchestration` **13 ported**, 0 declined, 0 deferred, **23 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The session and history stores are bound in the adapter to
`storage_path('autonomous-sessions')` and `storage_path('execution-history')`,
overridable with `NVOOS_AUTONOMOUS_SESSIONS_ROOT` and
`NVOOS_EXECUTION_HISTORY_ROOT`. The start probe writes through the real store,
so the storage convention is proven end to end rather than mocked.

## 164.79 `orchestration`, slice 3 — templates and task plans

The remaining eight declared tools are two families over two WordPress post
types: `mcp_task_plan` (`create_task_plan`, `get_task_plan`,
`update_task_plan`, `instantiate_template`, `create_execution_prompt`) and
`mcp_task_template` (`create_template`, `list_templates`,
`instantiate_template`, `seed_template_library`). **Both are CPTs, so both map
to `ContentStoreInterface`** — the classroom pattern — and this slice adds no
store contract at all.

### The shared format, in one place

`TaskPlanMarkdown` renders, parses, checks/unchecks and appends the checkbox
list, so the six tools that touch it cannot drift. Two parse dialects are kept
and named: the strict `^- \[([ x])\] (.+)$` the plan readers use, and the loose
`/^\s*-\s*\[ \]\s*(.+)$/` the prompt builder uses for hand-indented lists.
CRLF is normalised on entry. `TaskTemplateCategory` is the single category
vocabulary; `TaskTemplateLibrary` is the fourteen template bodies, extracted
verbatim from the WordPress seeder and verified byte-identical.

### Twelve measured behaviours, fixed or pinned

**Fixed**

1. **`new_tasks` destroyed the plan.** The pattern's non-capturing group had
   been mangled to `( ? ( :`, so `preg_replace` returned null and
   `wp_update_post` stored an empty body. Appending is line surgery now, which
   also removes `$1` replacement semantics from caller text. A test appends a
   fourth task and asserts the first three and the goal survive.
2. **The template validator rejected its own library.** Six categories were
   accepted and fourteen declared, so eight of the fourteen failed on an empty
   host. `TaskTemplateCategory` serves create, list and seed.
3. **An instantiated plan lost its goal and status.** `instantiate_template`
   wrote *unprefixed* meta (`status`, `task_count`, `progress`, `project_id`)
   while every reader used `_`-prefixed keys, and it never wrote `goal` at all.
   The port writes one schema, so `get_task_plan` and `create_execution_prompt`
   see the same plan.
4. **`update_task_plan` had no authorisation check.** Any user with
   `edit_posts` could mark tasks on anyone's plan. The content store's edit rule
   now applies — the same rule `create_task_plan` already applied on its update
   path.
5. **The prompt was HTML-escaped.** `esc_html()` turned `Client's` into
   `Client&#039;s` and `&` into `&amp;` inside the instructions sent to the
   model. A prompt is plain text; escaping belongs to the transport envelope.
6. **`overwrite` duplicated the library.** WordPress skipped the existence
   check and called create without an id, adding a second copy of all fourteen.
   Overwrite now updates the existing template by name.
7. **`usage_count` increments bypassed edit rules.** Instantiating another
   user's published template wrote its meta unconditionally. The counter is
   incremented only when the caller may edit the template; the plan is still
   created and the counter is reported as it stands.
8. **`list_templates` did not sort by the field it named.**
   `orderby => meta_value_num` without a `meta_key` ordered by neither
   `usage_count` nor `success_rate`. The filtered set is fetched (at most 200)
   and sorted in PHP; `truncated` marks the ceiling. `total_count` is the
   matching total, not the page size.

**Pinned**

9. **A new plan stores status `draft` while the item is published**, which is
   the WordPress lifecycle. One correction rides with it: an update that
   regenerates the body resets `completed_count` and `progress` to 0, because
   the new body has nothing checked, where WordPress left the old counters
   stale. The status is left alone.
10. **An out-of-range `task_index` is silently ignored**, and new tasks are
    appended rather than merged by text. The recomputed counts in the response
    are the signal that nothing applied.
11. **`include_history` is accepted and inert**, and `success_criteria` is read
    by the prompt builder without any plan tool writing it.
12. **The prompt's progress comes from the stored counters, not the markdown.**
    It is the one reader that can disagree with `get_task_plan` after a hand
    edit; kept because changing it would alter what a running loop is told
    mid-session.

### Per-tool notes

`get_task_plan` derives everything from the markdown and reports `project_id`
only when set; an empty `completed_at` is `null` rather than `''`, and
timestamps are ISO 8601 UTC.

`create_template` creates a draft and reports `storage_type: content_store`;
the duplicate `status` meta row WordPress wrote beside `post_status` is
dropped, because only the item status was ever read back.

`seed_template_library` checks existence with one query over up to 200 existing
templates and matches titles exactly and case-insensitively. One failed
template is reported in `errors` while the rest are attempted, as WordPress
does. It is gated on `manage_options` (WordPress declared no capability), and a
second run is a no-op.

`instantiate_template` requires every placeholder to be supplied and lists the
missing names; a non-scalar variable is skipped rather than cast to `Array`;
the merged `config` is returned but not stored, as WordPress did.

`create_execution_prompt` honours a `tasks_parsed` JSON list when a host writes
one and falls back to the loose markdown parse otherwise; `prompt_length` is a
byte count.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,525 / 21,365** (46 tests and 233 assertions added) |
| adapter `ToolProbesTest` | **180 / 1,282**, 2 skipped, 0 failures — eight new probes |
| tool readiness audit | **400 / 400**, servable **381** (+8), no violations |
| toolkit parity | **179 of 854**; `orchestration` **21 ported**, 0 declined, 0 deferred, **15 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The adapter's probe host now defines `read_content`, `edit_content` and
`delete_content` permissively in `ToolProbesTest`, matching the dedicated
content-store suites; per-user policy remains covered there.

## 164.80 `orchestration`, slice 4 — the ten schedule tools

The declared group is ten tools over one record: `create_pro_schedule`,
`list_pro_schedules`, `update_pro_schedule`, `delete_pro_schedule`,
`dry_run_pro_schedule`, `get_schedule_run_history`,
`get_schedule_latest_result`, `plan_schedules_from_workflow`,
`render_schedule_result` and `configure_schedule_widget_defaults`.

### The seam is a new contract, not the app's table

WordPress keeps definitions, run history and result envelopes in three options
(`wp_mcp_ai_pro_schedules`, `wp_mcp_ai_pro_schedule_history`,
`wp_mcp_ai_pro_schedule_results`) managed by a 5,000-line static class. The app
skeleton has an `nvoos_schedules` table and a `ScheduleRunner`, but a tool in
`lib/core` cannot touch Eloquent, and no tool should depend on the schema of a
host's runner. So the seam is **`ScheduleStoreInterface`**: definitions, runs
and envelopes behind nine methods, with `JsonScheduleStore` as the bundled
implementation. One file per id holds all three arrays, as the autonomous
session store does, because a history entry and its result envelope belong to
the same definition and one atomic write cannot leave them disagreeing. A host
that already has a runner can implement the contract and keep the tools.

### The id is derived, and that is load-bearing

WordPress hashes a type-specific key with `md5()`: hook plus args for `task`,
type plus name plus first-run timestamp for everything else. Two consequences
the port keeps:

- Re-creating an **identical task schedule is an upsert**. `save()` therefore
  preserves the history and results already stored for the id — editing a task
  by re-creating it does not erase its run record. A test creates the same task
twice, asserts one record, and asserts a run appended in between survives.
- Two non-task schedules planned for the same 09:00 would collide.
  `plan_schedules_from_workflow` therefore offsets each successive first run by
  60 seconds, exactly as WordPress did, and a test pins the 60-second gap.

### The re-arm rule, written into the record

`update_pro_schedule` is a partial update, and four interactions with
`timestamp` are easy to get wrong. They are now stated in the tool and pinned
by tests:

1. An explicit `timestamp` wins after validation.
2. Disabling stores `timestamp = 0`; a paused schedule reports `null`.
3. A consumed one-shot (`schedule: single`, `run_count > 0`) is **not** re-armed
   by a metadata-only save — renaming it does not make it fire again.
4. A cadence change or a re-enable arms exactly one new interval from now.
   A metadata change on a live schedule leaves the armed time alone.

### Intervals are fixed seconds, and the drift is named

Every slug in `ScheduleInterval` is a number of seconds — the four core
intervals plus the eight the Pro addon registers. `daily` is 86,400 s, not "the
same local time tomorrow", so a daily schedule shifts by an hour in local time
across a daylight-saving change. That is WordPress's behaviour, documented in
Trac #17957, and the same model every fixed-interval scheduler has: a job that
must keep a wall-clock time reschedules a single event after each run instead of
extrapolating an interval. The port keeps the model and states the limitation in
the readiness rows rather than silently switching to cron expressions; the
`next_runs` projection extrapolates from the stored timestamp at the fixed
interval. The research basis for that choice is the misfire handling in Quartz
Scheduler (`MISFIRE_INSTRUCTION_DO_NOTHING` skips missed fire times and waits for
the next scheduled one), which is the semantic `wp_next_scheduled()` has, not a
catch-up loop.

### Results are stored, never delivered

No tool in this group executes anything. `result_delivery`, the notify
credentials and `max_retries` are stored for a host runner; `dry_run_pro_schedule`
resolves nothing external, and `render_schedule_result` renders an envelope a
runner already recorded. That is the same split as WordPress, where the manager
records and the tools read, and it keeps every tool's capability flags honest.

### Four divergences, each deliberate

1. **`html-safe` escapes instead of running kses.** WordPress passed
authenticated `raw` + `html-safe` output through `wp_kses_post()`. The core has
no kses, and returning unfiltered HTML from a tool is the wrong default, so
`raw` mode escapes every value with `htmlspecialchars(ENT_QUOTES, 'UTF-8')`.
2. **`widget_defaults` merges deeply.** WordPress merged `display` with a
shallow `array_merge()` and re-sanitised, so supplying only
`widget_defaults.title` reset the stored `render_mode` to `summary-card` and
`refresh_interval` to 0 — silent data loss in a partial update whose schema
gives the caller no warning. Both display writers (`update_pro_schedule` and
`configure_schedule_widget_defaults`) merge that one nested object key-by-key;
a caller that supplies the whole object sees identical behaviour.
3. **An unset timestamp is `null`, not 1970.** `wp_date(DATE_ATOM, 0)` rendered
`1970-01-01T00:00:00+00:00` for a run that never started. The shared
`ScheduleDefinition::iso()` returns `null` for 0, so "no run" stays
distinguishable from a run at the epoch.
4. **`workflow_builder` is declined as a schedule type.** The WordPress manager
supported it but verified the workflow against the `wp_mcp_ai_pro_workflows`
option store, which the core does not have, and the tool schema never offered
the type. `create_pro_schedule` accepts the four types the manager genuinely
implements.

### Capabilities are the effective ones

Every schedule tool except two declares `edit_posts` in WordPress, but
`execute()` checks `manage_options` first, so that is what the port declares for
`create_`, `list_`, `update_`, `delete_`, `dry_run_`, `plan_`, and
`configure_`. `get_schedule_latest_result` and `render_schedule_result` accept
`read_private_posts` **or** `manage_options`; the core contract names one
capability, so both declare the narrower `read_private_posts`, which an
administrator role holds.

### Per-tool notes

`list_pro_schedules`' type enum is the four the manager supports; WordPress
offered three and omitted `assistant_run`, so a filter for it was impossible.
Filters are ANDed, ordering is priority then name, and `next_run` is `null` for
a paused or unarmed schedule.

`dry_run_pro_schedule` keeps WordPress's four warnings — paused, no cadence,
unregistered cadence, no projected runs — and reports `timezone: UTC` because
the core has no site timezone. The action preview omits the assistant title
(`''`), since there is no post store to resolve it from.

`get_schedule_run_history` reads the 50-entry ring buffer, newest first, with
`limit` clamped to 1–50. `get_schedule_latest_result` treats "no runs yet" as a
success with `envelope: null`, not an error.

`render_schedule_result` passes the schedule and envelope to a renderer that is
pure with respect to them; only the `timeline` mode reads the store, because it
is inherently multi-run. Unknown modes fall back to `summary-card`.

`plan_schedules_from_workflow` reproduces the WordPress keyword tables in their
order: explicit `suggested_*`, then cadence inference (hourly → twice-daily →
monthly → weekly → daily, then a follow-up/review/check/respond/monitor
heuristic), then the batch default. An **invalid** `suggested_cadence` falls to
the default rather than to inference, as it did before. Text input supports
`## Category` headings and strips bullet/number prefixes. Three site-state
defaults have no core counterpart and are named rather than faked: times are
UTC, `notify_email` defaults to `''` instead of `admin_email`, and
`default_assistant_id` is accepted without a post-status check; an invalid
recipient is dropped rather than failing the batch.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,575 / 21,522** (50 tests and 157 assertions added) |
| adapter `ToolProbesTest` | **190 / 1,335**, 2 skipped, 0 failures — ten new probes |
| tool readiness audit | **410 / 410**, servable **391** (+10), no violations |
| toolkit parity | **189 of 854**; `orchestration` **31 ported**, 0 declined, 0 deferred, **5 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The adapter binds `ScheduleStoreInterface` to a `JsonScheduleStore` rooted at
`oos.schedules_root` / `NVOOS_SCHEDULES_ROOT`, defaulting to
`storage_path('schedules')`. The ten probes create one schedule through the real
store, list it back, and use an impossible id for every reader and writer, so
both the success and refusal paths run against the bound implementation.

Next is **research and content** (5 tools), the last of the declared
`orchestration` group.

## 164.81 `orchestration`, slice 5 — research and content, and the group closes

The five remaining declared tools are not one family; they are what was left
over: two research producers (`aggregate_research_data`,
`generate_research_report`), one research consumer (`create_post_from_research`),
one schedule wrapper (`schedule_channel_broadcast`) and one portability surface
(`export_assistant_blueprint`). With them the toolkit reaches **36/36**.

### What each one needed

| tool | collaborators | notes |
|---|---|---|
| `aggregate_research_data` | none | pure: dedupe, extract, render markdown/JSON/HTML |
| `generate_research_report` | `WebSearchTool`, `ProviderRouter`, `PaperStoreInterface`, `ContentStoreInterface` | all optional; each absence is named |
| `create_post_from_research` | `PaperStoreInterface`, `ContentStoreInterface` | source read, content written |
| `schedule_channel_broadcast` | `CreateProScheduleTool` + `ScheduleStoreInterface` | delegation, not duplication |
| `export_assistant_blueprint` | `AssistantExportInterface`, `FileStoreInterface` | one export engine, two dialects |

### The generator is the whole pipeline, not a plan

The core's own `deep_research` tool returns a framework for the agentic loop to
execute. This Pro tool did the work itself, and the port keeps that: it builds
the query plan, runs `WebSearchTool` per query, builds the WordPress prompt
verbatim (report-type instructions, source list capped at five, JSON output
contract), asks the router for a completion at WordPress's 4,000-token budget,
strips the fence, parses the JSON, and assembles the document with the table of
contents and references. `ProviderRouter` is bound in the adapter, so the whole
path is live; a host without one gets `completion_unavailable`, the same refusal
every completion tool gives.

Two parameter differences matter and are mapped rather than passed through: the
core search tool reads `count` where WordPress read `max_results`, and its items
carry `description` where the WordPress pipeline read `snippet`. The port passes
`count` and maps `description` → `snippet` when building sources.

### Five corrections

1. **`aggregate_research_data` reported defaults it had not applied.** An
   unknown `deduplication_mode` or `output_format` was echoed back while the
   tool behaved as `moderate`/`markdown`. The port normalises and reports the
   effective value.
2. **Every excerpt got an ellipsis**, including a ten-character one. It is
   appended only when the 200-character excerpt actually truncated.
3. **Marking a research record published erased it.** `create_post_from_research`
   saved a fresh record with id/title/type/status/description/meta and no
   `body`, so the report it had just published disappeared from the store. The
   port merges status and meta into the record it read.
4. **A failed side effect vanished.** WordPress dropped a failed Paper Store
   save or draft creation without a word. The port returns the report and adds
   `paper_store_error` / `draft_post_error`.
5. **`export_assistant_blueprint` called itself read-only** while offering
   `save_to_media`, which writes an attachment. The port declares the write it
   can perform; a read-only profile is right to gate it.

### Adaptations with no WordPress counterpart

- **The category.** `create_post_from_research` took a `category_id` and handed
  the integer to `wp_insert_post`. The core content contract names terms, so the
  id is resolved through `listTaxonomyTerms('category')` and an unknown id is a
  validation error — passing it through would create a term named "12".
- **The body and kses.** WordPress ran `wp_kses_post()`. The core has no kses;
  the research body is stored as supplied, exactly as `create_post` already does.
  Content policy belongs to the store and the renderer, and a block or shortcode
  body must not be tag-stripped on the way in.
- **Similarity cost.** `similar_text()` is super-linear, so deduplication
  compares at most the first 2,000 characters of each source and short-circuits
  on an exact content hash. Same verdicts for the syndicated-article case, a
  bounded worker cost for every case.
- **The blueprint dialect.** `export_assistant_blueprint` reads the canonical
  `nvoos-assistant` bundle through `AssistantExportInterface` — the same engine
  `export_assistant`, the REST controller and the admin page share — and renames
  the entry into the blueprint shape (`name`, `description`, `meta.instructions`,
  `meta.available_tools`, provider/model/temperature). A host with no file store
  gets an explicit `media_store_unavailable` refusal instead of a success with
  no file.

### Not ported

The three optional **Jev** passes in the generator — relevance filtering of
sources, citation verification, and the capped revision of flagged claims — are
absent. They are gated on a WordPress setting and call the TypeSafe Jev client,
which the core has no counterpart for, and every one of them was fail-open: on
error or absence the sources and the report passed through untouched. Their
absence therefore changes no required output; it removes an optimisation, not a
behaviour. The readiness row names it rather than implying the passes ran.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,607 / 21,634** (32 tests and 112 assertions added) |
| adapter `ToolProbesTest` | **195 / 1,375**, 2 skipped, 0 failures — five new probes |
| tool readiness audit | **415 / 415**, servable **396** (+5), no violations |
| toolkit parity | **194 of 854**; `orchestration` **36 ported**, 0 declined, 0 deferred, **0 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The blueprint probe expects a refusal in this host on purpose: the probe suite
registers only the package provider, while `AssistantExportInterface` is bound
by the app skeleton. That is the same split `export_assistant` already has, and
the probe still proves the tool is registered and refuses honestly.

`orchestration` is complete. Next in the queue is **`calendar-booking`**
(34 declared), then **`gtmetrix`** (23).

## 164.82 `calendar-booking`, slice 1 — the appointment core

The toolkit declares 34 tools across nine groups, all built on three
WordPress CPTs, one option and two third-party booking plugins:

| group | tools | storage |
|---|---:|---|
| appointment core | 5 | `mcp_appointment` |
| availability | 4 | `mcp_appointment`, `mcp_blocked_time`, `wp_mcp_ai_business_hours` |
| events | 6 | `mcp_ai_event` |
| services | 2 | `mcp_service` + `mcp_service_category` |
| booking links & optimisation | 2 | `mcp_booking_link`, `mcp_appointment` |
| notifications | 4 | `mcp_appointment` + email |
| triage | 2 | `mcp_appointment` |
| JetAppointment / JetBooking | 7 | plugin adapters |
| Google / Outlook sync | 2 | OAuth + external APIs |

This slice ports the **appointment core**: `create_appointment`,
`update_appointment`, `cancel_appointment`, `reschedule_appointment` and
`get_appointment_details`. All five read and write one record type through
`ContentStoreInterface`, keeping the `mcp_appointment` type and the
`_client_*` / `_appointment_*` / `_status` / `_created_*` / `_activity_log` /
`_change_history` meta family so an export round-trips.

### Times are UTC wall-clock strings, parsed strictly

WordPress stored site-local `Y-m-d H:i:s` (`current_time( 'mysql' )`). The core
has no site timezone, so stored times are **UTC** and the tools say so. The
parser accepts the documented format and ISO 8601 with an explicit offset, and
normalises both to UTC; a relative expression such as `next friday` is refused.
WordPress passed the string through `strtotime()`, then stored it **verbatim** —
so a relative booking was stored as the literal text and never matched a slot.

### Corrections

1. **The overlap query missed contained appointments.** Two OR'd range clauses
   together caught the cases where an existing booking starts inside the new
   slot or encloses its end, but not one **entirely inside** it: for new
   09:00–12:00 and existing 10:00–11:00, neither `existing.start <= 09:00` nor
   `existing.end >= 12:00` holds, so the double booking was accepted. The port
   uses the half-open test — `existing.start < new.end AND existing.end >
   new.start` — three ANDed meta clauses the adapter executes directly.
2. **A replace reported a status it had not stored.** `create_appointment`
   leaves `_status` alone on the update path but returned `confirmed`; a
   pending appointment was reported as confirmed. The port returns the stored
   status.
3. **A start-only update kept the old end.** Moving the start forward without
   an end left the previous end in place — possibly before the new start — and
   WordPress stored the negative-length booking. The port keeps the duration,
   the same rule rescheduling uses.
4. **A moved appointment kept its old time in the title.** The title embeds the
   start (`Appointment: {client} - {start}`), and `reschedule_appointment`
   never regenerated it, so every listing advertised the old slot. It is
   regenerated now.
5. **Writes trusted a capability instead of the record.** `update`,
   `cancel` and `reschedule` checked only `manage_options`, then wrote to any
   id; `get_appointment_details` read any id. The port applies the content
   store's `edit` and `read` rules.
6. **`cancelled_by` accepted any string.** It is validated against
   `client` / `staff` / `system` and the effective value is reported.

### Adaptations and what is not ported

- **`target_system` accepts `nvoos` only.** WordPress could route a booking into
  JetAppointment or JetBooking through `WP_MCP_AI_Booking_Adapter_Factory`; when
  the adapter class was absent it silently fell through to the native path. No
  adapter exists here, so any other value is a named validation error.
- **Refunds are not ported.** WordPress raised
  `wp_mcp_ai_process_appointment_refund` and recorded the result. The core has
  no filter that can return a value and no refund gateway, so
  `process_refund: true` reports `refund_processed: false` with a
  `refund_error`, and nothing writes `_refund_processed`.
- **The reschedule link is prose.** `offer_reschedule` interpolated
  `home_url( '/book-appointment/' )`; the core has no site URL, so the email
  asks the client to reply instead of linking to a page that may not exist.
- **Email is optional and never fails a booking.** The four writing tools take
  an optional `EmailServiceInterface`; a host without a transport, or a
  transport that throws, still completes the write and reports
  `notification_sent: false` plus `notification_error`.
- **The event, availability, service, notification, triage, Jet and external-sync
  groups** are untouched by this slice. The seven Jet tools and the two external
  syncs need a register decision (a plugin adapter contract and an OAuth
  credential store respectively) before they can be ported; the other twenty are
  straightforward continuations of the CPT-over-content-store pattern.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,641 / 21,761** (34 tests and 127 assertions added) |
| adapter `ToolProbesTest` | **200 / 1,401**, 2 skipped, 0 failures — five new probes |
| tool readiness audit | **420 / 420**, servable **401** (+5), no violations |
| toolkit parity | **199 of 854**; `calendar-booking` **5 ported**, 0 declined, 0 deferred, **29 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The create probe runs with the conflict check on, so it also proves the
three-clause meta query against the real adapter's JSON meta column — the
refusal path is probed with an impossible id for the other four tools.

Next in the queue: **availability** (4), then **events** (6), **services** (2),
**booking links and optimisation** (2), **notifications** (4) and **triage** (2).

## 164.83 `calendar-booking`, slice 2 — the availability rules

This slice ports the four availability tools: `set_availability_rules` and
`block_time_slot` write, `check_availability` and `get_available_slots` read,
and all four share one definition of what a working day is.

### Where the rules and the blocks live

WordPress kept the weekly rules in the `wp_mcp_ai_business_hours` option as
`{monday: {enabled, start_time, end_time, slot_duration, buffer_time}, …}`. The
port writes the same shape under the `business_hours` key of
`SettingsStoreInterface`; the adapter settings store is already namespaced, so
the key does not repeat the prefix and no migration is needed.

Blocked ranges are `mcp_blocked_time` records with the same `_start_time` /
`_end_time` pair appointments use, plus `_reason` and `_blocked_by`, read
through `ContentStoreInterface`. One overlap test therefore serves both record
types, and a block is visible to both readers without a second query shape.

### Corrections

1. **Business hours were only a weekday test.** `check_business_hours()`
   returned true as soon as the day was `enabled`, so a 22:00 booking on an
   enabled Monday was reported as within hours. `withinHours()` requires the
   whole span to sit inside the day `start_time` to `end_time` window now; no
   stored rules at all still means always open, which is what an empty option
   returned.
2. **`get_available_slots` ignored blocked ranges and `buffer_time`.** The tool
   guidance promised blocked ranges would be respected, and the rules stored a
   buffer, but neither was read. A slot overlapping a block is omitted, and the
   cursor advances by `slot_duration + buffer_time`.
3. **The weekday came from the server timezone.** `gmdate( 'l', strtotime(
   $date ) )` parses midnight in the PHP default zone and formats in UTC, which
   is the previous day west of Greenwich. The port reads the date as UTC and
   rejects an impossible one.
4. **The duration was unbounded.** `absint` went straight into the loop, so `0`
   produced zero-length slots and a huge value produced none. It is clamped to
   15–480 minutes.
5. **`set_availability_rules` stored any string.** `9am` became a rule no
   reader could parse, and a reversed window produced a day with zero bookable
   slots. Only `HH:MM` is accepted, and the closing time must be after the
   opening time.
6. **`block_time_slot` accepted reversed or unparseable spans.** It checked
   only that both strings were non-empty. The port requires a real calendar
   span, stored in UTC, with the end after the start.
7. **`check_availability` carried the contained-appointment bug.** It now uses
   the shared `AppointmentDefinition::conflicts()`, so the half-open
   `start < new_end AND end > new_start` test catches an appointment that sits
   entirely inside the window, as the writers do.

### Adaptations

- **A day is replaced, not merged.** One call writes one weekday and leaves the
  other six alone; omitted fields take the documented defaults. WordPress did
  the same, and because the schema documents the defaults the caller can see
  exactly what will be written.
- **Overnight windows are unsupported.** `end_time` is a same-day clock value
  and must be after `start_time`; `set_availability_rules` refuses rather than
  storing a rule no reader can interpret.
- **A block never refuses a write.** `create_appointment` does not consult
  blocks; only the two readers do. That is the WordPress division and it is
  deliberate: refusing a write on a block would need an override rule that
  neither runtime has.
- **The buffer is a grid step, not a write rule.** `create_appointment` still
  accepts a back-to-back booking.
- **`per_system` keeps a single `nvoos` entry.** Only the native booking system
  exists here; the map is kept because clients read it and a host that routes
  bookings elsewhere may add keys.
- **Capabilities.** `set_availability_rules` and `block_time_slot` declare
  `manage_options`; the two readers declare `edit_posts`, which WordPress
  declared and never checked. Both are enforced now.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,663 / 21,829** (22 tests and 68 assertions added) |
| adapter `ToolProbesTest` | **204 / 1,433**, 2 skipped, 0 failures — four new probes |
| tool readiness audit | **424 / 424**, servable **405** (+4), no violations |
| toolkit parity | **203 of 854**; `calendar-booking` **9 ported**, 0 declined, 0 deferred, **25 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide (1,737 files) |

The rule-writer probe proves the settings upsert against the bound store, and the
block writer proves a second content type reaches the same store. Both readers
are probed with no stored rules, so an empty grid is still a success envelope
and the schema keys are pinned.

Next in the queue: **events** (6), **services** (2), **booking links and
optimisation** (2), **notifications** (4) and **triage** (2). The final **9**
need a register decision: the seven Jet tools on a booking-adapter contract and
the two external syncs on an OAuth credential store.

## 164.84 `calendar-booking`, slice 3 — events

This slice ports the six event tools: `create_event`, `update_event`,
`delete_event`, `list_events`, `get_calendar_view` and `export_calendar_ics`.
They are the first tools in this toolkit that also read the project-management
types (`mcp_ai_project`, `mcp_ai_task`), because a calendar view and a project
export are meaningless without them.

### Storage and the event shape

An event is an `mcp_ai_event` record with the WordPress `_event_*` meta family:
start and end date as `Y-m-d`, start and end time as `HH:MM` in UTC (both empty
means all-day), the type, the location, the project id and the attendee list.
An event is either all-day or timed, and the writers keep `_event_all_day` in
step with the pair of clock times.

Attendee IDs are stored as **strings**, the encoding `_project_assigned_to`
already uses (see `ProjectRow` and `list_projects`), so membership is a quoted
JSON/PHP LIKE that behaves the same on both runtimes. WordPress stored integers
and filtered against the PHP-serialised array, which cannot be ported to a store
that compares JSON.

### Corrections

1. **A replace clears what it omits.** WordPress offered an update path on
   `create_event` and wrote only the fields it was given, so clearing a time
   left the old value in meta while `_event_all_day` flipped to 1. The port
   writes every key on create and on replace, using `''` or `[]` to clear.
2. **An update is validated.** `update_event` wrote any string into a date or a
   time, never recomputed `_event_all_day` and never checked the result. The
   port merges the stored and supplied values, validates the effective record
   and recomputes the flag whenever a time is touched. Moving the start past the
   end is refused with the field that has to change.
3. **A record that spans the window is visible.** The calendar view asked
   whether the start **or** the end fell inside the range, so a project running
   all year disappeared from a January view. Projects and events use the
   half-open overlap test, the same correction the appointment query needed.
4. **The delete tool honours the record rule.** WordPress deleted any id
   carrying the event type. The port keeps the declaration (`edit_posts`) and
   the check (`delete_posts`) and adds the store delete rule, which is what
   actually protects another author record.
5. **`list_events` validates and sorts deterministically.** A reversed range
   returned an empty list with no explanation and an unknown type matched
   nothing. Both are refused now, and events are ordered by start date, then
   start time, then id.
6. **The export reads what the writers write.** WordPress required the post
   type `project`, collected tasks and events by `post_parent` and read
   `_event_date`. The port reads `_task_project_id` / `_event_project_id` and
   the date meta the writers store, so the export is no longer empty by
   construction.
7. **The export no longer needs a sidecar.** WordPress required a media worker
   route or a filter to generate anything. `IcsWriter` builds the document in
   PHP.

### Adaptations

- The store cannot order by meta, so `list_events` and the view read a bounded
  window (1,000 rows per collection) and sort in PHP; `truncated` says when the
  window held fewer rows than the collection.
- `get_calendar_view` does not merge external bookings. The WordPress argument
  that enabled them was absent from its own schema, and this runtime binds no
  booking adapter.
- The ICS document is emitted in UTC with `Z` times and `VALUE=DATE` all-day
  entries, so no `VTIMEZONE` component is needed. `timezone` is validated and
  written as `X-WR-TIMEZONE` for the display of all-day dates; stored values are
  already UTC and are not shifted twice.
- The stored calendar file is private by default (the WordPress copy was public
  in the uploads directory). `download_file: false` returns the content inline
  and needs no file registry.
- `delete_event` declares `edit_posts` and checks `delete_posts`, as WordPress
  did; the effective protection is the store delete rule.
- The adapter file registry gained `text/calendar` to `.ics`, because libmagic
  types a document that starts with `BEGIN:VCALENDAR`; without the entry the
  store would refuse the file or file it as `.txt`.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,692 / 22,009** (29 tests and 180 assertions added) |
| adapter `ToolProbesTest` | **210 / 1,470**, 2 skipped, 0 failures — six new probes |
| tool readiness audit | **430 / 430**, servable **411** (+6), no violations |
| toolkit parity | **209 of 854**; `calendar-booking` **15 ported**, 0 declined, 0 deferred, **19 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The create probe writes through the bound content store; the two readers answer
an empty store; the two id-taking writers and the export are probed with an
impossible id to pin the refusal path. The ICS file path is proven in `lib/core`
against a fake registry, because this host cannot sniff a file without
`ext-fileinfo`.

Next in the queue: **services** (2), **booking links and optimisation** (2),
**notifications** (4) and **triage** (2). The final **9** need a register
decision: the seven Jet tools on a booking-adapter contract and the two external
syncs on an OAuth credential store.

## 164.85 `calendar-booking`, slice 4 — triage, and four keys that never matched

The two triage readers ask two questions about the same record:
`get_no_show_appointments` lists appointments marked `no-show`, and
`get_unconfirmed_bookings` lists pending bookings with no confirmation stamp.
Both are read-only.

### The readers queried a schema the writers do not produce

The WordPress tools were written against keys that no writer sets, so neither
could ever match an appointment the toolkit created:

| WordPress queried | The writers store |
|---|---|
| `_appointment_status = 'no_show'` | `_status = 'no-show'` |
| `_appointment_date` | `_start_time` |
| `_service_type` | `_appointment_type` |
| `_appointment_notes` | the record content |

`get_unconfirmed_bookings` added `unconfirmed` and the empty string to its
status list; the writer vocabulary is `pending`, `confirmed`, `completed`,
`cancelled`, `no-show`, so `unconfirmed` could never appear.

The port reads the writer keys, and the tests seed a record carrying only the
old keys to prove it is not matched.

### Corrections

1. **The key repair above.** The row keeps the WordPress keys, so clients see
   the same shape; `status` now reports the stored value instead of a literal,
   and `notes` reads the content.
2. **The date window includes both boundary days.** `date_from` compares from
   `00:00:00` and `date_to` to `23:59:59` against the stored UTC start, and an
   invalid or reversed window is refused rather than returning an empty list.
3. **The confirmation stamp exists.** The unconfirmed reader defines its
   question by `_confirmation_sent_at`, and no writer set it, so a booking
   confirmed at creation was listed again. `create_appointment` now stamps the
   key when the transport accepts the notification, and only then.
4. **Ordering is explicit.** No-show results are newest first, unconfirmed
   results oldest first. The store cannot order by meta, so each reader scans a
   bounded window and sorts it, reporting `truncated` when the window was
   smaller than the collection.

### Adaptations

- The row adds `client_phone` (the no-show tool omitted it) and
  `start_time_iso` / `end_time_iso`, the machine-readable pair the appointment
  reader returns.
- The `service_type` argument keeps its WordPress name and filters
  `_appointment_type`, normalised through `sanitizeKey`, so `Consult` and
  `consult` are the same filter.
- The bounded scan now lives in `CalendarScan`, shared by the event readers and
  the triage readers, so "scanned" means the same thing in both families.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,703 / 22,054** (11 tests and 45 assertions added) |
| adapter `ToolProbesTest` | **212 / 1,488**, 2 skipped, 0 failures — two new probes |
| tool readiness audit | **432 / 432**, servable **413** (+2), no violations |
| toolkit parity | **211 of 854**; `calendar-booking` **17 ported**, 0 declined, 0 deferred, **17 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

Both probes answer an empty store, which proves the repaired keys are accepted
by the real adapter rather than failing the query.

Next in the queue: **services** (2), **booking links and optimisation** (2) and
**notifications** (4). The final **9** need a register decision: the seven Jet
tools on a booking-adapter contract and the two external syncs on an OAuth
credential store.

## 164.86 `calendar-booking`, slice 5 — the service catalogue

This slice ports the two service tools: `create_service` writes one record and
`import_services` bulk-loads many. Both go through `ServiceDefinition` for the
rules and `ServiceWriter` for the write, so a single create and an imported row
cannot drift apart — which is what happened in WordPress, where the importer
saved `source_url` and the single writer's schema did not expose it.

### The record

An `mcp_service` content item with the WordPress `_service_*` family, plus three
keys the port adds:

| key | why |
|---|---|
| `_service_key` | `slug(name)` plus the place id; the fallback identity an import matches on |
| `_service_external_id` | the stable source identity, matched first |
| `_service_tags` | WordPress declared `tags` and never wrote it |

Duration and buffer reuse the appointment bounds (15–480 and 0–240), because a
service is bookable only if an appointment can represent it. WordPress accepted
`absint` with no ceiling, so a 10,000-minute service was storable and
unbookable. Price is a bare number with two decimals; the runtime keeps no
currency.

### Corrections: the single writer

1. **Bounds are enforced.** Duration, buffer, participants, price, place and
   source URL are validated, where WordPress stored whatever `absint` and
   `floatval` produced.
2. **`source_url` is in the schema.** WordPress read and saved it, but the
   schema omitted it, so a validated call could never set it.
3. **`tags` is stored.** It was declared and ignored.
4. **Remote images are refused.** `image_urls` needed
   `media_sideload_image()`, which fetches an arbitrary URL server-side; there is
   no SSRF-guarded fetch contract here. `media_ids` replaces it, and the refusal
   is explicit rather than a silent drop.
5. **The store edit rule replaces the author check.**

### Corrections: the importer

1. **`batch_size` only chose when to flush the object cache.** It never chunked
   the import. It is replaced by `offset` and `limit`, with `next_offset`,
   `complete` and `has_more` reported and a 5,000-row ceiling per call, so a
   large import is resumable and never silently truncated.
2. **The dry run validates every row.** It used to count each non-skipped row as
   created, so a negative price or a 10-minute duration looked fine. It now
   builds each record through the same writer and reports would-create and
   would-update separately, with per-row errors.
3. **CSV input is handled structurally.** The parser strips a UTF-8 BOM (which
   used to break the first header), sniffs comma, semicolon, tab or pipe,
   normalises header aliases, reports ignored columns and gives the source row
   number for a failure. UTF-16 is refused with a pointer to convert it.
4. **`auto_create_categories` is effective.** WordPress called
   `wp_set_object_terms()` with the unknown term even when the flag was false,
   which creates it anyway. The port checks the vocabulary and reports an
   unknown category per row.
5. **Deduplication has three rungs.** `external_id`, then `_service_key`, then —
   under the `name` strategy only — a legacy exact title, so a migrated database
   without keys still re-imports idempotently. `name_and_place` never falls back
   to the title, because two places may legitimately share a name.
6. **Duplicates inside one payload are reported.** A dry run and a write both
   skip the second row instead of planning it twice.

### Adaptations

- The places toolkit is not ported, so `place_id` is stored as an opaque
  reference. `default_place_id` and the `name_and_place` strategy still work.
- `media_ids` are stored as references; the media reader enforces access, as it
  does for every other file reference.
- A category is one term, which is what the WordPress tool set; the store
  supports more if a later slice needs it.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,722 / 22,169** (19 tests and 115 assertions added) |
| adapter `ToolProbesTest` | **214 / 1,505**, 2 skipped, 0 failures — two new probes |
| tool readiness audit | **434 / 434**, servable **415** (+2), no violations |
| toolkit parity | **213 of 854**; `calendar-booking` **19 ported**, 0 declined, 0 deferred, **15 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide (1,759 files) |

The create probe writes a record; the import probe runs its dry-run path, so it
proves parsing and per-row validation against the real store without adding rows
the other probes would see.

Next in the queue: **notifications** (4), then the final **9**, which need a
register decision: the seven Jet tools on a booking-adapter contract and the
two external syncs on an OAuth credential store.

## 164.87 `calendar-booking`, slice 6 — booking links, and a stub replaced

This slice ports `generate_booking_link` and `optimize_schedule`. The first is a
working WordPress tool with two storage defects; the second is a stub, so the
port implements the capability rather than the shape it happened to return.

### The booking link record

An `mcp_booking_link` content item with the WordPress meta family, minus the
secret and plus two keys:

| key | meaning |
|---|---|
| `_token_hash` | `hash( 'sha256', $token )`; the only stored copy of the secret |
| `_appointment_type` | the label, also used in the record title |
| `_duration_minutes` | the link's default appointment length |
| `_expiry_days` | the requested window, echoed for round-trip |
| `_expires_at` | absolute UTC deadline, **authoritative** |
| `_max_bookings` | `0` means unlimited |
| `_bookings_count` | incremented by the resolver, starts at `0` |

`_expires_at` is the authority because it is absolute. Recomputing from
`_expiry_days` later would move the deadline every time the record was read, and
no WordPress consumer ever did the conversion at all. The relative value is kept
only so an exported record reads back the numbers it was created with.

### Corrections: the link

1. **The token is hashed at rest.** WordPress wrote `_token` verbatim, so every
   backup, replica and support export contained working booking links. The token
   is returned once and only its hash is stored; `findByToken()` hashes the
   presented value and matches one field, so a wrong token and a missing link are
   indistinguishable.
2. **Expiry is absolute.** See above.
3. **The bounds are enforced.** Duration reuses the appointment bounds (15–480),
   expiry is capped at 3,650 days, and a negative `max_bookings` is refused.
   WordPress ran `absint` over all three, which turned a negative cap into
   "unlimited" — the opposite of the request.
4. **The capability is the one WordPress enforced.** The WordPress class declared
   `edit_posts` and then required `manage_options` inside `execute()`. Minting a
   public access grant is an administrative act, so the port declares the
   enforced capability. `optimize_schedule` has the same discrepancy and the same
   decision.
5. **`booking_url` is not fabricated.** The runtime has no site URL, so the
   payload always carries `booking_path`. `booking_url` is filled only when a
   `RuntimeIdentityInterface` is bound and reports an http(s) `site_url`.

### Corrections: the optimiser

WordPress returned `utilization_rate: 0.75` and `average_gap_minutes: 30` as
literals whatever the calendar held, and `generate_recommendations()` ignored
both of its parameters, so every call produced the same `consolidation` entry
with `potential_improvement: '15%'`. The
`WP_MCP_AI_Calendar_Orchestration_Optimization` class in the same folder is
retention and autoload housekeeping, not schedule analysis, so there was no
engine to port. The port measures instead:

1. **Counts come from the records.** Cancelled, no-show and end-before-start rows
   are excluded and reported separately, so the range total reconciles with the
   record count. An incomplete row is not given a default duration.
2. **Gaps are real.** Consecutive spans are compared against the furthest end
   seen so far, so an overlap cannot manufacture a gap, and the largest gap names
   its date and both endpoints.
3. **Capacity comes from `business_hours`.** With no rules configured,
   `utilization_rate` is `null` and `capacity.basis` says `unconfigured`.
   `outside_hours_booked_minutes` explains a utilisation above `1.0` rather than
   hiding it.
4. **The goal is honoured and validated.** The three goals produce different,
   measured recommendations; an unknown value is refused instead of defaulted.
5. **No invented percentages.** Every recommendation carries the minutes, counts
   and dates it was derived from.
6. **The range is bounded and the scan is honest.** Both dates are required
   `Y-m-d` values in order, the range is capped at 366 days, and a collection
   larger than the 1,000-row scan reports `scan.truncated` rather than silently
   analysing a prefix.

### Adaptations

- The `ai-feature` flag WordPress declared is not carried: no model is called, so
  the claim would be false. The tool declares the local-read vocabulary.
- Resolution is not a tool. WordPress served `/book/{token}` from the front end,
  so `BookingLinkDefinition::state()` carries the three checks a resolver needs —
  publication, expiry and the booking cap — and fails closed on an unreadable
  deadline.
- The parity baseline was regenerated. Four toolkits (`calendar-booking`,
  `cloudways`, `crm`, `food-beverage`) still had empty arrays, which made the
  regression check blind to them; the diff is the four arrays filling in and the
  two new slugs.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,772 / 22,343** (50 tests and 174 assertions added) |
| adapter `ToolProbesTest` | **216 / 1,524**, 2 skipped, 0 failures — two new probes |
| tool readiness audit | **436 / 436**, servable **417** (+2), no violations |
| toolkit parity | **215 of 854**; `calendar-booking` **21 ported**, 0 declined, 0 deferred, **13 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The link probe writes a record through the real adapter and returns the token
once; the analysis probe answers an empty far-future range, proving the `BETWEEN`
query and the unconfigured-capacity path without leaving appointments the other
probes would see.

Next in the queue: **notifications** (4). The final **9** stay on the register:
the seven Jet tools need a booking-provider contract and the two external syncs
need a credential store plus a sync-state store.

## 164.88 `calendar-booking`, slice 7 — notifications, and a contract that gained attachments

This slice ports the four notification tools. All four send mail through
`EmailServiceInterface`, so the rules that were previously copied — or missing —
in each tool now live once in `CalendarNotification`.

### The send log

A successful notification appends to `_notification_log`:

| key | meaning |
|---|---|
| `kind` | `confirmation`, `reschedule_invitation`, or `reminder:{hours_before}` |
| `revision` | the appointment revision, which is also its ICS `SEQUENCE` |
| `sent_at` | the UTC time the transport accepted the message |
| `message_id` | what the transport returned, when it returns one |

**Revision is the reschedule count.** `reschedule_appointment` already appends
one `_activity_log` entry per move, so counting those entries is the RFC 5545
sequence without a second key to keep in step. A confirmation sent before a
reschedule therefore does not suppress the confirmation owed afterwards.
**Only successful sends are logged**, so a transport failure stays retryable, and
the log is capped at 50 entries. `resend: true` overrides the suppression.

Splitting the reminder kind by `hours_before` matters: a 24-hour and a 2-hour
reminder are different notifications, and the original schema carried the value
without ever letting it distinguish anything.

### The mail contract gained attachments

WordPress declared `include_ical`, defaulted it to `true` and never read it. The
runtime could not honour it either: `EmailServiceInterface` carried `to`,
`subject`, `body` and `is_html`, and no implementation had an attachment path.
Repeating the declaration without the capability is the defect this port exists
to remove, so the contract now documents `attachments` as a list of
`{name, mime, content}` with raw bytes, and the Laravel adapter builds them as
`rawAttachments` on the mailable. Attachment names are flattened to one path
segment, and the list is capped at five files and 5 MiB because it arrives from
tool arguments. The interface states that a transport which cannot carry a file
must refuse the message rather than drop it.

An invite is published (`METHOD:PUBLISH`) unless the caller supplies
`organizer_email`, in which case it is a `METHOD:REQUEST` with `ORGANIZER` and an
`ATTENDEE;RSVP=TRUE` — a REQUEST with no organizer is not conformant. The `UID`
is `appointment-{id}@{host}`, with the host taken from
`RuntimeIdentityInterface` when bound and the RFC 2606 reserved `nvoos.invalid`
otherwise.

### Corrections

1. **A failed send is an error in all four tools.** WordPress returned
   `success => true` with `email_sent`, `reminder_sent` or `sent` false, so a
   caller had to read a nested flag to learn that nothing was delivered.
2. **`include_ical` is implemented** for the single and batch confirmations.
3. **The batch reads `_appointment_type`.** It collected `_service_type`, a key
   no writer sets, so every preview was blank.
4. **`method` and `reminder_type` are honoured.** WordPress accepted any string
   for the batch method — `sms` and `both` then matched nothing and every row
   reported "no delivery method succeeded" — and always emailed the reminder
   while logging `type => email` whatever was asked. No SMS transport is bound
   here, so both values are refused up front.
5. **The reschedule gate reads the key and value the writers store.** WordPress
   checked `_appointment_status === 'no_show'`; the toolkit writes `_status`
   with the hyphenated `no-show`, and no writer ever set that key — so the
   original skipped every appointment the toolkit created, including real
   no-shows. The underscore spelling is still accepted for migrated records.
6. **`hours_before` is bounded and meaningful.** It went through `absint` and
   was used only in the log.
7. **The batch envelope reflects the outcome.** A run where every send failed is
   `delivery_failed` with the per-booking detail, not a success with a failure
   counter.
8. **Batches are bounded** at 50 recipients per call.
9. **The reschedule subject is a plain string** — WordPress passed the client
   name to a `sprintf()` format with no placeholder — and the closing line asks
   the client to reply instead of visiting a booking page that the message never
   linked.
10. **Capabilities are the ones WordPress enforced.** The two single-send tools
    declared `edit_posts` and required `manage_options`; the two batch tools
    declared and enforced `edit_posts`, and stay there.

### Adaptations

- **SMS is refused, not faked.** WordPress reached an SMS gateway through a
  filter that returns `false` by default; this runtime binds no SMS contract, so
  declining the value is the honest answer.
- **Times are rendered as ISO 8601.** The stored values are UTC; printing them as
  WordPress did would silently label a UTC instant as local.
- **A confirmable record without a readable start sends no invite** and says so
  (`ical_included: false`) rather than attaching an empty calendar.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,819 / 22,488** (47 tests and 145 assertions added) |
| adapter suite | **227 / 1,562**, 2 skipped, 0 failures — four new probes and two mailer tests |
| tool readiness audit | **440 / 440**, servable **421** (+4), no violations |
| toolkit parity | **219 of 854**; `calendar-booking` **25 ported**, 0 declined, 0 deferred, **9 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The two singleton probes answer the not-found refusal; the two batch probes run
the dry-run default over an impossible id, which proves enumeration and the skip
path without sending anything. The send path, the invite contents and the
duplicate suppression are pinned in `lib/core`.

Next: the final **9**. The seven Jet tools (providers, services, units,
instances, and the three syncs) need a booking-provider contract before a port
would be more than a wrapper over an unbound dependency, and the Google and
Outlook syncs need an OAuth credential store plus a sync-state store with
tombstones. Both are register decisions — `deferred` with the prerequisite named,
or `declined` with the reason — not porting work.

## 164.89 `calendar-booking`, slice 8 — the register closes, and a count that hid work

The toolkit's last nine tools are registered rather than ported, each with the
prerequisite that would make a port more than a wrapper. All nine are `deferred`;
none is `declined`, because each names a concrete piece of infrastructure that
would let the capability be built properly.

### The register

| tools | prerequisite |
|---|---|
| `get_jetappointment_providers`, `get_jetappointment_services` | a JetAppointment client (JetEngine tables or REST with credentials) and the provider/service-to-local mapping, including a Jet id to `_service_external_id` link so an import stays idempotent |
| `get_jetbooking_instances`, `get_jetbooking_units` | a JetBooking client, plus a decision on whether an instance or unit maps to a service, a location or both — the answer shapes the record, so it has to precede the port |
| `sync_from_jetappointment`, `sync_from_jetbooking` | the client, a sync-state store holding the last cursor per provider, and an id map so a second run updates instead of duplicating |
| `sync_to_jetappointment` | the client **with write scope**, an outbound id map, and a conflict policy for "both changed since the last cursor" |
| `sync_google_calendar` | an OAuth credential store with refresh, a per-calendar sync-state store, and deletion tombstones. The Calendar API syncs with a per-calendar `syncToken`/`nextSyncToken`; an expired token answers `410 GONE` and the client must clear its storage and full-sync |
| `sync_outlook_calendar` | the same credential and sync-state stores, plus a per-calendar delta link. Graph's delta query returns an `@odata.deltaLink` and answers `410` with `syncStateNotFound`/`syncStateInvalid` when the state expires, at which point the client restarts with a full sync |

The tombstones are the part that is easy to skip and expensive to skip: without
them a full resync cannot tell an event deleted at the provider from one that was
never imported, so the first `410` would either resurrect cancellations or drop
real bookings.

### The backlog count was hiding the deferrals

Registering nine deferrals exposed a defect in the measurement. `measure()` in
`bin/toolkit-parity.php` computed
`remaining = wp − ported − declined − deferred`, while the script header, the
`ToolkitParityDecisions` docblock and the register's own `$comment` all state that
a deferral **stays** in `remaining` because it is work waiting on a prerequisite.
On this slice's own numbers the two readings differ by the whole backlog:
subtracting the deferrals would have reported `calendar-booking` as
`25 ported, 9 deferred, 0 remaining` — a closed toolkit with nine tools unbuilt,
which is exactly the laundering the class docblock warns against.

The measurer now computes `remaining = wp − ported − declined`, so the deferred
tools sit inside the backlog and the published columns no longer sum to `wp`
(`wp = ported + declined + remaining`, with `deferred ⊆ remaining`). The table's
docblock says so. Programme totals move from `591 remaining` to `627`: the 36
deferrals other toolkits already carried, plus these nine. That is a correction to
the reported number, not new work.

`ToolkitParityMeasurementTest` guards the relation by reading `--json` and
asserting `remaining = wp − ported − declined` and `deferred ≤ remaining` for
every toolkit, so a future edit cannot quietly shrink the backlog again.

### What the toolkit now reports

| measure | value |
|---|---|
| declared | **34** |
| ported | **25** |
| declined | **0** |
| deferred | **9** |
| remaining | **9** (the deferred set) |

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,821 / 22,778** (2 tests and 290 assertions added) |
| adapter suite | **227 / 1,562**, unchanged — no tool moved |
| tool readiness audit | **440 / 440**, servable **421**, unchanged — a register entry adds no class |
| toolkit parity | **219 ported, 8 declined, 45 deferred, 627 remaining**; `calendar-booking` **25 / 0 / 9 / 9** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The calendar-booking queue is now empty: every declared tool is either served or
registered against the prerequisite that would unblock it. The next work in this
family is the prerequisites themselves — the Jet booking client and the OAuth
credential and sync-state stores — not more slugs.

## 164.90 `gtmetrix`, slice 0 — the measurement, before the first port

The gtmetrix toolkit declares **23** tools and serves **0**. This is the
measurement §8298 asks for before the first slug is built: what each tool calls,
which contracts the runtime already has, and which defects the port must carry a
fix for.

### The API is live, v2.0, and matches the client

Checked against the published documentation on 2026-11-27 (its changelog runs to
2026-11-18, so the surface is maintained, not frozen):

- **Base URL** `https://gtmetrix.com/api/2.0/`, HTTPS only, JSON:API v1.1
  envelopes, errors as `{errors: [{status, code, title, detail}]}`.
- **Auth** HTTP Basic with the API key as the username and a blank password
  (`curl -u KEY:`). `401/E40100` is a bad key, `403/E40300` an unvalidated
  e-mail, `403/E40301` a role that may not write.
- **Credits** are spent per test: lighthouse `1`, legacy `0.7`, both `1.1`,
  metrics-only `0.6`; retention of 6/12/24 months adds `0.4/0.9/1.8`; video adds
  `0.9`. The local `Scheduler::COSTS` matches these exactly.
- **Rate limits**: `240` requests per 60 s on Basic, `960` on PRO, reported in
  `X-RateLimit-Limit/Remaining/Reset`; `429/E42901` blocks until the window
  resets. Test concurrency is `2` on Basic and `8` on PRO (`429/E42900`).
- **Retention**: tests are kept 24 hours; reports default to 1 month and can be
  bought to 6/12/24. A caller that treats a report id as durable will be wrong.
- **Completion is a redirect**: `GET /tests/{id}` returns `303 See Other` with
  `Location: /reports/{id}` when the test finishes, and `Retry-After: 3` while it
  runs.

The client's endpoint map, error taxonomy and header handling already match that
document, so the port is a re-expression rather than a redesign.

### The tool → endpoint map

| group | tools | endpoint |
|---|---|---|
| account and options | `gtmetrix_account_status`, `gtmetrix_list_locations`, `gtmetrix_list_browsers`, `gtmetrix_list_simulated_devices` | `GET /status`, `/locations`, `/browsers`, `/simulated-devices` |
| tests | `gtmetrix_start_test`, `gtmetrix_get_test_status`, `gtmetrix_list_tests` | `POST /tests`, `GET /tests/{id}`, `GET /tests` |
| reports | `gtmetrix_get_report`, `gtmetrix_get_lighthouse_audits`, `gtmetrix_get_resource_summary`, `gtmetrix_list_report_resources` | `GET /reports/{id}`, its `lighthouse.json` and `resource-summary` resources, and the report's `links` |
| analysis | `gtmetrix_analyze_report`, `gtmetrix_compare_reports` | none of its own: fetch one or two reports, then the score engine |
| code generation | `gtmetrix_generate_wpcode_snippet`, `gtmetrix_install_wpcode_fix` | none: the fix engine, then (for install) the WPCode repository |
| pages | `gtmetrix_list_pages`, `gtmetrix_get_page`, `gtmetrix_retest_page` | `GET /pages`, `GET /pages/{id}`, `POST /pages/{id}/retest` |
| monitoring and alerts | `gtmetrix_set_monitoring`, `gtmetrix_get_alert_conditions`, `gtmetrix_set_alert_conditions`, `gtmetrix_get_alert_events` | `POST /pages/{id}/performance_monitor`, `GET`/`POST`/`DELETE …/performance_alert_conditions`, `GET …/performance_alert_events` |
| tags | `gtmetrix_manage_page_tags` | `GET`/`POST /page_tags`, `POST /page_tags/{id}`, `…/attach`, `…/detach` |

### What the runtime already has

| seam | contract | use |
|---|---|---|
| outbound HTTP | `HttpClientInterface` (+ `HttpResponse`) | every call; the WordPress client's `wp_remote_request` becomes this |
| API key | `SettingsStoreInterface::getApiKey( 'gtmetrix' )` | replaces `wp_mcp_ai_get_api_key( 'gtmetrix_api_key' )` |
| toolkit config | `SettingsStoreInterface` | credit budget, retention, allowlist, default location and browser, poll cadence |
| credit counter | `CacheStoreInterface::getValue`/`increment` with a 24 h TTL | replaces the `gtmetrix_credits_used_today` transient |
| default allowed host | `RuntimeIdentityInterface::identity()['site_url']` | replaces `home_url()` in the allowlist default |
| snapshots (optional) | `ContentStoreInterface` | only if a later slice stores report snapshots; no tool requires it |

Two pieces have no core seam and do not need one for the tool contract: the
WP-Cron poller and the CPT snapshot store. `start_test` already returns `test_id`
and a `next` instruction; a host schedules the follow-up as a workflow step.

### Corrections the port must carry

These were found by reading the client against the documentation, not by
porting:

1. **`request()` drops the JSON:API `meta` block.** It returns `$decoded['data']`
   only, so `start_test()`'s wiring of `meta.credits_left` and
   `meta.credits_used` can never fire: the reported `credits_left` is always
   `null` and `credits_used` is the local estimate. The API does return both.
2. **`get_test_status` cannot see a completed test.** Completion is `303` plus
   `Location: /reports/{id}`; `wp_remote_request` follows redirects by default,
   so the client receives the *report*, finds no `state` attribute, and answers
   `state: unknown` with no `report_id`. The port must treat `303` as the
   completion signal and read the report id from the `Location` header.
3. **The retry sleeps inside the request.** `E42901` is retried after 1, 2 and 4
   seconds inside `request()`, holding the worker for up to 7 s. The port should
   surface a retryable error with the server's `Retry-After` or
   `X-RateLimit-Reset` and let the caller decide.
4. **Fixture mode is a compile-time constant**
   (`WP_MCP_AI_GTMETRIX_USE_FIXTURES`). The port injects `HttpClientInterface`,
   and the 19 committed JSON fixtures become the tests' oracle instead of a
   runtime branch.
5. **Credit accounting can overspend.** `preflight_budget()` checks the account
   balance and a rolling 24 h local counter, then `record_charge()` runs after
   the API call, so two concurrent tests both pass the check first. The port
   should reserve before the call and reconcile with the response.
6. **`install_wpcode_fix` needs the WPCode plugin.** It resolves the shared
   `WP_MCP_AI_Pro_WPCode_Snippet_Repository` and refuses when the plugin is
   absent. This runtime has no code-snippet store.

Capabilities are already right: reads use `read`, and every credit-spending or
account-writing tool (`start_test`, `retest_page`, `set_monitoring`,
`set_alert_conditions`, `manage_page_tags`) overrides to `manage_options`;
`install_wpcode_fix` requires `wpcode_edit_snippets`. The port keeps them.

### Portability

**22 of 23 are portable** against the seams above; **1 is a register decision**:

- `gtmetrix_install_wpcode_fix` → `deferred` on a code-snippet store contract
  (create an inactive draft, return its id and edit target). Deferred rather
  than declined because the capability — turning an audit into a reviewable
  draft — is real, and `generate_wpcode_snippet` already produces the code; only
  the destination is missing. This decision is not yet written to the register,
  and changes no count until it is.

### The slice plan

| slice | tools | why grouped |
|---|---|---|
| 1 | `account_status`, `list_locations`, `list_browsers`, `list_simulated_devices`, `list_tests` | the client plus the five credit-free reads; no writes, no report parsing |
| 2 | `start_test`, `get_test_status` | the credit path and the `303` correction |
| 3 | `get_report`, `get_lighthouse_audits`, `get_resource_summary`, `list_report_resources` | report payloads and resources |
| 4 | `analyze_report`, `compare_reports` | the score engine, ported once |
| 5 | `generate_wpcode_snippet` | the fix engine |
| 6 | `list_pages`, `get_page`, `retest_page` | the page registry and its retest |
| 7 | `set_monitoring`, `get_alert_conditions`, `set_alert_conditions`, `get_alert_events` | account writes and alert history |
| 8 | `manage_page_tags` | the five-action tag surface |

### Measurements

| gate | value |
|---|---|
| declared | **23** |
| served | **0** |
| likely deferred | **1** (`install_wpcode_fix`) |
| planned slices | **8** |
| fixture JSON files available as test oracles | **19** |
| readiness | unchanged, **440 / 440**, servable **421** |
| parity | **219 ported, 8 declined, 45 deferred, 627 remaining**; `gtmetrix` **0 / 0 / 0 / 23** |

No code changes in this slice: measurement only.

## 164.91 `gtmetrix`, slice 1 — the transport and the five credit-free reads

The client and the five reads that cost no credits: `gtmetrix_account_status`,
`gtmetrix_list_locations`, `gtmetrix_list_browsers`,
`gtmetrix_list_simulated_devices`, `gtmetrix_list_tests`. The toolkit moves from
0 to **5 of 23**.

### The transport

`GtmetrixClientInterface` is one verb over a path, like the Cloudways contract,
because a client method per endpoint is a second copy of the API surface that
drifts. `GtmetrixClient` implements it over `HttpClientInterface` with the API
key from `SettingsStoreInterface::getApiKey( 'gtmetrix' )`, and the provider
binds it as a singleton. The response shape is
`{status, data, meta, links, location, rate_limit}` — the `meta` block is part of
the contract because it is where credits are reported, and `location` because
completion is a redirect.

`GtmetrixApiException` keeps the core code, the HTTP status, the API's own
`E4xxxx`/`E5xxxx` code and the server's `retry_after` apart. The WordPress client
flattened them into a message, which is why a rate limit and a rejected
parameter read the same.

### Corrections

1. **`meta` survives.** WordPress returned `$decoded['data']` only, so
   `start_test` could never read `meta.credits_left` / `meta.credits_used` and
   reported its own estimate.
2. **A `303` is reported, not followed.** Completion is `303` plus
   `Location: /reports/{id}`; `wp_remote_request` followed it, so
   `get_test_status` received a report and answered `state: unknown`. The client
   returns the status and location; slice 2 interprets them.
3. **No sleeping retry.** `E42901` was retried after 1, 2 and 4 seconds inside
   the request. The exception carries `retry_after` (from `Retry-After`, or
   `X-RateLimit-Reset` for a rate limit) and the tool surfaces it.
4. **The fixture switch is gone.** Tests inject `HttpClientInterface`; the 19
   committed JSON fixtures remain the oracle for payload shapes.
5. **`list_tests` sends its limit.** WordPress applied `absint()` and sliced
   after the fetch, so `limit: 1000` returned the first page. The port bounds it
   to 1–100 and sends `page[size]`.
6. **Unknown enum values are refused.** An unknown device `category` or test
   `state` returned an empty list in WordPress, which reads as "none exist".
7. **Absent numbers stay `null`.** A missing balance is not `0`.
8. **Reads are not `cacheable`.** The WordPress base declared it for all 23
   tools; a live balance and a 24-hour test list are not.

### Adaptations

- Reads keep the WordPress capability (`read`); the credit-spending and
  account-writing tools keep their `manage_options` overrides in later slices.
- Error classification adds `gtmetrix_invalid_request` for unmapped `E4xxxx`
  codes, so a caller can tell "fix the request" from "retry later".
- The rate-limit headers are read and returned; no tool surfaces them yet.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,842 / 22,858** (21 tests and 80 assertions added) |
| adapter `ToolProbesTest` | **225 / 1,570**, 2 skipped, 0 failures — five new probes |
| tool readiness audit | **445 / 445**, servable **426** (+5), no violations |
| toolkit parity | **224 of 854**; `gtmetrix` **5 ported**, 0 declined, 0 deferred, **18 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The five probes answer the not-configured refusal, because the probe host binds
no API key and a probe that called gtmetrix.com would be a network test. The
payload shapes are pinned in `lib/core` against fixture-shaped responses; slice 2
adds the first probe that exercises a real request path, still without the
network, once the client is injectable in the probe host.

Next: slice 2, the test lifecycle — `start_test` with the credit budget and the
origin allowlist, and `get_test_status` with the `303` reading.

## 164.92 `gtmetrix`, slice 2 — the test lifecycle, the budget and the `303`

The two tools that spend the account: `gtmetrix_start_test` and
`gtmetrix_get_test_status`. The toolkit moves from 5 to **7 of 23**.

### What a test costs, and who decides

`GtmetrixConfig` holds the three tables the API charges by — report type
(1.0 Lighthouse, 0.7 legacy, 1.1 both, 0.6 neither), retention (1 month free,
6 +0.4, 12 +0.9, 24 +1.8) and video (+0.9) — copied from the WordPress
`Scheduler::COSTS`, which matched the published table. The table lives outside
the tool because the later sweeps price the same configurations, and two copies
would eventually disagree. `retention` is an enumerated parameter, so a value
the table has no price for cannot reach `cost()`.

The budget key is `gtmetrix_credit_budget_daily` (default 25, the WordPress
default) and the host list is `gtmetrix_allowed_test_hosts`. The allowlist is
the configured entries plus the deployment's own host from
`RuntimeIdentityInterface::identity()['site_url']`; WordPress appended
`home_url()`. There is no `home_url()` in the core, so with no identity bound
and no configured hosts **every URL is refused**: an account key must not become
a probe for arbitrary third-party pages just because the runtime cannot say who
it is.

### Corrections

1. **Credits come from the response.** The WordPress client returned
   `$decoded['data']` and dropped `meta`, so `credits_used` was the local
   estimate and `credits_left` was always `null`. The contract returns `meta`;
   both figures are now the server's, with the estimate only as a fallback when
   a response omits the block.
2. **The budget is reserved before the call.** WordPress checked the balance and
   the counter, started the test, then recorded the charge, so two concurrent
   calls both passed the same check and one of them was unaccounted for. The
   port increments the counter first, compares the result against the budget,
   and restores the previous value if the reservation overshoots or the POST is
   refused or returns no test id.
3. **The counter is in hundredths.** A fractional cost (0.7, 1.1, 2.5) cannot
   be added to an integer credit counter without drift. The key is
   `gtmetrix:credits_cents:YYYYMMDD` and every comparison is in cents; the
   figure a caller sees is divided back to credits. The 2-day TTL lets
   yesterday's key expire without a cleanup job.
4. **Optional values are validated, not silently dropped.** WordPress ran
   `sanitize_key()` over `location`, `browser` and `simulate_device`, and
   `sanitize_text_field()` over `throttle`, dropping whatever did not survive.
   A test then ran with different options than the caller asked for, and the
   reply said nothing. An unknown `report`, `retention`, `location`, `browser`,
   `simulate_device` or malformed `throttle` is now refused by field name.
5. **A completed test is recognised.** This is the correction the slice exists
   for: completion is `303 See Other` with `Location: /api/2.0/reports/{id}`.
   `wp_remote_request` followed the redirect, the tool received the report,
   found no `state`, and answered `state: unknown` with an empty `report_id` —
   the single transition a poller exists to detect. The port reads the status
   and the `Location`, and also accepts a `200` whose attributes carry
   `report`, for a client or proxy that flattened the transition. The failing
   state carries the API's own `error` message.
6. **No poll is scheduled.** WordPress scheduled a WP-Cron poll that
   snapshotted the result into a post type. The core has no cron, and a poll
   that owns storage would be a second snapshot store; the tool returns
   `test_id` and names the next call, and scheduling belongs to the host.

### Adaptations

- `daily_budget` is a port addition: WordPress returned no budget figures at
  all. `limit` and `used` are two-decimal floats so the field has one shape,
  whether the charge was whole or fractional.
- Capabilities follow what WordPress enforced: `start_test` requires
  `manage_options` (as the WordPress tool declared), `get_test_status` requires
  the base `read`. `start_test` is flagged
  `external-api, network-dependent, requires-credentials, state-changing,
  write`; the status tool inherits the read flag set.
- Error codes are named rather than prose: `gtmetrix_not_configured` (no key),
  `gtmetrix_host_denied` (the URL, not a 404 from gtmetrix.com),
  `gtmetrix_insufficient_credits`, `gtmetrix_budget_exhausted` (with required,
  limit and used), and the classified API failures with `status`, `api_code`
  and `retry_after`.
- `test_id` is validated against `[A-Za-z0-9_-]+` before it reaches a path, so
  a crafted id cannot walk out of `/tests/`.
- Tests are retained for 24 hours, which the description says: a listed report
  id may already be gone, and an old `test_id` answers a not-found error rather
  than a state.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,857 / 22,915** (15 tests and 57 assertions added) |
| adapter `ToolProbesTest` | **227 / 1,578**, 2 skipped, 0 failures — two new probes |
| tool readiness audit | **447 / 447**, servable **428** (+2), no violations |
| toolkit parity | **226 of 854**; `gtmetrix` **7 ported**, 0 declined, 0 deferred, **16 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

Both probes answer the not-configured refusal, because the guard runs before
any credit work and the probe host binds no API key. The status probe still
passes a valid id, so the refusal is the guard rather than the id validator.
Payload shapes and the refusal paths are pinned in `lib/core` against
fixture-shaped responses.

Two fixture defects were found and fixed in this slice, both in the test rather
than the code: a budget assertion compared credits with the counter's cents
(2.5 against 250), and the `getValue` stub captured the counter array **by
value** inside an arrow function, so reads never saw the increments. The second
is the more instructive: it made a correct reserve-and-read cycle look broken.

Next: slice 3, the reports — `gtmetrix_get_report`,
`gtmetrix_get_lighthouse_audits`, `gtmetrix_get_resource_summary` and
`gtmetrix_list_report_resources`.

## 164.93 `gtmetrix`, slice 3 — the reports, and links that were never read

The four report readers: `gtmetrix_get_report`,
`gtmetrix_get_lighthouse_audits`, `gtmetrix_get_resource_summary` and
`gtmetrix_list_report_resources`. The toolkit moves from 7 to **11 of 23**.

### The transport gained one key

A report resource answers a *bare* object: `lighthouse.json` is the Lighthouse
report, `resource-summary` is a map of seven categories. A JSON:API test or
report answers `{"data": {...}}`. The WordPress client kept a separate
`get_resource()` method that unwrapped `attributes` when present per call.

`request()` now also returns `document` — the decoded body as it arrived — and
`GtmetrixTool::resourceBody()` accepts either shape (a wrapped datum unwraps to
`attributes`; a bare object is returned whole). One verb still serves every
endpoint, and the endpoint name stays in the tool. The alternative, a client
method per resource, is the second copy of the API surface this contract
exists to avoid.

### Corrections

1. **The report links live beside `attributes`.** JSON:API, the published v2.0
documentation and every committed fixture agree: a report datum is
`{type, id, attributes, links}`. WordPress read `$report['attributes']['links']`,
so `get_report` always answered `resources: []` and
`list_report_resources` always answered `count: 0` on a real response. Both
read the sibling block now. This is the slice-defining bug: two tools whose
whole purpose is the resource inventory could not see one.
2. **The annotation table is keyed by link name.** The API names links `har`,
`lighthouse`, `resource_summary`, `optimized_images`, `report_pdf`; the
WordPress table was keyed by endpoint names (`net.har`, `lighthouse.json`,
`resource-summary`, `optimized-images.tar`, `report.pdf`). Every lookup missed
and fell through to "not sensitive", so **`har` — a raw network capture, and
one of the two resources the client refuses to fetch — was listed as
downloadable**. The corrected table carries both names (`name` is the link
key, `resource` the endpoint), and adds `report_pdf_full`, `report_url` and
`filmstrip-frame`, which the WordPress table never had.
3. **An unknown impact band is refused.** WordPress treated any unrecognised
`min_impact` as "no filter", so a typo returned the full list while reading as
a filtered one. `None` is a data band, not a filter value, and is refused with
the same message.
4. **The audit limit is checked before the append.** WordPress appended first
and compared after, so `limit: 0` still returned one audit; it is bounded to
1–200 here.
5. **Numbers keep their type.** WordPress passed attributes through, so an
absent key was a PHP notice, and a cumulative layout shift of 0.05 survived by
luck. `numeric()` preserves int/float and answers `null` when absent; a
non-scalar category label falls back to the category name instead of reaching
the reply as an array.

### Adaptations

- **No snapshot preference.** WordPress preferred a report the poller had
  snapshotted locally. The core has no poller (section 164.92), so there is
  nothing to prefer and the report is read live; a host with its own store can
  front the tool.
- Report ids are validated against `[A-Za-z0-9_-]+` before becoming a path
  segment. WordPress ran `sanitize_text_field()`, which leaves `/` and `..`
  intact.
- The audit reader orders by `_impactScore`, the documented bands (0.12, 0.24,
  0.36, 0.48), and returns the LCP phase breakdown with `estimated: true` when
  the API omitted the phases.
- The resource table records the documented add-on costs (a PDF render is +0.2
  credits, a full PDF +0.3), which is why the notes mention credits rather than
  a boolean field.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,877 / 22,996** (20 tests and 81 assertions added) |
| adapter `ToolProbesTest` | **231 / 1,594**, 2 skipped, 0 failures — four new probes |
| tool readiness audit | **451 / 451**, servable **432** (+4), no violations |
| toolkit parity | **230 of 854**; `gtmetrix` **11 ported**, 0 declined, 0 deferred, **12 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The four probes answer the not-configured refusal, because the guard runs
before validation and the probe host binds no API key; each passes a valid
report id so the refusal is the guard and not the id validator. Payload shapes,
the two corrections above and the estimated-phase fallback are pinned in
`lib/core` against fixture-shaped responses.

The link names and the sibling placement were re-verified against the live v2.0
documentation on 2026-11-27 (changelog current to 2026-11-18), not inferred from
the fixtures alone.

Next: slice 4, the score engine — `gtmetrix_analyze_report` and
`gtmetrix_compare_reports`.

## 164.94 `gtmetrix`, slice 4 — the score engine, and a grade that was never earned

`gtmetrix_analyze_report` and `gtmetrix_compare_reports`, with the assessment
engine and the fix matcher they share. The toolkit moves from 11 to **13 of
23**.

### The engine is arithmetic

`GtmetrixScoreEngine` is pure: thresholds in, verdicts out, no API and no
storage. It holds the 2026 Core Web Vitals table (LCP 2500/4000, CLS 0.1/0.25,
TBT 200/600, TTFB 600/1800, Speed Index 3400/5800, onload and fully-loaded
3000/5000), the four page-weight budget classes, the grade floors, the impact
bands, the LCP phase reader and the eight delta rules.

The band ladder and the phase reader moved out of `get_lighthouse_audits` into
the engine, so the audit tool, the analysis tool and the summary all read the
same table rather than three copies that agree until one is edited.

`GtmetrixFixEngine` holds the nine-entry remediation table and the matcher. Its
snippet half is slice 5.

### Corrections

1. **A report without a score is not an F.** WordPress derived
   `band_grade( gtmetrix_score / 100 )` unconditionally, so a metrics-only
   report — which has no grade and no GTmetrix score — was reported as an F.
   The grade is derived only when a score exists; otherwise it is empty and the
   scores block shows `null`, which is what the API said.
2. **A failed Lighthouse fetch is reported.** WordPress wrapped the resource
   fetch in `if ( ! is_wp_error() )` and answered an empty audit list for both a
   metrics-only report and a network failure. The second case read as "nothing
   to fix". The reply now carries a `lighthouse` block with `available` and,
   when not, the classified `error_code` and message; the audit list is empty
   only when the resource genuinely has no audits.
3. **The budget describes the next class.** WordPress returned
   `next_class: <the class you are already in>` with `bytes_to_next: 0`, and for
   a `very_heavy` page returned `next_class: heavy` with the bytes *over* the
   heavy limit. The port returns the next heavier class (`null` for
   `very_heavy`) and the headroom left before leaving the current class, which
   is the question a page-weight triage actually asks.
4. **Every fix that names an audit is returned.** Two entries —
   `third-party-recaptcha` and `third-party-duplicate-ga` — name
   `third-party-summary`, and WordPress broke after the first match, so the
   duplicate-analytics advice was unreachable: the engine always answered the
   reCAPTCHA fix. The matcher row (`id`, `title`, `display_value`, `impact`)
   cannot tell the two apart, so both are returned with the `matched_audit` that
   produced them and the human or the caller decides from the audit detail.

### Adaptations

- **`url` is not offered, and is refused by name.** WordPress resolved the two
  newest local snapshots for a URL; the core has no snapshot store (section
  164.92), so the parameter cannot work. A caller that still sends it gets
  `gtmetrix_no_snapshot_store` rather than a silent ignore.
- Report ids are path-validated in both tools, as in slice 3.
- A metric absent on either side is omitted from the comparison rather than
  reported as `steady`, which the WordPress engine already did; the port keeps
  it and says so, because "not measured" and "did not move" are different
  answers.
- The comparison names each side by its datum id, falling back to the id that
  was requested; WordPress reported an empty id when the datum omitted one.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,904 / 23,128** (27 tests and 132 assertions added) |
| adapter `ToolProbesTest` | **233 / 1,602**, 2 skipped, 0 failures — two new probes |
| tool readiness audit | **453 / 453**, servable **434** (+2), no violations |
| toolkit parity | **232 of 854**; `gtmetrix` **13 ported**, 0 declined, 0 deferred, **10 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The boundary cases are pinned to the WordPress smoke-run oracles
(`tests/smoke-run.php`): LCP 2500 is good and 2501 is not, CLS 0.10 is good and
0.11 is not, `budget_class( 2621440, 91 )` is heavy because the request ceiling
gates regardless of bytes, and every grade floor is inclusive. The two probes
answer the not-configured guard on the keyless host.

Next: slice 5, the fix engine — `gtmetrix_generate_wpcode_snippet`, and the
register entry for `gtmetrix_install_wpcode_fix` (deferred: it needs a
code-snippet store contract the core does not have).

## 164.95 `gtmetrix`, slice 5 — the snippet emitter, and an install that is deferred

`gtmetrix_generate_wpcode_snippet` with the emitter half of the fix engine, and
the register entry that keeps `gtmetrix_install_wpcode_fix` visible as blocked
work. The toolkit moves from 13 to **14 of 23**; the deferred count for the
toolkit moves from 0 to 1 and `remaining` from 10 to 9.

### What the emitter does

`GtmetrixFixEngine::generateSnippet()` emits a self-contained PHP file: the
`ABSPATH` guard, a header naming the report and page, and one commented section
per matched finding with the code block that finding names. A `high`-risk block
— dequeuing a script, rewriting `style_loader_src` — is wrapped in a comment
under a staging warning, because that change needs a browser and not a review.
A fix with no block (keep one analytics path) contributes its advice and no
code. Seven block templates cover the nine fixes; two are advice-only.

### Corrections

1. **Only a missing resource is a missing resource.** WordPress replaced every
   Lighthouse fetch failure — a network error, a rate limit, a 500 — with
   "this report has no Lighthouse JSON (metrics-only or legacy report)", which
   hid the cause. A `gtmetrix_not_found` classification keeps that message
   (`gtmetrix_no_lighthouse`, with `status` and `api_code`); anything else is
   returned as the classified failure it is.
2. **Matched fixes and emitted blocks are counted apart.** A configuration-only
   fix matched a finding, so WordPress counted it in `count`, while the
   emitter skipped it. The reply claimed one more block than the snippet held.
   `count` is the matched fixes (the advice list) and `emitted` is the code
   blocks actually in the file.

### Deferred, with a name

`gtmetrix_install_wpcode_fix` writes the snippet into WPCode and activates it.
Nothing in this runtime stores code snippets: no record contract, no activation
seam, no store to read back, and inventing a WPCode-shaped table in the core
would be the wrong shape for every other host. The register entry names the
prerequisite — a code-snippet store contract (create, activate, read back) that
both WPCode and any other snippet host could implement as an adapter — so the
slug stays in `remaining` as work rather than disappearing as a decision.

### Adaptations

- `include_risky` uses the tool boolean coercion, so a JSON string `"false"` is
  false rather than PHP-cast true.
- The tool is read-only (`read`), flagged `external-api, network-dependent,
  read-only, requires-credentials`: it fetches a report and returns code, and
  installs nothing.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,913 / 23,168** (9 tests and 40 assertions added) |
| adapter `ToolProbesTest` | **234 / 1,606**, 2 skipped, 0 failures — one new probe |
| tool readiness audit | **454 / 454**, servable **435** (+1), no violations |
| toolkit parity | **233 of 854**; `gtmetrix` **14 ported**, 0 declined, **1 deferred**, **9 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The probe answers the not-configured guard on the keyless host. The snippet
structure — header, `ABSPATH` guard, only matching blocks, a commented
high-risk block, and a configuration-only fix that adds no code — is pinned in
`lib/core`.

Next: slice 6, the pages — `gtmetrix_get_page`, `gtmetrix_list_pages` and
`gtmetrix_retest_page`.

## 164.96 `gtmetrix`, slice 6 — the pages, and a URL map that is not needed

`gtmetrix_get_page`, `gtmetrix_list_pages` and `gtmetrix_retest_page`. A page
is GTmetrix's URL registry entry — one URL plus one analysis-options
combination — so these three are the lookup and the regression primitive that
monitoring and alerts will build on. The toolkit moves from 14 to **17 of
23**.

### Two helpers moved onto shared ground

Two things were about to be written twice, so they moved instead:

- `GtmetrixBudget` owns the counter key, the two-day TTL and the
  reserve-then-release cycle. `start_test` and `retest_page` both spend
  credits, and a money path with two copies is a money path that will disagree
  with itself. The reservation compares in hundredths and restores the previous
  value on an overshoot, a refused call or a reply without a test id.
- `GtmetrixTool::urlArg()`, `budgetExhausted()` and `credits()` are shared
  rather than repeated per tool. The refusal message is now "this call needs N
  credits", which is true for both callers.

### Corrections

1. **The page-list limit is a page size.** WordPress fetched the default page
   (ten pages), applied `absint()` and sliced the result, so `limit: 100` still
   returned at most ten. The port sends `page[size]` and bounds it to 1–500, the
   documented maximum.
2. **An unknown monitoring filter is refused.** WordPress `sanitize_key`d the
   value; anything that matched nothing read as "no monitored pages". The filter
   is validated against the documented set.
3. **The URL filter is encoded.** It is a URL fragment, and it is
   `rawurlencode`d into the query string so an `&` or `]` in it cannot add a
   parameter.
4. **A page without a datum id still has an id.** `get_page` reports the
   requested id when the datum omits one, and filters `page_tags` to strings.
5. **The retest budget is reserved before the call.** The WordPress retest
   checked, started and recorded in that order — the same defect `start_test`
   had — and two concurrent retests both passed. It now shares the reservation
   and releases it on a refusal.
6. **A URL resolves through the API.** WordPress read the page id out of its
   snapshot map. `GET /pages?filter[url]=` is the authoritative lookup: the port
   requires an exact, case-insensitive URL match, answers
   `gtmetrix_page_not_found` with the candidates when there is none, and
   `gtmetrix_page_ambiguous` when several match, because retesting the wrong URL
   is worse than a refusal. The host allowlist still applies to a supplied URL.

### Adaptations

- `retest_page` keeps the WordPress `manage_options` gate and the
  state-changing flag set; the two reads keep `read`.
- No WP-Cron poll is scheduled (section 164.92); the reply names `test_id` and
  the next call.
- The retest cost is the flat Lighthouse price (1.0 credits), which is what the
  API charges for a retest and what the WordPress scheduler assumed.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,929 / 23,229** (16 tests and 61 assertions added) |
| adapter `ToolProbesTest` | **237 / 1,618**, 2 skipped, 0 failures — three new probes |
| tool readiness audit | **457 / 457**, servable **438** (+3), no violations |
| toolkit parity | **236 of 854**; `gtmetrix` **17 ported**, 0 declined, 1 deferred, **6 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The probes answer the not-configured guard on the keyless host. The query the
list tool builds (`filter[url]`, `filter[monitored]`, `page[size]`) is pinned
exactly, as are the URL-resolution outcomes, the budget refusal and the
reservation release on a refused retest.

Next: slice 7, monitoring and alerts — `gtmetrix_set_monitoring`,
`gtmetrix_get_alert_conditions`, `gtmetrix_set_alert_conditions` and
`gtmetrix_get_alert_events`.

## 164.97 `gtmetrix`, slice 7 — monitoring and alerts, and an alert that was dropped

`gtmetrix_set_monitoring`, `gtmetrix_get_alert_conditions`,
`gtmetrix_set_alert_conditions` and `gtmetrix_get_alert_events`. The toolkit
moves from 17 to **21 of 23**; only `manage_page_tags` and the deferred install
remain.

### Corrections

1. **A malformed alert condition refuses the whole replace.** The endpoint is a
   full replace, and WordPress `continue`d past a non-array entry while the
   ten-condition check still counted it. A caller that sent ten conditions and
   one typo got nine alerts and a success. Every entry is validated by index
   (`conditions[2].metric`) and the call is refused when one is wrong —
   all-or-nothing is what "replace" means.
2. **A threshold must be a number.** WordPress cast with `(float)`, so
   `"fast"` became `0` and passed the non-negative check. An alert on
   `ttfb > 0` fires on every report.
3. **The condition count is what was stored.** WordPress counted the list it
   sent; the port counts the set the API returned, which is the set that will
   fire.
4. **`send_to` is offered.** The API accepts recipients for Team organizations
   and WordPress never sent the field, so a Team account could not set
   recipients through the toolkit at all. It is validated (a valid address and
   the only documented channel) and sent only when supplied, because omitting
   it leaves the existing recipients untouched.
5. **The monitoring frequency is case-folded and reported twice.** `Daily` now
   behaves like `daily` (`sanitize_key()` used to lowercase it silently, which
   the port makes explicit), and the reply carries the requested `frequency`
   beside the stored `monitored` — `none` is stored as `no`, and a plan can
   refuse `hourly`.
6. **The event limit is a page size.** WordPress fetched the default page and
   sliced it, so `limit: 100` returned at most the default page. The port sends
   `page[size]` and bounds it to 1–100.
7. **Alert and recipient rows are projected to scalars.** WordPress forwarded
   nested values into fields the schema describes as a metric and a threshold.

### Transport

`E40303` is classified as `gtmetrix_plan_denied`. The docs use it for both a
monitoring restriction (plan, location, browser) and a tag ceiling; the shared
meaning is a plan limit, and `gtmetrix_invalid_request` — the unmapped `E4xx`
fallback — would tell an agent to fix a request that no change can fix.

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,947 / 23,300** (18 tests and 71 assertions added) |
| adapter `ToolProbesTest` | **241 / 1,634**, 2 skipped, 0 failures — four new probes |
| tool readiness audit | **461 / 461**, servable **442** (+4), no violations |
| toolkit parity | **240 of 854**; `gtmetrix` **21 ported**, 0 declined, 1 deferred, **2 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The whole-replace refusal, the numeric-threshold refusal, the stored-set count,
the recipient validation, the stored monitoring state and the event page size
are pinned in `lib/core`. The test fake gained method-keyed responses because
`GET` and `POST` share the alert-conditions path.

Next: slice 8, the last portable tool — `gtmetrix_manage_page_tags` — and the
toolkit closes at 22 ported, 1 deferred.

## 164.98 `gtmetrix`, slice 8 — the tags, and the toolkit closes

`gtmetrix_manage_page_tags`, five actions behind one slug. The toolkit closes
at **22 of 23**: 22 ported, 0 declined, 1 deferred (`gtmetrix_install_wpcode_fix`,
section 164.95), 1 remaining. The toolkit is done; the loop returns to the
parity table.

### Corrections

1. **`update` updates.** WordPress required a `tag_id`, validated it, and then
   called the create endpoint *without* the id. An update therefore created a
   second tag — or failed on the unique-name constraint — and never touched the
   tag named. The documented partial update is `POST /page_tags/{tag_id}`.
2. **A partial update may omit the name.** The docs say an omitted attribute
   keeps its value; WordPress refused an update without a name. The port
   requires at least one of name, description or colour.
3. **`create` requires a name.** WordPress sent empty attributes and let the
   API answer a 400. The declared unique name is required before the call, and
   over 45 characters is refused by field.
4. **Attach and detach are one request.** WordPress looped over the page ids
   against the page-side endpoint, discarded the per-page results, and answered
   success with a page count even when every call failed. The tag-side endpoint
   takes the whole list (1–500) in one request, and a refusal is returned as
   the classified error it is. A partial outcome is therefore impossible to
   report as a success.
5. **Every page id is path-checked** before it becomes a body value or a path
   segment; `sanitize_text_field()` leaves `/` and `..` intact.

Deletion stays unexposed, as WordPress decided: removing a tag from every page
it labels is irreversible and no caller of this tool asked for it.

### The toolkit, closed

| slice | section | tools |
|---|---|---|
| 0 | 164.90 | measurement |
| 1 | 164.91 | transport, `account_status`, `list_locations`, `list_browsers`, `list_simulated_devices`, `list_tests` |
| 2 | 164.92 | `start_test`, `get_test_status` |
| 3 | 164.93 | `get_report`, `get_lighthouse_audits`, `get_resource_summary`, `list_report_resources` |
| 4 | 164.94 | `analyze_report`, `compare_reports`, score engine, fix matcher |
| 5 | 164.95 | `generate_wpcode_snippet`; `install_wpcode_fix` deferred |
| 6 | 164.96 | `get_page`, `list_pages`, `retest_page` |
| 7 | 164.97 | `set_monitoring`, `get_alert_conditions`, `set_alert_conditions`, `get_alert_events` |
| 8 | 164.98 | `manage_page_tags` |

### Measurements

| gate | value |
|---|---|
| `lib/core` PHPUnit | **3,960 / 23,348** (13 tests and 48 assertions added) |
| adapter `ToolProbesTest` | **242 / 1,638**, 2 skipped, 0 failures — one new probe |
| tool readiness audit | **462 / 462**, servable **443** (+1), no violations |
| toolkit parity | **241 of 854**; `gtmetrix` **22 ported**, 0 declined, 1 deferred, **1 remaining** |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

The update-path correction is pinned by the request path itself
(`page_tags/TAG1`, not `page_tags`), the partial update by the absence of a
`name` attribute, and the single-request attach by the call count. The probe
answers the not-configured guard on the keyless host.

Next: no gtmetrix work remains. The parity table's largest remaining toolkits
are `regulatory-registration` (59), `cloudways` (52), `chat-channels` (50) and
`financial-planning` (44); each starts with a measurement section like
164.90 before any port.

## 164.99 `chat-channels`, slice 0 — the measurement, before the first port

Fifty declared tools across twelve platforms, 16,800 lines: minimum 220
(`send_google_chat_message`), median 296, maximum 727
(`send_whatsapp_interactive`). Twelve platforms is ten APIs — Teams, Outlook
and OneDrive are one Microsoft Graph surface — plus a unified broadcast and one
scheduled SMS tool that belongs to no platform at all. Nothing is ported; this
section decides what will be, what will not, and what the first slice has to
prove.

### The families

| family | tools | endpoint | auth |
|---|---:|---|---|
| Slack | 4 | `slack.com/api/*` | bot/user token argument |
| Discord | 6 | `discord.com/api/v10` | bot token argument |
| Telegram | 5 | `api.telegram.org/bot{token}/*` | token in the path |
| WhatsApp | 5 | `graph.facebook.com/v*/{phone_id}/messages` | bearer plus phone id |
| Messenger | 3 | `graph.facebook.com/v*/me/messages` | page access token |
| Microsoft Graph (Teams 3, Outlook 2, OneDrive 3) | 8 | `graph.microsoft.com/v1.0` | bearer |
| Google Chat | 7 | `chat.googleapis.com` plus the OAuth host | webhook URL, or service-account JSON / OAuth token |
| Twitter/X | 3 | `api.twitter.com` | OAuth 1.0a (two), bearer (one) |
| Apple Messages for Business | 4 | caller-supplied MSP URL | bearer MSP key |
| iCloud Drive | 3 | caller-supplied gateway URL | bearer |
| Unified broadcast | 1 | delegates to the send tools | per-channel credentials |
| Scheduled SMS (Notify.lk) | 1 | the `notifylk` package | user id and API key |

### The seams the ports need

- **`HttpClientInterface` exists and is enough.** Almost every execute makes one
  request; the three `manage_*` webhook/command tools branch across two or three
  endpoints, and the SMS tool schedules instead of sending. This is the same
  seam the GTmetrix client uses.
- **Credentials are arguments, not settings.** All 50 take `token`, `api_key`
  or `access_token` in the schema; there is no option, transient or credential
  store in the family (one `get_option( 'timezone_string' )` in the SMS tool).
  The port keeps the argument shape because a host that passes credentials per
  call must keep working, and marks each credential property `writeOnly: true`
  so a UI, a transcript or a logger can redact it.
- **`ToolInvokerInterface` exists and fits the broadcast.**
  `unified_channel_broadcast` already resolves the sibling send tools through
  the registry and calls `execute()`; the core contract is the same shape, so
  the port injects the invoker and delegates rather than copying seven send
  paths.
- **`CacheStoreInterface` covers the service account.** The Google Chat helper
  signs a JWT and caches the access token under
  `wp_mcp_ai_gc_sa_token_*`; the core cache is the same idea, so the helper
  ports as a small class with an injected cache and HTTP client.
- **No file seam is needed.** WhatsApp media takes `media_url` or `media_id`,
  and the Graph/iCloud uploads take a path plus a content string. No tool reads
  a local file.

### Findings that change the plan

1. **Every tool declares `edit_posts` and enforces `manage_options`.** The
   registry and the schema consumers see `edit_posts`; every `execute()` then
   computes `$default_capability = 'manage_options'` behind a per-slug filter.
   Fifty tools, fifty mismatches. The port declares what it enforces, and the
   first slice's base class owns the decision so no later slice re-litigates
   it.
2. **No Graph tool follows `@odata.nextLink`.** Teams channels and messages,
   Outlook messages and OneDrive listings return the first page and drop the
   continuation. The port returns `next_link` when the API sent one and accepts
   it back on the list tools (restricted to the Graph host). Silently stopping
   at 25 is what an agent misreads as "that is all there is".
3. **Provider payloads are returned verbatim.** Most sends `return $decoded` or
   a raw array. The core envelope has a stable shape, so each tool projects the
   identifiers and statuses it promises (message id, timestamp, channel,
   counts) instead of echoing the provider document.
4. **Error classification is per provider, not per family.** Slack answers
   HTTP 200 with `ok: false`; Graph nests `error.message`; Meta and Telegram
   use their own keys. The first slice establishes one `ChatChannelTool` base
   with a per-provider error map, as the GTmetrix client did, rather than fifty
   ad-hoc message reads.
5. **Caller-supplied endpoints are an SSRF surface.** The Google Chat webhook,
   the Apple MSP URL and the iCloud gateway URL all arrive as arguments, and
   the core's `HttpClientInterface` has no URL policy. The port adds one shared
   guard — https only, no loopback, private or link-local hosts, optional host
   allowlist from settings — and the two webhook-registration tools validate
   the URL they register the same way.
6. **110 `apply_filters` calls have no equivalent.** The core's hooks live at
   the host boundary, not inside a tool. The port keeps the defaults and drops
   the filters: the capability is the declared one, the timeout is a constant.
7. **`WP_MCP_AI_Logger` and `is_multisite()` appear in all 50.** Logging is the
   host's concern (the tool returns a classified error), and the multisite
   membership check is tenancy, which the adapter's authorization seam owns.
8. **`schedule_notify_sms` is not portable as it stands.** It requires the
   `notifylk/notify-php` package (its own `is_available()` refuses without it)
   and a WP-cron single event whose callback performs the send. The core's
   schedule store records a hook and args and leaves dispatch to the host, so
   scheduling alone would report success while nothing sent, and sending inline
   would store the provider key in a schedule row. **Deferred**, with both
   prerequisites named in the register.

### Classification

**49 portable, 1 deferred** (`schedule_notify_sms`), **0 declined**. Nothing in
the family is a duplicate or a loop over another tool; the broadcast is the
closest, and it already delegates.

### Slices

| slice | tools | count | what it proves |
|---|---|---:|---|
| 1 | shared ground + Slack: `send_slack_message`, `get_slack_channels`, `get_slack_messages`, `create_slack_channel` | 4 | the `ChatChannelTool` base, the credential-as-argument schema, HTTP-200-with-`ok:false` classification |
| 2 | Discord: send, channels, messages, voice members, create channel, add reaction | 6 | bot-token REST and reactions |
| 3 | Telegram: send, updates, reaction, commands, webhook | 5 | token-in-path, long-poll bounds, webhook registration |
| 4 | WhatsApp: message, template, interactive, media, get messages | 5 | Cloud API payload builders |
| 5 | Graph: Teams 3, Outlook 2, OneDrive 3 | 8 | one bearer family, `next_link` |
| 6 | Messenger 3 + Google Chat 7 | 10 | Meta Graph, the webhook URL guard, service-account JWT |
| 7 | Twitter: DM, DMs, webhook | 3 | OAuth 1.0a signing |
| 8 | Apple 4 + iCloud 3 | 7 | caller-supplied endpoints under the URL guard |
| 9 | `unified_channel_broadcast`; the SMS register entry | 1 | delegation through `ToolInvokerInterface` |

The toolkit closes at **49 ported, 1 deferred, 0 declined**.

### The baseline

| gate | before |
|---|---|
| `lib/core` PHPUnit | 3,960 / 23,348 |
| adapter `ToolProbesTest` | 242 / 1,638 |
| tool readiness audit | 462 / 462, servable 443 |
| toolkit parity | 241 of 854; `chat-channels` 0 ported, 0 declined, 1 deferred, 50 remaining |
| phpcs | clean under `phpcs-laravel.xml.dist`, tree-wide |

Slice 1 must prove three things before any other tool is read line by line:
that a credential argument can be accepted without being echoed in a reply;
that a provider which answers 200 with an error body is classified as a
failure; and that the one-request-per-tool shape ports without a second copy of
the family's HTTP plumbing. If those hold, the remaining eight slices are
mechanical. If they do not, what changes is the shape of the base class, not
fifty tools.

### §164.100 — Slice 1: the Slack four

**Recorded.** Four of fifty. `chat-channels` is now **4 ported, 0 declined,
1 deferred, 46 remaining**.

| slug | call | kind |
|---|---|---|
| `send_slack_message` | `chat.postMessage`; returns the message `ts` that a threaded reply or a permalink needs | write |
| `get_slack_channels` | one `conversations.list` page: id, name, privacy and archive flags, member count, cursor | read |
| `get_slack_messages` | one `conversations.history` page: ts, type, subtype, user, bot_id, text, thread parent, reply count | read |
| `create_slack_channel` | one `conversations.create`; id, name, privacy, creation time | write |

All four require `manage_options`, because that is what all fifty WordPress
tools enforced behind their per-slug filter even though forty-nine declared
`edit_posts`. The shared ground is `ChatChannelTool` (credential argument, one
error envelope, one transport normalisation) plus `SlackTool` (the `ok:false`
classifier, the friendly-code map, the cursor and channel projection).

#### Corrections

1. **The limit ceiling is 999, not 1000.** Both Slack list methods document
   "under 1000". WordPress accepted 1–1000 and, for anything outside that
   range, appended nothing, so `limit: 1000` was silently replaced by Slack's
   default of 100 and `limit: 0` likewise. The port clamps to 1–999 and always
   sends the effective value, so the request and the reply agree.
2. **`is_private` is parsed, not cast.** WordPress used `(bool)`, which turns
   the string `"false"` into `true`; a caller sending form-encoded booleans
   would create a private channel believing it asked for a public one.
   `boolParam()` reads `"false"`, `"0"` and `"off"` as false.
3. **A successful answer must carry the thing that was made.** WordPress
   returned the provider document as-is, so an `ok: true` body without a `ts`
   (or without a channel on a create) read as an empty success. Both are
   reported as `slack_invalid_response`.
4. **`num_members` is null when Slack omits it.** A direct message has no
   member count and `conversations.create` does not return one; reporting zero
   would fabricate a fact.
5. **`types` is sent only when given.** WordPress documented a
   `public_channel` default and never sent it; Slack's own default is the same,
   so behaviour is unchanged and the reply no longer claims a filter the
   request did not carry.

#### Adaptations

- Credentials stay arguments, but their schema properties are `writeOnly`, and
  every failure test serialises the envelope and asserts the token is absent.
- Payloads are projected: ids, statuses and the fields the tool's contract
  names, never the provider document.
- The per-slug capability and timeout filters, `WP_MCP_AI_Logger` events and
  the multisite membership check are dropped (§164.99).
- The caller-supplied-endpoint guard is **not** in this slice: Slack's endpoint
  is a constant. It lands with the first tool that accepts a URL (slice 6),
  where it has a consumer and a test rather than a hypothetical.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 3,960 / 23,348 | 3,982 / 23,430 |
| adapter `ToolProbesTest` | 242 / 1,638 | 246 / 1,654 |
| tool readiness audit | 462 / 462, servable 443 | 466 / 466, servable 447 |
| toolkit parity | 241 of 854 | 245 of 854 |
| `chat-channels` | 0 ported / 0 declined / 1 deferred / 50 remaining | 4 / 0 / 1 / 46 |
| phpcs | clean | clean |

The four adapter probes pass no token, so each proves the wiring and the
credential refusal without a network call.

#### Next

Slice 2, Discord: send, list channels, read messages, list voice members,
create channel, add reaction. Six tools over a bot-token REST API. Discord
reports errors with real HTTP status codes and a `{code, message}` body, so
`DiscordTool` gets its own classifier while the transport normalisation and
the credential shape stay in `ChatChannelTool`.

### §164.101 — Slice 2: Discord, five portable and one impossible

**Recorded.** Nine of fifty ported; `chat-channels` is now **9 ported,
0 declined, 2 deferred, 41 remaining**. The slice was planned as six tools; one
of the six cannot do what it says, and it is recorded rather than shipped.

| slug | call | kind |
|---|---|---|
| `send_discord_message` | `POST /channels/{id}/messages`; returns the message id a reaction or reply needs | write |
| `get_discord_channels` | `GET /guilds/{id}/channels`; id, name, type, position, parent | read |
| `get_discord_messages` | `GET /channels/{id}/messages`, newest first, 1-100 | read |
| `create_discord_channel` | `POST /guilds/{id}/channels`; id, name, type, position, parent | write |
| `add_discord_message_reaction` | `PUT .../reactions/{emoji}/@me`, then 204 | write |

#### The finding: `get_discord_voice_channel_members` cannot work

The WordPress tool reads `voice_states` from `GET /channels/{id}` and returns
the members with their mute, deaf, stream and video flags. Discord's REST
channel object does not carry `voice_states`. Voice state is a **gateway**
event (`VOICE_STATE_UPDATE`), and there is no REST route that lists the members
of a voice channel — the feature requests asking for one are open. The tool
therefore passes its channel-type check and then always answers
`member_count: 0`, whatever the channel actually holds.

So it is **deferred**, not ported: a tool whose contract is a lie is worse than
a missing tool, and this programme already took that position on
`schedule_notify_sms`. Prerequisite: a gateway connection, or a host-maintained
voice-state cache fed by `VOICE_STATE_UPDATE`, exposed through a port the tool
can read. The toolkit closes at **48 ported, 2 deferred, 0 declined**.

#### Corrections

1. **Ids are path segments, so they are validated.** Discord interpolates
   channel, guild and message ids into the URL, and WordPress passed
   `sanitize_text_field()` output — which leaves `/` and `..` intact — straight
   into the path. Every id is now checked as a 1-20 digit snowflake before a
   request is made.
2. **`create_discord_channel` refuses an unsupported type.** WordPress ran
   `absint()` and replaced anything outside 0/2/4/5 with 0, so a caller that
   asked for a forum (15) silently got a text channel and no signal.
3. **Success is any 2xx.** The WordPress tools required exactly 200, 201 or 204
   per route. Those are the codes Discord sends, so behaviour is unchanged for
   a conforming response; a valid but differently-numbered success is no longer
   an error.
4. **The limit is read with the shared numeric clamp** rather than `absint()`,
   which turned a non-numeric argument into 0 and then into 1.
5. **Rows are projected** — message, channel and reaction reports carry the
   documented fields, not Discord's full objects (avatars, embeds, permission
   overwrites).

#### Adaptations

- `retry_after` is a new axis on the family exception and envelope. Every
  provider in the toolkit rate-limits; Discord says so in the body
  (`retry_after`, seconds, fractional), Telegram the same, Slack in the
  `Retry-After` header. A 429 now surfaces the interval instead of only the
  status.
- Discord's numeric error code (`50013`, `10003`) is the provider code, which
  is what lets a caller distinguish a missing permission from an unknown
  channel without reading the message.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 3,982 / 23,430 | 4,005 / 23,511 |
| adapter `ToolProbesTest` | 246 / 1,654 | 251 / 1,674 |
| tool readiness audit | 466 / 466, servable 447 | 471 / 471, servable 452 |
| toolkit parity | 245 of 854 | 250 of 854 |
| `chat-channels` | 4 / 0 / 1 / 46 | 9 / 0 / 2 / 41 |
| phpcs | clean | clean |

#### Next

Slice 3, Telegram: send, updates, reaction, commands, webhook. Five tools over
`api.telegram.org/bot{token}/…`. The token is a path segment here, so the
snowflake guard proves its worth in a second shape; the classifier is
`ok:false` with a numeric `error_code`, and 429 carries `retry_after` in the
body, which the new axis already carries.

### §164.102 — Slice 3: Telegram

**Recorded.** Fourteen of fifty ported; `chat-channels` is now **14 ported,
0 declined, 2 deferred, 36 remaining**.

| slug | call | kind |
|---|---|---|
| `send_telegram_message` | `sendMessage`, chunked at 4,096 characters | write |
| `get_telegram_updates` | `getUpdates`, offset in and `next_offset` out | read |
| `add_telegram_message_reaction` | `setMessageReaction` | write |
| `manage_telegram_commands` | `setMyCommands`, `deleteMyCommands`, `getMyCommands` under a `BotCommandScope` | write |
| `manage_telegram_webhook` | `setWebhook`, `deleteWebhook` | write |

The token is the route: `/bot{token}/{method}`, encoded once. `TelegramTool`
keeps `error_code` as the provider code and reads `parameters.retry_after`, so
the axis added in §164.101 carries a Telegram 429 without a second mechanism.
An empty payload is sent as **no body**, not `[]`: the parameterless methods
take none, and a JSON array is not a JSON object.

#### Corrections

1. **An unsupported `parse_mode` is refused.** WordPress checked the value and
   silently sent plain text when it did not match, so a caller that asked for
   `markdown` got unformatted output with no signal.
2. **A partial chunked send reports what was delivered.** WordPress's error
   named the failed chunk but not the ids of the chunks already sent, so a
   retry could duplicate them. `telegram_chunk_error` carries `sent`.
3. **A command name is lowercased and then validated, not rewritten.**
   WordPress stripped every character outside `[a-zA-Z0-9_]`, so `hello-world`
   became `helloworld` and the registered name was not the one the caller
   wrote. Only case is normalised now; anything else is refused.
4. **A chat-level scope must carry its id.** WordPress built
   `{"type":"chat","chat_id":""}` and `{"type":"chat_member","user_id":0}`
   from missing arguments, so the request failed at Telegram rather than where
   the mistake was.
5. **The webhook URL and secret are validated in the tool.** The URL must be
   absolute https with a host; the secret must match `[A-Za-z0-9_-]{1,256}`.
   WordPress delegated the secret check to a WordPress remote-site class and
   fell back to a stored connection value; nothing is stored here.
6. **The message id must be a positive integer.** `absint()` refused zero but
   turned a non-numeric value into zero; the refusal is now explicit.
7. **An over-long command description is refused** rather than truncated with
   byte-based `substr()`, which can split a multi-byte character.

#### Adaptations

- The chunker is kept whole: paragraph boundaries, then lines, then a hard
  split at the last space near the limit, and a hard-split part is sent without
  `parse_mode` because the split can cut a markup entity in half.
- The WordPress kses pass is dropped. `parse_mode: HTML` is a supported input
  and the core has no sanitiser for a provider's markup dialect.
- The automatic `setMyCommands` fan-out after `setWebhook` is dropped: it
  registered the WordPress plugin's default command list from a controller that
  has no core counterpart. `manage_telegram_commands` covers it explicitly.
- The fixed `allowed_updates` list is kept. A webhook that omits it does not
  receive `chat_member` updates, and the WordPress tool asked for them.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,005 / 23,511 | 4,033 / 23,630 |
| adapter `ToolProbesTest` | 251 / 1,674 | 256 / 1,694 |
| tool readiness audit | 471 / 471, servable 452 | 476 / 476, servable 457 |
| toolkit parity | 250 of 854 | 255 of 854 |
| `chat-channels` | 9 / 0 / 2 / 41 | 14 / 0 / 2 / 36 |
| phpcs | clean | clean |

#### Next

Slice 4, WhatsApp: send, template, interactive, media and get messages. Five
tools over the Meta Cloud API, whose nested `error.code` and
`error_data.details` need their own classifier and whose `phone_number_id`
joins the `access_token` as a credential argument. Media is a `link` or an
`id` in the JSON body, not an upload, so no multipart body is needed.

### §164.103 — Slice 4: WhatsApp, four portable and one impossible

**Recorded.** Eighteen of fifty ported; `chat-channels` is now **18 ported,
0 declined, 3 deferred, 32 remaining**. Slice 4 was planned as five tools; one
of them cannot work, and the same standard as slice 2 applies.

| slug | payload | kind |
|---|---|---|
| `send_whatsapp_message` | `type: text` with `preview_url` | write |
| `send_whatsapp_template` | `type: template` with name, language, components | write |
| `send_whatsapp_interactive` | `type: interactive`, reply buttons or a list | write |
| `send_whatsapp_media` | `type: image\|video\|audio\|document\|sticker`, by link or id | write |

#### The finding: `get_whatsapp_messages` cannot work

It issues a **GET** on `/{phone_number_id}/messages`. Meta documents exactly
one method on that route: **POST**, for sending. A GET answers
`100 Unsupported get request`, so the WordPress tool never returned a message.
WhatsApp does not expose conversation history over the Graph API at all —
inbound messages arrive only as webhooks, and the message log belongs to the
WhatsApp Business app, not to the API.

So it is **deferred**, with the prerequisite named in the register: an
inbound-message receiver (a webhook endpoint plus a message store the tool can
read), which is a host concern the core has no contract for today. That makes
the toolkit's final shape **47 ported, 3 deferred, 0 declined**: the SMS
scheduler, the Discord voice-state reader and this one.

#### Corrections

1. **`preview_url` is parsed, not cast.** WordPress's `(bool)` turned the
   string `"false"` into true, so a caller that refused a link preview got one.
2. **Exactly one of `media_url` and `media_id` is accepted.** WordPress
   preferred the id and silently ignored a URL given alongside it.
3. **The media link must be absolute https.** `esc_url_raw()` accepts `http://`,
   while the schema promised https and Meta requires a public https URL.
4. **An unsupported caption or filename is refused.** WordPress dropped a
   caption for audio and sticker, and a filename on anything but a document.
5. **An over-long value is refused, not truncated.** Button titles (20), row
   titles (24), descriptions (72), section titles (24), footers and header text
   (60) and captions (1024) were all cut with byte-based `substr()`, which both
   rewrites the request and can split a multi-byte character.
6. **A malformed button or row is refused, not skipped.** WordPress `continue`d
   past an entry without an id or title, so the message carried fewer options
   than the caller wrote.
7. **The list row cap is total.** Meta allows ten rows across every section;
   WordPress allowed ten per section and up to ten sections.
8. **An unknown header type is refused.** WordPress copied `type` through and
   only attached a payload for text, image, video and document.
9. **An empty `language_code` falls back to `en_US`** rather than being sent as
   an empty string.

#### Adaptations

- Two credentials stay arguments: `access_token` in the bearer header and
  `phone_number_id` in the path (digits only, so it is validated rather than
  just encoded).
- The recipient is normalised once, in the base: a phone number keeps a leading
  `+` and loses every other non-digit; a group JID keeps the characters a JID
  uses.
- The Graph API version is pinned to `v19.0`, the version the WordPress tool
  used. An unversioned call silently takes whatever the account defaults to.
- WordPress's `sanitize_textarea_field()` and `sanitize_file_name()` passes are
  dropped: WhatsApp renders plain text and the filename is only a display label.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,033 / 23,630 | 4,060 / 23,718 |
| adapter `ToolProbesTest` | 256 / 1,694 | 260 / 1,710 |
| tool readiness audit | 476 / 476, servable 457 | 480 / 480, servable 461 |
| toolkit parity | 255 of 854 | 259 of 854 |
| `chat-channels` | 14 / 0 / 2 / 36 | 18 / 0 / 3 / 32 |
| phpcs | clean | clean |

#### Next

Slice 5, Microsoft Graph: Teams three, Outlook two, OneDrive three. One bearer
family over `graph.microsoft.com/v1.0`, with `@odata.nextLink` pagination — the
first tools in the family that page by URL rather than by cursor, so the
host-restricted link check lands there.

### §164.104 — Slice 5: Microsoft Graph

**Recorded.** Twenty-six of fifty ported; `chat-channels` is now **26 ported,
0 declined, 3 deferred, 24 remaining**.

| slug | route | kind |
|---|---|---|
| `get_teams_channels` | `GET /teams/{id}/channels` | read |
| `get_teams_messages` | `GET /teams/{id}/channels/{id}/messages` | read |
| `send_teams_message` | `POST` the same route | write |
| `get_outlook_messages` | `GET /me/mailFolders/{id}/messages` | read |
| `send_outlook_mail` | `POST /me/sendMail` → 202, no body | write |
| `get_onedrive_file` | `GET /me/drive/items/{id}` or `/me/drive/root:/{path}` | read |
| `list_onedrive_files` | `GET /me/drive/root/children` or `…:/{path}:/children` | read |
| `upload_onedrive_file` | `PUT /me/drive/root:/{path}:/content` with the decoded bytes | write |

#### The link guard

Graph paginates with `@odata.nextLink`: an **absolute URL** that the next
request fetches with the caller's bearer token. The four list tools return it
and accept it back as `next_link`, but only after checking that it is https and
its host is `graph.microsoft.com` or `graph.microsoft.us` and its path is under
`/v1.0`. Without that check, a `next_link` of `https://evil.example/steal`
would hand the caller's token to the host it named. WordPress dropped the link
entirely, so only the first page of any large collection was visible.

#### Corrections

1. **Every id that becomes a path segment is validated.** A team id is a GUID;
   a channel id is restricted to the characters a Teams channel id uses
   (`19:abc@thread.tacv2`) and percent-encoded; a drive path is split, each
   part encoded, and empty, `.` and `..` parts refused (encoding does not
   change those two). WordPress interpolated `sanitize_text_field()` output
   into team and channel paths and allowed `..` in drive paths.
2. **A traversal-shaped id never reaches the client**, which the tests assert
   by leaving the response queue empty: a request would throw.
3. **Success is any 2xx** for the two writers that had exact-code checks, where
   Graph sends 201 and 202.
4. **An invalid CC is refused.** WordPress checked `is_email()` and silently
   dropped a CC that failed, so the sender believed the copy had gone.
5. **`content_type` is validated.** WordPress mapped anything that was not
   `html` to Text, so a typo quietly sent plain text.
6. **Exactly one of `item_id` and `file_path`** in the OneDrive reader, where
   WordPress preferred the id and ignored the path.
7. **`top` is read with the numeric clamp**, so a non-numeric value means the
   default rather than `absint()` and then one.
8. **Rows are projected** in all eight tools.

#### A bug this slice found in itself

The first test run failed five cases with a `null` error slot. The paging helper
returns either a page or an error envelope, and the four callers tested
`is_array()` — which is true for an array-shaped error. In production the
Laravel factory returns an object, so the bug would have hidden; the mock
returned an array and did not. `ErrorFactoryInterface::isError()` is the
contract's discriminator and is now used instead. The same pattern is already
used by the WhatsApp interactive builder.

#### Adaptations

- Graph reports `error.code` as a **word** (`Forbidden`, `itemNotFound`) rather
  than a number; it is carried as the provider code so a caller can branch on
  it without reading the message.
- A 429 carries `Retry-After` in a header; the family envelope surfaces it as
  `retry_after`, the same key Discord and Telegram use.
- The OneDrive reader returns **metadata**, not bytes, as the WordPress tool
  did. Downloading content would be a second endpoint whose body is the file.
- Graph's simple-upload size ceiling is not guessed locally: a larger file
  surfaces Graph's own refusal with its message.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,060 / 23,718 | 4,090 / 23,828 |
| adapter `ToolProbesTest` | 260 / 1,710 | 268 / 1,742 |
| tool readiness audit | 480 / 480, servable 461 | 488 / 488, servable 469 |
| toolkit parity | 259 of 854 | 267 of 854 |
| `chat-channels` | 18 / 0 / 3 / 32 | 26 / 0 / 3 / 24 |
| phpcs | clean | clean |

#### Next

Slice 6, Messenger three and Google Chat seven. Messenger is Meta Graph with a
page token and a different recipient shape; Google Chat is the first tool in
the family whose endpoint is **caller-supplied** (a webhook URL), so the URL
guard promised in §164.99 finally has its consumer, and the service-account JWT
tools need a token cache.

### §164.105 — Slice 6: Messenger and Google Chat

**Recorded.** Thirty-five of fifty ported; `chat-channels` is now **35 ported,
0 declined, 4 deferred, 15 remaining**.

| slug | call | kind |
|---|---|---|
| `send_messenger_message` | `POST /me/messages` | write |
| `get_messenger_conversations` | `GET /me/conversations?platform=messenger` | read |
| `send_google_chat_message` | `POST` a caller-supplied webhook URL | write |
| `get_google_chat_spaces` | `GET /v1/spaces` | read |
| `create_google_chat_space` | `POST /v1/spaces` | write |
| `get_google_chat_messages` | `GET /v1/{space}/messages` | read |
| `list_google_chat_space_members` | `GET /v1/{space}/members` | read |
| `add_google_chat_space_member` | `POST /v1/{space}/members` | write |
| `remove_google_chat_space_member` | `DELETE /v1/{membership}` | write |

#### The URL guard

`send_google_chat_message` takes an endpoint from the caller, and the
credential is **inside it**: a Google Chat webhook URL carries its own key.
WordPress checked `esc_url_raw()` and `FILTER_VALIDATE_URL` only, which accept
`http://` and `http://169.254.169.254/…`. `ChatChannelTool::publicUrl()` is
now the family guard: https only, no userinfo, no literal private, loopback,
link-local or reserved address (`FILTER_FLAG_NO_PRIV_RANGE |
FILTER_FLAG_NO_RES_RANGE`), no `localhost` or `.local`/`.internal` hostname.
The reply never echoes the URL, because the URL is the secret.

A public hostname that **resolves** to a private address (DNS rebinding) is not
caught: the HTTP seam does not expose the resolved address. That limit is
documented in the guard and is a host responsibility.

#### The finding: `create_messenger_broadcast` cannot work as written

It posts `{message: {text}}` to `/me/broadcast_messages`. Meta does not accept
that body: a broadcast takes a `message_creative_id` created first by
`POST /me/message_creatives`, and the broadcast is delivered by a separate
`POST /me/broadcast_messages/{id}/send`. The WordPress tool therefore never
created a broadcast.

Making it work needs the creative step **and** the send step, and the send
delivers to the entire labelled audience — an outward, approval-gated operation
that no tool in this family has a confirmation gate for. It is **deferred**,
with the two steps and the gate named as the prerequisite. That makes the
toolkit's final shape **46 ported, 4 deferred, 0 declined**.

#### Corrections

1. **Google Chat resource names are validated.** `spaces/{id}` and
   `spaces/{id}/members/{id}` are matched to the characters Google uses and
   interpolated unchanged; WordPress put `sanitize_text_field()` output into
   the route.
2. **An unsupported space type or membership role is refused.** WordPress
   replaced an unknown type with `SPACE` and an unknown role with
   `ROLE_MEMBER`, so a typo created the wrong thing silently.
3. **A Messenger PSID must be numeric.** WordPress passed any scalar through
   and let Meta reject it.
4. **`paging.next` is not returned for Messenger conversations.** Meta builds
   it with the access token inside; the reply carries the cursor from
   `paging.cursors.after` instead.
5. **`page_size` uses the numeric clamp** in the four Google Chat paging tools,
   rather than `absint()` and then one.
6. **Rows are projected** in all nine tools.

#### Adaptations

- **The Google service-account exchange is the host's job.** WordPress accepted
  `service_account_key` and minted a token with an RS256 JWT through
  `WP_MCP_AI_Pro_Google_Service_Account`. The port takes `access_token`; the
  seven tools then share one bearer family. A host that holds a service-account
  key mints the token before it calls the tool. This is a real narrowing of the
  schema and is recorded as such: a follow-up could add a token-minting service
  behind the same tools without changing any of them.
- Google Chat paginates by `pageToken`, not by URL: there is no absolute link to
  guard, unlike Graph.
- The Chat error object carries both a numeric `code` and a word `status`; the
  word is the provider code.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,090 / 23,828 | 4,118 / 23,938 |
| adapter `ToolProbesTest` | 268 / 1,742 | 277 / 1,778 |
| tool readiness audit | 488 / 488, servable 469 | 497 / 497, servable 478 |
| toolkit parity | 267 of 854 | 276 of 854 |
| `chat-channels` | 26 / 0 / 3 / 24 | 35 / 0 / 4 / 15 |
| phpcs | clean | clean |

#### Next

Slice 7, Twitter: send a DM, list DMs and manage the webhook. Three tools over
OAuth 1.0a — a signed request rather than a bearer, so the family gets its
first request signer, and the webhook tool registers a caller-supplied URL under
the guard added here.

### §164.107 — Slice 7: Twitter/X

**Recorded.** Thirty-eight of fifty ported; `chat-channels` is now **38 ported,
0 declined, 4 deferred, 12 remaining**. Eight tools are left: Apple (4), iCloud
(3) and the unified broadcaster.

| slug | call | kind |
|---|---|---|
| `send_twitter_dm` | `POST /2/dm_conversations/with/{recipient_id}/messages` | write |
| `get_twitter_dms` | `GET /2/dm_events`, or `/2/dm_conversations/{id}/dm_events` | read |
| `manage_twitter_webhook` | `POST`/`DELETE`/`GET` `/1.1/account_activity/all/{env}/…` | write |

#### The finding: there is no `/2/webhooks`

WordPress chose its webhook endpoint from `env_name`:

```php
'' !== $env_name
    ? 'https://api.twitter.com/1.1/account_activity/all/' . rawurlencode( $env_name ) . '/webhooks.json'
    : 'https://api.twitter.com/2/webhooks';
```

The second branch does not exist. Webhook management is the **Account Activity
API**, which X documents only under v1.1 and only per environment:
`…/all/:env_name/webhooks.json`, `…/webhooks/:webhook_id.json` and
`…/subscriptions.json`. The v2 reference carries the DM endpoints and no
webhook route at all. `env_name` defaults to `''` in the WordPress schema, so
the documented default path of the tool asked for a route that 404s.

The port therefore **requires `env_name`**. There is a real prerequisite behind
the requirement — a paid Account Activity subscription, which is what creates an
environment — and it is named in the description rather than discovered from a
404.

Two smaller things fell out of the same reading. `subscribe` required a
`webhook_id` and then did not use it on the Premium route, which subscribes the
user whose tokens sign the request; the argument is no longer required. And
`list` has three response shapes (`environments[] → webhooks[]`, a bare list,
and a single object), so the projection flattens all three into one `webhooks`
array.

#### The signer

This is the family's first non-bearer credential. Four values produce a
per-request `Authorization: OAuth …` header, HMAC-SHA1 over the RFC 5849
signature base string.

The implementation is checked against the **provider's own worked example**:
the base string and the digest `hCtSmYh+iHYCEqBWrE7C7hYmtUk=` published on X's
"Creating a signature" page are reproduced byte for byte by
`TwitterTool::signatureBaseString()` and `TwitterTool::sign()`. That is a check
against X's arithmetic, not against this implementation's.

**Correction.** WordPress signed `rawurlencode($fullUrl)` and ignored the query
string. That is correct only while the URL has no query, no default port and no
upper-case host — which happened to hold for all four of its signed endpoints.
The port derives the base URI per RFC 5849 §3.4.1.2: lower-cased scheme and
host, the port only when it is not the scheme default, no query, no fragment.
Parameters are sorted by encoded name then encoded value rather than by raw key.
A future signed endpoint with a query string cannot silently produce an invalid
signature.

#### Corrections

1. **Both DM id shapes are validated, not sanitised.** WordPress ran
   `sanitize_text_field()` and interpolated the result into a path:
   `/2/dm_conversations/with/{recipient_id}/messages` and
   `/2/dm_conversations/{dm_conversation_id}/dm_events`. A value containing `/`
   or a dot sequence addresses a different route. `recipient_id` is the
   documented `^[0-9]{1,19}$`; the conversation id is the safe subset
   `[A-Za-z0-9_-]{1,64}` because X publishes two shapes for it (two user ids
   joined by a hyphen for one-to-one, numeric for a group).
2. **The 10,000-character ceiling is enforced locally.** WordPress declared it
   in the schema and then sent whatever it was given, so the refusal arrived
   after the round trip.
3. **The pagination token is checked against its documented floor.** It is
   base32hex and at least 16 characters; the floor catches an event id or a page
   size passed where a token belongs, which WordPress forwarded and the API
   answered with a 400.
4. **A webhook URL must be publicly resolvable.** WordPress accepted
   `esc_url_raw()` with the https scheme, which passes
   `https://127.0.0.1/hook` and `https://user:pass@host/hook`. X sends a CRC
   challenge to that URL, so a private or credentialed one is both useless and a
   way to make the host issue an authenticated request inward. `publicUrl()`
   refuses it.
5. **`env_name` and `webhook_id` are path segments.** Both are validated and
   encoded once.
6. **Text that cannot be JSON-encoded is refused**, where WordPress built the
   request first and returned an encoding error afterwards.

#### Adaptations

- **The reply projects the event, not the envelope.** `get_twitter_dms` returns
  `events[]` with id, type, text, timestamp, sender and conversation, plus
  `next_token`, `previous_token` and `result_count`. WordPress returned the v2
  document verbatim, so a caller had to know the envelope to find the cursor.
- **`event_types` is fixed to `MessageCreate`**, as WordPress fixed it. A caller
  asking for DMs does not want `ParticipantsJoin` rows interleaved, and the tool
  that would surface them does not exist. The description says so rather than
  pretending the parameter is absent.
- **The credential for reading is an OAuth 2.0 *user* token**, not the app-only
  bearer token used for public reads; an app-only token answers 403 on
  `/2/dm_events`. The schema description states the scope (`dm.read`).
- **The API host stays `api.twitter.com`.** X documents `api.x.com` and keeps
  the older host as an alias; the alias is used so a credential set issued
  against the older host keeps addressing it.
- A 429 carries `x-rate-limit-reset` as a **Unix timestamp**, not a delay, so
  the remaining seconds are measured against now; a `Retry-After` header, when a
  proxy adds one, wins because it is already a delay.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,118 / 23,938 | 4,146 / 24,038 |
| adapter `ToolProbesTest` | 277 / 1,778 | 280 / 1,790 |
| tool readiness audit | 497 / 497, servable 478 | 500 / 500, servable 481 |
| toolkit parity | 276 of 854 | 279 of 854 |
| `chat-channels` | 35 / 0 / 4 / 15 | 38 / 0 / 4 / 12 |
| phpcs | clean | clean |

#### Next

Slice 8, Apple Messages (4) and iCloud Drive (3). Both families take a
caller-supplied `msp_api_url` / `gateway_url`, so `publicUrl()` is the guard for
all seven, and both are bridge protocols rather than first-party APIs — the
provider is a host-run gateway, which makes the description the contract.

### §164.108 — Slice 8: Apple Messages and iCloud Drive

**Recorded.** Forty-five of fifty ported; `chat-channels` is now **45 ported,
0 declined, 4 deferred, 5 remaining**. One tool is left to port: the unified
broadcaster.

| slug | call | kind |
|---|---|---|
| `send_apple_message` | `POST {msp_api_url}` (`type: text`) | write |
| `send_apple_message_group` | `POST {msp_api_url}` (`type: group`) | write |
| `send_apple_message_interactive` | `POST {msp_api_url}` (`type: interactive`) | write |
| `get_apple_messages` | `GET {msp_api_url}?businessId&limit&status…` | read |
| `list_icloud_drive_files` | `GET {gateway_url}?limit&folderId&offset` | read |
| `get_icloud_drive_file` | `GET {gateway_url}?fileId` | read |
| `upload_icloud_drive_file` | `POST {gateway_url}` | write |

#### The finding: neither family has an API a business can call

Apple Messages for Business is reached only through an approved **Messaging
Service Provider**. Apple publishes the MSP REST API that providers *implement*;
the business calls its provider's endpoint, and the provider owns the path, the
auth and the envelope. iCloud Drive is further away still: Apple exposes
CloudKit to app developers, not a drive API to a server, so reaching a user's
iCloud Drive needs a bridge the host runs.

Both families are therefore the same shape as `send_google_chat_message` —
caller-supplied endpoint plus credential — and both are SSRF-sensitive for the
same reason: the bearer token travels to whatever host the caller names.
`publicUrl()` is the guard for all seven, and no reply echoes the endpoint,
because a gateway URL may carry a key in its query string.

**Honesty point.** The Apple envelope these tools build — `businessId`, `type`,
`body.text`, `locale`, `conversationId`, `recipientId`, `intent`, and
`interactiveType` plus `interactive` — follows Apple's conceptual model and the
WordPress plugin's own convention. It is **not** Apple's MSP wire format, which
nests the payload under `interactiveData.data.<camelCaseType>` and refers to
images by a separately uploaded identifier. A gateway that speaks the native
format must translate. The tool's contract is "POST this JSON to this URL",
which it honours; the description says so rather than implying native semantics.

#### Why the reply is not projected

Everywhere else in this family a success returns a named projection, because the
provider's schema is published and the fields worth keeping are knowable. Here
the response schema belongs to the caller's gateway, and the tool cannot know
which field a caller needs. Projecting to a guessed field set would silently drop
fields the gateway defines. The reply carries the decoded document under
`response`, the conventional collection under `items` when one is present, and
the inputs the tool normalised.

#### Corrections: the silent-loss class

The four Apple tools had a habit of **skipping or truncating** what they could
not accept. Each is a refusal now, because a widget that quietly loses content is
worse than an error the caller can act on:

1. **An over-long body is refused.** WordPress sliced at 2,000 characters with
   `mb_substr()` and sent the shortened text.
2. **An over-full list picker is refused.** WordPress `array_slice()`d each
   section to ten items and dropped the rest.
3. **A malformed section, item, participant or time slot is refused.** WordPress
   `continue`d past it, so a picker arrived with fewer choices and a group was
   addressed to fewer people.
4. **A time slot's `startTime` must be an ISO 8601 instant.** WordPress accepted
   any string, so the customer could be shown a slot that does not exist.
5. **Coordinates are range-checked** (±90 / ±180) and a duration must be
   positive.
6. **A `data:` image must be a base64 image.** WordPress forwarded any string
   beginning `data:image/`.
7. **A message must name a conversation or a recipient.** WordPress required
   neither, although both of its own property descriptions said one was needed,
   so a request no gateway can route was sent and failed remotely.
8. **The participant cap applies after sanitisation.** WordPress checked the raw
   array length, so 40 entries of which 30 were blank passed, and 32 valid
   entries plus one blank was refused.
9. **`multipleSelection` is a real boolean.** The WordPress `(bool)` cast turns
   the string `"false"` into true — the one setting a caller is most likely to
   send as a string.

#### Corrections: the input class

10. **A caller-supplied endpoint must be public https.** WordPress checked
    `FILTER_VALIDATE_URL` and an `https://` prefix, which accepts
    `https://127.0.0.1/` and `https://user:pass@host/` — with the bearer token
    attached.
11. **The locale is a BCP 47 tag**, validated rather than passed through, and
    `en-US` when absent.
12. **`status` defaults to `open`**, as the schema declares. WordPress omitted
    the parameter entirely when the caller passed nothing, so the provider
    chose.
13. **A file name cannot be a path.** WordPress forwarded `../../secrets` and
    `a/b` for the gateway to interpret.
14. **Base64 is normalised before the strict decode.** `base64_decode($x, true)`
    fails on the line-wrapped form most encoders produce; whitespace is stripped
    first and the payload is still strictly decoded.
15. **The content type must be a MIME type.**
16. **Ids and cursors are bounded** instead of forwarding unbounded
    `sanitize_text_field()` output.
17. **`limit` uses the numeric clamp.** WordPress `absint()` turned `-50` into
    fifty items.

No upload size ceiling is invented: the gateway owns that limit, and a local
number would refuse uploads the caller's provider accepts.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,146 / 24,038 | 4,182 / 24,180 |
| adapter `ToolProbesTest` | 280 / 1,790 | 287 / 1,818 |
| tool readiness audit | 500 / 500, servable 481 | 507 / 507, servable 488 |
| toolkit parity | 279 of 854 | 286 of 854 |
| `chat-channels` | 38 / 0 / 4 / 12 | 45 / 0 / 4 / 5 |
| phpcs | clean | clean |

#### Next

Slice 9, `unified_channel_broadcast`: one tool that fans a message out to the
providers the caller names, by delegating to the tools already ported. It needs a
session-state or per-call target contract, and it is the last tool in the
toolkit — after it, the toolkit closes at 46 ported, 4 deferred, 0 declined.

### §164.109 — Slice 9: the unified broadcaster, and the toolkit closed

**Recorded.** `chat-channels` closes at **46 ported, 0 declined, 4 deferred,
4 remaining**. All four remaining are the deferrals named in §164.102–164.105,
so the toolkit has no unported tool left that could work.

| slug | behaviour | kind |
|---|---|---|
| `unified_channel_broadcast` | resolves seven senders and delegates | write |

#### The seam: `ToolResolverInterface`, not the registry

WordPress called
`WP_MCP_AI_Tool_Registry::get_instance()->get_tool( $slug )` — the concrete
singleton — and then `execute()` on the result. That is why the WordPress version
could not run against any other host, and it is the wrong layer for a core tool:
`ToolRegistry` lives in `Application`, and a tool may depend only on `Domain`.

The port depends on **`ToolResolverInterface`**, which `ToolRegistry` and
`ToolScope` both implement, and calls `execute()` on the resolved tool. Two
things follow:

- **The failure detail survives.** `ToolInvokerInterface::invoke()` collapses an
  unregistered tool, a thrown exception and a provider error envelope to `null`.
  That is right for the retrieval harness, which skips a source and moves on, and
  useless for a broadcast report: "telegram: failed" with no reason is the same
  quality as no report. Resolving and executing keeps the envelope, which is
  reported per channel.
- **A host can narrow the fan-out.** Binding `ToolScope` instead of the full
  registry means the broadcaster can only reach the tools the host allows. That
  policy belongs to the host, and this seam is where it fits.

The adapter binds the interface to the existing registry singleton, so a tool
constructed at boot still resolves senders registered after it.

#### The correction of substance: `success` was an array

WordPress returned
`array( 'success' => array( …per-channel… ), 'failures' => array( … ), 'summary' => … )`.
`success` there is a list of channel results, not the boolean every other tool in
this family returns, so a caller checking `$result['success'] === true` saw a
working broadcast as a failure. The port returns the canonical envelope and puts
the per-channel report in `data`.

#### Three outcomes, not two

A fan-out is not a single send, so "did it work" has three answers:

| outcome | envelope |
|---|---|
| every channel sent | success |
| some sent | success, with the failures listed |
| none sent | error `channel_broadcast_failed` |

Partial delivery is a legitimate result of sending to independent services, and
the caller needs the detail rather than a blanket error. A broadcast that
reached nobody must not look like a success with a nested counter — that is the
one outcome a caller should never have to infer.

#### Other corrections

1. **An unknown channel name refuses the call before anything is sent.**
   WordPress reported it as one more per-channel failure, so a typo among six
   valid channels still broadcast to the six and read as a partial success.
2. **A repeated channel is sent to once.** WordPress iterated the list as given,
   so `["telegram","telegram"]` sent twice and reported one result.
3. **A channel with no credential entry fails alone**, as in WordPress, because a
   caller may list a channel it has not configured and still want the rest
   delivered.
4. **A JSON-encoded credential string is decoded at both levels.** WordPress did
   this for the outer map because the Schedule Manager stored them that way; the
   per-channel value is decoded too, and a string that is not a JSON object is
   refused rather than cast.
5. **Message length limits stay with the senders that own them.** Telegram,
   Discord, Slack and WhatsApp cap text differently; duplicating those numbers
   here would drift from the tools that enforce them, so an over-long message
   fails only on the channels that reject it.

#### Deliberately not ported: the chat-client restriction

WordPress marked the tool as restricted from the public chat client, because an
assistant invoked from a public surface must not fan out on the site owner's
behalf. That is a host policy, and this runtime has no chat-client context to
check. The description says the tool sends outward to every channel named; a
host that exposes it to untrusted callers should gate it at the assistant or
surface level.

#### A note on the errors this slice cost

Every failure in this slice was the same mistake, and it is the one §164.100
warned about: **an error envelope is itself an array**, so `is_array()` cannot
discriminate a `string|array|error` return. Across slices 7 to 9 it was written
at nine guards — two in the Twitter tools, five in the Apple and iCloud tools,
and two here. Two were caught by a failing test (a credential-set guard that
treated the refusal as credentials, and a channel-list guard that iterated the
envelope and passed `false` to a `string` parameter); the other seven were found
by auditing every `is_array()` in the family before committing.

`ErrorFactoryInterface::isError()` is the only correct discriminator, and it is
now used at every such guard in the toolkit. The audit rule is simple: a helper
that returns "a value or the canonical error" must be discriminated by
`isError()`, never by the value's own type.

#### Measurements

| gate | before | after |
|---|---|---|
| `lib/core` PHPUnit | 4,182 / 24,180 | 4,197 / 24,233 |
| adapter `ToolProbesTest` | 287 / 1,818 | 288 / 1,822 |
| tool readiness audit | 507 / 507, servable 488 | 508 / 508, servable 489 |
| toolkit parity | 286 of 854 | 287 of 854 |
| `chat-channels` | 45 / 0 / 4 / 5 | 46 / 0 / 4 / 4 |
| phpcs | clean | clean |

#### The toolkit, closed

| slice | tools | state |
|---|---:|---|
| 1 Slack | 4 | ported |
| 2 Discord | 5 | ported (1 deferred: no REST voice-state route) |
| 3 Telegram | 5 | ported |
| 4 WhatsApp | 4 | ported (1 deferred: no GET message history) |
| 5 Microsoft Graph | 8 | ported |
| 6 Messenger and Google Chat | 9 | ported (1 deferred: no broadcast creative step) |
| 7 Twitter/X | 3 | ported |
| 8 Apple and iCloud | 7 | ported |
| 9 Unified broadcaster | 1 | ported |
| — | 4 | deferred with the prerequisite named |

**46 ported, 4 deferred, 0 declined.** The four deferrals share a shape: each
needs something the runtime does not have — a gateway connection, an inbound
message store, a two-step creative API with an approval gate, or a scheduled-send
listener with a secret reference. None is a tool that could be ported now.

#### Next

The `chat-channels` toolkit is complete. The next pass is the next toolkit in
the 164 register.

### §164.110 — `ecommerce` measurement

**Recorded.** Forty-four tools, one already ported (`generate_invoice_pdf` →
`GenerateInvoicePdfTool`), so **43 remain**. This is the measurement that
precedes the first slice, and it changes what the loop can do here.

#### The finding: there is no commerce substrate in the runtime

`chat-channels` was portable because `lib/core` already owns
`HttpClientInterface`. `crm` was portable because it owns
`ContentStoreInterface`. This toolkit has neither an equivalent nor an obvious
mapping, and establishing that before planning slices is the point of the pass.

Three facts, each checked rather than assumed:

1. **`lib/core` has no commerce contract.** Nothing in `Domain/Contract` covers
   a product, catalog, cart, order or customer. The `cms_*` tools the console
   exposes are served by a plugin, not by the core.
2. **The commerce model that does exist is `addons/nvoos-astro-bridge`** — the
   `nvoos-commerce/v1` REST surface. Its object model is **products,
   categories, brands, colours, media, promotions and promocodes**
   (`includes/REST/`). There is **no orders controller and no customers
   controller**; the full REST set is Products, Promocodes, Promotions,
   ShopGlobals, Statuses, Terms, ThemePolicy, WooLogin, Cart, Checkout and the
   content controllers.
3. **Variants are read-only.** `Sources/WooProductSource::transform_variants()`
   emits `{ id, sku, price, oldPrice, stock, attributes[{ name, value }], image }`
   for a `WC_Product_Variable` (Proposal 106 §1.1).
   `Transformers/ProductTransformer` — the native source — emits none, and
   `REST/ProductsController` has no variants handling on write.

So the runtime can **serve** a variant list from Woo and cannot **store** or
**create** one.

#### What that does to the 44

| population | tools | substrate | verdict |
|---|---:|---|---|
| Remote provider clients | 10 | `HttpClientInterface` + caller credentials | **port now** |
| Pure algorithm | 1 | none needed | **port now** |
| Composite / AI | 5 | other tools, or the media worker | per case |
| WooCommerce-local store operations | 27 | **none** | blocked on a contract |

**Remote provider clients (10).** `shopify_products`, `shopify_orders`,
`shopify_customers`, `shopify_inventory`, `shopify_catalog` (Admin GraphQL, and
Storefront for the catalog search), `printful` (`api.printful.com`),
`quickbooks_report` (`quickbooks.api.intuit.com`), `quickbooks_desktop_sync`
(a host-run QBBRIDGE, like the Apple/iCloud gateways),
`get_import_duty` (`api.trade.gov/v1/tariff_rates/search`), and
`shipping_rate_estimator` (carrier APIs, configured endpoints). Each takes a
credential and an endpoint the way the chat-channel providers do, so the
`ChatChannelTool` disciplines apply directly: credential as an argument, one
canonical envelope, `publicUrl()` for any caller-supplied endpoint, and the
provider's own error vocabulary preserved.

**Pure algorithm (1).** `shipping_box_packer` is a 3D bin-packing routine; it
needs no store, only a normalised list of item dimensions.

**Composite / AI (5).** `lookup_product_price` orchestrates
`run_crawl4ai_job` plus vision identification; `rfdetr_catalog_search` needs the
catalog-detection model; `product_actualization` needs a scene generator;
`validate_image_for_product` and `validate_image_for_vehicle` are vision checks.
Each depends on something that may already exist elsewhere in the runtime, which
is why they are measured per case rather than grouped.

**WooCommerce-local (27).** Product writes (`create_product_advanced`,
`bulk_update_products`, `update_woo_product_price`, `update_woo_product_qty`,
`import_products_csv`, `export_products_report`, `sync_product_inventory`),
orders (`process_order_workflow`, `bulk_order_status_update`,
`refund_order_advanced`, `get_order_analytics`), customers (`segment_customers`,
`customer_lifetime_value`, `export_customer_data`), inventory
(`track_inventory_movement`, `low_stock_alert_automation`,
`inventory_forecast`), marketing (`create_discount_campaign`,
`abandoned_cart_recovery`, `get_abandoned_carts`, `send_cart_recovery_email`,
`upsell_recommendations`, `sales_performance_dashboard`) and the four CRUD
bundles (`woo_products`, `woo_orders`, `woo_customers`, `woo_coupons`). Every one
of them reads or writes a WooCommerce object — `wc_get_orders()`,
`WC_Order`, `$wpdb` over `wc_order_stats` and `woocommerce_sessions` — and the
runtime has no counterpart for any of it. `sales_performance_dashboard` is
the extreme case at 33 direct `$wpdb` reads.

This is not a port problem; it is a missing model. Porting any of the 27 would
mean either a WordPress dependency in a framework-agnostic core or a tool that
fabricates an empty answer — the failure mode §164.102 rejected for the Discord
voice tool.

#### The variant model, and what the measurement settles

The one design question worth resolving now, because it decides whether the
product-write tools ever move: the runtime's variant **read** shape is already
the right one, and the smallest model that round-trips Woo variations, Shopify
variants and the bridge is the same shape with a write side:

- a product has 1..n variants;
- a variant carries `sku`, `price`, `oldPrice`, `stock` and a set of
  `{ name, value }` options;
- the option names are defined per product, the values per variant.

That is `transform_variants()`'s emitted array, and it is also the classic
`product → sku` + `attribute ↔ sku` pivot with the value on the pivot — the
model set out in Shaun Thornburgh's "Managing Product Variants in an E-Commerce
Store with Laravel" (2023), where `attribute_sku.value` holds the per-variant
value and `attributes` are scoped to the product. The two agree, so the read
side already constrains the write side: adding variants to
`ProductsController` and to `ProductTransformer` is a schema addition, not a
redesign.

#### Decline candidate

`lookup_product_price` is documented as "like Google Lens", but its mechanism
is scraping retail search result pages (`amazon.com/s`, `ebay.com/sch`,
`target.com/s`) through `run_crawl4ai_job` and then guessing which result is the
product. Retailer search HTML is neither stable nor a licensed interface, and a
core tool that ships that mechanism will break quietly and repeatedly.
Recommended decision: **decline**, and keep the crawl and vision primitives
available to a caller who wants to compose its own lookup.

#### Proposed slices

| slice | tools | shape |
|---|---:|---|
| 1 | 5 | Shopify: Admin GraphQL, plus Storefront for the catalog search |
| 2 | 3 | Printful, trade.gov tariff rates, carrier rate shop |
| 3 | 2 | QuickBooks Online, QuickBooks Desktop bridge |
| 4 | 1 | 3D bin packer |
| 5+ | 5 | the composite and vision tools, measured one at a time |
| deferred | 27 | WooCommerce-local, prerequisite: a commerce contract with products (including variant write), orders and customers |

Slice 1 first: Shopify is five tools over one credential model, which is the
same shape as slice 3 of `chat-channels` (Telegram) and the cheapest way to
establish the family's conventions before the more varied slices.

#### Next

Slice 1, Shopify. Each tool is read line by line before it is ported, as
§164.100 requires: the Admin GraphQL document, the pagination shape, the error
vocabulary, and whether the tool's own behaviour matches the documented API.

---

### §164.111 — the commerce contract, and why it stays open

**Recorded.** §164.110 measured `ecommerce` and deferred 27 WooCommerce-local
tools on "no commerce contract". The owner's constraint on the answer was
explicit: the contract stays **open**, because stores move from WooCommerce to
the Laravel runtime and that migration has to stay possible. So this section does
not defer the model. It defines it, in `lib/core`, with no WordPress symbol in it.

Nothing here is a tool port, so the parity table does not move. What changes is
that the 27 have a prerequisite that **exists** rather than one that is named.

#### What the three source models agree on

The design was not invented. It is the intersection of three independent models,
read rather than assumed:

| `lib/core` | WooCommerce | Shopify (2026-10) | Thornburgh (Laravel, 2023) |
|---|---|---|---|
| `Product` | `product` post | `Product` | `products` |
| `ProductOption` | `_product_attributes`, or the `pa_*` taxonomy | `product.options[]` | `attributes` rows, scoped to the product |
| `ProductVariant` | `product_variation` post under its parent | `ProductVariant` | `skus` rows |
| `OptionSelection` | `attribute_pa_<name>` / `attribute_<name>` meta | `variant.selectedOptions[]` | `attribute_sku.value` on the pivot |

The intersection is non-empty and it is the same three levels in all three: a
product owns its **option definitions**, and each variant owns **one value per
option**. That is the model. Everything else — categories, media, promotions — is
a different aggregate and is not in this contract.

WooCommerce's two kinds of attribute are the one place the intersection is ragged:
a global attribute stores a **term slug** in `attribute_pa_<name>`, a custom one
stores a **value** in `attribute_<name>`. Resolving a slug to its display name is
the adapter's job, and `OptionSelection` is deliberately value-based so the
adapter has to do it rather than leaking a slug into the canonical model.

#### Two things the reference article gets wrong, and one it gets right

The Laravel model this follows is Shaun Thornburgh's *Managing Product Variants
in an E-Commerce Store with Laravel* (2023). Its structure is sound and is what
the port adopts. Two details are not:

1. **`skus.unit_amount` is a `float`.** A float cannot represent `0.10` exactly.
   Every price in this contract is a `Money` — integer minor units plus an ISO
   4217 currency — and the exponent is resolved per currency, because JPY has no
   minor unit and KWD has three. `Money::fromDecimal()` refuses more fractional
   digits than the currency allows instead of rounding them: a caller passing
   `19.999` USD has a bug upstream, and silently writing `20.00` hides it.
2. **There is no stock column anywhere.** A SKU with no quantity is not a
   sellable thing, so `ProductVariant` carries `stock` and `trackInventory`.
   `stock` is **nullable** because "untracked" and "zero on hand" are different
   answers — WooCommerce distinguishes them with `_manage_stock` + `_stock`, and
   Shopify with `inventoryItem.tracked` + the quantity.

What it gets right, and this contract keeps: **`sku` is unique**, and the option
name is scoped to the product while the value lives on the variant. The uniqueness
is what a migration matches on, so it is enforced rather than hoped for.

#### The model

| Symbol | Kind | The one decision it encodes |
|---|---|---|
| `Money` | value object | amount in minor units; per-currency exponent; refuses excess precision |
| `ProductStatus` | enum | `draft` / `published` / `archived`; `private` and `pending` collapse to `draft` because no storefront can act on the difference |
| `ProductOption` | value object | option name + the values it offers; `offers()` is case-insensitive |
| `OptionSelection` | value object | a variant's chosen values, name-sorted; `key()` is order- and case-independent |
| `ProductVariant` | entity | `identity()` is the SKU when there is one, the option key otherwise |
| `Product` | entity (aggregate) | owns options and variants; `priceRange()` and `totalStock()` are derived, never stored |
| `ProductQuery` / `ProductCollection` | entity | the same pagination idiom as `ContentQuery` / `ContentCollection` |
| `ProductCatalogInterface` | contract | find, `findByExternalRef`, `findBySku`, query, save, delete, saveVariant, deleteVariant |
| `CommerceMigrator` | application service | idempotent, dry-runnable, page-wise copy between two catalogs |
| `CommerceMigrationReport` | entity | per-item `created` / `updated` / `skipped` / `warning` / `failed`, never just a total |

Two invariants are enforced in the constructors rather than trusted to adapters:

- **No two variants of one product may share an identity.** Two rows resolving to
  the same SKU (or, with no SKU, the same option signature) make every later
  lookup arbitrary, and the row that wins is whichever the storage returns first.
- **Option coherence is reported, not thrown.** `Product::optionErrors()` names a
  variant that selects an option the product does not define, or a value the option
  does not offer. It does not throw, because Woo stores built by hand routinely
  contain exactly that, and an importer must be able to *describe* partial data
  instead of refusing the whole catalogue.

#### The write rule

`save()` creates a product and its variants together, and on **update replaces the
product fields and option definitions but does not touch the stored variant list**.
Variant lifecycle after creation is explicit (`saveVariant` / `deleteVariant`).

That asymmetry is the point. The alternative — "upsert the whole aggregate" — lets
any partial payload wipe a variant list by omission: an admin form that edits the
title, a sync that only knows the price, a migration that lost a page. The rule
makes the destructive case impossible to express.

`externalRef` is the anti-corruption key: `woocommerce:product:101`,
`shopify:gid://shopify/Product/123`. The core never interprets it; it exists so a
re-run can find what a previous run wrote. `save()` must refuse a duplicate.

Unlike `ContentStoreInterface`, there is **no `userId`**: catalog permissions are
store-wide, so the calling tool checks its capability once. An adapter that can
enforce tenancy still may.

#### The migration is part of the design, not a follow-up

`CommerceMigrator` copies one `ProductCatalogInterface` into another. It is the
executable form of the claim that this contract is sufficient, and it runs with no
framework:

- **idempotent** — matches on `externalRef`, then on the first variant SKU, never
  on storage id (the target's ids are its own);
- **dry-runnable** — the same walk, the same report, no `save()`;
- **fault-isolating** — one bad product is recorded and the run continues;
- **paged** — it queries the source page by page and stops on `hasMorePages()`.

A store moving WooCommerce → Laravel points it at a Woo-backed source adapter and
a Laravel-backed target adapter. A migration that fails halfway is simply run
again, because the matcher holds.

#### Deliberately absent

- **Orders and customers.** The 27 need them; product writes do not. They follow
  the same pattern as the next slice, and `OrderStoreInterface` /
  `CustomerStoreInterface` are not guessed at here.
- **Tax, shipping, discounts, media, locations, translations.** Separate concerns
  with separate owners. In particular a per-location stock model is *not* being
  modelled before there is a caller that needs it: one quantity per variant is
  what all three sources agree on.
- **The storefront's flat `product.price`.** Proposal 148 stores one price per
  product. In this model that value is `Product::priceRange()['min']` — a
  projection, so the two cannot drift.

#### Measurements

| Gate | Result |
|---|---|
| `lib/core` unit suite | 3,603 tests, 21,174 assertions, **0 failures** (52 new tests / 115 assertions) |
| phpcs (`phpcs-laravel.xml.dist`) | clean on the 15 new or changed files |
| Toolkit parity | unchanged — this adds no tool |
| Adapter probes | none yet: no adapter implements the port in this change |

#### Decisions required

1. **Write the deferrals into the register.** §164.110 recommends 27 deferred
   (WooCommerce-local, prerequisite now satisfied in the model but not yet in an
   adapter) and 1 declined (`lookup_product_price`, scraper). That would move
   `ecommerce` from `1 / 0 / 0 / 43` to `1 / 1 / 27 / 15`. It is a scope decision,
   so it is not taken here.
2. **Which adapter first.** The WooCommerce adapter (migration source) or the
   Laravel one (target)? The migrator needs both, but each is independently
   testable and neither belongs in `lib/core`.

#### Next

1. Merchant-side adapters behind `ProductCatalogInterface`, one in the WordPress
   adapter layer and one in the Laravel adapter, so the migrator has real ends.
2. `OrderStoreInterface` and `CustomerStoreInterface`, same pattern, so the
   remaining Woo-local tools have their whole substrate.
3. Then the 27 become portable one at a time, each against the contract.

Slice 1 for **parity** is unaffected and still next: Shopify is five tools over
one credential model and needs none of this.

---

### §164.112 — the pre-existing PHPStan findings, triaged

**Recorded.** `lib/core/phpstan.neon.dist` configures PHPStan **level 8** and
stated that "levels 5-8 are now clean, so level 8 is enforced here". They were
not. A run at the committed configuration reports **243 findings across 94
files** — and no CI job runs it, so nothing has been preventing growth. (The only
PHPStan job in CI is level 1 over `includes/` + `addons/`, with
`continue-on-error`.)

The config comment has been corrected rather than the number quietly left to rot.

#### What was actually broken, and is fixed here

| Finding | Where | What it was |
|---|---|---|
| `variable.undefined` ×4 | `GTmetrix/GtmetrixFixEngine` | `$src` and `$handle` inside **double-quoted** block strings, so PHP interpolated them: the generated snippet became `function ( , ) {` and generating it raised warnings nothing caught |
| `binaryOp.invalid` | `PaperStoreExportTool` | `$criteria['offset'] + $count` where the offset may be a string — a `TypeError` waiting for a non-numeric value |
| `throws.notThrowable` ×8 | `ControlledDocumentInterface`, `DocsCorpusInterface` | `@throws NotFoundException` resolved against `Domain\Contract`, where no such class exists; both files now import `Domain\Error\NotFoundException` |
| `class.notFound` ×2 | `ChatOrchestrator` | Docblock named `Application\Chat\ToolInterface`, which does not exist |
| `parameter.defaultValue` | `ControlledDocumentInterface::sign()` | Default `array()` for a parameter whose shape makes `meaning` required — an advertised omission the implementation must refuse. The default is gone and the type now says what the docblock says |
| `classConstant.phpDocType` | `GTmetrixScoreEngine` | `@var ...float` over integer literal thresholds |
| `return.unusedType` | `Classroom/ExportEcaDataTool` | Return type included an `array{error: mixed}` variant the method never returns |
| `classConstant.unused` | `Crm/ArchiveStaleContactsTool` | `CONTACT_TYPES` was documentation while the code repeated the mapping; the loop now reads the constant |
| `offsetAccess.notFound` ×6 | `Fnb/FnbMetrics` | `stockUsed()` returned a bare `array`, so every caller's `['value']` was unchecked; a shaped `@return` cleared the callers too |
| docblock | `GTmetrixFixEngine::generateSnippet()` | Claimed a block-less fix "contributes a comment only"; the code skips it. The code is right — the snippet is code, the advice is in the report — so the docblock was corrected |

A regression test (`GtmetrixFixEngineTest`) now asserts the emitted snippet
contains literal `$src` / `$handle`; the old code failed it through the
interpolation warning alone.

#### What was left alone on purpose

Deliberate dead code, not defects: `ManageEcaWaitlistTool`'s post-`match`
guard (commented "unreachable"), `SendWhatsAppInteractiveTool`'s empty-list
guard, `QmsGetAuditTrailTool`'s defensive catch, and
`AnalyzeFileSuitabilityLocalTool`'s `>= 0` on an already-non-negative integer.
Deleting a cheap guard to satisfy a linter is the wrong trade.

#### What remains, and the recommendation

**216 findings**, of which **91 are `missingType.iterableValue`** and 62 are
`argument.type` — annotation debt, not defects. Hand-editing 216 docblocks in a
cleanup pass is the wrong move: it is unreviewable, it touches execution paths for
zero behaviour change, and without a gate it re-rots.

Recommended, and not done here because it changes repository infrastructure:

1. `phpstan --generate-baseline` against the current state, committed.
2. A CI job running `composer run lint` in `lib/core`, failing on **new** findings.
3. Ratchet the baseline down file by file as each file is next touched — the same
   discipline the WPCS ratchet already uses.

#### Measurements

| Gate | Before | After |
|---|---:|---:|
| PHPStan level 8, `lib/core` | 243 findings / 94 files | **216 / 86** |
| `lib/core` unit suite | 3,603 | **3,608, 0 failures** |
| phpcs (`phpcs-laravel.xml.dist`) | — | 0 errors on the 10 touched files |

#### Decision required

Adopt the baseline + CI gate (above), or leave PHPStan advisory. The findings are
now documented either way, and the config no longer claims a cleanliness it does
not have.

---

### §164.113 — Slice 1: the four Shopify Admin tools

**Recorded.** Four of the five tools §164.110 placed in slice 1 shipped:
`shopify_products`, `shopify_orders`, `shopify_customers`, `shopify_inventory`.
The fifth, `shopify_catalog`, is deliberately not here; the reason is at the end.

The WordPress originals are 3,306 lines across five files plus a 2,020-line
client. The port is one base class, one pure helper and four tools, and it is
behaviour-faithful where the behaviour was right.

#### The provider had moved, and the originals had not

Five findings, each verified against the 2026-10 schema rather than assumed. Each
one broke the WordPress tool, and each one changed the port:

| Finding | What it did to the original |
|---|---|
| **2026-10 is the latest stable**; `2025-01` and `2025-04` have been unsupported since 2026-04-16 | The client pinned `2025-01`. Shopify does not error on a retired version — it **falls forward** to the oldest accessible stable one, so the tool worked against a schema nobody had read |
| **`InventoryChangeInput.changeFromQuantity` is mandatory** — "you must explicitly pass in a value, even if that value is `null`" | The client sent only item, location and delta, so `inventoryAdjustQuantities` was rejected |
| **`Customer.phone` is removed**; `email`, `emailMarketingConsent` and `addresses` are deprecated | Selecting `phone` fails the **whole** query, not one field |
| **`productCreate`'s `input:` argument is deprecated and `ProductInput` has no `variants` field**; `productCreate` creates only the initial variant | The create path passed `variants` inside the input, so it could not create variants at all |
| **`productUpdate` takes `product: ProductUpdateInput!`** with the id inside | The client passed `input:` |

#### What the port does instead

One base class (`ShopifyTool`) carries what all four share:

- **The API version** defaults to `2026-10` and is overridable. When Shopify
  serves a different version than the one requested, the difference is surfaced
  as `api_version: {requested, served}` in the result — a fall-forward is made
  visible instead of silent.
- **`errors[]` is classified once.** Shopify reports a bad query, a throttle and
  an unknown field as HTTP 200; the WordPress client never looked, and each tool
  re-checked differently (nine checks in one file, four in another). Now it is
  `shopify_graphql_error` in one place, with the provider's message and extension
  code.
- **`userErrors` is a refusal.** A mutation that "succeeds" with `userErrors` wrote
  nothing; it becomes `shopify_user_error` with the offending field.
- **Money is the provider's decimal string**, never a float.
- **The store URL and token are arguments**, as in `chat-channels`, and the URL is
  refused unless it is public https with no path — the request carries an Admin
  token, so an unchecked host is a way to hand it away.

Per tool:

- **`shopify_products`** — list/search/get/create/update, Admin API only.
  `create` is two calls: `productCreate(product:)` with `productOptions`, then
  `productVariantsBulkCreate` with `optionValues: [{optionName, name}]` when
  variants were supplied. `update` sends `productUpdate(product:)` and refuses an
  empty payload. Smart-search relaxation is preserved, extracted as a pure class
  (`ShopifySmartSearch`) so the algorithm is testable on its own.
- **`shopify_orders`** — read-only. `fulfillments`, `transactions` and `refunds`
  are lists, not connections, and are projected as such.
- **`shopify_customers`** — read-only. The query uses `defaultEmailAddress`,
  `defaultPhoneNumber` and `addressesV2`; the **result** still projects `email`
  and `phone`, so a caller's shape does not change while the query stays on
  fields that will exist next release. SMS consent is not projected: on
  `CustomerPhoneNumber` it is itself deprecated in this version.
- **`shopify_inventory`** — every adjustment sends `changeFromQuantity`, null by
  default, or the caller's `expected_quantity` for Shopify's compare-and-swap
  guard. A zero delta is refused before the call, and `reason` is validated
  against the documented list (the schema types it `String!`, so a typo would
  otherwise cost a round trip). `get_shop_info` omits `Shop.plan`, which has moved
  between releases.

#### What is not in this slice

**`shopify_catalog`** (1,012 lines) is entirely catalog/UCP: the deprecated REST
Catalog API plus the two keyless UCP MCP catalogs. That is a different transport
(an MCP client over HTTP, not GraphQL), a different auth model (keyless), and a
different policy (UCP usage guidelines forbid caching results). Bolting it into a
GraphQL family would bury three separate decisions in one file. Product search on
the admin transport is already covered by `shopify_products`; the live cross-store
catalog is its own slice, and it needs a decision about shipping an MCP client in
`lib/core` before it is ported at all.

#### Measurements

| Gate | Result |
|---|---|
| Shopify tool tests | **41 tests, 153 assertions**, 0 failures |
| `lib/core` unit suite | **3,649 tests, 21,342 assertions**, 0 failures |
| Readiness manifest | 512 classes / 512 rows, **493 servable** (+4) |
| Toolkit parity | 287 → **291 ported**; `ecommerce` now `44 \| 5 \| 0 \| 0 \| 39` |
| Adapter probes | **292** (4 new), 2 pre-existing skips |
| phpcs / PHPStan | 0 errors; no new static findings |

#### Next

Slice 2 — Printful, trade.gov tariff rates and the carrier rate shop — or
`shopify_catalog` as its own slice, whichever the owner prefers. The three
decisions from §164.111 and §164.112 are still open and still cheap to take now.

---

### §164.114 — the three open decisions taken, and the first catalog adapter

**Recorded.** The three decisions §164.113 left open are closed here, and the
first implementation of `ProductCatalogInterface` ships with them. Two of the
three are register-and-infrastructure work; the third is the adapter that makes
the contract more than an interface.

#### 1. The 27 deferrals and one decline, registered

§164.110 named 27 ecommerce tools blocked on a prerequisite and one tool that
should not be ported at all. All 28 are now in
`lib/core/resources/toolkit-parity-decisions.json`, each naming its
prerequisite:

- **27 deferred.** Every reason names the contract or store that would unblock
  the tool — `OrderStoreInterface` plus a status-transition rule for
  `bulk_order_status_update`, a promotion/coupon store for
  `create_discount_campaign`, an order model carrying an invoiced marker for the
  order-invoice tools. A deferral that does not name its prerequisite is a
  hidden decision; these do.
- **1 declined: `lookup_product_price`.** Its mechanism is scraping retailer
  search pages (`amazon.com/s`, `ebay.com/sch`, `target.com/s`) through
  `run_crawl4ai_job` and guessing which result is the product. Retailer search
  HTML is neither stable nor a licensed interface, so the tool would break
  quietly and repeatedly. Declined rather than deferred because no prerequisite
  would make the *mechanism* sound — the crawl and vision primitives remain
  available to a caller that wants to compose its own lookup.

The register is decisions-only, so `toolkit-parity.php --check` is unchanged:
**291 ported, 9 declined, 77 deferred, 554 remaining**. That is the intended
behaviour — a deferral stays inside `remaining`, and only a served slug moves a
tool into `ported`.

#### 2. PHPStan: a baseline and a gate, rather than a comment

§164.112 fixed the findings that were defects and left **216** that were
annotation debt, recommending a baseline plus a CI gate. Both are in:

- **`lib/core/phpstan-baseline.neon`** — 216 findings collapsed into **189
  entries**. PHPStan groups by message and path and records the multiplicity in
  `count:`, so the sum of the counts is 216; nothing was dropped.
- **`lib/core/phpstan.neon`** includes the baseline. It is included from the
  *aliases* file rather than from `.dist`, because `phpstan.neon` is the file the
  local and CI runs actually load.
- **`.github/workflows/phpstan-core.yml`** runs level 8 in `lib/core` on pushes
  and PRs to `main` and `alpha-working`, failing on **new** findings only.
- **`phpstan.neon.dist`'s comment is corrected.** It claimed levels 5-8 were
  clean; level 8 is now *gated*, which is a different and true statement.

The ratchet is the WPCS ratchet already used here: as a file is next touched,
its baseline entries are removed. Without the gate the number re-rots; without
the baseline the gate can never be switched on.

#### 3. The first adapter is the Laravel target

`ProductCatalogInterface` now has a real implementation:
`Nvoos\Laravel\Adapter\ProductCatalog`, bound in `NvoosServiceProvider`. Three
reasons decided it over a WooCommerce adapter:

1. **The product tools are mostly reads**, and the migration (§164.111
   `CommerceMigrator`) runs WooCommerce → Laravel. Laravel is the destination of
   record, so that is where the store has to be queryable first.
2. **A WooCommerce adapter would drag WordPress into a framework-agnostic
   layer.** `WC_Product` and postmeta are exactly what §164.111 exists to keep
   out of `lib/core`.
3. **The Laravel app is the only one deployable and testable here.**

#### What the platform actually runs, verified rather than assumed

Probed through the Cloudways API against server **1686836** on 2026-11-27, and
against Cloudways' documented limits:

| Fact | Consequence for the adapter |
|---|---|
| Services: `mysql` (**MariaDB 10.11**), `php8.2-fpm`, `php8.3-fpm`, `redis`, `rabbitmq`, `varnish`, `nginx`, `apache2`, `memcached`, `imunify360`. **No PostgreSQL.** | MariaDB is the target today; PostgreSQL is a portability constraint, not a current deployment |
| Cloudways PHP servers offer **MySQL 8.4 or MariaDB 10.11 only**; PostgreSQL is a **Velocity (Node)** feature where the vendor creates the database and the app must already support PG | The adapter may not use a PostgreSQL-only type or a MySQL-only function. JSON goes through Eloquent casts and `whereJsonContains`; price ordering is a standard scalar subquery |
| **`character_set_server = latin1`** | Every new table pins `charset=utf8mb4` / `collation=utf8mb4_unicode_ci` explicitly, or a non-ASCII product name is mangled by a server default nobody chose |

#### The schema, and three decisions inside it

- **Money is an integer plus a currency.** `price_minor BIGINT` + `price_currency
  CHAR(3)`, and the same pair for `compare_at_price` and `cost`. §164.111's
  `Money` refuses to be a float because a catalogue migrated through floats
  accumulates drift no reconciliation can undo; storing the minor unit carries
  that decision into the database instead of undoing it at the boundary.
- **`sku` and `external_ref` are 191 characters and unique.** 191 × 4 bytes
  fits MySQL's 767-byte index limit at utf8mb4; a 255-character unique key is
  truncated or refused depending on the row format. Multiple NULLs are allowed,
  which is what makes `external_ref` optional.
- **`description` is nullable, not `default('')`.** MySQL rejects a default on
  `TEXT`; the adapter coalesces null to the empty string on read.
- **Option definitions are JSON on the product; the chosen values are JSON on
  the variant.** They are product-scoped and never queried across products, so a
  normalised option table would add two joins to every read for a value that only
  ever travels with its product.

#### Two defects the adapter had before it ever ran, and one silent one

The adapter was written in the previous session but never executed. The first
execution found:

1. **`Carbon` is not `\DateTimeImmutable`.** Eloquent's `datetime` cast returns
   `Carbon`, which extends `\DateTime`; `Product::createdAt` is typed
   `?\DateTimeImmutable`. Every hydrated product was a `TypeError`, so `find()`
   and `query()` could never have worked. Fixed with
   `\DateTimeImmutable::createFromInterface()`, which preserves the timezone.
2. **The unique `sku` index had no matching guard.** `assertIdentityIsFree()`
   only compared against siblings *within the product*, so a SKU already used by
   another product reached the driver and surfaced as a `QueryException` — while
   the migration docblock promised a validation error naming the SKU. The guard
   now checks catalog-wide and returns `ValidationException` with
   `errors: {sku: [...]}`.
3. **`created_at` is not mass-assignable**, so a migration passing the source's
   creation time had it silently dropped by `fill()` and replaced with "now" —
   losing the only record of when the product was created. It is now assigned as
   a date attribute on insert. On update it is deliberately *not* rewritten:
   creation time is history.

`delete()` also deletes the variants explicitly instead of relying on the
foreign key's `ON DELETE CASCADE`. That cascade is a property of the connection —
SQLite leaves foreign keys off unless the PDO layer enables them — and a
contract that promises "the product and every variant it owns" should not depend
on how a host configured its database.

#### The empty selection is legal

`OptionSelection` now accepts `array()`. Refusing it forced every simple product
— one variant, no options — to invent a fake option, which is a worse lie than an
empty map. `key()` on an empty selection is `''`, and `Product::hasVariants()`
still distinguishes one variant from many. `ProductModelTest` was updated to
assert the new rule rather than the old one.

#### Measurements

| Gate | Result |
|---|---|
| `ProductCatalogTest` | **17 tests, 115 assertions**, 0 failures |
| `lib/core` unit suite | **3,649 tests, 21,400 assertions**, 0 failures |
| Laravel adapter suite | 661 tests, 3,567 assertions, 4 skipped, **1 pre-existing environment error** (`finfo` extension disabled in the local `php.ini`; the test and the extension are both untouched by this work) |
| Readiness manifest | 512 classes / 512 rows, **493 servable**, no violations |
| Toolkit parity | **291 ported / 9 declined / 77 deferred / 554 remaining**; `ecommerce` `44 \| 5 \| 1 \| 27 \| 38` |
| phpcs (`phpcs-laravel.xml.dist`) | **0 errors across 1,909 files** |
| PHPStan level 8, `lib/core` | **no errors** against a 189-entry baseline covering 216 findings |
| Adapter migrations | 2 tables, both pinning utf8mb4 |

#### Next

Slice 2 — Printful, trade.gov tariff rates and the carrier rate shop — or
`shopify_catalog` as its own slice, which still needs a decision about shipping
an MCP client inside `lib/core`. On the catalog side the next useful increment is
`CommerceMigrator` running against this adapter, because that is what proves the
contract against a real WooCommerce export rather than against fixtures.
