# USE_CASES Fact Sheet

> **Companion to** [`USE_CASES_AND_QUICKSTARTS.md`](USE_CASES_AND_QUICKSTARTS.md). This file is the *source of truth* for every count, price, and version that appears in that document. When the use-cases doc is revised, **start here** — update the fact sheet from the live codebase first, then propagate the numbers.

**Fact sheet revision:** 2 (matches `USE_CASES_AND_QUICKSTARTS.md` rev 2.0)
**Tested against plugin version:** `1.1.18` (May 14, 2026)
**Catalog snapshot:** `includes/data/model-catalog.json` v `2026.05.04`

---

## 1. Plugin version & branch

| Artefact | Value | Source file |
|---|---|---|
| `WP_MCP_AI_VERSION` constant | `1.1.18` | `includes/bootstrap/constants.php` L20 |
| Plugin header `Version:` | `1.1.18` | `mcp-ai-wpoos.php` L6 |
| `readme.txt` stable tag | `1.1.18` | `readme.txt` |
| Default branch | `main` | `git remote show origin` |
| Latest release date | May 14, 2026 | `CHANGELOG.md` `[1.1.18]` heading |

**Doc-versioning policy:** This document carries an independent revision number (currently `2.0`). The plugin version is shown separately as "Tested against plugin: `1.1.18`".

---

## 2. Tool counts (registry-authoritative)

Per project rules, the live `WP_MCP_AI_Tool_Registry::get_tools()` registry is **authoritative**; file counts are a sanity check only.

| Measure | Count | Method |
|---|---|---|
| **Reconciled base tools** | **~195** | `readme.txt` 1.1.18 entry |
| **Reconciled Pro tools** | **~635** | `readme.txt` 1.1.18 entry |
| **Reconciled total** | **~830** | `readme.txt` 1.1.18 entry |
| Base tool class files | 226 | `find includes/tools -maxdepth 1 -name "class-wp-mcp-ai-tool-*.php"` |
| Pro tool class files | 669 | `find addons/pro -name "class-wp-mcp-ai-tool-*.php"` |
| Other addon tool class files | 21 | `find addons -name "class-wp-mcp-ai-tool-*.php" -not -path "*/pro/*"` |

> File counts overshoot the registry total because some classes register multiple variants, some are abstract base classes, and some are deprecated. The reconciled `~195 / ~635 / ~830` numbers in `readme.txt` are the canonical values to cite in user docs.

---

## 3. Professions & teams

| Measure | Count | Method |
|---|---|---|
| Profession knowledge documents | **190** | `find includes/knowledge-base/profession-documents -name "*.txt" \| wc -l` |
| Profession seeder source | `includes/professions/class-wp-mcp-ai-profession-seeder.php` | `get_default_professions()` + JSON loader |
| Profession CPT slug | `mcp_ai_profession` | `class-wp-mcp-ai-profession-cpt.php` L27 |

> Replace the previous doc's hard-coded `182 professions` with `~190 (190 knowledge documents)`. Categories enumerated in the use-cases doc must be re-derived from the seeder, not from the doc's existing table.

**Pre-built teams** (referenced in use-cases doc §7 IGCSE and Team Deployments):

| Team | Members | Source |
|---|---|---|
| IGCSE Mathematics | 3 | `includes/teams/` (verify) |
| IGCSE Science | 3 | `includes/teams/` (verify) |
| IGCSE Humanities | 3 | `includes/teams/` (verify) |
| IGCSE Languages & Tech | 4 | `includes/teams/` (verify) |
| IGCSE Year-Level Support | varies | `includes/teams/` (verify) |
| IGCSE Academic Support | varies | `includes/teams/` (verify) |
| Engineering Team | 4 | `includes/teams/` (verify) |
| Pharmaceutical Dev Team | 3 | `includes/teams/` (verify) |
| Research & Data Science Team | 3 | `includes/teams/` (verify) |
| Marketing & Growth Team | 3 | `includes/teams/` (verify) |

