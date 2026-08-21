# oOS Cross-Platform Extraction — Related Proposals Index

> **Auto-generated:** 2026-07-01 by multi-agent architecture review (manually extended 2026-08-14 with the Media Worker thread)
> **Master document:** [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md)
> **Current state:** [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md)

This index maps every proposal in `docs/project/proposals/` that is directly relevant to the cross-platform extraction, Laravel-scale deployment, Graphify ecosystem, and media worker sidecar threads.

---

## 1. Core Extraction Thread

Documents that define the Hexagonal Architecture extraction (`lib/core/` + platform adapters):

| Document | Status | Role |
|---|---|---|
| [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md) | ✅ Phases 0-2 complete, Phase 3 at 22% | **Master vision** — Hexagonal Architecture, 9 domain contracts, Strangler Fig migration |
| [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md) | ✅ Live (refreshed 2026-07-01) | **Current state** — What's done, what remains, 4 new contracts proposed |
| [`UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`](./UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md) | ✅ Implemented (P0-P7) | Tool authoring rules — canonical envelope, two-gate sanitise, data contracts, deprecated aliases |
| [`phase-2-bulk-tool-audit.md`](./phase-2-bulk-tool-audit.md) | ✅ Complete | Identifies 85 unbounded query sites across 57 files — used for tool migration planning |
| [`nvoos-base-restructuring-roadmap.md`](./nvoos-base-restructuring-roadmap.md) | ✅ Phase 0 complete, Phases 1-5 pending | Restructures the monolith into Graphify-centric core + addons |

---

## 2. Laravel-Scale Deployment Thread

Documents that define production-scale deployment on Laravel Octane:

| Document | Status | Role |
|---|---|---|
| [`laravel-scale-deployment-architecture.md`](./laravel-scale-deployment-architecture.md) | 📋 Proposal (2026-07-01) | **Master plan** — Octane, Horizon, Reverb, pgvector, Federation, Mesh Router |
| [`fastapi-porting-implementation-plan.md`](./fastapi-porting-implementation-plan.md) | 📋 Proposal (2026-06-25) | **Alternative architecture** — Python/FastAPI re-implementation with same Hexagonal pattern |
| [`PLAYWRIGHT_SERVICE_IMPLEMENTATION.md`](./PLAYWRIGHT_SERVICE_IMPLEMENTATION.md) | ✅ Reference implementation | **Companion microservice pattern** — Standalone Node.js service for browser automation |
| [`schedule-anything-saas-proposal.md`](./schedule-anything-saas-proposal.md) | ✅ Implemented | **Existing SaaS pattern** — Stripe-integrated booking platform, relevant as a scale reference |

---

## 3. Graphify Ecosystem Thread

Documents that define the standalone knowledge graph plugins:

| Document | Status | Role |
|---|---|---|
| [`graphify-core-implementation-spec.md`](./graphify-core-implementation-spec.md) | ✅ Specification | **Product spec** — Core + addon architecture, dependency chain, API surface |
| [`nvoos-graphify-core-buildout-plan.md`](./nvoos-graphify-core-buildout-plan.md) | ✅ All 9 phases complete | **Buildout plan** — PSR-4 migration, namespace changes, hook prefix migration |
| [`nvoos-graphify-release-readiness.md`](./nvoos-graphify-release-readiness.md) | 📋 Audit | **Release checklist** — Plugin Check compliance, escaping errors, nonce verification, test suite |
| [`nvoos-graphify-next-steps-plan.md`](./nvoos-graphify-next-steps-plan.md) | 📋 Plan | **Actionable next steps** — Priority 1-5 remaining work after Phase 0 |

---

## 4. Federation & Mesh Thread

Documents that define peer discovery, routing, and cross-site communication:

| Document | Status | Role |
|---|---|---|
| [`mcp-server-directory-addon-proposal.md`](./mcp-server-directory-addon-proposal.md) | 📋 Proposal | **Peer discovery** — MCP server directory for federation peer registration and discovery |
| [`CHAT_PRELOAD_CONNECTIONS_ENHANCEMENT.md`](./CHAT_PRELOAD_CONNECTIONS_ENHANCEMENT.md) | 📋 Proposal | **Connection preloading** — Eliminates mandatory `list_connections` call, reduces mesh latency |

---

## 5. Storage & Content Thread

Documents that define content storage alternatives and pipelines:

| Document | Status | Role |
|---|---|---|
| [`paper-store-architecture.md`](./paper-store-architecture.md) | ✅ Implemented (Pro) | **Flat-file storage** — Markdown+YAML, Git sync, alternative/companion to Graphify |
| [`schedule-result-delivery-pipeline.md`](./schedule-result-delivery-pipeline.md) | 📋 Proposal | **Async result delivery** — Multi-channel pipeline architecture (11 channels) |
| [`multi-channel-result-delivery-enhancement.md`](./multi-channel-result-delivery-enhancement.md) | 📋 Proposal | **Delivery UI** — Admin interface for the multi-channel pipeline |

---

## 6. Orchestration & Quality Thread

Documents that define orchestration enhancements and quality standards:

