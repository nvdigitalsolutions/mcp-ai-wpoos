# Nvoos Graphify — Core Buildout Plan

> **Branch**: `plan/nvoos-graphify-core-buildout`  
> **References**: [`graphify-core-implementation-spec.md`](../../docs/project/proposals/graphify-core-implementation-spec.md), [`nvoos-base-restructuring-roadmap.md`](../../docs/project/proposals/nvoos-base-restructuring-roadmap.md)  
> **Target**: Produce `plugins/nvoos-graphify/` as a standalone, wp.org-ready WordPress plugin

---

## 1. Overview

**Goal**: Transform the existing `addons/graphify/` addon (~60 PHP files, ~8,000 lines) into a standalone PSR-4 plugin at `plugins/nvoos-graphify/` that works with zero API keys and is immediately useful upon activation.

**Current state**: `plugins/nvoos-graphify/` is scaffolded with bootstrap, `composer.json`, `uninstall.php`, and empty `src/` directories. No implementation code yet.

**Approach**: Port the existing `addons/graphify/` classes one-by-one into the new PSR-4 structure, modernizing namespace, hook prefixes, and removing base-plugin dependencies as we go.

---

## 2. Directory Mapping

### Source → Target

| Source (`addons/graphify/`) | Target (`plugins/nvoos-graphify/src/`) |
|---|---|
| `includes/class-nvoos-graphify-db.php` | `Graph/Db.php` |
| `includes/class-nvoos-graphify-builder.php` | `Graph/Builder.php` |
| `includes/class-nvoos-graphify-structural-extractor.php` | `Graph/StructuralExtractor.php` |
| `includes/class-nvoos-graphify-analyzer.php` | `Graph/Analyzer.php` |
| `includes/class-nvoos-graphify-exporter.php` | `Graph/Exporter.php` |
| `includes/class-nvoos-graphify-report.php` | `Graph/Report.php` |
| `includes/class-nvoos-graphify-detector.php` | `Graph/Detector.php` |
| `includes/class-nvoos-graphify-semantic-extractor.php` | `Graph/SemanticExtractor.php` |
| `includes/class-nvoos-graphify-embeddings.php` | `Memory/Embeddings.php` |
| `includes/class-nvoos-graphify-embeddings-on-ingest.php` | `Memory/EmbeddingsOnIngest.php` |
| `includes/class-nvoos-graphify-memory-bridge.php` | `Memory/Bridge.php` |
| `includes/tools/class-nvoos-graphify-tool-*.php` (14 files) | `Tools/*.php` |
| `includes/rest/class-nvoos-graphify-rest.php` | `Rest/Controller.php` |
| `includes/admin/class-nv-oos-graphify-settings.php` | `Admin/SettingsPage.php` |
| `includes/admin/class-nvoos-graphify-remote-admin.php` | `Admin/RemoteAdmin.php` |
| — | `Admin/GraphExplorer.php` (new — Cytoscape.js UI) |
| — | `Frontend/Shortcode.php` (new) |
| — | `Frontend/Block.php` (new) |
| — | `Frontend/SchemaOrg.php` (new) |
| — | `Frontend/RelatedContent.php` (new) |
| `includes/remote/*` (17 files) | `Remote/*` + `Remote/Drivers/*` |
| `includes/class-nvoos-graphify-nvoos-bridge.php` | **Removed** — no longer needed (was AI bridge to base plugin) |
| `includes/class-nvoos-graphify.php` | `Plugin.php` (composition root, rewritten per spec) |
| `assets/js/*`, `assets/css/*` | `assets/js/*`, `assets/css/*` (copy + refactor) |
| `assets/vendor/*` | `assets/vendor/*` (copy directly) |
| `tests/test-nvoos-bridge.php` | `tests/Unit/*` + `tests/Integration/*` (new test suite) |

### New files (don't exist in addons/graphify/)

| File | Purpose |
|---|---|
| `src/Contracts/Tool.php` | Tool interface (extracted from base plugin dependency) |
| `src/Contracts/RemoteSource.php` | Remote source contract |
| `src/Schema.php` | Centralized constants |
| `src/Settings.php` | Unified options accessor |
| `src/ToolRegistry.php` | Tool container |
| `src/Admin/GraphExplorer.php` | Cytoscape.js admin page |
| `src/Frontend/Shortcode.php` | `[nvoos_graph]` shortcode |
| `src/Frontend/Block.php` | Gutenberg block |
| `src/Frontend/SchemaOrg.php` | JSON-LD injection |
| `src/Frontend/RelatedContent.php` | Related content widget |
| `composer.json` | ✅ Already scaffolded |
| `uninstall.php` | ✅ Already scaffolded |
| `nvoos-graphify.php` | ✅ Already scaffolded (needs activation hook) |

