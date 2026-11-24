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