---

## 4. Pro toolkits

10 toolkits are GA with SPA manifests; an additional 34 settings-page classes exist (some GA, some "Coming Soon").

### 4.1 GA Pro toolkits (SPA-manifested)

Source: `addons/pro/config/spa-manifests/*.json`

1. `analytics`
2. `calendar-booking`
3. `cre-debt`
4. `crm`
5. `ecommerce`
6. `financial-planner`
7. `law-firm`
8. `multilingual`
9. `regulatory-registration`
10. `social-media`

### 4.2 Additional Pro toolkit settings pages

Source: `addons/pro/includes/admin/class-wp-mcp-ai-*-settings-page.php` (44 files total).

Beyond the SPA-manifested 10, settings pages also exist for: `ai-tool-builder` (**Coming Soon, Phase 2.9**), `architect-agent`, `architectural-design`, `architectural-drawing`, `architectural-project`, `architectural-specification`, `chat-channels`, `dj-management`, `document-generation`, `document-generation-cpt`, `eca`, `event`, `financial-planner-cpt`, `image-production`, `image-production-cpt`, `media`, `media-toolkit`, `member`, `nv-cloud`, `page`, `place`, `policy`, `post`, `pro-packages`, `pro-schedule-toolkit`, `product`, `project`, `project-management-toolkit`, `quiz`, `reg-product`, `registration`, `regulatory-product-cpt`, `regulatory-registration-toolkit`, `site-creator-toolkit`, `video-production`.

### 4.3 Phase 2.9 / Roadmap toolkits

Settings pages that currently render a `notice notice-info` "Coming Soon — Phase 2.9" banner:

- `ai-tool-builder` — verified at `addons/pro/includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php` L66-68.

> Cross-check before finalising the Roadmap appendix in the use-cases doc — additional pages may carry the same banner; grep for `'Coming Soon - Phase 2.9'` across `addons/pro/includes/admin/`.

---

## 5. AI provider catalog (active models)

Source: `includes/data/model-catalog.json` v `2026.05.04`. Counts below are **active** entries only (`status === "active"`).

| Provider | Active models | Notes |
|---|---|---|
| `anthropic` | 4 | claude-haiku-4-5, claude-opus-4-6, claude-opus-4-7, claude-sonnet-4-6 |
| `azure` | 1 | gpt-4o |
| `cloudflare` | 7 | Workers AI LLM lineup (Llama 3.x / 4, Gemma 3, Mistral, DeepSeek-R1-Distill) |
| `deepseek` | 3 | deepseek-chat, deepseek-reasoner, deepseek-coder |
| `digitalocean` | 5 | **Prices zeroed by design** — operators must populate via Models admin page |
| `embedded` | 3 | In-browser MLC models (Llama-3.2-3B, Qwen2.5-3B, gemma-3-2b) |
| `gemini` | 4 | gemini-3.1-flash, -flash-lite, -pro, imagen-4 |
| `google` | 4 | gemini-2.5-flash, -flash-image, -flash-lite, -pro |
| `huggingface` | 7 | Qwen 2.5/3, Llama 3.3/4, Mistral 7B/Small |
| `kimi` | 7 | kimi-k2/k2.5/k2.6/k2-thinking + moonshot-v1-{8k,32k,128k} |
| `lm_studio` | 20 | Local; all $0 |
| `nvidia` | 56 | Build/NIM-hosted |
| `ollama` | 29 | Local; all $0 |
| `openai` | 23 | gpt-4.1 family, gpt-4o-mini, gpt-5 / gpt-5.1 / gpt-5.2 lines |
| `openrouter` | 8 | Pass-through router |
| `webllm` | 5 | In-browser; all $0 |

### 5.1 Headline prices for the cost-considerations rewrite

All prices are per **1M tokens** unless noted; read directly from the catalog (input → output).

