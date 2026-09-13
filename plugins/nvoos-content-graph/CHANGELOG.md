# NV oOS Content Graph — Changelog

## 1.0.8 — 2026-09-13

### Fixed — Stripe checkout failed for non-EU buyers

- **Payment Element billing address** — the element was created with
  `fields.billingDetails.address: 'never'`, which makes Stripe require
  `billing_details.address.country` on every `confirmPayment()` call. The
  modal only attaches an address for EU buyers, so every non-EU purchase
  died client-side with `IntegrationError` before the payment was ever
  attempted. The element now uses `'auto'`: Stripe collects the address
  only when a payment method (or Stripe tax) genuinely requires it, and EU
  buyers still pass their full billing address via `payment_method_data`

### Fixed — Already-licensed sites could be charged again

- **Pre-purchase license gate** — `/payments/session` now refuses to create
  a chargeable session when the site is already licensed and the Complete
  bundle (or the legacy AI addon) is active, returning an `already_licensed`
  payload; the purchase modal renders the recorded license (key +
  bundle-aware message) instead of the payment form, so a second charge is
  impossible from this screen
- **Bundle-aware messaging** — the `/payments/verify` short-circuit and the
  success screen now name the artifact actually active (Complete bundle vs
  legacy AI addon) via a `bundle_active` flag and an addon-specific
  checklist line (`success_step_installed_addon` i18n key)
- Tests: `CommerceTest` gains `sessionReturnsAlreadyLicensedWhenBundleActive`
  and `verifyShortCircuitsWhenLicensedAndAddonActive` (both assert zero
  vendor HTTP calls)

## 1.0.7 — 2026-09-12

### Changed — Purchase modal price note

- **Price-subject-to-change note** — the modal's price block now renders "Introductory price — prices are subject to change." under the one-time label (`price_subject_change` i18n key → `nvoos-cg-price-change` in `content-graph-commerce.js`) while the owner settles final pricing. Deliberately phrased without a fake "limited time" claim per the hard rules in `docs/checkout-enhancement-plan.md` §6 (EU UCPD / FTC dark-pattern rules, wp.org guideline 9)

### Fixed — Purchase modal showed a stale price after vendor-side changes