---

## 3. Namespace Migration

| Old | New |
|---|---|
| `NV_oOS_Graphify_DB` | `NvoosGraphify\Graph\Db` |
| `NV_oOS_Graphify_Builder` | `NvoosGraphify\Graph\Builder` |
| `NV_oOS_Graphify_Analyzer` | `NvoosGraphify\Graph\Analyzer` |
| `NV_oOS_Graphify_Exporter` | `NvoosGraphify\Graph\Exporter` |
| `NV_oOS_Graphify_Report` | `NvoosGraphify\Graph\Report` |
| `NV_oOS_Graphify_Structural_Extractor` | `NvoosGraphify\Graph\StructuralExtractor` |
| `NV_oOS_Graphify_Detector` | `NvoosGraphify\Graph\Detector` |
| `NV_oOS_Graphify_Semantic_Extractor` | `NvoosGraphify\Graph\SemanticExtractor` |
| `NV_oOS_Graphify_Embeddings` | `NvoosGraphify\Memory\Embeddings` |
| `NV_oOS_Graphify_Embeddings_On_Ingest` | `NvoosGraphify\Memory\EmbeddingsOnIngest` |
| `NV_oOS_Graphify_Memory_Bridge` | `NvoosGraphify\Memory\Bridge` |
| `NV_oOS_Graphify_Tool_Build_Graph` | `NvoosGraphify\Tools\BuildGraph` |
| ... (14 tools) | `NvoosGraphify\Tools\*` |
| `NV_oOS_Graphify_REST` | `NvoosGraphify\Rest\Controller` |
| `NV_oOS_Graphify_Remote_Registry` | `NvoosGraphify\Remote\Registry` |
| `NV_oOS_Graphify_Remote_Wikidata` | `NvoosGraphify\Remote\Drivers\Wikidata` |
| ... (20 drivers) | `NvoosGraphify\Remote\Drivers\*` |

---

## 4. Hook Prefix Migration

| Old | New |
|---|---|
| `nv_oos_graphify_*` | `nvoos_graphify/*` |
| `wp_mcp_ai_*` (base plugin hooks) | Remove dependency entirely |

---

## 5. Dependency Removal

The current `addons/graphify/` depends on the base plugin (`mcp-ai-wpoos.php`) for:

| Dependency | How it's removed |
|---|---|
| `WP_MCP_AI_Tool_Interface` | Create `NvoosGraphify\Contracts\Tool` interface in `src/Contracts/Tool.php` |
| `wp_mcp_ai_get_embedding()` | Abstract to a provider registry — Memory bridge uses it when an embeddings addon is active |
| `wp_mcp_ai_chat_completion()` | Remove entirely — AI chat is an addon concern |
| `class-nvoos-graphify-nvoos-bridge.php` | Delete — this bridged graphify into the base plugin's tool registry |

---

## 6. Phased Buildout

### Phase 0 — Scaffolding ✅ (Done)

- [x] `plugins/nvoos-graphify/` directory structure
- [x] `nvoos-graphify.php` bootstrap (PSR-4 + `spl_autoload_register` fallback)
- [x] `composer.json` (PSR-4 autoload, `type: wordpress-plugin`, `composer/installers`)
- [x] `uninstall.php` (standalone cleanup)
- [x] Sync workflow (`.github/workflows/sync-nvoos-graphify.yml`)

### Phase 1 — Foundation Contracts + Infrastructure (Week 1)

**Goal**: The plugin activates cleanly with zero errors. Core interfaces and infrastructure are in place.

- [ ] `src/Contracts/Tool.php` — Tool interface (7 methods: `getSlug()`, `getName()`, `getDescription()`, `getParametersSchema()`, `getRequiredCapability()`, `getCapabilityFlags()`, `execute()`)
- [ ] `src/Contracts/RemoteSource.php` — Remote source interface
- [ ] `src/Schema.php` — Centralized constants (option keys, table names, hook names, capabilities, REST namespace)
- [ ] `src/Settings.php` — Static settings accessor (`get()`, `all()`, `update()`) for `nvoos_graphify_settings`
- [ ] `src/ToolRegistry.php` — Tool container (`register()`, `get()`, `all()`, `has()`, `count()`)
- [ ] `src/Plugin.php` — Composition root (wires services, registers hooks, exposes singletons)
- [ ] `nvoos-graphify.php` — Add activation hook with PHP 8.1+ check, `dbDelta()` table creation, default settings, initial build schedule
- [ ] `tests/bootstrap.php` — PHPUnit bootstrap
- [ ] `phpunit.xml.dist` — PHPUnit config

