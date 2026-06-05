# NV oOS — Reviewer Onboarding

> **Target audience:** External reviewers, auditors, security researchers, and Codeable experts evaluating this plugin.
> **Goal:** Answer every common question within the first 10 minutes of looking at the repo.

---

## 1. What is this project? (TL;DR)

NV oOS (Open Operator System) is a WordPress plugin that turns a WordPress site into an AI-powered assistant. It connects to 13 language-model providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama) and exposes ~250 tools the AI can use — everything from creating posts to managing WooCommerce products to running shell commands (gated behind an opt-in constant).

The repo is a **monorepo** containing:
- The **base plugin** (GPLv3, ships to WordPress.org) — `mcp-ai-wpoos.php` + `includes/`
- A **Pro addon** (commercial/proprietary) — `addons/pro/`
- **15 additional addons** (various licenses) — `addons/*/`
- A **Cloudflare Worker** (SaaS backend, not a WP plugin) — `addons/cloud-worker/`
- A **separate "Core" plugin** (lightweight MCP framework, v1.0.0) — `core/`

**Current version:** 1.1.27 (June 2026)
**Total PHP files:** ~3,000 (base + pro + addons)  
**Total tools:** ~960 (~195 base + ~765 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)

---

## 2. Quick Answers to Common Questions

| Question | Answer |
|---|---|
| Is this your first WordPress plugin? | Yes |
| Was this written by AI? | Heavily. Multi-agent pipeline with human review. See [AI-Assisted Development](development/AI_ASSISTED_DEVELOPMENT.md). |
| Is it safe to run on production? | The base plugin passes all WP.org guidelines. The April 2026 security audit found 0 Critical, 5 High (3 Fixed, 2 Partially Fixed — both in addons). May 2026 hardening resolved an additional 1 Critical + 5 Warnings. See [Security Posture](SECURITY_POSTURE.md). |
| What PHP version is required? | **Base: 7.4+** · **Pro addon: 8.1+** (due to npm packages like sharp/fluent-ffmpeg) |
| What WP version? | 6.0+, tested up to 6.9 |
| Is there a Pro/freemium model? | Yes. Base is fully functional GPLv3. Pro addon adds ~584 advanced tools (commercial license). Base never gates features behind a license check. |
| Was it rejected from WordPress.org? | Yes — the 90-day window expired before all fixes were completed. All rejection reasons are now resolved. See [Compliance Traceability](compliance/TRACEABILITY.md). |
| Can it be resubmitted to .org? | Possibly, but the window has closed. The author is seeking professional review before deciding next steps. |

---

## 3. Repository Map (What Matters for Review)

### Base plugin (critical path — this is what .org would review)
```
mcp-ai-wpoos.php              ← Main entry point (v1.1.27)
includes/
├── class-wp-mcp-ai-plugin.php ← Kernel / DI container / singleton
├── class-wp-mcp-ai-rest.php   ← ~190 REST endpoints
├── class-wp-mcp-ai-tool-registry.php ← Central tool registry
├── tools/                     ← ~231 base tool classes
├── admin/                     ← Admin UI, settings, dashboards
├── rest/                      ← REST controllers (chat, MCP, webhooks)
├── assistants/                ← Assistant CPT & CCT management
├── bootstrap/                 ← Constants, helpers, lifecycle
├── agents/                    ← Agent skills & registration
├── security/                  ← Encryption, audit, rate-limiting
└── ... (38 subdirectories total)
```

### Pro addon (commercial — not shipped to .org)
```
addons/pro/
├── mcp-ai-wpoos-pro.php       ← Pro entry point
├── includes/tools/            ← ~584 pro tool classes
├── includes/admin/            ← Pro dashboards, settings
├── includes/blocks/           ← ~30 React-based Pro SPAs
└── config/spa-manifests/      ← Per-toolkit JSON manifests
```

### Core plugin (separate, lightweight — v1.0.0)
```
core/
├── mcp-ai-wpoos-core.php      ← Standalone MCP server framework
├── includes/                  ← Baseline tools (posts, media, users, taxonomies)
└── README.md                  ← This is a SEPARATE plugin, not a dependency
```
**Important:** `core/` is an independent plugin with its own MCP server implementation. It shares NO code with the main plugin. It's a lighter-weight alternative for users who only want basic MCP tool exposure — not the full AI assistant.

---

## 4. Addon Inventory (Production vs Experimental)

See [ADDON_INVENTORY.md](ADDON_INVENTORY.md) for full details.

**Actively maintained (review priority):**
| Addon | Version | License | Notes |
|---|---|---|---|
| Pro | (live) | Proprietary | 30 toolkits, ~584 tools, commercial |
| Graphify | 0.6.0 | Proprietary | Knowledge graph builder |
| Chat SPA | 0.6.0 | GPLv3 | React chat surface |
| Docs Hub | 0.3.9 | GPLv3 | Documentation browser SPA |
| Algorave | 1.0.7 | AGPL-3.0 | Live-coding music (⚠️ has open High finding) |

**Experimental / blueprint-generated (lower priority):**
| Addon | Version | Notes |
|---|---|---|
| Canvas Toolkit | 0.2.0 | SPA blueprint-generated |
| Document Editor | 0.2.0 | SPA blueprint-generated |
| Media Studio | 0.1.0 | SPA blueprint-generated |
| Toolkit Shell | 0.2.0 | SPA blueprint-generated |
| Cloud Worker | — | Not a WP plugin (Cloudflare Worker) |