- **Vendor-authoritative price display** — the modal's price block used a hardcoded client-side default (`$49.00`) and never picked up the vendor's configured price, so changing the price in Stripe + the checkout-api left the modal showing the old amount while the Payment Element charged the new one. The modal now syncs its price label from the vendor `/session` response (`amount` + `currency`, formatted via `Intl.NumberFormat` with a plain-USD fallback) as soon as the session is created; the local default remains only as the pre-session/fallback label
- **Price bump** — the client-side fallback default (`Payments::DEFAULT_PRICE_CENTS`, mirrored by the checkout-api's `DEFAULT_PRICE_CENTS` for fresh vendor installs) moves to **$34.99** (3499 cents); `CommerceTest::defaultPriceIs3499Cents` pins the new default
- **Test bootstrap hardening** — `tests/bootstrap.php` now prefers the plugin's own pristine vendored wp-phpunit test lib when `WP_TESTS_DIR` is unset (temp-dir fallback kept). The monorepo root bootstrap patches its vendor copy of `abstract-testcase.php` for PHPUnit 11 and persists the patch on disk; wp-phpunit can copy that patched file into the shared temp-dir lib, which breaks this plugin's PHPUnit 9.6 runs with `Call to undefined method ::name()`. A fresh clone of the plugin is now self-contained for local test runs

### New — Checkout connectivity diagnostics

- **`GET /payments/health` admin route** — pings the vendor's new public `GET /health` endpoint and reports reachability, round-trip latency, and the vendor's own status payload; deliberately **not** throttled so admins can diagnose connectivity without consuming the session/verify buckets (the probe can't trigger the "Too many checkout attempts" lockout)
- **`Vendor::health()`** — thin client for the vendor health endpoint (10s timeout, same error envelopes as the session/verify calls)
- **"Test connection" action in the purchase modal** — when session creation fails with a client error, the modal offers a one-click connectivity probe that renders the result inline (e.g. `Checkout service is reachable — nvoos-checkout v0.1.0 (123 ms)`)
- **Stripe 4xx rejections stay in-modal** — the vendor now maps Stripe 4xx to status 424 with the real message; the modal shows it inline (with the connection probe) and only redirects to the product-page fallback for genuinely unreachable checkout (404, network failure, 5xx). Contract codified in `scripts/verify-commerce-fallback.js` (424 case: no redirect)
- Tests: `tests/Unit/Commerce/CommerceTest.php` extended with six health tests (reachable/unreachable/vendor-error paths, health-probe passthrough, health-not-throttled-when-session-bucket-exhausted, unconfigured-build report)

### Fixed — Payment Element failed to mount (checkout redirect loop)

- **Wrong element name** — the purchase modal called `elements.create( 'paymentElement', … )`, which is not a valid Stripe.js element type (the core JS API name is `payment`; `paymentElement` is the React component). Stripe.js threw an `IntegrationError`, the modal's catch-all mislabelled it as "checkout unavailable", and the browser was redirected to the product-page fallback — so every purchase attempt died right after session creation with a silent redirect. The modal now creates the `payment` element
- **Stripe setup errors stay in-modal** — Stripe element creation/mounting is now wrapped so any setup failure (invalid element name, blocked iframe, extension interference) shows an in-modal error with a reload hint instead of being swallowed by the fetch-rejection catch-all and redirected to the product page

### Changed — Purchase modal form styling

- **Checkout form fields get real styling** — the email input, country selector, EU billing-address fields, Terms consent row, and the Stripe Payment Element mount point were previously unstyled browser defaults ("wireframe" look); they now use bordered WP-admin-style controls with focus rings, labelled-field spacing, a two-column address grid (street full-width, city + postal side by side), a card-style consent block with an accent-coloured checkbox, and a bordered Stripe element container
- **Layout toggle fix** — the EU address/withdrawal rows now toggle to the stylesheet default ('' instead of inline `display: block`) so the CSS grid owns the layout; the truncated duplicate modal-style block at the end of `content-graph-admin.css` (a partial `.nvoos-cg-modal` rule cut off mid-declaration) was removed

### Fixed — Stale asset caches after hotfix updates

- **`Schema::assetVersion()` cache-busting** — every plugin-owned enqueued asset (admin page JS/CSS, commerce modal JS, remote-admin JS, frontend JS/CSS, and the vendored Cytoscape scripts) now uses the file's modification time as the `$ver` enqueue argument instead of `NVOOS_CONTENT_GRAPH_VERSION`. Any file change — including hotfixes deployed without a version bump — produces a new URL, so year-long browser/CDN caches (`Cache-Control: public, max-age=31536000`) can no longer keep serving a broken asset. Falls back to the plugin version when the file is missing

### New — JetEngine Custom Content Type sources selection

- **CCT checkbox grid on the Sources tab** — a new "Custom Content Types (JetEngine)" section (between the post-type and external-table grids) lists every JetEngine CCT registered on the site — including the CCTs the NV oOS base+Pro plugin registers — with include/exclude checkboxes, so CCTs are finally selectable sources instead of silently all-in
- **`excluded_cct_slugs` setting** — every CCT stays indexed by default (unchanged behavior for existing graphs); unchecking a CCT stores its slug in the new setting and the graph `Detector` skips it on the next build. The grid ships a hidden marker field so "uncheck everything" is saved correctly instead of silently keeping the old exclusions; when JetEngine is inactive the saved exclusions are preserved untouched
- **`Detector::getCctTypes()`** — the JetEngine enumeration (previously private to `detectCcts()`) is now a public helper shared by the detector and the admin grid, with the existing `nvoos_content_graph_indexed_cct_slugs` filter still applied last so integrations keep their override
- **JetEngine-inactive degradation** — the section renders an explanatory notice when JetEngine is missing or has no CCTs registered
- Tests: `tests/Unit/Admin/SourcesCctsSectionTest.php` (sanitize contract incl. the hidden-marker semantics + render output) and `tests/Unit/Graph/CctDetectionTest.php` (enumeration, default-include, exclusion honoring, filter override) backed by a new shared JetEngine CCT stub at `tests/helpers/jetengine-cct-stubs.php`

### Fixed — CCTs checked on the Sources tab but missing from the graph

- **Per-CCT status in the Notes column** — a checked CCT could silently vanish from the graph because JetEngine creates CCT tables lazily: a registered type whose table does not exist yet (or has no rows) was skipped without explanation. The Sources grid now annotates every CCT with its live status ("table not created yet", "no items yet", "N items indexed", "Excluded", "unavailable") using a lightweight `Detector::inspectCctTypes()` snapshot that only checks table existence and row counts — it never pulls rows
- **`Detector::detectCcts()` per-type report** — the detector now records every registered CCT's status and item count (`Detector::getCctTypeReport()`), surfaced in the build summary as `cct_types`, and stops querying JetEngine tables that do not exist instead of erroring into a silent skip
- Tests: `CctDetectionTest` extended with per-type report coverage (indexed/empty/table-missing/excluded + `inspectCctTypes()` non-population) and `SourcesCctsSectionTest` with grid-annotation coverage; the shared JetEngine stub learns `is_table_exists()` and `count()`

### New — Checkout consent & legal links

- **Terms of Service consent checkbox in the purchase modal** — the Pay button stays disabled until the buyer ticks agreement to the Terms of Service and the 30-day money-back Refund Policy (links open in a new tab; URLs come from the vendor `/session` response with filterable client-side defaults `nvoos_content_graph/payments/terms_url` / `nvoos_content_graph/payments/refund_policy_url`)
- **Buyer email collection in the purchase modal** — a required email field (prefilled with the logged-in admin's address) gates the Pay button alongside the consent checkbox; the address is attached to the Stripe PaymentIntent via `confirmParams.receipt_email` (so Stripe emails the receipt) and sent as `buyer_email` on `/payments/verify` for refund matching
- **EU billing address (VAT records)** — a country selector in the modal: buyers choosing an EU member state (`nvoos_content_graph/payments/eu_countries` filter) must provide a billing address, attached to the payment as Stripe `billing_details` and sent as `buyer_country` on `/payments/verify` (stored on the vendor license, fill-once); non-EU buyers see no address fields
- **Consent recorded with the license** — the modal sends the consent timestamp as `terms_agreed_at` on `/payments/verify`; the plugin forwards it to the vendor, which stores it on the license row, and keeps it in the local purchase record
- **Manual install is the primary documented path** — the success state always offers the signed ZIP download with upload instructions (Plugins → Add New Plugin → Upload Plugin) alongside the one-click automatic installer; readme/README/contract docs updated to match
- **wp.org review readiness** — readme.txt disclosures now list the exact data sent (buyer email + consent timestamp), a payment-optional FAQ, and a new `WPORG-REVIEW-COMMERCE-NOTES.md` for the plugin review team; `SUBMISSION.md` documents the off-directory commercial model
- **Vendor contract update** — `docs/commerce-vendor-api.md` documents the new session fields, the optional verify params (`terms_agreed_at`, `buyer_email`), and their validation windows
- Legal-document templates for the vendor: `docs/legal/TERMS-OF-SERVICE.md` + `docs/legal/REFUND-POLICY.md` (30-day no-questions-asked guarantee)

### New — Purchase modal trust, value & post-purchase enhancements

- **Price block** — the modal price now sits in a dedicated block with "One-time payment — no subscription", the license scope line (**1 year of updates + email support**, mirroring the updated Terms §7.1), and a VAT note ("VAT may be added at checkout based on your country" — shown until Stripe Tax is enabled vendor-side)
- **Trust list** — a checkmarked row under the price: 30-day money-back guarantee (matching the published Refund Policy), instant download + automatic install, and the existing Stripe security note
- **"Included in NV oOS Complete" block** — a four-item value list (full base + Pro plugin, 1 year of updates, email support directly from the developer, Content Graph ecosystem access when it launches at no extra cost) plus an optional "your purchase funds the roadmap" line with a "Share your ideas" link; the roadmap line renders only when a roadmap URL is configured (`nvoos_content_graph/payments/roadmap_url` filter, default GitHub discussions)
- **EU withdrawal acknowledgment** — when the buyer selects an EU country, the consent row shows the immediate-delivery note ("By downloading, you acknowledge that you lose your EU right of withdrawal for this digital content") as required for the statutory waiver; the 30-day guarantee remains on top of it
- **Success-screen checklist** — after checkout the buyer sees "What happens next" (receipt email, install/activation, license key saved, changelog link with the ecosystem launch reminder) plus a "Questions? Email …" support line (`nvoos_content_graph/payments/support_email` + `nvoos_content_graph/payments/changelog_url` filters)
- **Compliance docs** — `docs/checkout-enhancement-plan.md` captures the researched industry standards (Freemius licensing guidance, Stripe seller duties, EU withdrawal rules, trust-signal and post-purchase research) with sources, the hard-rules list (no fake scarcity/anchors, no release-date promises), and the owner launch checklist
- Tests: `tests/Unit/Commerce/CommerceTest.php` extended for the new filter defaults (roadmap/changelog URLs, support email)

## 1.0.6 — 2026-09-08

### Changed — Checkout delivers the NV oOS Complete bundle

- **Product swap** — the checkout now sells and installs the **NV oOS Complete** bundle (`nvdigital-open-operator-system-oos-complete-{version}.zip` from the monorepo GitHub releases: the full NV oOS plugin, base + Pro, as a separate WordPress plugin) instead of the not-yet-ready companion AI addon. Purchase payload product id: `nvoos-oos-complete` (the vendor checkout addon accepts it alongside the legacy `nvoos-content-graph-ai` id)
- **Conflict guard** — `Installer::install()` now detects any other copy of the NV oOS base plugin on the site (dev folder, wp.org slug, or an active base plugin) and refuses with a clear `nvoos_content_graph_base_plugin_exists` error instead of creating a second copy that would redeclare constants/classes. Idempotent success still recognized for the legacy AI addon (pre-1.0.6 purchases)
- **Copy + disclosures** — upsell buttons, purchase modal, and install messages now say "NV oOS Complete"; the fallback ZIP URL points at the Complete release assets; `readme.txt` Stripe/GitHub disclosure sections updated to describe the Complete bundle purchase; the checkout-unavailable fallback URL now defaults to the public GitHub releases page (filterable)
- Docs: `docs/commerce-vendor-api.md` rewritten with the Complete-bundle flow plus the vendor-side setup (version + ZIP source pattern)

## 1.0.5 — 2026-09-08

### New — Agent Memory Bridge

- **Memory projection into the graph** — `NvoosContentGraph\Memory\Bridge` now implements the agent-memory bridge (previously a stub). It subscribes to the NV oOS base+Pro plugin's canonical `wp_mcp_ai_memory_stored` event (in addition to the ecosystem-native `nvoos_content_graph/memory_stored`) and projects each memory as a `memory:*` node with agent (`OBSERVED_BY`), wing/room (`MEMBER_OF`), and source-post (`DERIVED_FROM`) edges into the graph DB
- **Advisory degradation** — projection is wrapped defensively: malformed payloads, a missing schema, or any bridge failure never break the memory write (the source store remains the source of truth)
- **Graph-ranked retrieval** — new `Bridge::retrieveGraph()` blends agent/wing/room anchor expansion with keyword search (tunable via the shared `wp_mcp_ai_graph_score_weights` filter) and serves the NV oOS `wake_up_context` graph mode through the `wp_mcp_ai_wake_up_context_graph_retriever` filter seam, so sites running NV oOS + Content Graph get graph-ranked memory wake-ups without the bundled Graphify addon
- **Schema probe** — new `Db::tablesInstalled()` (uncached `SHOW TABLES` probe) gates projection/retrieval while the tables are absent
- Tests: `tests/Integration/MemoryBridgeTest.php` (real-DDL projection/edges/retrieval/retriever) + `tests/Unit/Memory/BridgeTest.php` (mocked-`$wpdb` schema-absent degradation); the test bootstrap now ships the PHPUnit 11 compat shim used by the pinned wp-phpunit fork

## 1.0.4 — 2026-09-05

### New — Visual Experience System

- **Appearance settings tab** — theme (dark / light / auto / WordPress-admin), color-by mode (type / community / degree / monochrome), icon style (filled / outline / high-contrast), optional shape encoding, edge styles, edge-label modes, node-size and label-font controls, animation toggle, per-type color and icon override grids, a live WCAG 2.2 contrast report, and one-click style presets (Default / High Contrast / Editorial / Minimal)
- **Theme engine** (`assets/js/content-graph-theme.js` + `src/Visual/Tokens.php`) — single shared token registry drives both the admin explorer and the front-end embed; every curated type color is lightness-corrected per theme so it meets ≥ 3:1 contrast (SC 1.4.11) on both canvases; label and selection colors meet ≥ 4.5:1 / ≥ 3:1
- **Type design system** — inline-SVG stroke icon glyphs (`assets/js/content-graph-icons.js`, 24 glyphs), monogram fallback for unknown types (CPTs, CCTs, remote sources), and a deterministic algorithmic color for uncurated types
- **Explorer chrome** — auto-generated interactive legend (click a row to filter), minimap with click/drag panning, zoom cluster with % badge, layout presets (fcose balanced/compact, circle, grid, concentric, breadth-first), fullscreen toggle, view persistence via localStorage
- **Edge upgrades** — new `GET /edges` REST route; edges render up front with relationship color families (hierarchical / similarity / reference / authorship), arrow / tapered / haystack density presets, hover edge labels, and a 2,000-edge render budget with auto-density above 500
- **Accessibility & performance** — keyboard navigation (arrows / Enter / Escape / + / − / 0), `prefers-reduced-motion` support, zoom-aware label density, texture-on-viewport rendering, pixelRatio 1
- **Export** — theme / transparent / white backgrounds and 1×/2×/3× scale options
- **Frontend parity** — `[nvoos_graph]` and the block gain `theme`, `color_by`, `show_legend`, `show_icons`, `show_edges`, `edge_style`, `min_label_zoom`, `label_font_size` attributes; block attributes left unset (in the block code editor) inherit Appearance settings until changed
- **Filters** — `nvoos_content_graph/type_palette`, `nvoos_content_graph/type_icons`, `nvoos_content_graph/visual_config` (addons can register icons/colors for their own node types)
- Docs: `docs/visual-theming.md`; parity/contrast verification script at `scripts/verify-theme-engine.js`; PHPUnit coverage in `tests/Unit/Visual/TokensTest.php` and `tests/Unit/Admin/AppearanceSectionTest.php`

### New — Checkout for the AI addon

- **Checkout for the AI addon** — the "Get NV oOS Content Graph — AI" upsell buttons now open a Stripe Payment Element modal, verify the payment via the vendor checkout API, record a local license key, and install + activate the addon from a signed download URL in one flow
- **No Stripe keys in the plugin** — PaymentIntent creation, the Stripe secret key, server-side verification, and signed download URLs are delegated to the vendor checkout API (see `docs/commerce-vendor-api.md`); customers simply pay with their card
- New REST endpoints: `POST /payments/session` and `POST /payments/verify` (admin-only, cookie + nonce auth)
- Filters: `nvoos_content_graph/payments/vendor_api_url`, `nvoos_content_graph/payments/price_cents`, `nvoos_content_graph/payments/addon_version`, `nvoos_content_graph/payments/addon_zip_url`, `nvoos_content_graph/payments/fallback_url`
- **Checkout-unavailable fallback** — when the `/payments/session` endpoint is unreachable (network failure, 404, or 5xx), the purchase modal redirects to the vendor product page (`https://nvdigitalsolutions.com/plugins/nvoos-content-graph-ai/` by default, filterable; empty = disabled) instead of dead-ending in an error

### Security

- The browser never sees a secret key; the publishable key is returned per-session by the vendor
- Stripe.js is not enqueued with the settings page — it is injected on demand when the purchase modal opens, so no third-party script loads without an explicit user action
- Verification (status, amount, product, site binding) happens on the vendor's server; the plugin re-checks only the HTTPS scheme of the returned download URL
- Checkout-session creation is throttled per user (max 5 per 10 minutes)
- Install ZIP downloads go through `download_url()` + `Plugin_Upgrader` with the same filesystem checks as wp-admin installs

## 1.0.3 — 2026-08-20

### Security

- Remote-source credentials (API tokens, passwords, secrets) are now encrypted with AES-256-GCM before being stored — the `Crypto::encrypt()` path is wired into `Db::saveRemoteSource()`; read paths decrypt transparently via the new `Crypto::decryptConfig()` helper
- SSRF guard now re-validates every redirect hop; transport-level redirect following is disabled in `HttpClient`
- Config keys are sanitized and unknown drivers are rejected in both the AJAX and REST source-creation paths
- `Crypto::getKey()` falls back to a fixed salt when `AUTH_KEY`/`SECURE_AUTH_KEY` are defined but empty

### Fixes

- Fixed invalid inline JS emitted by the `[nvoos_graph]` shortcode — the frontend embed and Gutenberg block now render
- Wired the "Scheduled Rebuild" setting to WP-Cron (added a "Never" option); the recurring build event is kept in sync on activation, boot, and settings save
- Fixed the admin "Test" button: it tested the first source with an empty config instead of the selected source with decrypted credentials; the REST `/test` endpoint had the same config bug and now uses per-source driver instances
- Fixed `Db::upsertNode()` silently dropping `external_id`, `source_slug`, `confidence`, and `expires_at` on updates
- Remote Sources modal now honors schema field types (password, textarea, checkbox, number, url) instead of rendering everything as text inputs
- Removed the unregistered `GraphExplorer` admin page (the explorer is embedded in the settings page)
- `.distignore` no longer strips the Composer autoloader from distribution builds
- `ResolveExternal` tool defaults to `auto_ingest=false` and requires `manage_options` for auto-ingest, matching the REST endpoint

## 1.0.2 — 2026-08-18

### WordPress.org Review Fixes

- Renamed plugin from "NV oOS Graphify" to "NV oOS Content Graph" (slug `nvoos-content-graph`) across the codebase, text domain, hooks, options, tables, REST namespace, and assets
- Hardened Schema.org JSON-LD output: JSON_HEX_TAG/AMP/APOS/QUOT flags prevent `</script>` breakout
- REST `/resolve`: auto-ingest now requires `manage_options`; read-level users get read-only resolution (default `auto_ingest=false`)
- Removed inline `<script>` from the Embeddings panel; handler moved to the enqueued remote-admin asset
- Removed dead legacy commented-out JS block from `RemoteAdmin`
- Standalone HTML export: hardened inline graph payload with JSON_HEX flags and documented the enqueue exemption
- Added `== External services ==` readme section with Wikidata terms-of-service and privacy-policy links
- Readme: added contributor `vsamtani`, `Tested up to: 6.9`, shortened description to ≤150 characters
- Ported field-map validator into the plugin (`src/Remote/FieldMapValidator.php`) — no longer depends on legacy addon classes
- Embeddings reindex now uses the AI addon's `EmbeddingService` via `CoreBridge`, with cron-batched continuation

## 1.0.0 — 2026-06-05

### Initial Standalone Release

**Core Product:**
- Visual knowledge graph for WordPress — zero API keys required
- One-click graph builder: Detector → StructuralExtractor → DB
- Interactive Cytoscape.js graph explorer (admin + frontend)
- 6 export formats: JSON, GraphML, CSV, Neo4j, Obsidian, HTML
- Schema.org JSON-LD injection for SEO
- Related content widget based on graph proximity

**Architecture:**
- PSR-4: `NvoosContentGraph\` namespace with `spl_autoload_register` fallback
- 5 custom database tables with `dbDelta()` safe migrations
- Singleton composition root (`Plugin.php`) wiring 9 subsystems
- Contract-first: `Tool` and `RemoteSource` interfaces
- Centralized constants: `Schema.php` for all option keys/hooks/table names
- Grouped settings in single `nvoos_content_graph_settings` option

**Graph Engine:**
- Content detection: posts, terms, users, media, JetEngine CCTs
- Structural extraction: LINKS_TO, CATEGORIZED_BY, TAGGED_WITH, AUTHORED_BY, HAS_FEATURED_IMAGE
- Degree recalculation + community detection (Louvain algorithm)
- Content gap analysis: orphans, thin communities, ambiguity rate
- Content recommendations: missing intra-community links

**Tool System:**
- 14 built-in tools: GetNode, QueryGraph, GetNeighbors, BuildGraph, GraphStats, ShortestPath, ContentGaps, GodNodes, SuggestLinks, RetrieveContext, ResolveExternal, ListRemoteSources, SyncRemoteSource, GetCommunity
- Tool interface: 7 methods (getSlug, getName, getDescription, getParametersSchema, getRequiredCapability, getCapabilityFlags, execute)
- Addon registration hook: `nvoos_content_graph/register_tools`
- All tools extend `AbstractTool` with default `edit_posts` capability

**REST API:**
- 14 endpoints at `/wp-json/nvoos-content-graph/v1/`
- Read endpoints: `read` capability (all logged-in users) or valid guest token
- Write endpoints: `manage_options` capability (NEVER `__return_true`)
- Webhook endpoint: HMAC-SHA256 authentication
- Export endpoint: `manage_options` (bulk data is an administrative operation)
- Pagination, filtering, search, export

**Admin:**
- Tabbed settings page: General, Remote, Embeddings, Sources
- Per-tab sanitisation preserves values across tabs
- Graph Explorer submenu with Cytoscape.js visualization
- Remote source management with AJAX handlers

**Frontend:**
- `[nvoos_graph]` shortcode with full/community/ego modes
- `nvoos-content-graph/graph` Gutenberg block
- Schema.org JSON-LD injection on singular views
- Related content widget appended to `the_content`

**Extensibility:**
- `nvoos_content_graph/default_settings` filter for addon settings
- `nvoos_content_graph/indexed_post_types` filter for post type configuration
- `nvoos_content_graph/emit_cct_edges` filter for CCT edge customization
- `nvoos_content_graph/before_build` + `nvoos_content_graph/after_build` actions
- Remote source driver registry (`nvoos_content_graph/register_remote_sources`)

**Requirements:**
- PHP 8.1+
- WordPress 6.5+
- GPL-3.0-or-later
- Zero Composer runtime dependencies
- Zero API keys required for core functionality