**Milestone**: Plugin activates without errors. `nvoos_graphify_settings` option is created. Test suite runs (even if just a smoke test).

### Phase 2 — Graph Engine (Week 1-2)

**Goal**: The graph builds and can be queried. This is the heart of the product.

- [ ] `src/Graph/Db.php` — Port from `class-nvoos-graphify-db.php`
  - Custom tables: `nvoos_graphify_nodes`, `nvoos_graphify_edges`, `nvoos_graphify_meta`, `nvoos_graphify_remote_sources`, `nvoos_graphify_embeddings`
  - Methods: `install()`, `upgrade()`, `getNode()`, `searchNodes()`, `getEdgesForNode()`, `upsertNode()`, `upsertEdge()`, `deleteNode()`, `countNodes()`, `countEdges()`, `getAllNodes()`
  - All queries use `$wpdb->prepare()`
- [ ] `src/Graph/StructuralExtractor.php` — Port from `class-nvoos-graphify-structural-extractor.php`
  - Extract post→term, post→author, post→linked-page edges
  - Extract term nodes and user nodes
- [ ] `src/Graph/Builder.php` — Port from `class-nvoos-graphify-builder.php`
  - Full build: process all post types through extractor, deduplicate, upsert
  - Incremental build: process single post on save
  - Cron scheduling: `nvoos_graphify/cron_build`
- [ ] `src/Graph/Detector.php` — Port from `class-nvoos-graphify-detector.php`
- [ ] `src/Graph/SemanticExtractor.php` — Port from `class-nvoos-graphify-semantic-extractor.php` (keep, but AI-free — structural only)
- [ ] Tests: `tests/Unit/Graph/DbTest.php`, `tests/Unit/Graph/BuilderTest.php`

**Milestone**: Click "Build Graph" → nodes and edges appear in the database. Cron rebuild works.

### Phase 3 — Graph Features (Week 2)

**Goal**: Analysis, export, and content gap reporting work.

- [ ] `src/Graph/Analyzer.php` — Port from `class-nvoos-graphify-analyzer.php`
  - Community detection, god nodes, graph stats, shortest path
- [ ] `src/Graph/Exporter.php` — Port from `class-nvoos-graphify-exporter.php`
  - JSON, GraphML, CSV, Neo4j, Obsidian formats
- [ ] `src/Graph/Report.php` — Port from `class-nvoos-graphify-report.php`
  - Orphan nodes, underlinked nodes, content gaps
- [ ] Tests: `tests/Unit/Graph/AnalyzerTest.php`, `tests/Unit/Graph/ExporterTest.php`

### Phase 4 — Tools (Week 2-3)

**Goal**: All 14 built-in tools are registered and executable.

- [ ] `src/Tools/AbstractTool.php` — Base class implementing `Contracts\Tool`
- [ ] Port 14 tools from `addons/graphify/includes/tools/`:
  - [ ] `Tools/GetNode.php`
  - [ ] `Tools/QueryGraph.php`
  - [ ] `Tools/GetNeighbors.php`
  - [ ] `Tools/BuildGraph.php`
  - [ ] `Tools/GraphStats.php`
  - [ ] `Tools/ShortestPath.php`
  - [ ] `Tools/ContentGaps.php`
  - [ ] `Tools/GodNodes.php`
  - [ ] `Tools/SuggestLinks.php`
  - [ ] `Tools/RetrieveContext.php`
  - [ ] `Tools/ResolveExternal.php`
  - [ ] `Tools/ListRemoteSources.php`
  - [ ] `Tools/SyncRemoteSource.php`
  - [ ] `Tools/GetCommunity.php`
- [ ] Each tool implements `NvoosGraphify\Contracts\Tool`
- [ ] Tool slugs prefixed `nvoos_graphify_*`
- [ ] Tool execution returns canonical success/error envelope
- [ ] Tests: one test file per tool

**Milestone**: All 14 tools pass their unit tests. Can query graph nodes, find neighbors, detect communities programmatically.

### Phase 5 — REST API + Admin (Week 3)

