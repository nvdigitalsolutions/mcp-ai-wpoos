# NV oOS Content Graph — Changelog

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
