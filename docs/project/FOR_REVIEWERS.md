# NV oOS — Reviewer Onboarding

> **Target audience:** External reviewers, auditors, security researchers, and Codeable experts evaluating this plugin.
> **Goal:** Answer every common question within the first 10 minutes of looking at the repo.

---

## 1. What is this project? (TL;DR)

NV oOS (Open Operator System) is a WordPress plugin that turns a WordPress site into an AI-powered assistant. It connects to 15 language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, Z.AI, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama, Flowhub) and exposes ~300+ tools the AI can use — everything from creating posts to managing WooCommerce products to running shell commands (gated behind an opt-in constant).

Architecturally, the project has undergone a major framework extraction: the AI orchestration engine (`lib/core/`) is now a framework-agnostic Hexagonal Architecture package (`nvoos/core`) with 32 domain contracts, 21 WordPress adapters, and 109+ migrated tools. The plugin also includes the **OKF v0.1** (Open Knowledge Format) engine for curated deterministic knowledge, and the **Meta-Harness** trace optimization system.

The repo is a **monorepo** containing:
- The **base plugin** (GPLv3, ships to WordPress.org) — `mcp-ai-wpoos.php` + `includes/`
- A **Pro addon** (commercial/proprietary) — `addons/pro/`
- **26 additional addons** (various licenses) — `addons/*/` (including Fleet Operator, Media Worker v3.2.0, Checkout API v0.1.0)
- The **extracted AI engine** (framework-agnostic, Hexagonal Architecture) — `lib/core/`
- A **standalone Core plugin** (lightweight MCP server, v1.0.0) — `core/`
- A **Cloudflare Worker** (SaaS backend, not a WP plugin) — `addons/cloud-worker/`