**OpenAI**

| Model | Input $/1M | Output $/1M | Notes |
|---|---|---|---|
| `gpt-4.1` | $6.00 | $18.00 | Replaces the doc's outdated `gpt-4o` row for "complex reasoning, orchestration" |
| `gpt-4.1-mini` | $1.50 | $4.50 | General tasks |
| `gpt-4.1-nano` | $0.40 | $1.20 | Cost-effective baseline |
| `gpt-4o-mini` | $0.15 | $0.60 | Still in catalog; cheapest OpenAI tier |
| `gpt-5` | $1.25 | $10.00 | New default for high-quality tasks |
| `gpt-5-mini` | $0.25 | $2.00 | Cheap reasoning |
| `gpt-5-nano` | $0.05 | $0.40 | Cheapest reasoning |
| `gpt-5-pro` | $21.00 | $168.00 | Premium |
| `gpt-5.1` | $1.25 | $10.00 | Current "smart default" |
| `gpt-5.1-codex` | $1.25 | $10.00 | Code tasks |
| `gpt-5.2` | $1.75 | $14.00 | Latest |

**Anthropic**

| Model | Input $/1M | Output $/1M |
|---|---|---|
| `claude-haiku-4-5` | $1.00 | $5.00 |
| `claude-sonnet-4-6` | $3.00 | $12.00 |
| `claude-opus-4-6` | $5.00 | $25.00 |
| `claude-opus-4-7` | $5.00 | $25.00 |

**DeepSeek**

| Model | Input $/1M | Output $/1M |
|---|---|---|
| `deepseek-chat` | $0.27 | $1.10 |
| `deepseek-reasoner` | $0.55 | $2.19 |
| `deepseek-coder` | $0.27 | $1.10 |

> **Note:** The previous doc cited `deepseek-chat-v3.2` — that model name does **not** appear in the catalog. Use `deepseek-chat` (the canonical alias).

**Google / Gemini**

| Model | Provider | Input $/1M | Output $/1M |
|---|---|---|---|
| `gemini-2.5-flash` | `google` | $0.30 | $2.50 |
| `gemini-2.5-flash-lite` | `google` | $0.10 | $0.40 |
| `gemini-2.5-pro` | `google` | $1.25 | $10.00 |
| `gemini-3.1-flash` | `gemini` | $0.075 | $0.30 |
| `gemini-3.1-flash-lite` | `gemini` | $0.015 | $0.06 |
| `gemini-3.1-pro` | `gemini` | $1.25 | $5.00 |

> **Doc-author note:** The catalog has both a `gemini` and a `google` provider entry — the 3.1 line is on `gemini`, the 2.5 line is on `google`. Doc previously cited `gemini-2.0-flash-exp` and `gemini-1.5-pro` — neither is in the current catalog as `active`.

**DigitalOcean Serverless Inference (NEW in 1.1.18)**

| Model | Status | Pricing |
|---|---|---|
| `llama3.3-70b-instruct` | active | $0 (catalog placeholder) |
| `llama3.1-8b-instruct` | active | $0 (catalog placeholder) |
| `deepseek-r1-distill-llama-70b` | active | $0 (catalog placeholder) |
| `openai-gpt-oss-120b` | active | $0 (catalog placeholder) |
| `gte-large-en-v1.5` | active | $0 (catalog placeholder) — embedding model |

> Per `CHANGELOG.md` 1.1.18: prices are zeroed by design — operators must populate them via the Models admin page or the `wp_mcp_ai_model_catalog` filter to reflect their account's per-token billing.

**Kimi / Moonshot**

| Model | Input $/1M | Output $/1M |
|---|---|---|
| `moonshot-v1-8k` | $12.00 | $12.00 |
| `moonshot-v1-32k` | $24.00 | $24.00 |
| `moonshot-v1-128k` | $60.00 | $60.00 |
| `kimi-k2`, `kimi-k2.5`, `kimi-k2.6`, `kimi-k2-thinking` | $0 (placeholder) | $0 (placeholder) |

