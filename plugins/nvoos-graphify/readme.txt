=== NV oOS Graphify ===

Contributors: nvdigitalsolutions
Tags: knowledge graph, content visualization, cytoscape, content strategy, semantic web
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Map your WordPress content into an interactive knowledge graph. See relationships between posts, terms, and authors. Discover content gaps. Zero API keys required.

== Description ==

NV oOS Graphify transforms your WordPress content into a visual knowledge graph — organized, searchable, and interactive. Every post, page, category, tag, and author becomes a node; every relationship becomes an edge.

**No API keys. No external services. No AI required.** The core graph engine runs entirely on your WordPress server using your existing content.



### What You Get

= One-Click Graph Builder =
Click "Build Graph" and ~10-30 seconds later, your entire site becomes a visual graph. Supports incremental rebuilds and scheduled cron updates.

= Interactive Graph Explorer =
Explore your content visually using Cytoscape.js. Search for nodes by label, click for details, zoom and pan. Color-coded by content type (posts, pages, terms, users).

= Content Gap Analysis =
Discover orphan content (no internal links), thin topic clusters, and missing link opportunities. Generate actionable content strategy recommendations.

= Six Export Formats =
Download your graph as JSON (NetworkX), GraphML (Gephi/yEd), CSV, Neo4j Cypher, Obsidian vault, or standalone HTML page.

= Schema.org JSON-LD =
Automatic structured data injection for SEO — taxonomy terms as `about` and internal links as `relatedLink`.

= Related Content Widget =
Appends graph-neighbor posts to your content based on knowledge graph proximity.

= REST API =
Full programmatic access with 14 endpoints. Read endpoints require the `read` capability (all logged-in users) or a valid guest token; write endpoints require `manage_options`.

= Extensible Tool System =
14 built-in tools for graph operations: query, search, traverse, analyze, export. Addon plugins can register their own tools.

### Addon Ecosystem (all optional)

- **nvoos-graphify-ai-chat** — AI chat assistant with OpenAI, Gemini, Ollama
- **nvoos-graphify-ai-tools** — AI content generation, image creation, SEO analysis
- **nvoos-graphify-embeddings** — Vector embeddings, semantic search, RAG
- **nvoos-graphify-pro** — Enterprise SaaS drivers (Jira, Slack, M365, etc.)

== Installation ==

1. Upload the `nvoos-graphify` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to **Knowledge Graph → Settings**
4. Click "Build Graph"

That's it. Your content is now a knowledge graph.

== Frequently Asked Questions ==

= Do I need an API key? =

No. The core graph engine works with zero external dependencies. AI features require their respective addon plugins and API keys.

= How long does a build take? =

~10-30 seconds for a typical WordPress site (100-500 posts). Large sites (10,000+ posts) may take 1-2 minutes.

= Can I build incrementally? =

Yes. Enable "Incremental Builds" in settings to only process content changed since the last build.

= Is this compatible with my theme? =

Yes. The graph explorer, shortcode, related content widget, and schema.org injection work with any theme. The shortcode `[nvoos_graph]` embeds wherever you place it.

= Does it work with custom post types? =

Yes. Any public post type (including JetEngine CPTs) is automatically detected. You can configure which post types to index in Settings.

= Does the plugin connect to external services? =

The core graph engine runs entirely on your server. The optional "Resolve External Entity" tool queries Wikidata (wikidata.org) when you explicitly ask it to look up a QID. Remote source drivers connect to user-configured endpoints (REST APIs, RSS feeds, SPARQL, etc.) only when you set them up in Settings. No data is ever sent off-site without your explicit configuration.

= Does it work on multisite? =

Yes. The plugin supports WordPress multisite with per-site configuration.

= What happens if I deactivate? =

Cron schedules are cleared. Your data stays intact — reactivate at any time.

= What happens if I uninstall? =

All custom tables and options are removed (see `uninstall.php`). Export your graph first if you want to keep it.

== Screenshots ==

1. Graph Explorer — interactive Cytoscape.js visualization with search and node details
2. Settings Page — configure post types, build schedule, display options
3. Content Gaps Report — identify orphan nodes, thin communities, missing links
4. Export Formats — JSON, GraphML, CSV, Neo4j, Obsidian
5. Remote Sources — connect external data (Wikidata, REST APIs, RSS)

== Changelog ==

= 1.0.0 — 2026-06-05 =
* Initial standalone release
* PSR-4 architecture: `NvoosGraphify\` namespace
* 5 custom database tables with dbDelta() migrations
* 14 built-in tools implementing `NvoosGraphify\Contracts\Tool`
* 14 REST API endpoints at `/wp-json/nvoos-graphify/v1/`
* Cytoscape.js graph explorer (admin + frontend)
* Tabbed settings page with per-tab sanitisation
* 6 export formats (JSON, GraphML, CSV, Neo4j, Obsidian, HTML)
* Schema.org JSON-LD injection for SEO
* Related content widget based on graph proximity
* Louvain community detection + content gap analysis
* `[nvoos_graph]` shortcode + Gutenberg block
* JetEngine Custom Content Type (CCT) support
* WordPress multisite compatible
* PHP 8.1+, WordPress 6.5+, GPL-3.0-or-later