| Document | Status | Role |
|---|---|---|
| [`CONTEXT-1-INSPIRED-ORCHESTRATION-ENHANCEMENTS.md`](./CONTEXT-1-INSPIRED-ORCHESTRATION-ENHANCEMENTS.md) | 📋 Proposal | **RAG best practices** — Chroma Context-1, Stanford ACE, Anthropic Context Engineering applied to oOS |
| [`FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md`](./FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md) | 📋 Analysis | **Feature gap analysis** — Cross-functional requirements assessment |
| [`librechat-addon-implementation-proposal.md`](./librechat-addon-implementation-proposal.md) | 📋 Proposal | **Code interpreter + speech** — LibreChat integration for advanced AI capabilities |
| [`dietpi-pro-toolkit.md`](./dietpi-pro-toolkit.md) | ✅ Implemented | **Edge computing** — 19+ server management tools for Raspberry Pi/edge nodes in the mesh |

---

## 7. Media Worker Sidecar & Scale Thread

Documents that define the Node.js media worker sidecar, its multi-tenant shared-worker mode, and the DB/queue scale stances:

| Document | Status | Role |
|---|---|---|
| [`023-database-connection-pooling-stance.md`](./023-database-connection-pooling-stance.md) | ✅ Implemented (v1.1.55) | **DB/queue scale stance** — RabbitMQ gating, atomic concurrency slots, PDO persistence, Site Health |
| [`025-media-worker-cloud-deployment-security-implementation-plan.md`](./025-media-worker-cloud-deployment-security-implementation-plan.md) | ✅ Implemented (v1.1.55) | **Companion microservice hardening** — Cloudways Velocity deploy, SSRF guard, sandboxed Puppeteer, rate limiting |
| [`026-media-worker-multi-tenancy-sidecar-proposal.md`](./026-media-worker-multi-tenancy-sidecar-proposal.md) | ✅ Phase 1 merged (v2.4.0) | **Shared worker mode** — `SITE_TOKENS` per-site isolation, per-site rate limits, token rotation |
| [`027-media-worker-multi-tenancy-phase2-spec.md`](./027-media-worker-multi-tenancy-phase2-spec.md) | ✅ Implemented (PR #5868) | **Phase 2 spec** — per-site provider keys, usage counters, grouped temp TTLs, k6 load-test kit |
| [`028-media-worker-phase3-proposal.md`](./028-media-worker-phase3-proposal.md) | ✅ Implemented (v3.0.0, W6 flip deferred) | **Phase 3 scale** — multisite per-blog tokens, usage reporter, Redis rate-limit store, provider-keys hot-reload |
| [`031-media-worker-crawl4ai-integration-plan.md`](./031-media-worker-crawl4ai-integration-plan.md) | ✅ Phases 1–2 implemented (worker v3.2.0) | **Crawling in the worker** — native `/api/crawl/*` endpoints, Crawl4AI-compatible facade for the plugin's `run_crawl4ai_job` remote mode, optional full Crawl4AI sibling container |

---

## How These Threads Connect

```
┌─────────────────────────────────────────────────────────────┐
│            CROSS-PLATFORM EXTRACTION (Thread 1)              │
│  lib/core/ + adapters — the framework-agnostic engine        │
│  [extraction-architecture] [gap-analysis] [unix-theory]      │
│  [tool-audit] [base-restructuring]                           │
└──────────────────────┬──────────────────────────────────────┘
                       │
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
┌───────────────┐ ┌──────────┐ ┌──────────────────────┐
│ LARAVEL SCALE │ │ FASTAPI  │ │ GRAPHIFY ECOSYSTEM   │
│ (Thread 2)    │ │ (Thread 2│ │ (Thread 3)           │
│               │ │  alt)    │ │                      │
│ [laravel-     │ │ [fastapi-│ │ [core-spec]          │
│  scale-deploy]│ │  porting]│ │ [core-buildout]      │
│ [playwright]  │ │          │ │ [release-readiness]  │
│ [saas-sched]  │ │          │ │ [next-steps]         │
└───────┬───────┘ └──────────┘ └──────────┬───────────┘
        │                                 │
        └──────────┬──────────────────────┘
                   │
        ┌──────────┴──────────┐
        ▼                     ▼
┌───────────────┐    ┌────────────────────┐
│  FEDERATION   │    │  STORAGE & CONTENT │
│  (Thread 4)   │    │  (Thread 5)        │
│               │    │                    │
│ [mcp-direct]  │    │ [paper-store]      │
│ [preload-conn]│    │ [result-delivery]  │
│               │    │ [multi-channel]    │
└───────┬───────┘    └──────────┬─────────┘
        │                       │
        └──────────┬────────────┘
                   │
                   ▼
        ┌────────────────────┐
        │ ORCHESTRATION      │
        │ & QUALITY          │
        │ (Thread 6)         │
        │                    │
        │ [context-1]        │
        │ [feature-gap]      │
        │ [librechat]        │
        │ [dietpi]           │
        └────────────────────┘
```

---

## Status Key

| Symbol | Meaning |
|---|---|
| ✅ | Complete / Implemented |
| 📋 | Proposal — awaiting review/prioritisation |
| 📋 Audit | Audit — identifies issues, may or may not become a proposal |
