# Maintainer Map — Open Operator System (NV oOS)

> **Start here.** This document answers the five questions every new maintainer asks: how the plugin boots, where the code lives, which commands to run, what Pro adds, and which docs to trust.
>
> Last reviewed: **August 10, 2026** (v1.1.50)

### Related Files

| File | Purpose |
|------|---------|
| [`CLAUDE.md`](CLAUDE.md) | Claude Code context — loaded every turn by Claude Code sessions |
| [`AGENTS.md`](AGENTS.md) | AI agent inventory — every coding agent, BMAD role, and context-loading strategy |
| [`CODEOWNERS`](CODEOWNERS) | GitHub review assignment — auto-assigns reviewers per path |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contributor guide — setup, testing, PR process, GSD × BMAD methodology |
| [`SECURITY.md`](SECURITY.md) | Vulnerability disclosure policy and IP notice |
| [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | GitHub Copilot repo-level instructions |

---

## 1. Runtime Boot Flow

```
mcp-ai-wpoos.php  (WordPress plugin header + entry point)
  │
  ├─ Guard: function_exists('wp_mcp_ai_core_loaded') → exit if already loaded
  ├─ Define WP_MCP_AI_FILE (__FILE__)
  ├─ PHP ≥ 7.4 check → admin notice + deactivate on failure
  │
  ├─ require includes/bootstrap/constants.php   ← version, paths, URLs, WP_MCP_AI_BASE_VERSION
  ├─ require includes/bootstrap/autoload.php    ← Composer autoloader (graceful degradation)
  ├─ require includes/bootstrap/helpers.php     ← global helpers, wp_mcp_ai_core_loaded()
  ├─ require includes/bootstrap/cron.php        ← cron schedules
  ├─ require includes/bootstrap/hooks.php       ← MIME filters, cache-busting hooks
  ├─ require includes/bootstrap/loader.php      ← require_once chain for all ~500 class files
  ├─ require includes/bootstrap/activation.php  ← activate/deactivate/uninstall handlers
  ├─ require includes/class-wp-mcp-ai-plugin.php
  │
  ├─ register_activation_hook / register_deactivation_hook / register_uninstall_hook
  │
  └─ add_action('plugins_loaded', 'wp_mcp_ai_bootstrap', 20)
         │
         └─ wp_mcp_ai_bootstrap()
               ├─ WP_MCP_AI::instance()->bootstrap()
               │     ├─ Root security key check
               │     ├─ WP_MCP_AI_Nefarious_Usage_Monitor init
               │     ├─ WP_MCP_AI_Tool_Registry init
               │     ├─ wp_mcp_ai_container() → WP_MCP_AI_Container singleton
               │     ├─ Bind services: router, resource_manager, assistant_cpt,
               │     │                  crawl4ai_local_api, rest_controller,
               │     │                  shortcodes, federation
               │     └─ Admin-only: cron_manager, dlq_manager, token_manager,
               │                     crawl4ai_monitor
               │     └─ WP_MCP_AI_DSpark_Hooks::register()
               │
               └─ do_action('wp_mcp_ai_bootstrapped')
```

### Pro Addon Boot (runs in parallel at `plugins_loaded` priority 15)

`addons/pro/mcp-ai-wpoos-pro.php` is **auto-loaded** by `wp_mcp_ai_maybe_load_pro_addon()` (defined at the bottom of `class-wp-mcp-ai-plugin.php`) when `addons/pro/mcp-ai-wpoos-pro.php` exists and `WP_MCP_AI_PRO_VERSION` is not yet defined. It can also be installed as a **standalone plugin** — it detects which scenario applies at runtime.

```
wp_mcp_ai_pro_init()   (at plugins_loaded priority 15)
  ├─ Dependency check (base plugin active?)
  ├─ Conditional: PHP ≥ 8.1 → load addons/pro/vendor/autoload.php
  ├─ Load npm-integration-filters.php   (Node.js microservice bridges)
  ├─ Load Pro CDN loader, CPT meta schema, product type helper,
  │   remote connection manager, ERP connector
  ├─ Register Pro tool classes (~635 tools via pro.php class-loader)
  └─ do_action('wp_mcp_ai_pro_init')
```

**Key constants set during boot**

| Constant | Default | Effect |
|---|---|---|
| `WP_MCP_AI_BASE_VERSION` | `true` | `true` = base-only (~195 tool classes); `false` = full ~830-tool mode (~195 base + ~635 Pro) |
| `WP_MCP_AI_FILE` | (plugin file path) | Used by lifecycle hooks |
| `WP_MCP_AI_PRO_VERSION` | set by Pro | Prevents double-loading of Pro addon |
| `WP_DEBUG` | WordPress default | Enables extra error logging throughout |

---

## 2. Where Domain Logic Lives

```
mcp-ai-wpoos/
│
├─ mcp-ai-wpoos.php            ← Plugin entry point (do not add logic here)
├─ mcp-ai-wpoos-base.php       ← Alternate entry for base-only distribution
│
├─ includes/
│   ├─ bootstrap/              ← Initialization sequence (constants → loader → activation)
│   ├─ class-wp-mcp-ai-plugin.php  ← Main singleton, DI container wiring
│   ├─ class-wp-mcp-ai-container.php + container-helpers.php  ← Service locator / DI
│   ├─ class-wp-mcp-ai-language-model-router.php  ← DSpark tiered model routing
│   │
│   ├─ tools/                  ← Tool classes (~234 class files; ~195 extend the tool base class)
│   │   └─ class-wp-mcp-ai-tool-{name}.php   (one file per tool)
│   │   └─ orchestration/      ← Tool routing / multi-tool orchestration
│   │
│   ├─ admin/                  ← All wp-admin UI (settings, pages, dashboards, AJAX)
│   │   ├─ class-wp-mcp-ai-admin-settings.php  (228 KB – settings mega-class)
│   │   ├─ class-wp-mcp-ai-admin-ajax-handlers.php  (128 KB – AJAX handlers)
│   │   ├─ sections/           ← Settings tab section classes
│   │   └─ widgets/            ← Dashboard widget classes
│   │
│   ├─ assistants/             ← Assistant CPT registration and metaboxes
│   ├─ blueprints/             ← Unified blueprint installer + import tools
│   ├─ security/               ← Security infrastructure (7 classes: request guard, posture, destructive ops gate, URL guard, concurrency guard, cost tracker, API key store)
│   ├─ bridge/                 ← WordPress adapters for nvoos/core domain contracts (21 adapters)
│   ├─ services/               ← Business logic (30+ service classes)
│   │   ├─ class-wp-mcp-ai-dspark-hooks.php ← DSpark efficiency data collectors
│   │   ├─ class-wp-mcp-ai-speculative-tool-executor.php ← DSpark speculative execution
│   │   ├─ class-wp-mcp-ai-orchestration-depth-scheduler.php ← DSpark depth scheduling
│   │   ├─ class-wp-mcp-ai-hybrid-plan-generator.php ← DSpark hybrid planning
│   │   └─ class-wp-mcp-ai-orchestration-preset-service.php ← Orchestration presets
│   ├─ rest/                   ← REST controllers
│   │   ├─ class-wp-mcp-ai-rest-chat-memory-controller.php  ← Chat-client memory bridge proxy
│   │   └─ class-wp-mcp-ai-rest-transcript-mining-controller.php  ← Transcript mining REST API
│   ├─ harness/                ← LLM Harnessing subsystem (Layers A–G)
│   │   ├─ class-wp-mcp-ai-prompt-cue-library.php  ← Layer A: cue templates
│   │   ├─ class-wp-mcp-ai-reasoning-trace.php     ← Layer B: reasoning traces
│   │   ├─ class-wp-mcp-ai-tool-router-harness.php ← Layer C: tool routing
│   │   ├─ class-wp-mcp-ai-retrieval-harness.php   ← Layer D: retrieval fan-out
│   │   ├─ class-wp-mcp-ai-self-refine-loop.php    ← Layer E: self-refine
│   │   ├─ class-wp-mcp-ai-pii-filter.php          ← Layer F: PII scrubbing
│   │   └─ class-wp-mcp-ai-harness-eval-scheduler.php  ← Layer G: eval scheduler
│   ├─ measurement/            ← Metrics, acceptance tracking, OTEL export
│   │   └─ class-wp-mcp-ai-tool-chain-acceptance-tracker.php ← DSpark chain acceptance
│   ├─ repositories/           ← Data access layer
│   ├─ integrations/           ← JetEngine, Elementor, Auth0, ChatKit, Gravatar
│   ├─ infrastructure/         ← HTTP client, options-store adapter, provider adapters
│   ├─ interfaces/             ← PHP interfaces (OptionsStore, CapabilityChecker, HttpClient)
│   ├─ knowledge-base/         ← KB system (documents, professions, playbooks)
│   ├─ professions/            ← Professional profiles
│   ├─ teams/                  ← Team management
│   ├─ agents/                 ← Agent definitions
│   ├─ blocks/                 ← WordPress blocks (chat, assistant-builder, tools-grid…)
│   ├─ bundled-skills/         ← Pre-packaged SKILL.md files (MCP, PDF, Excel, video…)
│   ├─ slash-commands/         ← Slash command toolkit manager
│   └─ helpers/                ← Utility helpers
│
├─ addons/pro/
│   ├─ mcp-ai-wpoos-pro.php    ← Pro entry point (no WP plugin header in repo)
│   └─ includes/
│       ├─ tools/              ← ~765 pro tool classes (same naming convention)
│       │   ├─ cloudways/      ← Cloudways Pro Toolkit — 60 tools + API v2 client
│       │   ├─ crm/            ← CRM Toolkit — 70+ tools, 5 phases A–E
│       │   └─ ...
│       ├─ harness/            ← Layer H fine-tune curriculum exporter (Pro)
│       │   └─ class-wp-mcp-ai-tool-export-fine-tune-curriculum.php
│       ├─ admin/              ← Pro admin pages (Pro Dashboard, imaging admin…)
│       ├─ rest/               ← Pro REST controllers (channels, TMA, social…)
│       ├─ integrations/       ← WooCommerce, Shopify, social media, Google, GitHub
│       ├─ cloudways/          ← Cloudways API v2 OAuth client + helpers
│       ├─ services/           ← Pro-specific service classes
│       └─ bundled-skills/     ← Pro-exclusive SKILL.md files
│
├─ assets/
│   ├─ js/                     ← 100+ JS files compiled by esbuild; *.min.js served
│   │   ├─ chat.js             ← Main chat UI (most user-visible JS)
│   │   ├─ admin-settings.js   ← Settings page JS
│   │   └─ vendor/             ← Vendored third-party JS (chart.js, vectorizer…)
│   └─ css/                    ← Styles; *.min.css served
│
├─ lib/core/                   ← Framework-agnostic AI engine (nvoos/core, PHP 8.1+, MIT)
│   ├─ src/Domain/Contract/    ← 32 domain interfaces (ports)
│   ├─ src/Application/        ← ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry
│   ├─ src/Infrastructure/     ← 12 AI provider clients, SSE handler, cost calculator
│   └─ src/Tool/               ← 109 framework-agnostic tool classes
│
├─ .agents/skills/             ← 21 coding-time agent skills for Zed editor (wp-* patterns)
├─ .bmad/                      ← 6 BMAD workflow agent YAML definitions + team composition
├─ .context/                   ← Subsystem context files (8 topics + 5 templates + active/archive)
│
└─ packages/                   ← 17 standalone NPM packages (published separately)
    ├─ nvoos-storage/          ← Storage utilities
    ├─ nvoos-markdown/         ← Markdown utilities
    ├─ nvoos-events/           ← Event system
    ├─ nvoos-clipboard/        ← Clipboard management
    ├─ nvoos-http-client/      ← HTTP client
    ├─ nvoos-offline-sync/     ← Offline sync
    ├─ nvoos-slash-commands/   ← Slash commands
    ├─ nvoos-audio/            ← Audio utilities
    ├─ nvoos-dom-batcher/      ← DOM batching
    ├─ nvoos-llm-worker/       ← Browser LLM worker (Tier 4)
    ├─ nvoos-model-loader/     ← Browser model loader (Tier 4)
    ├─ nvoos-transformers-client/ ← @huggingface/transformers wrapper (Tier 4)
    ├─ nvoos-client-tools/     ← Browser-native AI tool registry (Tier 5)
    ├─ nvoos-chat-memory/      ← REST client for chat memory bridge (Tier 5)
    ├─ nvoos-attachments/      ← File attachment helpers (Tier 5)
    ├─ nvoos-cron-status/      ← SSE-first job status monitor (Tier 5)
    └─ nvoos-transcription/    ← MediaRecorder + transcription pipeline (Tier 5)
```

### Tool naming convention

Every tool is a single PHP file:

```
includes/tools/class-wp-mcp-ai-tool-{slug}.php
```

The class inside extends a base tool class and must implement `get_slug()`, `get_definition()`, and `execute()`. New tools for base go in `includes/tools/`; new tools for Pro go in `addons/pro/includes/tools/`.

---

## 3. Build Commands That Matter

### PHP

```bash
composer install                  # Install dependencies (includes PHPUnit + WPCS)
composer run lint                 # PHPCS – full WordPress coding standards (includes WPMCPAI.Tools.CanonicalReturnEnvelope + WPMCPAI.Tools.SanitizeAtEntry custom sniffs at severity 5 — visible in default lint, current baseline is 2 documented P6 warnings)
composer run lint:base            # PHPCS – base plugin only (-w8 — silences the two Unix-Theory sniffs and any other severity-5 warnings)
composer run lint:compat          # PHP 7.4–8.3 compatibility check
composer run format               # PHPCBF – auto-fix style issues
composer run test:install         # One-time: install WordPress test suite into /tmp
composer run test                 # PHPUnit – full test suite
composer run test:coverage        # PHPUnit with HTML + Clover coverage
composer run ci:all               # lint:errors-only + test:coverage (CI entry point)
composer run pot                  # Generate .pot translation template
```

### JavaScript / CSS

```bash
npm install                       # Install JS deps (also copies chart.js, vectorizer)
npm run build                     # CSS + JS base + JS pro  (production, minified)
npm run build:full                # build + Workflow Builder React + TMA React builds
npm run build:css                 # CSS only (cleancss)
npm run build:js                  # Base JS only (esbuild)
npm run build:js:pro              # Pro JS only (esbuild)
npm run lint:js                   # ESLint on assets/js/**/*.js
npm run lint:js:fix               # Auto-fix JS lint issues
npm test                          # Jest unit tests
```

### React (Pro only)

```bash
npm run build:workflow            # Workflow Builder (src/workflow-builder → addons/pro/build/)
npm run build:tma                 # All TMA entries (webpack)
npm run build:tma-builder         # TMA template-builder entry only
npm run start:workflow            # Workflow Builder dev server (watch mode)
npm run start:tma-builder         # TMA builder dev server (watch mode)
```

### Distribution ZIPs

```bash
npm run build:zip:base            # Base plugin only (no Pro)
npm run build:zip:pro             # Pro addon only (requires base)
npm run build:zip:combined        # Combined (base + Pro bundled)
npm run rebuild:all               # Rebuild all three ZIPs
```

> **Typical workflow before a PR:** `composer run lint:base && composer run test`
>
> **Full CI check:** `composer run ci:all && npm run build`

---

## 4. How Pro Differs

| | Base | Pro |
|---|---|---|
| **Entry point** | `mcp-ai-wpoos.php` | `addons/pro/mcp-ai-wpoos-pro.php` |
| **Tools** | ~201 core tools | +~830+ Pro tools = **~1,031+ total** |
| **Control constant** | `WP_MCP_AI_BASE_VERSION=true` | `WP_MCP_AI_BASE_VERSION=false` |
| **PHP vendor** | `vendor/` (root) | `addons/pro/vendor/` (PHP 8.1+ deps: phpspreadsheet, etc.) |
| **JS build** | `esbuild.config.js` | `esbuild.config.pro.js` |
| **React builds** | none | Workflow Builder, TMA templates |
| **Distribution** | `build-plugin-zip.sh --base` | `build-plugin-zip.sh --pro` |
| **License** | GPLv3 | Proprietary (patent pending) |

### Pro-exclusive feature areas

- **WooCommerce** – products, orders, subscriptions, variable products
- **JetEngine** – advanced CPT/CCT/relation tools, vitals log CCT, channel CCTs
- **Social media** – Slack, Discord, Teams, Telegram, WhatsApp, Instagram, LinkedIn, Twitter
- **Google services** – Drive, Calendar, Gmail, Sheets
- **GitHub** – repository and PR tools
- **Media processing** – FFmpeg video, audio transcription, DICOM imaging viewer
- **Multi-agent orchestration** – autonomous sessions, orchestration dashboard
- **Health & wellness** – 27 tools (vitals, vaccinations, health records, DICOM)
- **Finance / ERP** – ERP connector, financial tools
- **Telegram Mini Apps** – TMA template builder and 8 built-in templates
- **DietPi Pro Toolkit** – 19+ tools for DietPi server management (system info, backup, update, storage, provisioning, SSH proxy, MCP server)
- **LibreChat** – code interpreter, speech services, web search reranker
- **Schedule Anything SaaS** – full SaaS booking platform with Stripe integration
- **Agent Skills (base + Pro)** – progressive-disclosure `load_skill` tool (base), bundled `SKILL.md` library (28+ Pro skills + 1 base skill curated from `Lonsdale201/wp-agent-skills` and `anthropics/skills`), Pro Skill Catalogue Service + REST controller for one-click installs from registered public GitHub repos, curated skill packs. See [`docs/features/agent-skills.md`](docs/features/agent-skills.md).
- **Pro Toolkit Optimizations** – autoload control, caching, and lazy loading across 6 toolkits (Chat Channels, Social Media, Healthcare, Ecommerce, Calendar/Orchestration, Document Generation)
- **Memory Retention** – agent memory lifecycle management with configurable pruning and retention windows

### How Pro loads in a cloned repo

Because `addons/pro/mcp-ai-wpoos-pro.php` **has no WordPress plugin header** in the repository, WordPress does not see it as a separate plugin. Instead, `wp_mcp_ai_maybe_load_pro_addon()` (called from `includes/class-wp-mcp-ai-plugin.php`) detects the file and requires it automatically. This means the full ~830-tool set is active in a fresh clone without any extra activation step.

To test the ~195-tool base-only mode, add this to `wp-config.php`:

```php
define( 'WP_MCP_AI_BASE_VERSION', true );
```

---

## 5. Canonical Docs

The `docs/` directory contains **570+ files** (including implementation history, proposals, and per-PR summaries). Use the list below to avoid rabbit holes.

### Always authoritative (root level)

| File | Purpose |
|---|---|
| `README.md` | User-facing overview, installation, feature list, shortcode reference |
| `CHANGELOG.md` | Version history, breaking changes |
| `CONTRIBUTING.md` | How to contribute, coding standards, PR process |
| `SECURITY.md` | Vulnerability disclosure policy |

### Primary reference docs

| Path | Purpose |
|---|---|
| `docs/DOCUMENTATION_INDEX.md` | Master index — start here when navigating `docs/` |
| `docs/QUICK_REFERENCE.md` | Fast look-up for common tasks |
| `docs/architecture/ARCHITECTURE.md` | Architectural overview and module boundaries |
| `docs/architecture/README.md` | Architecture doc index |
| `docs/development/README.md` | Development environment setup |
| `docs/getting-started/README.md` | Installation and first-run guide |
| `docs/getting-started/QUICK_START_5_MINUTES.md` | Five-minute quickstart |
| `docs/BUILD.md` | Comprehensive build system reference |
| `docs/DEVELOPER_HOOKS_REFERENCE.md` | All `do_action` / `apply_filters` extension points |
| `docs/EXTERNAL_SERVICES.md` | Third-party APIs and their credentials |
| `docs/SECURITY.md` (in docs/) | Security implementation details |

### Feature-specific references

| Path | Purpose |
|---|---|
| `docs/integrations/` | Third-party integration guides |
| `docs/features/agent-skills.md` | Agent Skills end-to-end reference (Phases 1–4: bundled skills, remote catalogues, progressive disclosure, skill packs) |
| `docs/reference/` | REST API, hooks, settings reference |
| `docs/developer/` | How-to guides for specific workflows |
| `docs/operations/production-hardening-guide.md` | Production security hardening checklist (WAF, OAuth, DICOM) |
| `docs/developer/api-key-encryption.md` | API key encrypted-at-rest storage |
| `docs/reference/admin/security-settings.md` | Security admin settings reference |
| `lib/core/README.md` | Framework-agnostic nvoos/core engine overview |
| `docs/project/architecture-decisions/ADR_001_module_boundaries.md` | Architecture Decision Record #1 — module boundaries |

### Docs to skip (unless debugging history)

- `docs/implementation-history/` — per-PR summaries, now merged
- `docs/archive/` — superseded documents
- `docs/proposals/` — not-yet-implemented ideas
- `docs/implementation-summaries/` — snapshot notes
- Any file matching `*_IMPLEMENTATION_SUMMARY.md`, `*_COMPLETE.md`, `*_SUMMARY.md`

---

## 6. AI Agent Coordination

This repository is developed with multiple AI coding agents. Each agent has a dedicated context file that tells it how to behave in this codebase.

| Agent | Context File | Purpose |
|-------|-------------|---------|
| **Claude Code** | [`CLAUDE.md`](CLAUDE.md) | Loaded automatically every turn — naming, security, architecture patterns |
| **GitHub Copilot** | [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | Repo-level instructions for Copilot chat and completions |
| **OpenAI Codex** | [`.codex/startup.sh`](.codex/startup.sh) | Codex sandbox bootstrap script |
| **BMAD Agents** | [`.bmad/agents/*.yaml`](.bmad/agents/) | Six specialized roles for the GSD × BMAD workflow |
| **Zed Agent Skills** | [`.agents/skills/*.md`](.agents/skills/) | 21 coding-time WordPress development skills auto-discovered by Zed |

The full agent inventory, capabilities, and context-loading strategy are documented in [`AGENTS.md`](AGENTS.md).

### When to update agent context files

- **New naming convention or security rule** → update `CLAUDE.md` + `.github/copilot-instructions.md` + `.context/conventions.md`
- **New tool, hook, or REST endpoint** → update `CLAUDE.md` (architecture patterns section)
- **New BMAD agent or workflow change** → update `.bmad/agents/` YAML + `AGENTS.md`
- **New subsystem context** → add to `.context/` and reference from `AGENTS.md`
- **New DSpark feature or orchestration preset** → update `CLAUDE.md` (architecture patterns), `MAINTAINER_MAP.md` (directory map), `includes/services/README.md` (public surface)

---

## Quick orientation checklist for new maintainers

- [ ] `composer install && npm install` to get all tooling
- [ ] `composer run test:install` once to configure the WordPress PHPUnit environment
- [ ] Read `README.md` to understand what the plugin does for end users
- [ ] Read `docs/architecture/ARCHITECTURE.md` to understand module boundaries
- [ ] Skim `includes/bootstrap/loader.php` — it loads every class in the right order
- [ ] Skim `includes/class-wp-mcp-ai-plugin.php` — it wires the DI container
- [ ] Run `composer run lint:base && composer run test` to confirm a green baseline
- [ ] Read [`AGENTS.md`](AGENTS.md) to understand AI-assisted development workflows
- [ ] Add `define('WP_DEBUG', true);` in `wp-config.php` for verbose error logging