**Goal**: Graph data is accessible via REST and the Admin UI works.

- [ ] `src/Rest/Controller.php` — Port + modernize from `class-nvoos-graphify-rest.php`
  - Endpoints: `GET /graph`, `GET /nodes`, `GET /nodes/{id}`, `POST /build`, `GET /search`, `GET /export`, `POST /retrieve`, `GET /resolve`, `GET /sources`, `POST /sources`, `DELETE /sources/{slug}`, `POST /sources/{slug}/sync`, `POST /sources/{slug}/test`
  - Namespace: `nvoos-graphify/v1`
  - Write endpoints: `permission_callback` requires `manage_options`
  - Read endpoints: `permission_callback` requires `edit_posts`
- [ ] `src/Admin/SettingsPage.php` — Port from `class-nv-oos-graphify-settings.php`
  - General tab: enable/disable, auto-rebuild, post types, terms, users, schema.org, related content
  - Addons tab: placeholder for future addon settings
- [ ] `src/Admin/GraphExplorer.php` — **New** (Cytoscape.js visualization admin page)
  - Enqueue `assets/vendor/cytoscape/cytoscape.min.js` + fcose + cose-base + layout-base
  - Enqueue `assets/js/graphify-admin.js`
  - REST data populates the interactive graph
- [ ] `src/Admin/RemoteAdmin.php` — Port from `class-nvoos-graphify-remote-admin.php`
- [ ] Copy assets: `addons/graphify/assets/` → `plugins/nvoos-graphify/assets/`
- [ ] Tests: `tests/Integration/RestApiTest.php`

**Milestone**: Admin → Graph Explorer shows the interactive Cytoscape.js graph. REST API returns graph data.

### Phase 6 — Frontend + Schema.org (Week 3-4)

**Goal**: Public-facing features work.

- [ ] `src/Frontend/Shortcode.php` — `[nvoos_graph]` embeds mini graph viewer
- [ ] `src/Frontend/Block.php` — Gutenberg block wrapper
- [ ] `src/Frontend/SchemaOrg.php` — JSON-LD injection using graph relationships
- [ ] `src/Frontend/RelatedContent.php` — Appends related posts via graph proximity
- [ ] Enqueue `assets/css/graphify-frontend.css` and `assets/js/graphify-frontend.js`

### Phase 7 — Remote Sources (Week 4)

**Goal**: External data sources connect to the graph.

- [ ] `src/Remote/Registry.php` — Port from `class-nvoos-graphify-remote-registry.php`
- [ ] `src/Remote/HttpClient.php` — Port from `class-nvoos-graphify-http-client.php`
- [ ] `src/Remote/Crypto.php` — Port from `class-nvoos-graphify-crypto.php`
- [ ] `src/Remote/Enricher.php` — Port from `class-nvoos-graphify-remote-enricher.php`
- [ ] `src/Remote/StateStore.php` — Port from `class-nvoos-graphify-remote-state-store.php`
- [ ] Port 7 free drivers to `src/Remote/Drivers/`:
  - [ ] `Drivers/Wikidata.php`
  - [ ] `Drivers/GenericRest.php`
  - [ ] `Drivers/RssSitemap.php`
  - [ ] `Drivers/Sparql.php`
  - [ ] `Drivers/WooCommerce.php`
  - [ ] `Drivers/Csv.php`
  - [ ] `Drivers/Webhook.php`
- [ ] Enterprise drivers (GitHub, Jira, Slack, etc.) stay in the existing `addons/graphify/` for now — they'll become the `nvoos-graphify-pro` addon

### Phase 8 — Memory Bridge (Week 4)

**Goal**: Agent memory storage works, ready for future AI addons.

- [ ] `src/Memory/Bridge.php` — Port from `class-nvoos-graphify-memory-bridge.php`
- [ ] `src/Memory/EmbeddingsOnIngest.php` — Port, abstracted to use provider registry
- [ ] `src/Memory/Embeddings.php` — Port from `class-nvoos-graphify-embeddings.php`

### Phase 9 — Cleanup, Docs, Release Prep (Week 5)

**Goal**: Ready for wp.org submission.

- [ ] `readme.txt` — WordPress.org format with screenshots
- [ ] `.distignore` — Exclude tests, dev config, etc.
- [ ] `phpcs.xml.dist` — WordPress Coding Standards config
- [ ] `languages/nvoos-graphify.pot` — Translation template
- [ ] CI workflow: `.github/workflows/graphify-ci.yml` — PHP 8.1-8.3 × WP 6.5-6.9 matrix
- [ ] Screenshots: Graph Explorer, Content Gaps, Export formats, Settings, Remote Sources
- [ ] Migration guide for existing NV oOS users
- [ ] Tag `nvoos-graphify-1.0.0` on the separate repo

