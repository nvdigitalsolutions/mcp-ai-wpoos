# Addon Inventory

> **Purpose:** One-stop reference for every addon in this monorepo — its status, version, license, dependencies, and whether it's production-ready.
> **Last Updated:** September 3, 2026

---

> **In-product installer:** Standalone addons whose ZIPs ship in the plugin's `build/`
> directory can be installed and activated in one click from the Pro admin page
> **NV oOS Pro Dashboard → Addons** (`/wp-admin/admin.php?page=wp-mcp-ai-addons`),
> implemented by `WP_MCP_AI_Addons_Page` in
> [`addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php`](../../addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php).
> Non-WordPress components (Media Worker, Cloud Worker, Tenant Router, Schedule Anything SPA)
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
| 1 | **Pro** | `addons/pro/` | (live) | ✅ Production | Proprietary | Base plugin, PHP 8.1+ | 30 toolkits, ~584 tools, commercial license. E-commerce, CRM, document generation, media production, healthcare, legal, scheduling, analytics, OKF knowledge routing (skill bridge, auto-enrichment, hybrid router, SPA skills drawer), artifact evolution (gated Darwinian loop for skills/prompts/roles, v1.1.63), Addons one-click installer page (v1.1.63), Google Calendar connection on the shared `includes/google/` foundation with 6 new google-workspace tools (v1.1.64), Composio account-health engine + `composio_manage_accounts` (v1.1.64). |
| 2 | **Core Plugin** | `core/` | 1.0.0 | ✅ Production | GPL-3.0 | None (standalone) | Separate lightweight MCP server framework. Not a dependency of the main plugin. Provides baseline tools (posts, media, users, taxonomies) via a stable public API. |

### Active Addons