**Cloudflare (Workers AI LLM)**

| Model | Input $/1M | Output $/1M |
|---|---|---|
| `@cf/meta/llama-3.2-1b-instruct` | $0.027 | $0.201 |
| `@cf/meta/llama-3.2-3b-instruct` | $0.051 | $0.335 |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | $0.293 | $2.253 |
| `@cf/meta/llama-4-scout-17b-16e-instruct` | $0.270 | $0.810 |
| `@cf/google/gemma-3-12b-it` | $0.150 | $0.450 |
| `@cf/mistralai/mistral-small-3.1-24b-instruct` | $0.351 | $0.555 |
| `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b` | $0.497 | $4.881 |

> **Important correction:** The previous doc invented Cloudflare image-model entries (`flux-2-dev @ $0.03/image`, `leonardo-ai @ $0.025`). **No such rows exist in `model-catalog.json`.** Only `imagen-4` (Gemini) and `gemini-2.5-flash-image` (Google) are catalogued image-generating models. If image-generation routing through Cloudflare ships later, add the rows then.

**Image generation models in catalog**

| Model | Provider | Input $/1M | Output $/1M | Notes |
|---|---|---|---|---|
| `imagen-4` | `gemini` | $0 | $0 | Catalog placeholder |
| `gemini-2.5-flash-image` | `google` | $0 | $30.00 | $30/M output reflects per-image pricing — see provider docs |

---

## 6. Major features shipped after the previous doc revision

Sourced from `CHANGELOG.md` and `readme.txt` headlines. Date column reflects the release tag.

| Feature | Released in | Notes |
|---|---|---|
| **DigitalOcean Serverless Inference provider** | 1.1.18 (2026-05-14) | New `WP_MCP_AI_DigitalOcean_Client`, embedding provider, settings UI subtab |
| **Unix Theory Compliance P0–P6** | 1.1.18 | Canonical tool envelope + two-gate sanitisation; two new PHPCS sniffs |
| **Async Chat Continuation** | 1.1.18 | Resumable streaming |
| **Jobs/Tasks Drawer** | 1.1.18 | Admin UI for background jobs |
| **Toolkit MCP Servers Phase 7 Admin UI** | 1.1.18 | Expose toolkits as external MCP servers (ADR-002) |
| **Inline-async-tick pattern** | 1.1.18 (and slices in earlier releases) | Shipped to Tool Async Executor, Transcript Mining, SaaS Apply, Veo polling, Graphify, Crawl4AI, Docs Hub rebuild, Harness Eval; makes background jobs work on `DISABLE_WP_CRON` hosts |
| **Scheduled Result widget + Gutenberg block + Elementor widget** | 1.1.18 | 6 render modes; new REST routes under `mcp-ai-pro/v1/schedules/*`; 3 new tools |
| **UI/UX Pro Max bundled skill** | 1.1.18 | Skill pack registry; base plugin skill count 44 → 45 |
| **WP.org Compliance Hardening** | 1.1.17 (2026-05-10) | 33-alert Dependabot sweep, SSRF hardening, banner additions |
| **Chat SPA addon** (`[nvoos_chat_spa]`) | 1.1.17 (Phases 1–7, v0.6.0) | React replacement for legacy `[mcp_ai_assistant]`; bundle ~81.3 KB gzip; gated by `WP_MCP_AI_LEGACY_CHAT_JS` constant |
| **Docs Hub addon** | 1.1.17 (v0.3.8) | In-WP docs viewer, sitemap provider, SSRF-hardened remote repos |
| **Toolkit SPA Blueprint Phases 5–12** | 1.1.17 | SPA shell for all GA Pro toolkits |
| **Orchestration Reference** (consolidated) | 1.1.9 | 10 workflow presets + 13 resource presets, PSO, reasoning controller, multi-agent system |