**Current version:** 1.1.66 (August 2026)
**Tested up to:** WordPress 6.10
**Total PHP files:** ~5,000 (base + pro + addons + lib/core; excl. vendor/node_modules)
**Total tools:** ~1,559 (~303 base + ~1,256 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

---

## 2. Quick Answers to Common Questions

| Question | Answer |
|---|---|
| Is this your first WordPress plugin? | Yes |
| Was this written by AI? | Heavily. Multi-agent pipeline with human review. See [AI-Assisted Development](../developer/AI_ASSISTED_DEVELOPMENT.md). |
| Is it safe to run on production? | The base plugin passes all WP.org guidelines. The April 2026 security audit found 0 Critical, 5 High (3 Fixed, 2 Partially Fixed — both in addons). May–June 2026 hardening resolved 1 Critical + 5 Warnings. July–August 2026 Phase 3 hardening added 10 security classes, CORS posture, error-verbosity control, auth brute-force detection, and asset-fingerprinting prevention. See [Security Posture](../operations/security/SECURITY_POSTURE.md). |
| What PHP version is required? | **Base: 7.4+** · **Pro addon: 8.1+** (due to npm packages like sharp/fluent-ffmpeg) · **lib/core: 8.1+** |
| What WP version? | 6.0+, tested up to 6.10 |
| Is there a Pro/freemium model? | Yes. Base is fully functional GPLv3. Pro addon adds ~1,256 advanced tools (commercial license). Base never gates features behind a license check. |
| Was it rejected from WordPress.org? | Yes — the 90-day window expired before all fixes were completed. All rejection reasons are now resolved. See [Compliance Traceability](../operations/compliance/TRACEABILITY.md). |
| Can it be resubmitted to .org? | Possibly, but the window has closed. The author is seeking professional review before deciding next steps. |

---

## 3. Repository Map (What Matters for Review)

### Base plugin (critical path — this is what .org would review)
```
mcp-ai-wpoos.php              ← Main entry point (v1.1.43)
includes/                     ← 1,060 PHP files
├── class-wp-mcp-ai-plugin.php ← Kernel / DI container / singleton
├── class-wp-mcp-ai-rest.php   ← REST route registration (151 calls, 36 files)
├── class-wp-mcp-ai-tool-registry.php ← Central tool registry
├── tools/                     ← ~303 base tool classes (~1,559 total registered through the singleton registry)
├── admin/                     ← Admin UI, settings, dashboards
├── rest/                      ← REST controllers (chat, MCP, webhooks)
├── assistants/                ← Assistant CPT & CCT management
├── bootstrap/                 ← Constants, helpers, lifecycle
├── agents/                    ← Agent skills & registration
├── security/                  ← 10 security classes (request guard, posture, destructive ops gate, URL guard, concurrency guard, cost tracker, API key store, CSP headers, audit logger, security posture)
├── okf/                       ← OKF v0.1 engine (parser, reader, writer, 6 MCP tools)
├── bridge/                    ← WordPress adapters for lib/core domain contracts
└── ... (42 subdirectories total)
```

### Pro addon (commercial — not shipped to .org)
```
addons/pro/
├── mcp-ai-wpoos-pro.php       ← Pro entry point (modular registry)
├── includes/tools/            ← ~1,232 pro tool classes
├── includes/admin/            ← Pro dashboards, settings
├── includes/blocks/           ← ~30 React-based Pro SPAs
└── config/spa-manifests/      ← Per-toolkit JSON manifests
```

### Extracted AI engine (framework-agnostic — lib/core/)
```
lib/core/                      ← 2,070 PHP files, Hexagonal Architecture
├── src/Domain/Contract/       ← 32 domain contracts (ports)
├── src/Application/           ← ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry
├── src/Infrastructure/        ← 12 AI provider clients, SSE handler, cost calculator
├── src/Tools/                 ← 43 framework-agnostic tool classes
└── composer.json              ← Composer-installable as nvoos/core (PHP 8.1+)
```
**Important:** `lib/core/` is the extracted AI orchestration engine — it uses Hexagonal Architecture (Ports & Adapters) and is framework-agnostic. It has its own test suite and PHPStan linting. The WordPress bindings live in `includes/bridge/`. This is NOT the same as the standalone `core/` plugin below.

### Standalone Core plugin (separate product, lightweight — core/)
```
core/
├── mcp-ai-wpoos-core.php      ← Standalone MCP server framework
├── includes/                  ← Baseline tools (posts, media, users, taxonomies)
└── README.md                  ← SEPARATE plugin — shares NO code with main plugin or lib/core/
```
**Important:** `core/` is an independent plugin with its own MCP server implementation. It is a lighter-weight alternative for users who only want basic MCP tool exposure — not the full AI assistant. Do not confuse it with `lib/core/`.

---

## 4. Addon Inventory (Production vs Experimental)

The monorepo contains **26 addon directories** under `addons/` (27 entries in the [ADDON_INVENTORY.md](ADDON_INVENTORY.md) inventory, including the standalone `core/` plugin). See the inventory for full details including license, version, and dependencies.

### Production (actively maintained — review priority)

| Addon | Version | License | Notes |
|---|---|---|---|
| **Pro** | (live) | Proprietary | 30 toolkits, ~1,232 tools, commercial license. E-commerce, CRM, document generation, media, healthcare, legal, scheduling, analytics. |
| **Graphify** | 0.6.0 | Proprietary | Knowledge graph builder. Entities, relationships, WooCommerce/Wikidata/RSS/SPARQL/CSV drivers. |
| **Chat SPA** | 0.6.0 | GPLv3 | React chat surface (Vercel AI SDK). Shortcode + Gutenberg block. |
| **Docs Hub** | 0.3.9 | GPLv3 | React SPA documentation browser (GitBook-style Markdown rendering). |
| **Algorave** | 1.0.7 | AGPL-3.0 | Live-coding music. Tone.js/Strudel, MIDI export, audio visualization. F-AI-01 accepted with rationale (raw-eval gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` + `edit_posts`; warning UI added). |
| **Fantasy Football** | 0.1.0 | Proprietary | ESPN/Yahoo Fantasy Sports API. Team management, player research, trade analysis, AI logo generation. |
| **Embedded** | 0.2.0 | Proprietary | Server-side llama.cpp GGUF inference + client-side WebLLM/WebGPU + P2P WebChat (WebRTC). Voice tool calling, OpenMed healthcare tools, MCP abilities. |
| **Canvas** | 0.1.0 | Proprietary | Platform-specific Tesseract PDF OCR binaries. |
| **Cornerstone3D** | 0.1.0 | Proprietary | Pre-built Cornerstone3D ESM bundles for DICOM medical imaging. |
| **SaaS Controller** | 0.1.0 | Proprietary | Cloudflare Workers + D1 + KV + AI Gateway deployment toolkit. One-click wizard, Plan/Apply, drift detector. |
| **Cloudways Dashboard** | 0.1.0 | GPLv3 | SaaS operator dashboard (Velzon-themed React SPA). Cloudways server + WP site management. |
| **Comic Reader** | 0.2.0 | GPLv3 | CBR/CBZ/CB7/CBT comic reader & AI-powered creator. React reading interface. |
| **Funiq Bridge** | 1.0.0 | GPLv3 | Payload CMS → WordPress bridge for Funiq React PWA. REST API, CPTs, taxonomies, React admin SPA. |
| **LibreChat** | 0.1.0 | GPLv3 | Sandboxed Python/JavaScript code interpreter, TTS/STT speech services, web search reranker. |
| **Fleet Operator** | 0.1.0 | GPLv3 | External-operator governance (Hermes or any MCP/A2A host). Scoped `op_` credentials with audience binding, expiry, rate limits, revocation; MCP `tools/list` scoping + `tools/call` enforcement; admin page, WP-CLI, config generator, skills pack. |
| **Media Worker** | 3.2.0 | GPLv3 | Docker-based Node.js sidecar. 11 route handlers (image, video, pdf, ocr, email, social, code, data, document, browser, workflow) plus native `/api/crawl/*` endpoints (single-URL Markdown, batched crawling, link scans) and a Crawl4AI-compatible facade. Queue module with concurrent processing. Multi-tenant shared worker mode since v2.4.0 (`SITE_TOKENS` per-site isolation, per-site rate limits); Phase 2 per-site provider keys (`SITE_PROVIDER_KEYS`) + usage counters + grouped temp TTLs; Phase 3 scale features (opt-in Redis rate-limit store, provider-keys file hot-reload). Timing-safe token auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet. Worker routing with local fallbacks. |

### Experimental (works but limited testing)

| Addon | Version | License | Notes |
|---|---|---|---|
| **Page Agent** | 0.1.0 | GPLv3 | Alibaba Page Agent (MIT) browser copilot. Natural-language page control — click, type, navigate. Client-side only. |
| **Schedule Anything** | 0.1.0 | Proprietary | Full SaaS booking platform with Stripe, calendar management, multi-tenant architecture. |
| **Schedule Anything SPA** | 0.1.0 | Proprietary | React SPA frontend (Vite + Tailwind) for Schedule Anything. |
| **Crocoblock DS** | 0.1.0 | GPLv3 | Design token system. 55+ CSS tokens, admin editor, DTCG export, a11y tokens. |

### Blueprint-generated (minimal manual review)

| Addon | Version | License | Notes |
|---|---|---|---|
| Canvas Toolkit | 0.2.0 | GPLv3 | SPA blueprint-generated canvas surface. |
| Document Editor | 0.2.0 | GPLv3 | SPA blueprint-generated document editing surface. |
| Media Studio | 0.1.0 | GPLv3 | SPA blueprint-generated media management (zoom/pan/drawing). |
| Toolkit Shell | 0.2.0 | GPLv3 | Manifest-driven React SPA shell (CRM, calendar, financial, legal, ecommerce toolkits). |

### Non-WordPress components (reference only)

| Component | Directory | Type | Description |
|---|---|---|---|
| Cloud Worker | `addons/cloud-worker/` | Cloudflare Worker | SaaS backend. Inference proxy, Stripe billing, D1 ledger. Deployed independently. |
| Tenant Router | `addons/tenant-router/` | Cloudflare Worker | Edge-level routing for Schedule Anything multi-tenant SaaS (Cloudflare KV + REST fallback). |

---

## 5. Known Issues (What Needs Attention)

The April 2026 security audit ([SECURITY_AUDIT_2026_04.md](../operations/compliance/SECURITY_AUDIT_2026_04.md)) found **50 findings: 0 Critical, 5 High (3 Fixed + 2 Partially Fixed), 14 Medium (all Fixed), 21 Low (19 Fixed), 10 Informational.** Additional hardening in May–June 2026 (v1.1.15–v1.1.27) resolved 1 Critical + 5 Warnings from code review. **Phase 3 operational security hardening** (July–August 2026, v1.1.38–v1.1.50) added 3 new security classes (audit logger, CSP headers, request guard enhancements), CORS posture signals, error-verbosity control, auth brute-force detection, body-size enforcement, asset-fingerprinting prevention, and OAuth hardening.

### Closed since the v1.1.52 snapshot (F-AUTHZ-01, F-AI-01, F-CMP-04 — closed in v1.1.65, 2026-08-28):
| ID | Severity | Status | What |
|---|---|---|---|
| F-AUTHZ-01 | High | ✅ Fixed | Webhook routes with `__return_true` permission callbacks — 4 fixed via signature verification (Telegram, agent-card ×2, Google Chat); final sweep added inline justification comments to every remaining legitimately-public route (Twitter CRC GET ×2, WhatsApp verify GET ×2, Messenger verify GET, Telegram Mini App page/validate ×4). |
| F-AI-01 | High | ⏭️ Accepted | Algorave live-coding `new Function()` sandboxing — accepted with rationale: gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` (default `false`) + `edit_posts`; Strudel engine is the safe default; added raw-eval warning banner + one-time per-session confirm-on-execute. Sandboxed iframe mandatory only if the addon ships on WP.org or Guest Access becomes public. |
| F-LINT-02 | Low | ✅ Resolved | Pro tree PHPCS blanket exclusion removed. 93% error reduction (1,143 → 82). Remaining errors are parse-error files (8 files) and naming conventions (addons use `NVOOS_*` naming). |
| F-CMP-04 | Low | ✅ Fixed | Minified JS without source maps — final sweep confirmed every plugin-authored bundle has a sibling map (page-agent esbuild emits external maps; tma-markdown regenerated). Third-party vendor bundles exempt per the R-Q-06 Chart.js precedent. |
| R-T-01 | — | ✅ Resolved | PHPCS re-enabled on `addons/pro/` via PRs #5070, #5078. |

### Recently fixed (Phase 3 hardening, July–August 2026):
- **11 HIGH/P0 security vulnerabilities** from July 2026 compliance review — all fixed (audit logger REST hook, destructive ops confirmation gate, credential token expiry, OAuth hardening, error verbosity control, exception guard, SSE connection limits, JSON depth enforcement, body size enforcement, asset version stripping, CORS posture)
- Security classes expanded from 7 → **10** (added audit logger, CSP headers, new posture signals)

See [SECURITY_POSTURE.md](../operations/security/SECURITY_POSTURE.md) for the full current state.

---

## 6. How to Set Up a Dev Environment (5 minutes)

```bash
# Option 1: Docker (recommended)
# Use --depth 1 for a fast shallow clone (repo is very large at ~5,000 PHP files)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
docker compose up -d
# → http://localhost:8000  (admin: admin / password)

# Option 2: Codex script
bin/codex-startup.sh
# → http://localhost:8000  (admin: admin / password)

# Install dependencies and run quality checks
composer install
composer run lint          # WPCS on the whole tree
composer run lint:base     # WPCS on the WP.org-shippable subset
composer run lint:compat   # PHP compatibility check (7.4–8.3)
composer run test          # PHPUnit suite (base plugin)

# lib/core quality checks (framework-agnostic engine)
cd lib/core
composer install
composer run lint          # PHPStan analysis
composer run test          # lib/core unit tests

# Plugin-check (simulates .org review)
composer run build         # Creates the distributable ZIP
# Then run `wp plugin-check` against the ZIP
```

---

## 7. Scoping Advice (Limited Budget)

If you have limited budget for a review, focus on this order:

### Phase 1: High-priority (~4-6 hours)
1. **Base plugin security surface** — REST endpoints (151 route registrations across 36 files), tool permission callbacks, nonce coverage, input sanitization in `includes/tools/`, output escaping in `includes/rest/`
2. **Authentication** — Credential storage (encrypted API keys), token generation, guest token flow. Now with 10 security classes including audit logger, CSP headers, and request guard.
3. **External service exposure** — 45 base + 3 Pro external services documented in `docs/reference/EXTERNAL_SERVICES.md`

### Phase 2: Architecture review (~3-4 hours)
4. **Plugin architecture** — DI container usage, class loading, lifecycle hooks (60+), singleton patterns
5. **Base/Pro separation** — Verify no pro feature gating in base plugin
6. **Tool registry** — How ~1,559 tools are registered and discovered
7. **lib/core extraction** — Hexagonal Architecture (32 domain contracts, 21 WordPress adapters), agentic loop, provider routing, 109+ migrated tools. `includes/bridge/` adapters.

### Phase 3: Deep dives (~4-6 hours, if budget allows)
8. **Pro addon security** — Shell-exec tools (gated), file operations, npm dependencies
9. **OKF engine** — `includes/okf/` knowledge format parsing/writing, 6 MCP tools
10. **SPA addons** — React bundle security, data flow from SPAs to REST
11. **Graphify** — SQL preparation, graph data ingestion
12. **Algorave** — Live-coding sandbox, `new Function()` usage

### What to skip on a tight budget:
- The standalone `core/` plugin (separate product, v1.0.0, not related to lib/core)
- Blueprint-generated SPAs (Canvas Toolkit, Document Editor, Media Studio, Toolkit Shell)
- The Cloud Worker (not a WP plugin, deployed independently)
- Non-WP components (AI Platform, Tenant Router)
- ISO 27001 / SOC 2 / HIPAA compliance docs (aspirational, not certification)

---

## 8. Key Files to Read First

| Order | File | Why |
|---|---|---|
| 1 | `mcp-ai-wpoos.php` | Main plugin entry point, see how it boots |
| 2 | `includes/class-wp-mcp-ai-plugin.php` | Kernel — initializes all subsystems |
| 3 | `includes/class-wp-mcp-ai-rest.php` | All REST route registrations |
| 4 | `includes/class-wp-mcp-ai-tool-registry.php` | How tools are registered and executed |
| 5 | `includes/tools/class-wp-mcp-ai-tool-base.php` | Base tool class — capability checks, execute contract |
| 6 | `includes/security/README.md` | Security infrastructure overview (10 classes) |
| 7 | `includes/okf/README.md` | OKF v0.1 knowledge format engine |
| 8 | `lib/core/README.md` | Extracted AI engine — Hexagonal Architecture, domain contracts, adapters |
| 9 | `includes/bridge/README.md` | WordPress adapters for lib/core contracts |
| 10 | `CLAUDE.md` | Coding standards, tool patterns, security rules |
| 11 | `AGENTS.md` | Multi-agent coordination rules |
| 12 | `docs/operations/compliance/TRACEABILITY.md` | All .org compliance fixes |
| 13 | `docs/operations/compliance/SECURITY_AUDIT_2026_04.md` | April 2026 audit findings |
| 14 | `docs/operations/security/SECURITY_POSTURE.md` | Current security posture (including Phase 3 hardening) |
| 15 | `docs/project/ADDON_INVENTORY.md` | What each addon does and its status |
| 16 | `docs/features/security/user-restrictions.md` | User-restriction registry + admin/REST/CLI surfaces (v1.1.60) |
| 17 | `docs/user-guides/conversation-import.md` | Conversation import → transcript CCT (v1.1.60) |

---

## 9. How This Plugin Was Developed

This is an AI-assisted project. The development uses a structured multi-agent pipeline documented in `AGENTS.md` and `CLAUDE.md`. Every change goes through human review and approval before merging.

The project currently employs:
- **Claude Code** — primary coding agent (feature implementation, bug fixes, refactoring)
- **GitHub Copilot** — inline suggestions and code completion
- **OpenAI Codex** — prototyping and exploration
- **BMAD Agents** — 6 internal workflow agents (`.bmad/agents/*.yaml`) running inside NV oOS assistants
- **Zed Agent Skills** — 52 coding-time skills (`.agents/skills/`: 20 wp-* WordPress plugin development patterns + 31 design-* skills + mcp-ai-wpoos-plugin operational guide)

See [AI-Assisted Development](../developer/AI_ASSISTED_DEVELOPMENT.md) for full transparency about the tools, workflow, and what this means for code review.

---

## 10. Compliance Status at a Glance

| Framework | Status | Notes |
|---|---|---|
| WordPress.org Plugin Guidelines (18 rules) | ✅ 100% | All 35+ violations resolved across v1.1.1 → v1.1.22. Most recent re-audit: May 23, 2026. |
| `wp plugin-check` | ✅ Passes | Gating job in CI |
| PHPCS (base tree) | ✅ 0 errors, 0 warnings | 796 files |
| PHPCS (Pro tree) | ✅ ~82 errors | Down from 1,143 (93% reduction in May 2026). Out of .org scope. Remaining: 8 parse-error files + intentional `NVOOS_*` naming. |
| PHPStan (lib/core) | ✅ Clean | Framework-agnostic engine, level 8 analysis |
| Security audit (April 2026) | ✅ 0 Critical, 2 Partially Fixed High (addons only) | 3 of 5 Highs already Fixed. May–June 2026 hardening + July–August 2026 Phase 3 hardening added. |
| Phase 3 hardening (July–August 2026) | ✅ 11 HIGH/P0 fixed | 10 security classes, CORS, auth brute-force, error verbosity, asset fingerprinting, OAuth hardening |
| `composer audit` | ✅ Clean | Root + Pro + lib/core trees |
| `npm audit` | ✅ Clean (root) / ⏭️ Accepted (pro) | One dev-only accepted risk |
| ISO 27001 / SOC 2 / HIPAA | 📋 Aspirational documentation | Not a certification |

---

**Questions?** Start with this document, then drill into the specific files listed in Section 8. If you need a working install, the Docker environment spins up in under 5 minutes.
