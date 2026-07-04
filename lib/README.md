# oOS Core Extraction Library

## Purpose

Framework-agnostic AI orchestration engine extracted from the NV oOS WordPress plugin using **Hexagonal Architecture (Ports & Adapters)**. The same `ChatOrchestrator`, provider clients, and tools run on WordPress, Laravel, and CraftCMS — each platform provides adapter implementations of the domain contracts.

## Structure

```
lib/
├── core/                          # nvoos/core — Composer package (PHP 8.1+)
│   └── src/
│       ├── Domain/
│       │   ├── Contract/          # 9 ports (interfaces)
│       │   ├── Entity/            # 10 immutable value objects
│       │   ├── Error/             # 5 typed domain exceptions
│       │   └── Event/             # 8 PSR-14 domain events
│       ├── Application/
│       │   ├── Chat/              # ChatOrchestrator (agentic loop)
│       │   ├── Provider/          # ProviderRouter (12-provider routing)
│       │   ├── Tool/              # ToolRegistry (tool lifecycle)
│       │   └── Skill/             # SkillRegistry (SKILL.md discovery)
│       ├── Infrastructure/
│       │   ├── Provider/          # 14 provider client files (12 concrete)
│       │   ├── Streaming/         # SseHandler (RFC 6202)
│       │   └── Cost/              # CostCalculator (all providers)
│       └── Tool/                  # 43 framework-agnostic tool classes
│
├── wordpress-adapter/             # nvoos/wordpress-adapter (PHP 7.4+, GPL-3.0)
│   └── src/Adapter/               # 8 WordPress adapter implementations
│
├── laravel-adapter/               # nvoos/laravel-adapter (PHP 8.1+, MIT)
│   └── src/Adapter/               # 8 Laravel adapter implementations
│
└── craft-adapter/                 # nvoos/craft-adapter (PHP 8.1+, MIT)
    └── src/Adapter/               # 8 Craft CMS adapter implementations
```

## Status

- **Domain contracts:** 9 interfaces, 10 entities, 5 exceptions, 8 events ✅
- **Application services:** ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry ✅
- **Infrastructure:** 12 provider clients, SSE handler, cost calculator ✅
- **Tools:** 43 migrated (Tier 1 + select Tier 2) ✅
- **WordPress adapters:** All 8 implemented ✅
- **Laravel adapters:** All 8 implemented ✅ — awaiting Octane/Horizon/Reverb deployment wiring (see [`docs/project/proposals/laravel-scale-deployment-architecture.md`](../docs/project/proposals/laravel-scale-deployment-architecture.md))
- **Craft adapters:** All 8 implemented ✅
- **Feature flag:** `?engine=oos` activates the extracted engine ✅
- **Remaining:** ~152 base tools, ~810+ Pro tools, 4 new domain contracts for Laravel orchestrator integration

### New: Laravel-Scale Deployment (2026-07-01)

The Laravel adapter is the foundation for a **central Laravel Octane orchestrator** that handles all AI orchestration while WordPress/Graphify nodes serve as federated content + knowledge graph peers. This enables:

- **3-10x throughput** via Octane (FrankenPHP) vs PHP-FPM
- **Guaranteed async execution** via Redis Queue + Horizon
- **Bidirectional streaming** via Laravel Reverb WebSockets
- **Production-grade vector search** via PostgreSQL + pgvector with HNSW indexes
- **Intelligent federation routing** across all WordPress/Graphify nodes

For the full deployment architecture and 16-week migration plan, see [`docs/project/proposals/laravel-scale-deployment-architecture.md`](../docs/project/proposals/laravel-scale-deployment-architecture.md).

### Graphify Ecosystem Integration

The extraction enables a federated knowledge-graph architecture where the oOS Core queries multiple `nvoos-graphify` WordPress instances:

- **`nvoos-graphify`** (v1.0.0) — Standalone knowledge graph plugin with 14 tools, Cytoscape.js explorer, 18 remote-source drivers, REST API, and Memory Bridge
- **`nvoos-graphify-ai`** (v1.0.0-dev) — AI addon with 13 providers, streaming chat, RAG, and embeddings
- **`nvoos-graphify-ai-platform`** (v1.0.0-dev) — Platform addon with Agents, A2A, ACP, Blueprints, Federation, Harness, Measurement, Professions, Skills, and Slash Commands

See [`docs/project/proposals/nvoos-base-restructuring-roadmap.md`](../docs/project/proposals/nvoos-base-restructuring-roadmap.md) for the full ecosystem architecture.

## Monorepo Sync Workflows

Each package under `lib/` is synced to its own standalone GitHub repo via `git subtree split` on push to `main` or `alpha-working`:

| Source directory | Workflow | Target repo |
|---|---|---|
| `lib/core/` | `sync-nvoos-core.yml` | `nvdigitalsolutions/nvoos-core` |
| `lib/wordpress-adapter/` | `sync-nvoos-wordpress-adapter.yml` | `nvdigitalsolutions/nvoos-wordpress-adapter` |
| `lib/laravel-adapter/` | `sync-nvoos-laravel-adapter.yml` | `nvdigitalsolutions/nvoos-laravel-adapter` |
| `lib/craft-adapter/` | `sync-nvoos-craft-adapter.yml` | `nvdigitalsolutions/nvoos-craft-adapter` |

Each workflow requires a corresponding repo secret (`NVOOS_CORE_REPO_TOKEN`, `NVOOS_WORDPRESS_ADAPTER_REPO_TOKEN`, etc.) — a PAT with write access to the target repo.

## Also Load

- [`docs/proposals/cross-platform-extraction-architecture.md`](../docs/proposals/cross-platform-extraction-architecture.md) — full proposal
- [`.context/conventions.md`](../.context/conventions.md) — naming, style
- [`includes/bootstrap/oos-bridge.php`](../includes/bootstrap/oos-bridge.php) — WordPress DI wiring