---

## 7. Compliance posture

> **Treat these claims with care in user-facing docs.** Do not restate percentage compliance numbers (`98% HIPAA`, etc.) unless sourced from a dated audit document in `docs/`.

| Standard | Status | Reference |
|---|---|---|
| WP.org plugin guidelines | Hardened in 1.1.17 | `readme.txt` 1.1.17 entry, `docs/03-wp-org-compliance.md`, `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md` |
| HIPAA | "Posture document" | `docs/HIPAA_POSTURE.md` |
| ISO 27001 | (verify in repo before quoting) | search for `ISO_27001` doc |
| SOC 2 | (verify in repo before quoting) | search for `SOC_2` doc |

---

## 8. Known-stale references in the prior doc revision

Tracked here so they aren't reintroduced in future revisions.

| Item in prior doc | Issue | Replacement |
|---|---|---|
| "Plugin Version: 1.3.0" | Plugin is `1.1.18`; doc had its own version masquerading as plugin version | Plugin: `1.1.18`. Doc rev: independent (`2.0`). |
| "207 tools (127 base + 70+ Pro)" | Off by ~4× | `~830 (~195 base + ~635 Pro)` — registry authoritative |
| "182 professions across 12 categories" | Outdated; 190 docs on disk | `~190 profession templates` |
| "13 Pro toolkits, 175+ tools" | 10 GA + many more settings-page toolkits | Cite GA count (10 SPA-manifested) explicitly; reference registry for total |
| "Updated January 2026" cost table | Pre-dates catalog v 2026.05.04 | Always cite `model-catalog.json` `version` field |
| Cloudflare `flux-2-dev` / `leonardo-ai` image-model rows | Not in catalog | Remove until real entries exist |
| `gpt-4o` / `o1` / `o1-mini` as headline OpenAI rows | Now legacy in catalog | Cite `gpt-4.1`, `gpt-5.x`, `gpt-4o-mini` lines |
| `gemini-2.0-flash-exp` / `gemini-1.5-pro` | Not in catalog as active | Use `gemini-2.5-*` / `gemini-3.1-*` |
| `deepseek-chat-v3.2` | Not in catalog | Use `deepseek-chat` |
| §6.0 Use Case "AI Tool Builder" | Toolkit is "Coming Soon — Phase 2.9" | Moved to `## Roadmap & Upcoming Toolkits` appendix |
| "v1.2.0 vs v1.3.0 cost comparison" | Fabricated version comparison | Removed; replaced with caching/load-balancing baseline behaviour |
| "5 new use cases" framing | Sections are now stable (≥4 months old) | 🆕 markers removed |

---

## 9. How to refresh this fact sheet

```sh
# tool counts (sanity check; registry is authoritative)
find includes/tools -maxdepth 1 -name "class-wp-mcp-ai-tool-*.php" | wc -l
find addons/pro -name "class-wp-mcp-ai-tool-*.php" | wc -l
find addons -name "class-wp-mcp-ai-tool-*.php" -not -path "*/pro/*" | wc -l

# professions
find includes/knowledge-base/profession-documents -name "*.txt" | wc -l

# Pro toolkit SPA manifests
ls addons/pro/config/spa-manifests/*.json | wc -l
find addons/pro/includes/admin -name "class-wp-mcp-ai-*-settings-page.php" | wc -l

# active models per provider (requires Node)
node -e "const c=require('./includes/data/model-catalog.json'); const a=c.models.filter(m=>m.status==='active'); const by={}; a.forEach(m=>{(by[m.provider]=by[m.provider]||0); by[m.provider]++}); console.log(JSON.stringify(by,null,2));"

# Phase 2.9 / 'Coming Soon' toolkits
grep -rn 'Coming Soon - Phase 2.9' addons/pro/includes/admin/
```

Re-run all of the above and update §1–§5 above whenever the use-cases doc is being revised.
