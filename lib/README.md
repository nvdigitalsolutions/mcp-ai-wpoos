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
└── wordpress-adapter/             # nvoos/wordpress-adapter (PHP 7.4+)
    └── src/Adapter/               # 8 WordPress adapter implementations
```

## Status

- **Domain contracts:** 9 interfaces, 10 entities, 5 exceptions, 8 events ✅
- **Application services:** ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry ✅
- **Infrastructure:** 12 provider clients, SSE handler, cost calculator ✅
- **Tools:** 43 migrated (Tier 1 + select Tier 2) ✅
- **WordPress adapters:** All 8 implemented ✅
- **Feature flag:** `?engine=oos` activates the extracted engine ✅
- **Remaining:** ~152 tools, Laravel adapter, Craft adapter

## Also Load

- [`docs/proposals/cross-platform-extraction-architecture.md`](../docs/proposals/cross-platform-extraction-architecture.md) — full proposal
- [`.context/conventions.md`](../.context/conventions.md) — naming, style
- [`includes/bootstrap/oos-bridge.php`](../includes/bootstrap/oos-bridge.php) — WordPress DI wiring
