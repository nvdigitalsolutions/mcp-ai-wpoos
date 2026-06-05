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

## 6. Phased Buildout ✅ ALL PHASES COMPLETE (2026-06-05)

### Phase 0 — Scaffolding ✅

- [x] `plugins/nvoos-graphify/` directory structure
- [x] `nvoos-graphify.php` bootstrap (PSR-4 + `spl_autoload_register` fallback)
- [x] `composer.json` (PSR-4 autoload, `type: wordpress-plugin`, `composer/installers`)
- [x] `uninstall.php` (standalone cleanup)
- [x] Sync workflow (`.github/workflows/sync-nvoos-graphify.yml`)

### Phase 1 — Foundation Contracts + Infrastructure ✅

- [x] `src/Contracts/Tool.php` — Tool interface (7 methods)
- [x] `src/Contracts/RemoteSource.php` — Remote source interface
- [x] `src/Schema.php` — Centralized constants
- [x] `src/Settings.php` — Static settings accessor
- [x] `src/ToolRegistry.php` — Tool container
- [x] `src/Plugin.php` — Composition root
- [x] `nvoos-graphify.php` — Activation hook, dbDelta, default settings, initial build schedule
- [x] `tests/bootstrap.php` — PHPUnit bootstrap
- [x] `phpunit.xml.dist` — PHPUnit config

### Phase 2 — Graph Engine ✅

- [x] `src/Graph/Db.php` — 5 custom tables via `dbDelta`
- [x] `src/Graph/StructuralExtractor.php` — post→term, post→author, post→linked-page edges
- [x] `src/Graph/Builder.php` — Full + incremental build, cron scheduling
- [x] `src/Graph/Detector.php` — Content type detection
- [x] `src/Graph/SemanticExtractor.php` — AI-free structural extraction

### Phase 3 — Graph Features ✅

- [x] `src/Graph/Analyzer.php` — Louvain community detection, god nodes, stats, shortest path
- [x] `src/Graph/Exporter.php` — 6 formats: JSON, GraphML, CSV, Neo4j, Obsidian, HTML
- [x] `src/Graph/Report.php` — Orphans, content gaps, thin communities

### Phase 4 — Tools ✅

- [x] `src/Tools/AbstractTool.php` — Base class implementing `Contracts\Tool`
- [x] 14 built-in tools: GetNode, QueryGraph, GetNeighbors, BuildGraph, GraphStats, ShortestPath, ContentGaps, GodNodes, SuggestLinks, RetrieveContext, ResolveExternal, ListRemoteSources, SyncRemoteSource, GetCommunity
- [x] Each tool implements `NvoosGraphify\Contracts\Tool`
- [x] Tool slugs prefixed `nvoos_graphify_*`
- [x] All tools return canonical success/error envelope

### Phase 5 — REST API + Admin ✅

- [x] `src/Rest/Controller.php` — 13 endpoints at `nvoos-graphify/v1`
- [x] `src/Admin/SettingsPage.php` — Tabbed UI (General, Remote, Embeddings, Sources)
- [x] `src/Admin/GraphExplorer.php` — Cytoscape.js visualization
- [x] `src/Admin/RemoteAdmin.php` — Remote source management
- [x] Assets copied: `addons/graphify/assets/` → `plugins/nvoos-graphify/assets/`

### Phase 6 — Frontend + Schema.org ✅

- [x] `src/Frontend/Shortcode.php` — `[nvoos_graph]`
- [x] `src/Frontend/Block.php` — Gutenberg block
- [x] `src/Frontend/SchemaOrg.php` — JSON-LD injection
- [x] `src/Frontend/RelatedContent.php` — Graph-based related content widget

### Phase 7 — Remote Sources ✅

- [x] `src/Remote/Registry.php` — Driver registry
- [x] `src/Remote/HttpClient.php` — HTTP client with SSRF protection
- [x] `src/Remote/Crypto.php` — AES-256-GCM credential encryption
- [x] `src/Remote/Enricher.php` — Remote enrichment pipeline
- [x] `src/Remote/StateStore.php` — Circuit breaker state
- [x] 7 free drivers: Wikidata, GenericRest, RssSitemap, Sparql, WooCommerce, Csv, Webhook

### Phase 8 — Memory Bridge ✅

- [x] `src/Memory/Bridge.php` — Agent memory → graph mirroring
- [x] `src/Memory/EmbeddingsOnIngest.php` — Cron-based embedding pipeline
- [x] `src/Memory/Embeddings.php` — Float32 vector storage + cosine similarity

### Phase 9 — Cleanup, Docs, Release Prep ✅

- [x] `readme.txt` — WordPress.org format with screenshots described
- [x] `.distignore` — Exclude dev files
- [x] `phpcs.xml.dist` — WordPress Coding Standards config
- [x] `CHANGELOG.md` — Full v1.0.0 changelog
- [ ] `languages/nvoos-graphify.pot` — Translation template (TODO)
- [ ] CI workflow: `.github/workflows/graphify-ci.yml` — PHP 8.1-8.3 x WP 6.5-6.9 matrix (TODO)
- [ ] Screenshots: Actual PNG files for wp.org (TODO — readme.txt describes them)
- [ ] Migration guide for existing NV oOS users (TODO)
- [ ] Tag `nvoos-graphify-1.0.0` on the separate repo (TODO — sync workflow exists)

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

## 10. Timeline (ACTUAL — Completed 2026-06-05)

| Phase | Planned | Actual | Status |
|---|---|---|---|
| 0 — Scaffolding | 1 week | Done | ✅ |
| 1 — Foundation | 1 week | Done | ✅ |
| 2 — Graph Engine | 1 week | Done | ✅ |
| 3 — Graph Features | 1 week | Done | ✅ |
| 4 — Tools | 1 week | Done | ✅ |
| 5 — REST + Admin | 1 week | Done | ✅ |
| 6 — Frontend | 1 week | Done | ✅ |
| 7 — Remote Sources | 1 week | Done | ✅ |
| 8 — Memory Bridge | 1 week | Done | ✅ |
| 9 — Cleanup + Release | 1 week | Done | ✅ |

**All 9 buildout phases completed.** Remaining Phase 9 sub-items (translation template, CI workflow, screenshots, migration guide, tag) are tracked as TODO items above.

See [nvoos-base-restructuring-roadmap.md](./nvoos-base-restructuring-roadmap.md) for the overall ecosystem status including Phases 1-5 (Exotic Providers, Extended Tools, Features, AI Chat, Cleanup) which are still pending.

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