---

## 5. Known Issues (What Needs Attention)

The April 2026 security audit ([SECURITY_AUDIT_2026_04.md](compliance/SECURITY_AUDIT_2026_04.md)) found **50 findings: 0 Critical, 5 High (3 Fixed + 2 Partially Fixed), 14 Medium (all Fixed), 21 Low (16 Fixed), 10 Informational.** Additional hardening in May–June 2026 (v1.1.15–v1.1.27) resolved 1 Critical + 5 Warnings from code review.

### Still open / partially fixed (as of v1.1.27):
| ID | Severity | Status | What |
|---|---|---|---|
| F-AUTHZ-01 | High | 🟡 Partial | Webhook routes with `__return_true` permission callbacks — 4 fixed, remaining are legitimately public per webhook protocol |
| F-AI-01 | High | 🟡 Partial | Algorave live-coding `new Function()` sandboxing |
| F-LINT-02 | Low | ✅ Resolved | Pro tree PHPCS blanket exclusion removed. 93% error reduction (1,143 → 82). Remaining errors are parse-error files (8 files) and naming conventions (addons use `NVOOS_*` naming). |
| R-T-01 | — | ✅ Resolved | PHPCS re-enabled on `addons/pro/` via PRs #5070, #5078 |

See [SECURITY_POSTURE.md](SECURITY_POSTURE.md) for the full current state.

---

## 6. How to Set Up a Dev Environment (5 minutes)

```bash
# Option 1: Docker (recommended)
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
docker compose up -d
# → http://localhost:8000  (admin: admin / password)

# Option 2: Codex script
bin/codex-startup.sh
# → http://localhost:8000  (admin: admin / password)

# Quick lint
composer install
composer run lint          # WPCS on the whole tree
composer run lint:base     # WPCS on the WP.org-shippable subset
composer run test          # PHPUnit suite

# Plugin-check (simulates .org review)
composer run build         # Creates the distributable ZIP
# Then run `wp plugin-check` against the ZIP
```

---

## 7. Scoping Advice (Limited Budget)

If you have limited budget for a review, focus on this order:

### Phase 1: High-priority (~4-6 hours)
1. **Base plugin security surface** — REST endpoints (190 routes), tool permission callbacks, nonce coverage, input sanitization in `includes/tools/`, output escaping in `includes/rest/`
2. **Authentication** — Credential storage (encrypted API keys), token generation, guest token flow
3. **External service exposure** — 45 base + 3 Pro external services documented in `EXTERNAL_SERVICES.md`

### Phase 2: Architecture review (~3-4 hours)
4. **Plugin architecture** — DI container usage, class loading, lifecycle hooks (60+), singleton patterns
5. **Base/Pro separation** — Verify no pro feature gating in base plugin
6. **Tool registry** — How ~815 tools are registered and discovered

### Phase 3: Deep dives (~4-6 hours, if budget allows)
7. **Pro addon security** — Shell-exec tools (gated), file operations, npm dependencies
8. **SPA addons** — React bundle security, data flow from SPAs to REST
9. **Graphify** — SQL preparation, graph data ingestion
10. **Algorave** — Live-coding sandbox, `new Function()` usage

### What to skip on a tight budget:
- The `core/` plugin (separate product, v1.0.0, not the main plugin)
- Blueprint-generated SPAs (Canvas Toolkit, Document Editor, Media Studio)
- The Cloud Worker (not a WP plugin, deployed independently)
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
| 6 | `CLAUDE.md` | Coding standards, tool patterns, security rules |
| 7 | `AGENTS.md` | Multi-agent coordination rules |
| 8 | `docs/compliance/WORDPRESS_ORG_COMPLIANCE_COMPLETE.md` | All .org compliance fixes |
| 9 | `docs/compliance/SECURITY_AUDIT_2026_04.md` | April 2026 audit findings |
| 10 | `docs/ADDON_INVENTORY.md` | What each addon does and its status |

---

## 9. How This Plugin Was Developed

This is an AI-assisted project. The development uses a structured multi-agent pipeline documented in `AGENTS.md` and `CLAUDE.md`. Every change goes through human review and approval before merging.

See [AI-Assisted Development](development/AI_ASSISTED_DEVELOPMENT.md) for full transparency about the tools, workflow, and what this means for code review.

---

## 10. Compliance Status at a Glance

| Framework | Status | Notes |
|---|---|---|
| WordPress.org Plugin Guidelines (18 rules) | ✅ 100% | All 35+ violations resolved across v1.1.1 → v1.1.22. Most recent re-audit: May 23, 2026. |
| `wp plugin-check` | ✅ Passes | Gating job in CI |
| PHPCS (base tree) | ✅ 0 errors, 0 warnings | 796 files |
| PHPCS (Pro tree) | ✅ 82 errors | Down from 1,143 (93% reduction in May 2026). Out of .org scope. |
| Security audit (April 2026) | ✅ 0 Critical, 2 Partially Fixed High (addons only) | 3 of 5 Highs already Fixed. May 2026 hardening added. |
| `composer audit` | ✅ Clean | Root + Pro trees |
| `npm audit` | ✅ Clean (root) / ⏭️ Accepted (pro) | One dev-only accepted risk |
| ISO 27001 / SOC 2 / HIPAA | 📋 Aspirational documentation | Not a certification |

---

**Questions?** Start with this document, then drill into the specific files above. If you need a working install, the Docker environment spins up in under 5 minutes.