---

## 7. Files NOT Ported

These files from `addons/graphify/` are deliberately left behind:

| File | Reason |
|---|---|
| `class-nvoos-graphify-nvoos-bridge.php` | Was the AI bridge to base plugin — no longer needed |
| `class-nvoos-graphify.php` (old bootstrap) | Replaced by `src/Plugin.php` |
| `class-nvoos-graphify-remote-entity-resolver.php` | Merged into `Graph/` layer |
| `class-nvoos-graphify-remote-field-mapper.php` | Merged into `Remote/Enricher.php` |
| `class-nvoos-graphify-remote-field-map-validator.php` | Merged into `Remote/Enricher.php` |
| `class-nvoos-graphify-remote-oauth-broker.php` | Deferred to `nvoos-graphify-remote` addon |
| `class-nvoos-graphify-remote-schema-org-mapper.php` | Became `Frontend/SchemaOrg.php` |
| Enterprise SaaS drivers (Jira, Slack, M365, etc.) | Stay in `addons/graphify/` → future `nvoos-graphify-pro` |
| `nvoos-graphify.php` (old addon bootstrap) | Replaced by new standalone bootstrap |

---

## 8. Testing Strategy

### Per-phase testing

Each phase has a concrete test milestone. Tests are written alongside the ported code, not after.

### Unit tests (no WP required)

- `tests/Unit/Graph/DbTest.php` — Mock `$wpdb`, test CRUD
- `tests/Unit/Graph/BuilderTest.php` — Mock WP functions, test node/edge construction
- `tests/Unit/Graph/AnalyzerTest.php` — Community detection, centrality
- `tests/Unit/Graph/ExporterTest.php` — Each format produces valid output
- `tests/Unit/Tools/*` — One per tool, test execute() with valid/invalid args

### Integration tests (WP test suite required)

- `tests/Integration/RestApiTest.php` — Auth: 401/403/200 responses
- `tests/Integration/LifecycleTest.php` — Activation creates tables, deactivation clears cron, uninstall drops tables

### Test commands

```bash
cd plugins/nvoos-graphify
composer install
composer run test           # Unit tests
composer run test:integration  # Requires WP test suite
```

---

## 9. Sync Workflow

Already configured: `.github/workflows/sync-nvoos-graphify.yml`

- Triggers on push to `main` or `alpha-working` when `plugins/nvoos-graphify/**` changes
- Runs `git subtree split --prefix=plugins/nvoos-graphify`
- Force-pushes to `github.com/nvdigitalsolutions/nvoos-graphify.git`

Requires secret `NVOOS_GRAPHIFY_REPO_TOKEN` (PAT with Contents: Write on nvoos-graphify repo).

---

## 10. Timeline

| Phase | Duration | Cumulative |
|---|---|---|
| 0 — Scaffolding | ✅ Done | — |
| 1 — Foundation | 1 week | 1 week |
| 2 — Graph Engine | 1 week | 2 weeks |
| 3 — Graph Features | 1 week | 3 weeks |
| 4 — Tools | 1 week | 4 weeks |
| 5 — REST + Admin | 1 week | 5 weeks |
| 6 — Frontend | 1 week | 6 weeks |
| 7 — Remote Sources | 1 week | 7 weeks |
| 8 — Memory Bridge | 1 week | 8 weeks |
| 9 — Cleanup + Release | 1 week | 9 weeks |

**Total**: ~9 weeks to a wp.org-ready plugin.

---

## 11. Key Decisions

1. **PSR-4 namespace**: `NvoosGraphify\` — consistent with `nvoos` branding
2. **Hook prefix**: `nvoos_graphify/` — forward slash separator per WordPress conventions
3. **Option key**: `nvoos_graphify_settings` — single grouped option
4. **Table prefix**: `nvoos_graphify_` — unique, collision-free
5. **REST namespace**: `nvoos-graphify/v1` — hyphenated for URL readability
6. **PHP floor**: 8.1 — matches the implementation spec and enables readonly classes, enums, fibers
7. **Zero external deps**: No Composer runtime dependencies. Cytoscape.js vendored in `assets/vendor/`.
8. **License**: GPL-3.0-or-later — required for wp.org, matches the restructuring roadmap
