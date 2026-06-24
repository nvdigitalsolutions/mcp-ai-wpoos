# NV oOS Graphify — Changelog

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
- PSR-4: `NvoosGraphify\` namespace with `spl_autoload_register` fallback
- 5 custom database tables with `dbDelta()` safe migrations
- Singleton composition root (`Plugin.php`) wiring 9 subsystems
- Contract-first: `Tool` and `RemoteSource` interfaces
- Centralized constants: `Schema.php` for all option keys/hooks/table names
- Grouped settings in single `nvoos_graphify_settings` option

**Graph Engine:**
- Content detection: posts, terms, users, media, JetEngine CCTs
- Structural extraction: LINKS_TO, CATEGORIZED_BY, TAGGED_WITH, AUTHORED_BY, HAS_FEATURED_IMAGE
- Degree recalculation + community detection (Louvain algorithm)
- Content gap analysis: orphans, thin communities, ambiguity rate
- Content recommendations: missing intra-community links

**Tool System:**
- 14 built-in tools: GetNode, QueryGraph, GetNeighbors, BuildGraph, GraphStats, ShortestPath, ContentGaps, GodNodes, SuggestLinks, RetrieveContext, ResolveExternal, ListRemoteSources, SyncRemoteSource, GetCommunity
- Tool interface: 7 methods (getSlug, getName, getDescription, getParametersSchema, getRequiredCapability, getCapabilityFlags, execute)
- Addon registration hook: `nvoos_graphify/register_tools`
- All tools extend `AbstractTool` with default `edit_posts` capability

**REST API:**
- 14 endpoints at `/wp-json/nvoos-graphify/v1/`
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
- `nvoos-graphify/graph` Gutenberg block
- Schema.org JSON-LD injection on singular views
- Related content widget appended to `the_content`

**Extensibility:**
- `nvoos_graphify/default_settings` filter for addon settings
- `nvoos_graphify/indexed_post_types` filter for post type configuration
- `nvoos_graphify/emit_cct_edges` filter for CCT edge customization
- `nvoos_graphify/before_build` + `nvoos_graphify/after_build` actions
- Remote source driver registry (`nvoos_graphify/register_remote_sources`)

**Requirements:**
- PHP 8.1+
- WordPress 6.5+
- GPL-3.0-or-later
- Zero Composer runtime dependencies
- Zero API keys required for core functionality