| # | Addon | Directory | Version | Status | License | Requires | Description |
|---|---|---|---|---|---|---|---|
| 3 | **Graphify** | `addons/graphify/` | 0.6.0 | ✅ Production | Proprietary | Base plugin | Knowledge graph builder. Extracts entities and relationships from content, builds navigable graphs, exposes via tools and REST API. Includes WooCommerce, Wikidata, RSS/Sitemap, SPARQL, CSV, and Federation drivers. |
| 4 | **Chat SPA** | `addons/chat-spa/` | 0.7.0 | ✅ Production | GPL-3.0 | Base plugin | React-based chat surface using Vercel AI SDK. Drop-in shortcode + Gutenberg block. Connects to existing NV oOS REST endpoints. |
| 5 | **Docs Hub** | `addons/docs-hub/` | 0.4.2 | ✅ Production | GPL-3.0 | Base plugin | React SPA documentation browser. Discovers and renders Markdown from all installed plugins/addons in a GitBook-style interface. 0.4.2: local-page link resolution, github-slugger-exact anchors, directory-relative "Accept fix", `../` validation + skip reasons, sync-failure surfacing, emoji-loader crash fix. |
| 6 | **Algorave** | `addons/algorave/` | 1.0.7 | ✅ Production | AGPL-3.0 | Base plugin | Live-coding music extension. AI-powered pattern generation, browser-based audio synthesis (Tone.js/Strudel), MIDI export, audio visualization. F-AI-01 accepted with rationale (raw-eval gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` + `edit_posts`; Strudel safe default; warning UI). |
| 7 | **Fantasy Football** | `addons/fantasy-football/` | 0.1.0 | ✅ Production | Proprietary | Base plugin | ESPN and Yahoo Fantasy Sports API integration. Team management, player research, trade analysis, league reports, AI logo generation. |
| 8 | **Embedded** | `addons/embedded/` | 0.2.0 | ✅ Production | Proprietary | Base plugin | Server-side LLM inference (llama.cpp GGUF), client-side browser inference (WebLLM/WebGPU), P2P WebChat rooms (WebRTC). Voice tool calling, OpenMed healthcare tools, and MCP abilities (v0.2.0). |
| 9 | **Canvas** | `addons/canvas/` | 0.1.0 | ✅ Production | Proprietary | Pro addon | Platform-specific Tesseract PDF OCR binaries. Pre-compiled canvas native modules for server-side OCR. |
| 10 | **Cornerstone3D** | `addons/cornerstone3d/` | 0.1.0 | ✅ Production | Proprietary | Pro addon | Pre-built Cornerstone3D ESM bundles for medical imaging (DICOM rendering). Eliminates CDN dependency for the Pro Healthcare Imaging Toolkit. |
| 11 | **SaaS Controller** | `addons/saas-controller/` | 0.1.0 | ✅ Production | Proprietary | Base plugin | Operator toolkit for deploying/managing NV oOS Cloud (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter). One-click wizard, Plan/Apply dashboard, drift detector, audit log. |
| 12 | **Cloudways Dashboard** | `addons/cloudways-dashboard/` | 0.1.0 | ✅ Production | GPL-3.0 | Base plugin | SaaS operator dashboard for managing Cloudways servers, WordPress sites, and NV oOS toolkits. Velzon-themed React SPA. |
| 13 | **Comic Reader** | `addons/comic-reader/` | 0.2.0 | ✅ Production | GPL-3.0 | Base plugin | Comic book reader & creator. Supports CBR/CBZ/CB7/CBT formats with React-based reading interface. AI-powered comic creation tools. |
| 14 | **Funiq Bridge** | `addons/funiq-bridge/` | 1.0.0 | ✅ Production | GPL-3.0 | Base plugin | Payload CMS-to-WordPress bridge for the Funiq React PWA. REST API, CPTs (Product, Promotion, Promocode), taxonomies (Category, Brand, Color, Status), React admin SPA. |
| 15 | **LibreChat** | `addons/librechat/` | 0.1.0 | ✅ Production | GPL-3.0 | Base plugin | Code interpreter (sandboxed Python/JavaScript), speech services (TTS/STT), and web search reranker. SPA build integration. |
| 16 | **Page Agent** | `addons/page-agent/` | 0.1.0 | ⚠️ Experimental | GPL-3.0 | Base plugin | AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Give any WordPress page its own AI agent that can click, type, and navigate via natural language. Client-side only — no headless browser required. Includes shortcode, Elementor widget, REST endpoints, and MCP tool bridge. |
| 17 | **Media Worker** | `addons/media-worker/` | 3.2.0 | ⚠️ Experimental | GPL-3.0 | None (standalone) | Docker-based Node.js sidecar for heavy media processing. 11 Express route handlers: browser (Puppeteer), code, data, document, email, image, ocr (Tesseract), pdf, social, video, workflow — plus native `/api/crawl/*` endpoints (single-URL Markdown, batched crawling, link scans) and a Crawl4AI-compatible facade. **v1.1.65:** env-gated full-Crawl4AI proxy (`POST /api/crawl/full` + `GET /api/crawl/full/task/:id` when `CRAWL4AI_FULL_URL` is set — SSRF-validated targets, token-gated, 503/502 envelopes) and a strict-path `TEMP_ROOT` allowlist (proposal 028 Q5). Queue module with concurrent processing. Multi-tenant shared worker mode (v2.4.0+): `SITE_TOKENS` per-site isolation, per-site rate limits, token rotation. Phase 2: per-site provider keys (`SITE_PROVIDER_KEYS`), usage counters, grouped temp TTLs, k6 load-test kit. Phase 3: opt-in Redis rate-limit store, `PROVIDER_KEYS_FILE` hot-reload, multisite per-blog tokens. Security: timing-safe X-Site-Token auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet headers. Pro integration via settings + client trait (`WP_MEDIA_WORKER_TOKEN` constant supported); worker routing with local fallbacks. |
| 18 | **Schedule Anything** | `addons/schedule-anything-platform/` | 0.1.0 | ⚠️ Experimental | Proprietary | Base plugin | Full SaaS booking platform with Stripe payment integration, calendar management, and multi-tenant architecture. |
| 19 | **Schedule Anything SPA** | `addons/schedule-anything-spa/` | 0.1.0 | ⚠️ Experimental | Proprietary | Schedule Anything Platform | React SPA frontend for the Schedule Anything SaaS booking platform. Vite + Tailwind + React frontend. |
| 20 | **Crocoblock DS** | `addons/crocoblock-ds/` | 0.1.0 | ⚠️ Experimental | GPL-3.0 | None | Design token system for Crocoblock suite. 55+ CSS tokens, admin editor, DTCG export, a11y tokens. |
| 21 | **Fleet Operator** | `addons/fleet-operator/` | 0.1.0 | ✅ Production | GPL-3.0 | Base plugin | External-operator governance (Hermes or any MCP/A2A host). Scoped `op_` operator credentials with audience binding, expiry, rate limits, and instant revocation; server-side MCP `tools/list` scoping + `tools/call` enforcement; admin page, WP-CLI commands, Hermes config generator, 3-skill nvoos pack. |
| 22 | **Checkout API** | `addons/checkout-api/` | 0.1.0 | ✅ Production | Proprietary | None (vendor server only) | Vendor-side checkout service for NV oOS premium addons. Stripe `POST /session` + `POST /verify` endpoints under `/wp-json/nvoos-checkout/v1/` (PaymentIntent creation, server-side verification + license issuance), license store in a custom table (idempotent per payment intent, active/revoked lifecycle), signed expiring HMAC-SHA256 download URLs (capped 10/link) serving cached addon ZIPs, signature-verified idempotent Stripe webhooks (refunds/disputes revoke; `payment_intent.succeeded` issues the license server-side so interrupted checkouts recover), per-IP rate limiting, storefront admin. Stripe secrets encrypted at rest (AES-256-CBC from `AUTH_KEY` + `SECURE_AUTH_KEY`). PHP 8.1+. Runs on the vendor's server only — never distributed to customers or WordPress.org. Client half lives in `plugins/nvoos-content-graph` (paid checkout for Content Graph AI, v1.1.66/PR #6063). |

### Blueprint-Generated SPAs

| # | Addon | Directory | Version | Status | License | Requires | Description |
|---|---|---|---|---|---|---|---|
| 23 | **Canvas Toolkit** | `addons/canvas-toolkit/` | 0.2.0 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Provides a canvas-based surface for the plugin. |
| 24 | **Document Editor** | `addons/document-editor/` | 0.2.0 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Document editing surface. |
| 25 | **Media Studio** | `addons/media-studio/` | 0.1.0 | 🧪 Blueprint | GPL-3.0 | Base plugin | React SPA generated from the Toolkit SPA Blueprint. Media management surface with zoom/pan/drawing tools. |
| 26 | **Toolkit Shell** | `addons/toolkit-shell/` | 0.2.0 | 🧪 Blueprint | GPL-3.0 | Pro addon | Manifest-driven React SPA shell. One bundle drives multiple toolkit SPAs (CRM, calendar, financial, legal, ecommerce, etc.) via per-toolkit JSON manifests. |

### Non-WordPress Components

| # | Component | Directory | Status | Type | Description |
|---|---|---|---|---|---|
| 27 | **Cloud Worker** | `addons/cloud-worker/` | 🗂️ Reference | Cloudflare Worker | SaaS backend for NV oOS Cloud. Inference proxy, Stripe billing, D1 ledger. Deployed independently on Cloudflare — never runs inside WordPress. Shipped in monorepo for review/reference only. |
| 28 | **Tenant Router** | `addons/tenant-router/` | 🗂️ Reference | Cloudflare Worker | Edge-level routing worker for Schedule Anything multi-tenant SaaS. Maps subdomain requests to correct WordPress Multisite tenant via Cloudflare KV with REST API fallback. |

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
