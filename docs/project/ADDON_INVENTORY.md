# Addon Inventory

> **Purpose:** One-stop reference for every addon in this monorepo — its status, version, license, dependencies, and whether it's production-ready.
> **Last Updated:** October 10, 2026 (v1.2.3 — **no addon version moves in-window**; everything unchanged (Docs Hub 0.5.3, Toolkit Shell 0.3.0, Schedule Anything SPA 0.2.0, MCP Gateway 0.1.1, Media Worker 3.4.0, Fleet Operator 1.0.0, Media Studio 0.6.1, SaaS Controller 0.3.0, Design System 0.3.0, ChatGPT Plugin 0.1.0, Checkout API 0.1.2, nvoos-content-graph 1.0.10 — the sub-projects keep their own tracks, flag-not-edited here; the window's `nvoos-content-graph-ai-platform-v2.0.0.zip` rebuild is build-only)

---

> **In-product installer:** Standalone addons whose ZIPs ship in the plugin's `build/`
> directory can be installed and activated in one click from the Pro admin page
> **NV oOS Pro Dashboard → Addons** (`/wp-admin/admin.php?page=wp-mcp-ai-addons`),
> implemented by `WP_MCP_AI_Addons_Page` in
> [`addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php`](../../addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php).
> Non-WordPress components (Media Worker, MCP Gateway, Cloud Worker, Tenant Router, Schedule Anything SPA)
> are listed read-only on that page.

---

## Status Legend

| Icon | Meaning |
|---|---|
| ✅ | **Production** — actively maintained, tested, used in production |
| ⚠️ | **Experimental** — works but limited testing, may have rough edges |
| 🧪 | **Blueprint-generated** — auto-generated from a template, minimal manual review |
| 🗂️ | **Reference only** — shipped for review convenience, not a deployable addon |
| ❌ | **Deprecated / not functional** |

---

## Addon Index

### Core Addons

| # | Addon | Directory | Version | Status | License | Requires | Description |
|---|---|---|---|---|---|---|---|
| 1 | **Pro** | `addons/pro/` | (live) | ✅ Production | Proprietary | Base plugin, PHP 8.1+ | 31 toolkits, ~584 tools, commercial license. E-commerce, CRM, document generation, media production, healthcare, legal, scheduling, analytics, OKF knowledge routing (skill bridge, auto-enrichment, hybrid router, SPA skills drawer), artifact evolution (gated Darwinian loop for skills/prompts/roles, v1.1.63), Addons one-click installer page (v1.1.63), Google Calendar connection on the shared `includes/google/` foundation with 6 new google-workspace tools (v1.1.64), Composio account-health engine + `composio_manage_accounts` (v1.1.64), Vision Analysis object-counting toolkit (`analyze_image_objects`, v1.1.69). |
| 2 | **Core Plugin** | `core/` | 1.0.0 | ✅ Production | GPL-3.0 | None (standalone) | Separate lightweight MCP server framework. Not a dependency of the main plugin. Provides baseline tools (posts, media, users, taxonomies) via a stable public API. |

### Active Addons

| # | Addon | Directory | Version | Status | License | Requires | Description |
|---|---|---|---|---|---|---|---|
| 3 | **Graphify** | `addons/graphify/` | 0.6.0 | ✅ Production | Proprietary | Base plugin | Knowledge graph builder. Extracts entities and relationships from content, builds navigable graphs, exposes via tools and REST API. Includes WooCommerce, Wikidata, RSS/Sitemap, SPARQL, CSV, and Federation drivers. |
| 4 | **Chat SPA** | `addons/chat-spa/` | 0.7.0 | ✅ Production | GPL-3.0 | Base plugin | React-based chat surface using Vercel AI SDK. Drop-in shortcode + Gutenberg block. Connects to existing NV oOS REST endpoints. |
| 5 | **Docs Hub** | `addons/docs-hub/` | 0.5.3 | ✅ Production | GPL-3.0 | Base plugin | React SPA documentation browser. Discovers and renders Markdown from all installed plugins/addons in a GitBook-style interface. 0.5.3: the rebuild-reliability + pre-submission release (live on the wp.org directory) — `on_settings_changed()`/`maybe_rebuild_after_version_change()` no longer clear the live cache (the pipeline builds into a staging namespace and promotes it atomically, so readers keep serving the previous index during a rebuild), update/activation handlers call `enqueue_async()` directly, a bounded shutdown tick keeps cron-less installs progressing, the checkout config global is read under the correct `NVOOS_DH_CHECKOUT` name, and the full 18-guideline review passed (PCP 0 blocking errors). 0.5.2: the NV oOS Complete checkout upsell — the Content Graph commerce stack ported in (`includes/checkout/` config/vendor client/license store/installer/REST, admin-only `/nvoos-docs/v1/payments/*` with per-user throttling + the already-licensed short-circuit, the `nvoos-dh-*` purchase modal, `== External Services ==` disclosure + commerce review notes; POT 193 → 271 msgids). 0.5.1: uploads content folder moved to the slug-named `wp-content/uploads/nvoos-docs-hub/content/` (runtime-resolved via `wp_upload_dir()`); one-time, non-destructive migration of the legacy `uploads/docs` folder (rename fast-path, never overwrites, never follows symlinks, removes the legacy folder only when empty); settings UI renders the live path. 0.5.0: local-first by default — fresh installs serve docs from the uploads folder with zero remote calls, and the GitHub importer becomes an explicit opt-in service (`enable_remote_repos`, off by default, server-side enforced incl. `/remote/tree` → 403; pre-0.5.0 remote installs get the toggle auto-enabled); `is_path_safe()` symlink-escape hardening (candidate file `realpath()`d before the containment check; regression test included); Plugin Check 0 blocking errors. 0.4.7: third wp.org reviewer pass — invalid `Tested up to:` PHP header removed (readme.txt-only now), daily rebuild cron moved from `plugins_loaded` to `init` (fixes the WP 6.7+ early-translation notice), deactivation clears the cron + pending chunked-rebuild tick events and no longer enqueues a rebuild that can never run, `== Third-Party Libraries ==` disclosure (React MIT, FlexSearch Apache-2.0, lowlight/highlight.js MIT/BSD-3-Clause, unified/remark/rehype MIT/ISC), exact `== External Services ==` trigger list, `Tested up to` major.minor rule; Plugin Check 0 blocking errors. 0.4.6: wp.org re-upload readiness — full 18-guideline pass (bundled GPLv3 `LICENSE`, seven-location version sweep, notice scoping + context-source isolation + token stripping re-audited, packaging tri-sync); Plugin Check 0 blocking errors. 0.4.5: second wp.org reviewer pass — `== External Services ==` readme section documents the documentation-import service (`raw.githubusercontent.com` / `api.github.com`, server-side, host-allowlisted, admin-triggered, no account required); `uninstall.php` checks `is_link()` on the top-level cache dir before recursing + same-category guards in `Cache::get_live_dir()`/`rm_rf()`; 2 new regression tests. 0.4.4: wp.org review-findings release — contributors list, Source Code readme section + self-describing source banner in the built JS, `/search` context-source filtering behind `manage_options`, `load_plugin_textdomain()` removed, admin-notice scoping, staging-transient isolation, symlink-safe recursive deletion (resolved-path containment), sitemap context-slug leak fix, stale page-transient invalidation; official Plugin Check 0 blocking errors. 0.4.3: wp.org submission prep — External Services readme section, settings-page scripts moved to a static asset (no inline `<script>`), text-domain fix, dev-file packaging excludes, CI plugin-check gate, translation template (.pot). 0.4.2: local-page link resolution, github-slugger-exact anchors, directory-relative "Accept fix", `../` validation + skip reasons, sync-failure surfacing, emoji-loader crash fix. |
| 6 | **Algorave** | `addons/algorave/` | 1.0.7 | ✅ Production | AGPL-3.0 | Base plugin | Live-coding music extension. AI-powered pattern generation, browser-based audio synthesis (Tone.js/Strudel), MIDI export, audio visualization. F-AI-01 accepted with rationale (raw-eval gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` + `edit_posts`; Strudel safe default; warning UI). |
| 7 | **Fantasy Football** | `addons/fantasy-football/` | 0.1.0 | ✅ Production | Proprietary | Base plugin | ESPN and Yahoo Fantasy Sports API integration. Team management, player research, trade analysis, league reports, AI logo generation. |
| 8 | **Embedded** | `addons/embedded/` | 0.2.0 | ✅ Production | Proprietary | Base plugin | Server-side LLM inference (llama.cpp GGUF), client-side browser inference (WebLLM/WebGPU), P2P WebChat rooms (WebRTC). Voice tool calling, OpenMed healthcare tools, and MCP abilities (v0.2.0). |
| 9 | **Canvas** | `addons/canvas/` | 0.1.0 | ✅ Production | Proprietary | Pro addon | Platform-specific Tesseract PDF OCR binaries. Pre-compiled canvas native modules for server-side OCR. |
| 10 | **Cornerstone3D** | `addons/cornerstone3d/` | 0.1.0 | ✅ Production | Proprietary | Pro addon | Pre-built Cornerstone3D ESM bundles for medical imaging (DICOM rendering). Eliminates CDN dependency for the Pro Healthcare Imaging Toolkit. |
| 11 | **SaaS Controller** | `addons/saas-controller/` | 0.3.0 | ✅ Production | Proprietary | Base plugin | Operator toolkit for deploying/managing NV oOS Cloud (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter). One-click wizard, Plan/Apply dashboard, drift detector, audit log. Ships the production NV oOS Cloud Worker bundle. 0.3.0: Phase 12 folds Worker secrets + D1 schema apply into the Plan/Apply flow (no more `wrangler secret put` / `wrangler d1 execute`); adds `saas_api_key` credential and `worker_secret` / `d1_schema` plan rows with value-free planning. |
| 12 | **Cloudways Dashboard** | `addons/cloudways-dashboard/` | 0.1.0 | ✅ Production | GPL-3.0 | Base plugin | SaaS operator dashboard for managing Cloudways servers, WordPress sites, and NV oOS toolkits. Velzon-themed React SPA. |
| 13 | **Comic Reader** | `addons/comic-reader/` | 0.5.0 | ✅ Production | GPL-3.0 | Base plugin | Comic book reader & creator. Supports CBR/CBZ/CB7/CBT formats with React-based reading interface. AI-powered comic creation tools. 0.5.0: Komga-parity industry upgrade (0.2.0 → 0.5.0) — archive magic-byte validation + size cap + owner-scoped delete + HTTP Range serving + CBZ cover extraction; persisted reader settings, vertical scroll/webtoon modes, scale types, double-page rules, gestures, continue-reading shelf; ComicInfo.xml RTL detection, series/collection taxonomies, per-user progress; settings page + capability overrides. |
| 14 | **Funiq Bridge** | `addons/funiq-bridge/` | 1.0.0 | ✅ Production | GPL-3.0 | Base plugin | Payload CMS-to-WordPress bridge for the Funiq React PWA. REST API, CPTs (Product, Promotion, Promocode), taxonomies (Category, Brand, Color, Status), React admin SPA. |
| 15 | **LibreChat** | `addons/librechat/` | 0.1.0 | ✅ Production | GPL-3.0 | Base plugin | Code interpreter (sandboxed Python/JavaScript), speech services (TTS/STT), and web search reranker. SPA build integration. |
| 16 | **Page Agent** | `addons/page-agent/` | 0.1.0 | ⚠️ Experimental | GPL-3.0 | Base plugin | AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Give any WordPress page its own AI agent that can click, type, and navigate via natural language. Client-side only — no headless browser required. Includes shortcode, Elementor widget, REST endpoints, and MCP tool bridge. |
| 17 | **Media Worker** | `addons/media-worker/` | 3.4.0 | ⚠️ Experimental | GPL-3.0 | None (standalone) | Docker-based Node.js sidecar for heavy media processing. 11 Express route handlers: browser (Puppeteer), code, data, document, email, image, ocr (Tesseract), pdf, social, video, workflow — plus native `/api/crawl/*` endpoints (single-URL Markdown, batched crawling, link scans) and a Crawl4AI-compatible facade. **v1.1.65:** env-gated full-Crawl4AI proxy (`POST /api/crawl/full` + `GET /api/crawl/full/task/:id` when `CRAWL4AI_FULL_URL` is set — SSRF-validated targets, token-gated, 503/502 envelopes) and a strict-path `TEMP_ROOT` allowlist (proposal 028 Q5). **v1.1.93:** the fleet status/monitoring module (opt-in `STATUS_ENABLED=1`) — `POST /api/status/heartbeat`, `GET /api/status/summary|sites/:slug|history/:slug|metrics`, OpenMetrics, a public allowlisted status page, heartbeat validation, a `at_risk` → `major_outage` state machine, dead man's switch sweeper, synthetic HTTP/TLS checks, and HMAC-signed webhook + email alerts. **v1.1.95:** the real image-processing routes (issue #6877) — `POST /api/image/enhance` + `POST /api/image/upscale` (Wave 1, Sharp-native: factor 2/4/8 validated, lanczos3, 8192px cap, honest `upscale_method`) and `POST /api/image/edit` (Wave 2: `colorize` | `style_transfer`, 9-preset `STYLE_PROMPTS`, provider auto-resolution Gemini → OpenAI → Replicate, `503 capability_unavailable` without keys; `REPLICATE_COLORIZE_MODEL` default deoldify, `REPLICATE_STYLE_TRANSFER_MODEL` required) — the four placeholder image tools (`enhance_image_quality`, `upscale_image_ai`, `colorize_image`, `apply_artistic_style`) now run real processing through the sidecar; Wave 3 (Real-ESRGAN) deferred. **v1.1.99:** synthetic external targets — `STATUS_EXTERNAL_TARGETS=gateway=https://mcp.nvoos.pro/health` seeds heartbeat-less targets (idempotent), `startSyntheticLoop()` probes the enabled ones and writes only `record.synthetic` (the sweeper stays the single owner of status transitions; synthetic-only mode reports `operational`/`major_outage`/`unknown`), `STATUS_<SLUG>_SYNTHETIC=1` + `STATUS_<SLUG>_SYNTHETIC_URL` per target, the split-brain fallback now reads `record.synthetic.checkedAt` (the old `.checked` key was set by no code), and the sweeper clears `downSince` on recovery. Queue module with concurrent processing. Multi-tenant shared worker mode (v2.4.0+): `SITE_TOKENS` per-site isolation, per-site rate limits, token rotation. Phase 2: per-site provider keys (`SITE_PROVIDER_KEYS`), usage counters, grouped temp TTLs, k6 load-test kit. Phase 3: opt-in Redis rate-limit store, `PROVIDER_KEYS_FILE` hot-reload, multisite per-blog tokens. Security: timing-safe X-Site-Token auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet headers. Pro integration via settings + client trait (`WP_MEDIA_WORKER_TOKEN` constant supported); worker routing with local fallbacks. |
| 18 | **Schedule Anything** | `addons/schedule-anything-platform/` | 0.1.0 | ⚠️ Experimental | Proprietary | Base plugin | Full SaaS booking platform with Stripe payment integration, calendar management, and multi-tenant architecture. |
| 19 | **Schedule Anything SPA** | `addons/schedule-anything-spa/` | 0.2.0 | ⚠️ Experimental | Proprietary | Schedule Anything Platform | React SPA frontend for the Schedule Anything SaaS booking platform. **v1.1.99 (0.2.0, Proposal 060):** Tailwind v3 → v4 (CSS-first, `@theme inline` NDS token aliases), a 16-primitive shadcn-style UI kit (Radix + CVA + tailwind-merge + sonner), @xyflow/react v12, route-level code splitting (Builder + Analytics on demand), `@wordpress/i18n` bootstrap, and axe + jsx-a11y lint gates; 23/23 shipped vitest suites. |
| 20 | **Design System** | `addons/nvoos-design-system/` | 0.3.0 | ⚠️ Experimental | GPL-3.0 | None | Design token system with DTCG export, accessibility tokens, and a token-driven email template module (previously "Crocoblock DS" at `addons/crocoblock-ds/`). ~70 tokens across 8 groups incl. email, `@property` output, a11y media queries, presets, JetEngine/JetSmartFilters/JetFormBuilder/Elementor integrations, 5 built-in accessible email templates + global `wp_mail` wrapper (sentinel double-wrap guard, full-document and text/plain skips), WCAG/EMC audit gates, and 8 AI email-template tools (`nds_generate_email_template`, audit, preview, test-send, export/import). v0.3.0 is the rename + email-module release — legacy `--cds-*`/`.cds-*` aliases, option migration, and class aliases keep existing builds working. |
| 21 | **Fleet Operator** | `addons/fleet-operator/` | 1.0.0 | ✅ Production | GPL-3.0 | Base plugin | External-operator governance (Hermes or any MCP/A2A host). Scoped `op_` operator credentials with audience binding, expiry, rate limits, and instant revocation; server-side MCP `tools/list` scoping + `tools/call` enforcement; admin page, WP-CLI commands, Hermes YAML + Zed/VS Code/Claude Desktop config generators (`generate_zed_json()`/`generate_claude_json()` through the npx bridge package, v1.1.96), 3-skill nvoos pack. **v1.1.99:** the External Operators page masks freshly created tokens (readonly `type="password"` input with Show/Hide + Copy, credential blocks collapsed behind `<details>` — no more plaintext tokens; the console site needs the updated build deployed). |
| 22 | **Checkout API** | `addons/checkout-api/` | 0.1.2 | ✅ Production | Proprietary | None (vendor server only) | Vendor-side checkout service for NV oOS premium addons. 0.1.2: buyer license emails — `NVOOS_Checkout_API_Mailer::maybe_send()` emails the license key/product/site/amount once per license from both the webhook and `/verify` paths, guarded by a new `email_sent_at` column (license table v4 → v5); storefront settings (enable/subject/From name/address) + an "Emailed" license-table column. Also: `/session` ships `statement_descriptor_suffix` (2–22 chars, ≥1 letter) — Stripe rejects the full `statement_descriptor` with `automatic_payment_methods` (424 on every live session) — and `ensure_product_and_price()` verifies stored Stripe IDs against the current key so the create-product action survives an account switch. Stripe `POST /session` + `POST /verify` endpoints under `/wp-json/nvoos-checkout/v1/` (PaymentIntent creation, server-side verification + license issuance), license store in a custom table (idempotent per payment intent, active/revoked lifecycle), signed expiring HMAC-SHA256 download URLs (capped 10/link) serving cached addon ZIPs, signature-verified idempotent Stripe webhooks (refunds/disputes revoke; `payment_intent.succeeded` issues the license server-side so interrupted checkouts recover), per-IP rate limiting, storefront admin. Stripe secrets encrypted at rest (AES-256-CBC from `AUTH_KEY` + `SECURE_AUTH_KEY`). PHP 8.1+. Runs on the vendor's server only — never distributed to customers or WordPress.org. Client half lives in `plugins/nvoos-content-graph` (paid checkout for Content Graph AI, v1.1.66/PR #6063). |

### Blueprint-Generated SPAs

| # | Addon | Directory | Version | Status | License | Requires | Description |
|---|---|---|---|---|---|---|---|
| 23 | **Canvas Toolkit** | `addons/canvas-toolkit/` | 0.2.0 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Provides a canvas-based surface for the plugin. |
| 24 | **Document Editor** | `addons/document-editor/` | 0.2.0 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Document editing surface. |
| 25 | **Media Studio** | `addons/media-studio/` | 0.6.1 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Media management surface with zoom/pan/drawing tools, plus the AI fashion production suite (`fashion-studio` mode: transforms, video, provenance, marketplace pipeline). |
| 26 | **Toolkit Shell** | `addons/toolkit-shell/` | 0.3.0 | 🧪 Blueprint | GPL-3.0 | Pro addon | Manifest-driven React SPA shell. One bundle drives multiple toolkit SPAs (CRM, calendar, financial, legal, ecommerce, etc.) via per-toolkit JSON manifests. **v1.1.99 (0.3.0, Proposal 060):** the headless UI stack — Radix Dialog/ConfirmDialog/DropdownMenu/Select/Tabs/Checkbox + CVA Button primitives, a TanStack TableView (sortable, ConfirmDialog deletes), a @dnd-kit KanbanView (persisted reorder + cross-column moves), a react-hook-form + zod FormView (runtime schema, per-field alerts), sonner toasts, 14 `--nds-*` design tokens with wp-admin fallbacks, and an ESLint dual-React guard; 31/31 shipped vitest suites. |

### Non-WordPress Components

| # | Component | Directory | Status | Type | Description |
|---|---|---|---|---|---|
| 27 | **Cloud Worker** | `addons/cloud-worker/` | 🗂️ Reference | Cloudflare Worker | SaaS backend for NV oOS Cloud. Inference proxy, Stripe billing, D1 ledger. Deployed independently on Cloudflare — never runs inside WordPress. Shipped in monorepo for review/reference only. |
| 28 | **Tenant Router** | `addons/tenant-router/` | 🗂️ Reference | Cloudflare Worker | Edge-level routing worker for Schedule Anything multi-tenant SaaS. Maps subdomain requests to correct WordPress Multisite tenant via Cloudflare KV with REST API fallback. |
| 29 | **mcp-wordpress Gateway** | `addons/mcp-wordpress-gateway/` | 0.1.0 | ✅ Production | Proprietary (pinned MIT upstream) | None (Node 22, Cloudways Velocity) | Auth-gated Streamable HTTP MCP server on pinned docdyhr/mcp-wordpress internals — no stdio/child processes. Express `/healthz` + `/mcp` with timing-safe `X-MCP-Token` auth (≥32 chars, rotation overlap) and a 1 MB body cap; `MCP_TOOLS_ALLOW`/`MCP_TOOLS_DENY` policy at tool registration (deny wins). Deploys via the media-worker subtree-split pattern to the `nvdigitalsolutions/nvoos-mcp-wordpress` mirror; connects to NV oOS assistants through the Remote Sites → MCP Server connection type. Follow-ups documented: per-IP rate limits, multi-site config, deploy-secret setup. |
| 30 | **ChatGPT Plugin** | `addons/chatgpt-plugin/` | 0.1.0 | ⚠️ Experimental | GPL-3.0 | None (portable agent plugin package) | ChatGPT / Codex plugin package connecting OpenAI agent surfaces to the site's native MCP bridge (`POST /wp-json/mcp-ai/v1/mcp`). Portable `plugin.json` + `mcp.json` (Agent Plugins schema) plus `.codex-plugin` compatibility overlay; three skills (`site-operations`, `content-studio`, `commerce-desk`); local/Git-backed marketplace entry; dependency-free `bin/` scripts (stamp-site, package-plugin, validate) and in-addon CI. Syncs via subtree split (`sync-chatgpt-plugin.yml`) to the `nvdigitalsolutions/nvoos-chatgpt-plugin` mirror — never commit there directly. Site-side companion contract (RFC 9728 `/.well-known/oauth-protected-resource`, `WWW-Authenticate` challenges, per-tool `securitySchemes`, `nvoos_get_profile`, `/.well-known/openai-apps-challenge`) ships in base `includes/mcp/`. See `docs/project/proposals/053-chatgpt-plugin-addon-*.md`. |
| 31 | **MCP Gateway** | `addons/mcp-gateway/` | 0.1.1 | ⚠️ Experimental | GPL-3.0 | None (standalone Express service) | Public fleet MCP endpoint (Proposal 055) — a media-worker-style Express service speaking stateless streamable HTTP (MCP 2026-07-28) at the planned `mcp.nvoos.pro`. Public API-key auth with rotation, per-key rate limits, `<site-slug>.<tool>` tool namespacing against the per-site MCP bridges, graceful per-site degradation, fail-closed env config (missing config refuses to start). v1.1.97: `initialize` negotiates the protocol version (`negotiateProtocolVersion()`, `2024-11-05` fallback) so strict clients (Zed) stop aborting with "Unsupported protocol version" (#6914); post-window #6925 bumps **0.1.0 → 0.1.1** and rewrites `sync-mcp-gateway.yml` to snapshot one commit per sync and push **without `--force`** (force-pushed refs had left Cloudways Velocity on stale commits — the mirror main stays a linear fast-forward line so auto-deploys fire on every sync; never commit to the standalone repo directly). v1.1.98: #6927 adds a `workflow_dispatch` trigger (manual syncs always possible) + a **version guard** (the sync skips when the incoming `package.json` is older than the mirror tip's — out-of-order runs can never roll the public mirror backwards) and `addons/mcp-gateway/CHANGELOG.md` now tracks 0.1.0 → 0.1.1. **v1.1.99:** the gateway gains an **OAuth 2.1 resource server (Phase 1, #6945)** — RFC 9728 metadata (`GET /.well-known/oauth-protected-resource` + `/mcp` variant), 401 `WWW-Authenticate` challenges with `resource_metadata`/scopes, zero-dependency JWT validation (node:crypto RS/ES, JWKS cache + rotation refetch, strict iss/aud/exp/nbf/scope, fail-closed), scope-based site binding (`site:<slug>`), and **no token passthrough** (the gateway keeps exchanging for per-site Fleet Operator tokens); inert until `GATEWAY_OAUTH_ISSUER`; Phases 2–5 (Auth0 provisioning, PKCE e2e, registry submission, deployment guide) deferred — the gateway changelog tracks them under **Unreleased**; the **listing prep + Claude Code plugin** (#6941–#6943) add the GPL LICENSE + `license` field + `docs/operations/deployment/mcp-gateway-directory-submission.md`, the `mcp-gateway.svg` icon (+ `/assets` static serving), and the `.claude-plugin` manifest + `/connect` command (mirror sync = marketplace source); the media worker's synthetic external targets probe `mcp.nvoos.pro/health` in-fleet (#6941). 39/39 node:test suites, `npm audit` 0; mirror at `nvdigitalsolutions/nvoos-mcp-gateway`; Velocity deployment guide in `docs/operations/deployment/mcp-gateway-velocity-setup.md` (deployment + DNS/TLS + directory submissions deferred). |

---

## Security Audit Posture (April 2026)

| Addon | Critical | High | Medium | Low | Verdict |
|---|---:|---:|---:|---:|---|
| Base plugin | 0 | 0 | 6 | 12 | ✅ Solid baseline |
| Pro addon | 0 | 3* | 7 | 6 | ⚠️ 3 Highs — all now Fixed or Partially Fixed |
| Algorave | 0 | 1* | 1 | 1 | ✅ F-AI-01 accepted with rationale (v1.1.64) |
| Canvas | 0 | 0 | 2 | 1 | OK |
| Cornerstone3D | 0 | 1* | 1 | 0 | ⚠️ HIPAA posture addressed (F-PRIV-03) |
| Embedded | 0 | 0 | 2 | 1 | OK |
| Fantasy Football | 0 | 0 | 2 | 2 | OK |
| Graphify | 0 | 1* | 2 | 1 | ⚠️ SQL preparation fixed (F-SQL-01) |

\* All High findings are now Fixed or Partially Fixed. See [SECURITY_POSTURE.md](SECURITY_POSTURE.md) for current status.

---

## PHP Version Requirements

| Component | Minimum PHP | Notes |
|---|---|---|
| Base plugin | 7.4 | Enforced at plugin load via `version_compare()` |
| Pro addon | 8.1+ | Required by npm packages (`sharp`, `fluent-ffmpeg`) |
| Core plugin | 7.4 | Standalone, no shared code with main plugin |
| All other addons | 7.4 | Except where noted in individual READMEs |

---

## Review Priority (Limited Budget)

If you're auditing this repo on a limited budget, use this prioritization:

### Tier 1 — Must review
1. Base plugin (`mcp-ai-wpoos.php` + `includes/`)
2. Pro addon (`addons/pro/`)

### Tier 2 — Should review
3. Graphify (`addons/graphify/`)
4. Chat SPA (`addons/chat-spa/`)
5. Algorave (`addons/algorave/`)

### Tier 3 — Nice to review
6. Docs Hub, Embedded, Canvas, Cornerstone3D, Fantasy Football, SaaS Controller, Cloudways Dashboard, Comic Reader, Page Agent

### Tier 4 — Skip
7. Blueprint-generated SPAs (Canvas Toolkit, Document Editor, Media Studio, Toolkit Shell)
8. Cloud Worker, AI Platform, Tenant Router (not WordPress plugins)
9. Core plugin (separate product, v1.0.0)

---

**Related documents:** [FOR_REVIEWERS.md](FOR_REVIEWERS.md) · [SECURITY_POSTURE.md](../operations/security/SECURITY_POSTURE.md) · [TRACEABILITY.md](../operations/compliance/TRACEABILITY.md)
