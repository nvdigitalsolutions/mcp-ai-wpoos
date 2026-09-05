# NV oOS Content Graph — Changelog

## 1.0.4 — Unreleased

### New — Visual Experience System

- **Appearance settings tab** — theme (dark / light / auto / WordPress-admin), color-by mode (type / community / degree / monochrome), icon style (filled / outline / high-contrast), optional shape encoding, edge styles, edge-label modes, node-size and label-font controls, animation toggle, per-type color and icon override grids, a live WCAG 2.2 contrast report, and one-click style presets (Default / High Contrast / Editorial / Minimal)
- **Theme engine** (`assets/js/content-graph-theme.js` + `src/Visual/Tokens.php`) — single shared token registry drives both the admin explorer and the front-end embed; every curated type color is lightness-corrected per theme so it meets ≥ 3:1 contrast (SC 1.4.11) on both canvases; label and selection colors meet ≥ 4.5:1 / ≥ 3:1
- **Type design system** — inline-SVG stroke icon glyphs (`assets/js/content-graph-icons.js`, 24 glyphs), monogram fallback for unknown types (CPTs, CCTs, remote sources), and a deterministic algorithmic color for uncurated types
- **Explorer chrome** — auto-generated interactive legend (click a row to filter), minimap with click/drag panning, zoom cluster with % badge, layout presets (fcose balanced/compact, circle, grid, concentric, breadth-first), fullscreen toggle, view persistence via localStorage
- **Edge upgrades** — new `GET /edges` REST route; edges render up front with relationship color families (hierarchical / similarity / reference / authorship), arrow / tapered / haystack density presets, hover edge labels, and a 2,000-edge render budget with auto-density above 500
- **Accessibility & performance** — keyboard navigation (arrows / Enter / Escape / + / − / 0), `prefers-reduced-motion` support, zoom-aware label density, texture-on-viewport rendering, pixelRatio 1
- **Export** — theme / transparent / white backgrounds and 1×/2×/3× scale options
- **Frontend parity** — `[nvoos_graph]` and the block gain `theme`, `color_by`, `show_legend`, `show_icons`, `show_edges`, `edge_style`, `min_label_zoom`, `label_font_size` attributes; block inspector defaults inherit Appearance settings until changed
- **Filters** — `nvoos_content_graph/type_palette`, `nvoos_content_graph/type_icons`, `nvoos_content_graph/visual_config` (addons can register icons/colors for their own node types)
- Docs: `docs/visual-theming.md`; contrast gate verification script at `scripts/verify-contrast.php`; PHPUnit coverage in `tests/Unit/Visual/TokensTest.php` and `tests/Unit/Admin/AppearanceSectionTest.php`

### New — Checkout for the AI addon

- **Checkout for the AI addon** — the "Get NV oOS Content Graph — AI" upsell buttons now open a Stripe Payment Element modal, verify the payment via the vendor checkout API, record a local license key, and install + activate the addon from a signed download URL in one flow
- **No Stripe keys in the plugin** — PaymentIntent creation, the Stripe secret key, server-side verification, and signed download URLs are delegated to the vendor checkout API (see `docs/commerce-vendor-api.md`); customers simply pay with their card
- New REST endpoints: `POST /payments/session` and `POST /payments/verify` (admin-only, cookie + nonce auth)
- Filters: `nvoos_content_graph/payments/vendor_api_url`, `nvoos_content_graph/payments/price_cents`, `nvoos_content_graph/payments/addon_version`, `nvoos_content_graph/payments/addon_zip_url`, `nvoos_content_graph/payments/fallback_url`
- **Checkout-unavailable fallback** — when the `/payments/session` endpoint is unreachable (network failure, 404, or 5xx), the purchase modal redirects to the vendor product page (`https://nvdigitalsolutions.com/plugins/nvoos-content-graph-ai/` by default, filterable; empty = disabled) instead of dead-ending in an error

### Security

- The browser never sees a secret key; the publishable key is returned per-session by the vendor
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
