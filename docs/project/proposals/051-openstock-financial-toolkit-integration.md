# Proposal 051: OpenStock Integration with the Financial Planner Toolkit

**Status:** ⏳ ACTIVE — Phases B1–B3 implemented 2026-10-02 (see [`051-openstock-financial-toolkit-implementation-plan.md`](./051-openstock-financial-toolkit-implementation-plan.md))
**Date:** 2026-10-02
**Priority:** MEDIUM
**Estimated Effort:** 60–140 hours (2–5 weeks, phased; see §11)
**Branch:** `proposal/openstock-financial-toolkit` (from `alpha-working`)
**Target:** Extend the Pro Financial Planner Toolkit with OpenStock-aligned market-data capabilities — via an optional self-hosted OpenStock sidecar, native provider parity (Finnhub + TradingView), and a watchlist/alerts bridge — without importing AGPL-3.0 code into the plugin.
**Related:** `FIREFLY-III-INTEGRATION-PROPOSAL.md`, `addons/pro/includes/tools/financial-planning/TOOL_INDEX.md`, `addons/pro/includes/services/class-wp-mcp-ai-market-data-providers.php`, `addons/media-worker/README.md`, `026-media-worker-multi-tenancy-sidecar-proposal.md`

---

## 1. Executive Summary

**Yes — integrating OpenStock into the Financial Planner Toolkit is possible, but the useful integration is not what it first appears to be.** OpenStock ([github.com/Open-Dev-Society/OpenStock](https://github.com/Open-Dev-Society/OpenStock), AGPL-3.0, ~19.6k stars) is a **self-hostable Next.js stock-market application**, not a market-data API. It exposes **no public REST API** (its `API_DOCS.md` documents internal Inngest architecture, not consumable endpoints), and its market data is provided entirely by **Finnhub** (quotes, symbols, news) and **TradingView** (charts, heatmaps, quotes widgets).

Consequently, "integrating OpenStock" resolves into three license-clean, industry-standard paths:

1. **Sidecar deployment (Phase A, quick win)** — self-host OpenStock via its Docker Compose stack and surface it to WP admins as an embedded dashboard pane. AGPL's network clause is satisfied by operating it as a separate, unmodified process communicating over HTTP at arm's length; no OpenStock code enters the plugin.
2. **Native provider parity (Phase B, recommended core)** — adopt the exact open architecture OpenStock validates — **Finnhub as an optional API-key provider** in the existing `WP_MCP_AI_Market_Data_Providers` fallback chain, plus **TradingView widget embeds** in a WordPress "Market Overview" admin page, plus **watchlist and price-alert parity tools**. This is "integrating OpenStock's architecture," not its code — the industry-standard move for a GPL/Proprietary plugin that cannot absorb AGPL code.
3. **Data bridge (Phase C, optional)** — read the self-hosted OpenStock MongoDB (`watchlists`, `alerts` collections) or call Finnhub directly to keep the NV oOS watchlist/alert tools and an OpenStock deployment in sync.

Direct code reuse (copying OpenStock's Next.js components, its Finnhub client, or its UI into the plugin) is **not recommended** — AGPL-3.0 is stronger copyleft than the plugin's GPLv3/Proprietary mix and would force re-licensing the Pro addon (§4).

The proposal recommends **Phase A + B now, Phase C deferred**, at a total effort of ~60–110 hours, delivering: Finnhub provider parity, TradingView chart/heatmap embeds, an `openstock` sidecar settings panel, and watchlist/alert feature parity against the 13,000-user OpenStock feature set.

---

## 2. Problem Statement

The Financial Planner Toolkit (42 tools, `addons/pro/includes/tools/financial-planning/`) already covers planning, budgeting, retirement, and portfolio operations. Its market-data layer is deliberately keyless: `WP_MCP_AI_YFinance_Service` (a Node.js yfinance microservice) with a keyless fallback chain (Stooq, Nasdaq, CoinGecko, Binance, FRED, TradingView scanner, Forex Factory) built in v1.1.80 following the OpenTerminal resilience pattern (`#6639`).

Three gaps remain, and OpenStock is the community reference that solves them:

1. **No optional real-time / higher-quality quote path.** Keyless public endpoints are rate-limited, delayed, and occasionally unreliable. OpenStock's answer — Finnhub free tier (60 req/min per key, multi-key rotation) — is the industry-standard upgrade path for exactly this scenario.
2. **No charting/market-overview surface.** The toolkit returns OHLCV and indicator arrays, but the agent cannot render a professional candlestick chart, heatmap, or market overview. OpenStock's answer — TradingView embeddable widgets — is the standard, license-friendly way to add charts without writing a charting engine.
3. **Watchlists and price alerts live in two places.** NV oOS has `price_alerts` (WP options + daily cron) and `portfolio_transaction_log` (CPT), while OpenStock users maintain per-user MongoDB watchlists. Teams running both want one source of truth or a sync bridge.

Integrating OpenStock (as a sidecar) plus its provider architecture (natively) closes all three gaps using patterns the repo already follows (fallback chains, transients + SWR, educational disclaimers).

---

## 3. What OpenStock Actually Is (Facts)

Verified from the repository README, `API_DOCS.md`, `MARKET_SUPPORT.md`, `docker-compose.yml`, and repo metadata on 2026-10-02:

| Attribute | Detail |
|---|---|
| **Type** | Full-stack stock market **application** (Next.js 15 App Router, React 19, TypeScript ~93.4%), not an API/SDK |
| **License** | **AGPL-3.0** (network copyleft; modification/redistribution/deployment-as-a-service triggers source-release obligations) |
| **Community** | ~19.6k stars, ~2.4k forks, 173 commits, 13,000+ registered users; funded by sponsorships + upcoming OpenStock Cloud ($5/mo) |
| **Market data** | **Finnhub** (symbols, profiles, news; free tier, 60 req/min per key, multi-key rotation, hourly cached mode or 15s realtime mode) + **TradingView widgets** (info, candlestick/advanced charts, technicals, heatmap, quotes, timeline) |
| **Sentiment (optional)** | Adanos API — cross-source snapshots for Reddit, X.com, news, Polymarket |
| **Auth/data** | Better Auth (email/password, optional Google/GitHub OAuth) + MongoDB/Mongoose (`users`, `watchlists`, `alerts` collections) |
| **Automation** | Inngest — `user.created` welcome email (Gemini w/ Siray fallback), weekly news cron (Mon 9 AM, Kit broadcast), `check-stock-alerts` cron every 5 min, `check-inactive-users` cron |
| **Deployment** | Docker Compose (`openstock` + `mongo:7`, persistent volume) or Vercel; env-driven config |
| **Features** | Cmd+K global search, per-user watchlists (unique symbol per user), stock detail pages, market overview (heatmap/quotes/news), personalized onboarding, email automation, dark-theme shadcn/ui |
| **Markets** | 30+ exchanges via Finnhub (NSE, LSE, TSX, …); real-time non-US data delayed 15+ min on free tier (`MARKET_SUPPORT.md`) |
| **Public API** | **None.** `API_DOCS.md` documents internal architecture (Inngest functions, MongoDB collections, provider env vars) — no authenticated REST surface for third parties |
| **Positioning** | Explicitly "not a brokerage," "nothing here is financial advice" — educational/informational only |

**The key conclusion:** OpenStock is a *consumer of* market data, not a *source* of it. Any serious integration must target either (a) the running application, or (b) the same providers OpenStock consumes.

---

## 4. Licensing Analysis (The Deciding Constraint)

OpenStock is AGPL-3.0. The NV oOS plugin is GPLv3-or-later (WordPress ecosystem) with the Pro addon's PHP files marked `@license Proprietary`. AGPL-3.0 is **one-way compatible** with GPLv3 (GPL code may be re-licensed AGPL, never the reverse), and stronger copyleft than a Proprietary addon. This yields three rules, consistent with industry AGPL guidance (FSF AGPL FAQ; [depproof.com/licenses/agpl-3.0](https://depproof.com/licenses/agpl-3.0/); [fastcrw.com AGPL-for-SaaS](https://fastcrw.com/blog/agpl-3-for-saas-explained); [vaultinum.com AGPL compliance guide](https://vaultinum.com/blog/essential-guide-to-agpl-compliance-for-tech-companies)):

| Action | Allowed? | Rationale |
|---|---|---|
| **Run unmodified OpenStock as a separate self-hosted service** and communicate over HTTP | ✅ Yes | Arm's-length separate process; plugin code does not become a derivative work. Source-availability obligation stays on the OpenStock instance (already AGPL). |
| **Modify OpenStock** (branding, SSO patch, custom endpoints) and deploy it | ⚠️ Yes, with obligations | Modifications must be released under AGPL-3.0 and offered to all network users. Fine for a self-hosted client deployment; must be tracked in our release process. |
| **Copy OpenStock code into the WordPress plugin / Pro addon** | ❌ No | AGPL code merged into a GPLv3/Proprietary plugin would force the *whole combined work* to AGPL-3.0 (contaminates the Pro addon's proprietary licensing). |
| **Copy OpenStock's UI/design tokens/components into the plugin** | ❌ No | Same rationale; only *ideas and architecture patterns* (unprotected) may be adopted. |
| **Call Finnhub / TradingView directly from the plugin** | ✅ Yes | Those providers are independent of OpenStock; each has its own ToS (§5). |

**Decision rule adopted by this proposal:** no OpenStock code, assets, or copy enter the repository. Integration is (a) sidecar operation of the unmodified upstream app and (b) independent use of the same third-party providers under their own terms. Every OpenStock modification we ship (Phase C bridge endpoints) is maintained in a public fork under AGPL-3.0.

---

## 5. Industry Standards & Best Practices (Research Synthesis)

This section synthesizes web research on market-data integration, licensing, and AI-finance tooling standards (2025–2026). Sources are cited inline; key findings are mapped to concrete requirements in §6.

### 5.1 Market-data licensing and redistribution

- **Display ≠ redistribution.** Retrieving quotes for a logged-in user's own screen is "display"; caching historical series for re-serving to other parties is "redistribution." Exchange data (NYSE, Nasdaq, CBOE, LSE) and aggregators (Finnhub) require **paid redistribution agreements** for any re-serving ([r/fintech: Free Stock APIs](https://www.reddit.com/r/fintech/comments/ozpuln/free_stock_apis_open_to_commercial_use_with_high/); [Finnhub pricing](https://finnhub.io/pricing-stock-api-market-data)).
- **Finnhub free tier is non-commercial.** "Our API is not free for commercial use. Please reach out to us at sales@finnhub.io" ([r/FinnhubAPI](https://www.reddit.com/r/FinnhubAPI/comments/ozoe8u/is_finnhub_free_for_commerical_use_and_what/)). → The plugin must (a) let each site operator bring **their own Finnhub key**, (b) treat the key as end-user config, and (c) not redistribute cached series beyond the owning site's UI/agent.
- **TradingView widgets** are embeddable via iframe without a charting license; attribution and their widget ToS apply, and some widgets restrict commercial contexts — review per-widget before shipping.

### 5.2 Delayed-data and educational disclaimers (regulatory hygiene)

- The **15-minute delayed-data disclosure** is an exchange/regulatory standard (Nasdaq/NYSE delayed-quote rules); OpenStock's `MARKET_SUPPORT.md` documents it per exchange. The NV oOS toolkit already ships these disclaimers on every market-data tool — the new provider must preserve them verbatim in behavior.
- SEC/FINRA posture on AI: tools that **don't execute trades and don't render personalized investment advice** with explicit "EDUCATIONAL ONLY — Not investment advice" labeling remain in the informational-tool category (consistent with the toolkit's existing `get_usage_guidance()` + envelope `disclaimer` fields). The OpenStock README uses the same framing ("not a brokerage … nothing here is financial advice"). → No new tool may imply recommendations, forecasting certainty, or trade execution.

### 5.3 Resilient multi-provider architecture (OpenTerminal pattern — already adopted here)

- The repo's `WP_MCP_AI_Market_Data_Providers` docblock explicitly credits [ErTasselli/OpenTerminal](https://github.com/ErTasselli/OpenTerminal)'s **provider fallback-chain** as the inspiration (commit `#6639`). This is the established industry pattern for free-tier data: primary provider → keyless fallbacks → stale-while-revalidate cache.
- Adding Finnhub slots into this existing chain is the *lowest-risk* integration: it inherits the 15 s timeouts, `is_valid_symbol()` guards, normalized shapes, transient cache (15 min TTL / 7-day stale), and the filterable HTTP seam already built for tests.

### 5.4 Caching, rate limiting, and multi-key rotation

- Finnhub free tier: **60 requests/minute per key**; OpenStock supports `FINNHUB_API_KEYS=key_one,key_two` rotation. → The plugin's Finnhub client should accept a comma-separated key list with round-robin rotation and 403/429 backoff, mirroring the upstream pattern.
- OpenStock's own modes are instructive: **`cached`** (hourly quote refresh for all users) vs **`realtime`** (15 s refresh + email alerts, an OpenStock Cloud paid feature). → The NV oOS settings page should expose a parallel `data_mode` knob (`cached` default), keeping realtime refresh opt-in and alert-bearing.

### 5.5 Watchlist / alerts as user-scoped data

- OpenStock scopes watchlists per user with a unique-symbol constraint (`database/models/watchlist.model.ts`). NV oOS already scopes `price_alerts` per user (WP options) and `portfolio_transaction_log` per user (CPT). → A sync bridge must respect **user-level scoping on both sides** and never merge data across users.

### 5.6 WordPress plugin integration standards (repo-internal)

- New tools must follow the **Unix Theory P0–P6 rules**: canonical envelope (`success` array or `WP_Error`, never `array('success' => false, …)`), **two-gate sanitization** (sanitize at entry, escape at exit), capability gates (`edit_posts`), `get_usage_guidance()` with `when_not_to_use`/`related_tools`, and `is_available()` gating on `enable_financial_planner_toolkit` (PHPCS sniffs `WPMCPAI.Tools.CanonicalReturnEnvelope` + `WPMCPAI.Tools.SanitizeAtEntry`, severity 5).
- Settings live behind `WP_MCP_AI_Settings` pages; API keys go through the existing **API key store** pattern (never hardcoded, never logged — `wp-security-secrets` skill applies).
- All external HTTP must route through the existing filterable HTTP seam for testability (per `market-data-providers` conventions).

---

## 6. Proposed Solution

### 6.1 Integration topology

```mermaid
flowchart TD
    subgraph WP[WordPress + NV oOS Pro]
        T1[stock_data_fetcher]
        T2[market_screener]
        T3[price_alerts]
        T4[watchlist_sync - new]
        T5[market_overview_widget - new]
        S1[WP_MCP_AI_YFinance_Service]
        S2[WP_MCP_AI_Market_Data_Providers]
        S3[WP_MCP_AI_Finnhub_Provider - NEW]
        OS1[OpenStock Sidecar settings page - NEW]
    end

    subgraph Sidecar[Docker: OpenStock sidecar - unmodified AGPL]
        A1[Next.js app]
        A2[MongoDB - users, watchlists, alerts]
        A3[Inngest crons + Gemini/Siray emails]
    end

    subgraph External[External providers]
        F1[Finnhub API - BYO key, 60 req/min/key, rotation]
        F2[TradingView widgets - iframe embeds]
        F3[Keyless fallbacks - Stooq, Nasdaq, FRED, CoinGecko...]
    end

    S2 -->|existing chain| F3
    S2 -->|NEW: optional primary, cached mode| F1
    T1 --> S1
    S1 --> S2
    T4 -->|read-only bridge: watchlists/alerts| A2
    T5 --> F2
    OS1 -->|embed via iframe| A1
    T3 -->|existing cron + delivery hook| S2
```

### 6.2 Phase A — OpenStock sidecar (self-host, embed)

- New settings section **Settings → NV oOS → Integrations → OpenStock** with fields: `openstock_url`, `openstock_mode` (off / embed / link), `openstock_allowed_roles`, `openstock_iframe_csp` allowlist entry (the repo has a CSP-header class; the embed origin must be allowlisted there).
- WP admin page **NV oOS → OpenStock** rendering the sidecar in an iframe (or a "Launch OpenStock" external link for multisite/headless hosts). No SSO in Phase A — OpenStock's own Better Auth handles login; document the separation explicitly in the UI copy.
- Deployment doc: `docs/integrations/openstock-sidecar.md` with the verified `docker compose` recipe (app + `mongo:7`, `MONGODB_URI=mongodb://root:example@mongodb:27017/openstock?authSource=admin`), env table, and reverse-proxy guidance.
- **License posture:** upstream unmodified; no code in repo. Users self-host; nothing for us to redistribute.

### 6.3 Phase B — Native provider parity (recommended core)

1. **`WP_MCP_AI_Finnhub_Provider`** (new file alongside `class-wp-mcp-ai-market-data-providers.php`): implements the same normalized shapes (`get_quote`, `get_history`, `search_symbol`, `get_company_profile`, `get_news`) used by the yfinance chain. Key handling: comma-separated `finnhub_api_keys` option, round-robin on 429/403, 15 s timeout, transient cache with SWR, `is_available()` false until a key is configured. Registered as **optional primary** for `stock_data_fetcher` actions `quote|history|batch_quotes|search` (fallback to yfinance → keyless chain unchanged when Finnhub is absent or failing). **Deployment-home alternative:** for multi-tenant/shared deployments the same provider logic can live in the media-worker instead — see §7.
2. **`market_overview_widget` tool** (new): returns render-safe TradingView widget embed URLs/iframes (chart, heatmap, quotes, timeline) for the WP admin market-overview page **and** a compact JSON summary (top movers from the quote batch) for chat responses. Escaped via `esc_url()`; widget domains allowlisted.
3. **`watchlist_sync` tool** (new, Phase B scope = Finnhub-native watchlist stored in user meta): `add|remove|list|bulk_quote` actions over a per-user watchlist (`user_meta` key `wp_mcp_ai_fin_watchlist`), with the unique-symbol constraint mirroring OpenStock's model and a `bulk_quote` action routed through the provider chain.
4. **`price_alerts` parity upgrade:** align the existing tool's `data_mode` semantics with OpenStock (`cached` default hourly refresh; `realtime` opt-in with an admin warning about Finnhub limits), and extend the delivery hook documentation.
5. **Settings surface:** extend the Financial Planner settings page with **Market Data Providers** → Finnhub (keys, `data_mode`, rate-limit status) + TradingView (enable widgets, allowed symbols). Persist keys through the existing API-key store; never echo keys back to the browser.
6. **Assistant blueprint:** add an `openstock-market-analyst` assistant preset (watchlist, quotes, news, indicators, screener tools; educational disclaimer system prompt) to the unified blueprint system, following the existing 55-assistant pattern.

### 6.4 Phase C — OpenStock data bridge (deferred, optional)

- Maintain a **public fork** of OpenStock (AGPL-3.0, per §4) adding a minimal read-only REST surface (`GET /api/bridge/watchlists`, `GET /api/bridge/alerts`) guarded by a new `OPENSTOCK_BRIDGE_TOKEN`.
- `openstock_watchlist_sync` tool: pull the logged-in NV oOS user's OpenStock watchlist into `wp_mcp_ai_fin_watchlist` (user-scoped mapping table in WP options), and push NV oOS `price_alerts` into OpenStock alerts. Read-only direction by default; writes behind a settings toggle.
- Only if/when demand exists; requires maintaining an AGPL fork + CI, which is a real ongoing cost.

### 6.5 Explicit non-goals

- ❌ No trade execution, no brokerage, no portfolio recommendations framed as advice.
- ❌ No bundling of OpenStock code, Docker images (AGPL artifacts stay out of our release pipeline), or Finnhub/TradingView keys.
- ❌ No open FinHub data redistribution (no public endpoints that re-serve cached series).
- ❌ No SSO/user-provisioning magic in Phase A.

---

## 7. Media-Worker Integration (Deployment Home for the Market-Data Layer)

**Short answer to "could it be integrated into the media-worker?":** OpenStock itself — no. The market-data module this proposal needs — yes, and for multi-tenant deployments the worker is arguably the better home than in-WP PHP.

### 7.1 What the media-worker is (verified 2026-10-02)

- `addons/media-worker/` — Design Stack media worker **v3.3.0** (`design-media-worker`), standalone repo `nvdigitalsolutions/mcp-ai-wpoos-media-worker` (one-way subtree mirror), **Node.js ≥22.12 + Express 4** sidecar, port 3100, Docker.
- Route groups: image, video, social, workflow (Redis job queue), pdf, document, ocr, email, code, data (translate / language-detect / qrcode / render-math / generate-ics / **render-chart** / geospatial), browser (Puppeteer), crawl + crawl4ai facade.
- Infrastructure already in place: token auth (`WORKER_API_TOKEN`, timing-safe SHA-256, rotation via `WORKER_API_TOKEN_PREVIOUS`), `express-rate-limit` budgets, Helmet, Redis (`ioredis`), an SSRF guard on crawl routes, and **multi-tenant mode v2.4.0** (`SITE_TOKENS`, per-site tokens/queues/rate budgets/site-scoped filesystems — proposal 026).
- The plugin auto-detects the worker via `WP_MEDIA_WORKER_URL` and already sends `X-Site-Token` / `X-Site-Url` on every request (`includes/traits/trait-wp-mcp-ai-media-worker-client.php`).
- Relevant deps: `chart.js` + optional `chartjs-node-canvas` — server-side chart rendering already exists in the worker stack.
- The yfinance microservice (`http://localhost:5000` default, `yfinance_service_url` setting) is a **separate** one-off Node service — not the media-worker.

### 7.2 Can OpenStock itself live inside the media-worker? No — and why

| Concern | Detail |
|---|---|
| Runtime mismatch | OpenStock is a Next.js 15 app (React SSR, its own server entry); the worker is a plain Express 4 app. Co-hosting = two servers/process managers in one image — an ops anti-pattern. |
| Data-store mismatch | OpenStock requires MongoDB (`users`, `watchlists`, `alerts`); the worker persists via Redis + filesystem only. |
| Auth-model clash | OpenStock's Better Auth (email/password + OAuth, session cookies) is user-facing app auth, orthogonal to the worker's token auth. |
| AGPL isolation | Placing OpenStock code inside the worker image would blend AGPL code with our proprietary worker code — exactly the licensing risk §4 prohibits. It must remain a **separate container** at arm's length. |

Therefore the Phase A sidecar stays a separate Compose service next to the worker; the worker plays no role in Phase A (the WP admin page embeds OpenStock directly).

### 7.3 What *should* live in the media-worker: a `market` route module

The Finnhub provider, watchlist, and chart rendering from Phase B map cleanly onto the worker's existing patterns:

1. **`src/routes/market.js`** (new route group, following `data.js`/`crawl.js` conventions):
   - `POST /api/market/quote`, `/api/market/search`, `/api/market/history`, `/api/market/batch`, `/api/market/news`, `/api/market/company-profile`
   - `POST /api/market/watchlist` (`add|remove|list|bulk_quote`) — Redis-backed, **per-site namespaced** in multi-tenant mode (`market:<site>:watchlist:*` keys), matching the v2.4.0 tenancy model
   - `GET /api/market/chart/:symbol` — server-rendered candlestick/line PNG via the existing chart.js stack, returned to chat as an image (complements the TradingView iframe widgets used on the admin page)
2. **Finnhub client with key rotation** — keys in worker env vars (`FINNHUB_API_KEYS` comma-separated, `MARKET_DATA_MODE=cached|realtime`), never in WordPress options or the browser; per-tenant key maps can follow the deferred Phase-2 per-site provider-key pattern from proposal 026.
3. **Caching:** Redis with SWR semantics (fresh TTL + stale copy), replacing WP transients for worker-routed requests; the PHP transient path remains the in-WP fallback.
4. **Price-alert cron:** worker queue job polling quotes and firing the existing `wp_mcp_ai_price_alert_triggered` delivery hook via callback URL — moving alert checks off WP-Cron for multi-site deployments.
5. **Plugin wiring:** the PHP service gains a `provider` setting (`yfinance | worker | php-native`); when `worker`, requests POST to `{WP_MEDIA_WORKER_URL}/api/market/...` with the existing `X-Site-Token` headers — **reusing `trait-wp-mcp-ai-media-worker-client.php`** instead of writing a new HTTP client.

### 7.4 Trade-offs: in-WP PHP provider vs media-worker market module

| Dimension | In-WP PHP (Phase B1 as written) | Media-worker market module |
|---|---|---|
| New infrastructure | None | Worker v3.4.0+ rollout |
| Key security | WP options + API-key store (server-side) | Worker env vars — strongest isolation; per-tenant maps possible |
| Multi-tenant isolation | N/A (per-site WP) | Native (v2.4.0 tenancy) |
| Caching | WP transients (15 min / 7-day SWR) | Redis SWR — shared, durable |
| Chart images for chat | Requires new PHP-side rendering (no PHP chart lib in the stack) | Already available (chart.js) |
| Alert cron | WP-Cron | Worker queue job — offloads WP-Cron |
| Complexity | Lower | Higher ops surface |
| License | No new components (our own code) | No new components (our own code) |

**Recommendation:** ship Phase B1 **in-WP first** (reuses the existing `market-data-providers` seam, zero new infrastructure), then offer the **worker market module as the recommended home for multi-tenant/shared and agency deployments** (worker v3.4.0). OpenStock itself stays a separate container in every mode.

---

## 8. Proposed Tools & Capability Matrix

| # | Tool (slug) | New/Upgraded | Capability flags | Persistence | Disclaimers |
|---|---|---|---|---|---|
| 1 | `finnhub_quote` (action inside `stock_data_fetcher`) | Upgraded | pro, external-api, cacheable, network-dependent | transient + SWR | EDUCATIONAL ONLY; delayed ≥15 min; BYO key |
| 2 | `market_overview_widget` | New | pro, external-api, cacheable | none (render URLs) | Widget ToS; informational |
| 3 | `watchlist_sync` | New | pro, database-read/write | user meta | EDUCATIONAL ONLY |
| 4 | `price_alerts` | Upgraded (`data_mode`) | pro, database-write, cron | WP options | Not a substitute for brokerage alerts |
| 5 | `openstock_watchlist_sync` | New (Phase C) | pro, external-api, database-read/write | WP options mapping | Requires sidecar + bridge fork |
| 6 | `import_openstock_blueprint` (assistant preset) | New | pro | — | EDUCATIONAL ONLY |

All tools: `edit_posts` capability, `is_available()` gated on `enable_financial_planner_toolkit` (+ provider configured for #1/#5), canonical envelope, two-gate sanitization, `get_usage_guidance()` with `related_tools`.

---

## 9. Security Considerations

- **Keys:** stored via the existing API-key store; never echoed, never logged (`error_log` scrubbing on provider HTTP errors); comma-rotated keys held server-side only. No `NEXT_PUBLIC_*` style client exposure (unlike OpenStock's own `NEXT_PUBLIC_FINNHUB_API_KEY`, which is browser-visible — we deliberately do better).
- **SSRF / allowlists:** provider base URLs fixed to `https://finnhub.io/api/v1` and TradingView's widget domains; `openstock_url` input validated as http(s), resolved, and allowlist-checked before use (repo URL-guard class applies). No user-supplied arbitrary fetch URLs.
- **CSP:** iframe embed requires adding the sidecar origin to the CSP `frame-src` — coordinated with the existing CSP-header class and documented.
- **User scoping:** watchlist/alert sync is strictly per-user; no cross-user aggregation; the unique-symbol constraint prevents junk rows.
- **Data minimization:** transient cache only (15 min fresh / 7-day stale), matching existing behavior; no historical series persisted beyond the existing `portfolio_transaction_log` CPT (user-owned).
- **Disclaimers:** every market-data envelope keeps the existing `disclaimer` field; new tools copy the toolkit's exact "EDUCATIONAL ONLY / delayed data / not investment advice" strings.

---

## 10. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| AGPL contamination via accidental code import | Low | High (licensing) | §4 rules; PR checklist gate; no OpenStock code/assets in CI scanner output |
| Finnhub free-tier 429s at scale | High | Medium | Key rotation, `cached` mode default, yfinance/keyless fallback chain unchanged |
| Finnhub ToS (non-commercial free tier) | Medium | Medium | BYO-key model; commercial sites directed to Finnhub sales; no redistribution surfaces |
| TradingView widget ToS/commercial restrictions | Medium | Low | Per-widget review in Phase B; attribution preserved; feature toggle |
| Sidecar adds ops burden (Mongo + Node + Inngest) | Medium | Medium | Docker Compose recipe; Phase A is fully optional; link-mode for managed hosts |
| Realtime mode abuse of provider limits | Medium | Low | `realtime` behind settings toggle + admin rate-limit warning; alerts throttled |
| Phase C bridge fork maintenance cost | High (long-term) | Low | Deferred; only on demonstrated demand; bridge token + read-only default |

---

## 11. Implementation Plan & Effort Estimation

| Phase | Work | Effort | Timeline |
|---|---|---|---|
| **A. Sidecar** | Settings page + embed page + CSP allowlist + deployment doc | 10–15 h | 3–5 days |
| **B1. Finnhub provider** | `WP_MCP_AI_Finnhub_Provider`, key rotation, SWR cache, tests (provider matrix incl. 429 fallback), settings UI | 20–30 h | 1–1.5 wks |
| **B2. Widgets + watchlist** | `market_overview_widget`, `watchlist_sync`, TradingView allowlist, admin page | 15–25 h | 1 wk |
| **B3. Parity + blueprint** | `price_alerts` `data_mode`, blueprint preset, docs, PHPCS + PHPUnit | 10–15 h | 3–5 days |
| **B4. Worker market module (optional)** | `src/routes/market.js`, Finnhub client + rotation, Redis watchlist, chart route, queue-based alert check, plugin `provider` setting | 25–35 h | 1–1.5 wks |
| **C. Bridge (deferred)** | AGPL fork + bridge endpoints + `openstock_watchlist_sync` + docs | 30–50 h | 2–3 wks (when approved) |
| **Total (A+B)** | | **60–85 h** (B4 adds **25–35 h**) | **2–5 weeks** |

Dependencies: none blocking — Phase B1 builds on the existing `market-data-providers` seam; Phase A is independent. Tests per phase: unit (provider normalization, rotation, fallback), REST (settings save/redaction), integration (seam-injected Finnhub fixtures), and capability/nonce checks per repo testing conventions.

---

## 12. Success Metrics

- **Phase B1:** `stock_data_fetcher` serves Finnhub-backed quotes on ≥99% of enabled sites in `cached` mode; zero fallback-chain regressions (existing test matrix stays green).
- **Phase B2:** `market_overview_widget` renders on the admin page with zero CSP violations; `watchlist_sync` CRUD covered by tests.
- **Phase A:** 100% of deployers can stand up the sidecar from the documented recipe; zero plugin-code licensing risk findings.
- **Usage:** ≥20% of Financial-Planner-enabled sites configure a Finnhub key within one release cycle (telemetry via existing opt-in cost/usage tracker if enabled).
- **Compliance:** no OpenStock source/assets present in the repository (CI scanner check); all new strings carry the educational disclaimer.

---

## 13. Alternatives Considered

| Option | Verdict | Reason |
|---|---|---|
| Direct code merge of OpenStock into plugin | ❌ Rejected | AGPL-3.0 vs GPLv3/Proprietary (§4) |
| Use OpenStock Cloud as a hosted API | ❌ Rejected | Not an API; Cloud is a UI subscription, no public endpoints |
| Keep keyless-only chain (status quo) | ⚠️ Insufficient | Loses realtime/quality path + charting; OpenStock proves the Finnhub+TradingView pattern |
| Build custom charting engine | ❌ Rejected | TradingView widgets are the industry standard; zero maintenance |
| Replace yfinance with Finnhub wholesale | ❌ Rejected | Keyless chain is the resilience backbone; Finnhub joins as optional primary only |
| Co-host OpenStock inside the media-worker image | ❌ Rejected | Runtime/data/auth mismatch + AGPL blending risk (§7.2); sidecar stays a separate container |
| Firefly III for market data | N/A | Firefly III is personal-ledger tooling; complements rather than competes (see `FIREFLY-III-INTEGRATION-PROPOSAL.md`) |

---

## 14. Decision Required

1. **Approve Phase A (sidecar embed)** — yes/no.
2. **Approve Phase B1–B3 (Finnhub provider parity + widgets + watchlist + blueprint)** — recommended yes.
3. **Defer Phase C (bridge fork) until post-B usage data** — recommended yes.
4. **Confirm licensing posture:** BYO-key model, no AGPL artifacts in the release pipeline, no redistribution endpoints.
5. **Choose the market-data deployment home:** in-WP PHP provider (default), media-worker market module for multi-tenant/agency deployments (recommended phased: B1 first, B4 when worker v3.4.0 demand justifies it) — OpenStock itself always a separate container.

---

## 15. References

- [OpenStock repository](https://github.com/Open-Dev-Society/OpenStock) — README, `API_DOCS.md`, `MARKET_SUPPORT.md`, `docker-compose.yml`
- [OpenStock Cloud & funding model](https://github.com/Open-Dev-Society/OpenStock#-sponsor-openstock)
- [Finnhub API docs](https://finnhub.io/docs/api) and [pricing](https://finnhub.io/pricing-stock-api-market-data)
- [r/FinnhubAPI — commercial-use policy](https://www.reddit.com/r/FinnhubAPI/comments/ozoe8u/is_finnhub_free_for_commerical_use_and_what/)
- [r/fintech — redistribution of market data](https://www.reddit.com/r/fintech/comments/ozpuln/free_stock_apis_open_to_commercial_use_with_high/)
- [AGPL-3.0 network clause analysis — depproof](https://depproof.com/licenses/agpl-3.0/)
- [AGPL for SaaS — fastcrw](https://fastcrw.com/blog/agpl-3-for-saas-explained)
- [AGPL compliance guide — vaultinum](https://vaultinum.com/blog/essential-guide-to-agpl-compliance-for-tech-companies)
- [OpenTerminal (fallback-chain inspiration)](https://github.com/ErTasselli/OpenTerminal)
- [Awesome-finance-skills](https://github.com/RKiding/Awesome-finance-skills)
- Repo: `addons/pro/includes/tools/financial-planning/TOOL_INDEX.md`, `addons/pro/includes/services/class-wp-mcp-ai-market-data-providers.php`, `class-wp-mcp-ai-yfinance-service.php`, `addons/pro/docs/FINANCIAL_PLANNER_TOOLKIT_PLAN.md`
- Repo: `addons/media-worker/README.md`, `addons/media-worker/package.json` (worker v3.3.0 surface), `docs/project/proposals/026-media-worker-multi-tenancy-sidecar-proposal.md` (tenancy model), `includes/traits/trait-wp-mcp-ai-media-worker-client.php` (client seam)

---

## 16. Final Recommendation & Status

**Adopted: rebuild as capability parity — not UI parity.** OpenStock's value surface (Finnhub data, TradingView charts, watchlists, alerts) is rebuilt natively in the Financial Planner Toolkit; OpenStock's app code and UI are not copied (AGPL, §4), and the sidecar/bridge phases are deferred to opt-in fallbacks.

### Implemented (2026-10-02, Phases B1–B3)

| Item | Artifact |
|---|---|
| Finnhub optional primary provider | `addons/pro/includes/services/class-wp-mcp-ai-finnhub-provider.php` — quote/history/search/batch/profile/news, key rotation, SWR cache, `cached`/`realtime` modes, filter-seam insertion at priority 5 |
| Cache-TTL hook | `wp_mcp_ai_yfinance_cache_ttl` in `class-wp-mcp-ai-yfinance-service.php` (additive, 1-line) |
| Watchlist parity | `class-wp-mcp-ai-tool-watchlist-sync.php` — user-meta, unique symbol, bulk quotes |
| Chart/market-overview parity | `class-wp-mcp-ai-tool-market-overview-widget.php` — TradingView embed URLs + top movers |
| Alert cron parity | `price_alerts` `is_realtime()` — hourly cron + hourly re-arm in realtime mode |
| Settings UI | Financial Planner Settings → Market Data Providers (masked keys, data mode) |
| Assistant preset | `examples/openstock-market-analyst.json` blueprint |
| Docs + tests | `TOOL_INDEX.md`, toolkit `README.md`, `test-finnhub-provider.php`, `test-watchlist-sync.php` |

### Sequencing already executed

B1 (provider) → B2 (watchlist + widgets) → B3 (alerts parity + blueprint), matching the priority order recommended in this proposal.

### Deferred (per decision points §14)

- **B4** — media-worker `market` route module (multi-tenant/agency deployments; needs worker v3.4.0 demand).
- **Phase A** — OpenStock sidecar embed (opt-in fallback only).
- **Phase C** — AGPL bridge fork (only on demonstrated demand).
- Repo: `docs/project/proposals/FIREFLY-III-INTEGRATION-PROPOSAL.md` (proposal precedent), `CLAUDE.md` (tool Unix Theory P0–P6 + two-gate sanitization)
