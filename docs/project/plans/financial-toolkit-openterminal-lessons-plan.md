# Financial Toolkit — OpenTerminal Lessons: Implementation Plan

**Branch:** `feat/financial-toolkit-openterminal-lessons` (from `origin/alpha-working`)
**Date:** 2026-09-16
**Source of ideas:** [ErTasselli/OpenTerminal](https://github.com/ErTasselli/OpenTerminal) — a free, no-API-key trading terminal whose two signature ideas are **provider fallback chains** (every data endpoint has primary → fallback → fallback) and **stale-while-revalidate caching**.

## Goal

Apply the 8 lessons identified in the comparison of OpenTerminal vs. the NV oOS
Financial Planner Toolkit (`addons/pro/includes/tools/financial-planning/`,
32 tools + yfinance microservice). The toolkit is already stronger on planning
math; the gaps are on the market-data side.

## Current state (verified)

- `WP_MCP_AI_YFinance_Service` (`addons/pro/includes/services/class-wp-mcp-ai-yfinance-service.php`, 457 lines) — single provider (Yahoo via yfinance), TTL transients only (no SWR), delegates via `wp_mcp_ai_yfinance_*` filters to `node-services/yfinance-client.js` (Node/axios) which talks to the Python Flask microservice `addons/pro/services/yfinance/app.py`.
- The Flask microservice has **no authentication** (its own README lists it as an unchecked production item).
- `financial_news_aggregator` merges articles from multiple RSS sources but does **not** de-duplicate across sources.
- `stock_data_fetcher` has actions `search|quote|history|batch_quotes`; no technical indicators.
- No macro, economic-calendar, earnings, options-chain, screener, or crypto tools exist.
- No portfolio transaction ledger / P&L model (only `portfolio_visualizer` + `financial-account-cpt`).
- No price-alert mechanism (the Pro Schedule Manager + result delivery exist as potential consumers).
- CG Pro ecosystem port of the financial toolkit is **complete** for the current surface (`plugins/nvoos-content-graph-pro`), so new/changed files need a follow-up port sub-cluster.

## Clusters

### Cluster A — Resilience: fallback chains + SWR + microservice auth

1. **New helper** `addons/pro/includes/services/class-wp-mcp-ai-market-data-providers.php`
   — pure-PHP, keyless public-endpoint providers (no Node/Python dependency):
   - Stooq CSV (`https://stooq.com/q/l/?s=SYM&f=sd2t2ohlcv&h&e=csv`) — quotes; `q/d/l/?s=SYM&i=d` — daily history.
   - Nasdaq public APIs (require browser-like `User-Agent`/`Accept` headers): quote
     (`/api/quote/{sym}/summary?assetclass=stocks`), option chain
     (`/api/quote/{sym}/option-chain`), earnings calendar (`/api/calendar/earnings?date=…`).
   - CoinGecko public (`/api/v3/coins/markets`, `/api/v3/coins/{id}/market_chart`),
     Binance public klines (`/api/v3/klines`) — crypto.
   - FRED no-key CSV (`https://fred.stlouisfed.org/graph/fredgraph.csv?id=DGS10,DGS2,T10Y2Y,VIXCLS`).
   - TradingView scanner (`POST https://scanner.tradingview.com/america/scan`, JSON-RPC-ish).
   - Forex Factory public calendar feed (`https://nfs.faireconomy.media/ff_calendar_thisweek.json`).
   - Every provider call is time-boxed, sanitized, wrapped in `try/catch` (via
     `wp_remote_get` + response-code checks) and returns `array|WP_Error`; each
     provider has its own filter seam (`wp_mcp_ai_market_data_provider_<slug>_request`).
2. **Rework `WP_MCP_AI_YFinance_Service`** (keep the public API backward compatible):
   - Add `fetch_with_fallback( $fetcher )` — run primary (yfinance filter chain)
     then the configured fallback chain (`stooq` → `nasdaq` for quotes/history;
     `coingecko` → `binance` for crypto); stop at first non-error result and
     stamp `source` + `fallback_used` on the payload. Chain is filterable via
     `wp_mcp_ai_market_data_fallback_chain`.
   - **SWR caching:** store `{ ts, data }` under the fresh transient (current TTL)
     and the payload under a long-lived stale transient (e.g. 7 days). On fresh
     miss + provider failure, serve the stale copy with `stale => true`,
     `data_age_seconds`, and `stale_while_revalidate` metadata; refresh in the
     background only when the request originated from an admin/CLI context
     (avoid background work on front-end requests).
   - Add `get_api_key()` reading `yfinance_api_key` from `wp_mcp_ai_settings`
     (filterable), and pass it in every `$params` blob.
3. **Microservice auth** (OpenTerminal's `data/.api-key` pattern):
   - `app.py`: on startup, load or generate a 32-byte hex secret into
     `data/.api-key` (dir from `DATA_DIR` env, default alongside the app);
     `before_request` requires `X-API-Key` matching (constant-time compare),
     exempting `GET /health`; CORS restricted to `WEB_ORIGIN` env when set.
   - `yfinance-client.js`: send `X-API-Key` from `params.api_key` or
     `YFINANCE_API_KEY` env on every request.
   - `npm-integration-filters.php` yfinance handlers: pass `api_key` through.
   - `WP_MCP_AI_Financial_Planner_Settings_Page::render_configuration_tab()`:
     add a `yfinance_api_key` password field next to the service URL.

### Cluster B — Six new market-data tools (pure PHP, keyless)

New files under `addons/pro/includes/tools/financial-planning/` (class prefix
`WP_MCP_AI_Tool_*`, `edit_posts` gate, toolkit-enabled gate, canonical envelope,
sanitize-at-entry, educational disclaimers), registered in the financial map in
`wp_mcp_ai_pro_register_tools()`:

| Tool slug | Source | Actions / inputs |
|---|---|---|
| `market_screener` | TradingView scanner API | `market` (america/crypto), `filters` (sector, market_cap, price change %, volume), `limit` |
| `macro_data_fetcher` | FRED fredgraph.csv (no key) | `series` list (DGS10, DGS2, DGS30, T10Y2Y, VIXCLS, DFF, CPIAUCSL, UNRATE), `range` |
| `economic_calendar_fetcher` | Forex Factory public JSON | `date` / `days_ahead`, `currency`, `impact`, `include_forecasts` |
| `earnings_calendar_fetcher` | Nasdaq earnings API | `date` / `days_ahead`, `symbol` |
| `options_chain_fetcher` | Nasdaq option-chain API | `ticker`, `expiration`, `limit` |
| `crypto_market_data` | CoinGecko → Binance fallback | `action` (board/quote/history), `symbols`, `currency`, `days` |

Each uses the Cluster A provider helper for caching + fallback + SWR.

### Cluster C — Technical indicators

- **New utility** `addons/pro/includes/services/class-wp-mcp-ai-technical-indicators.php`
  — pure-PHP, no dependencies: SMA, EMA, VWAP, Bollinger Bands, RSI (Wilder),
  MACD (12/26/9) over OHLCV arrays; per-indicator filter seams
  (`wp_mcp_ai_indicator_<slug>`); returns null for insufficient data.
- **`stock_data_fetcher`**: add `indicators` action (`ticker`, `period`, `interval`,
  `indicators` list) — fetches history through the resilient service and computes
  the requested indicators + a compact summary (last value, signal hint).

### Cluster D — News de-duplication

`financial_news_aggregator::execute()`: after merging sources (before sorting),
dedupe by normalized title (lowercase, strip punctuation/whitespace/HTML); the
first-seen article keeps its entry and gains a `sources` array; envelope gains
`duplicates_removed` count. Existing single-source behavior unchanged.

### Cluster E — Portfolio transaction ledger + P&L

- **New CPT** `addons/pro/includes/class-wp-mcp-ai-financial-transaction-cpt.php`
  — `mcp_ai_fin_txn`, hidden menu, toolkit-gated registration; meta: ticker,
  side (buy/sell), quantity, price, fee, executed_at, currency.
- **New tool** `portfolio_transaction_log` — actions: `add`, `list`, `remove`,
  `position_summary` (per-ticker average cost, quantity, realized P&L via
  matched sells, unrealized P&L via current prices from the resilient yfinance
  service, falling back to manual/recorded price with `price_source` stamped).
- Wire the CPT require into `financial-planning/init.php`; register the tool in
  the monolith map.

### Cluster F — Price alerts

- **New tool** `price_alerts` — actions: `create`, `list`, `delete`, `check_now`
  (ticker, condition above/below/crosses, threshold, optional note). Storage:
  per-user option `wp_mcp_ai_price_alerts_{user_id}` (max 50 alerts/user).
- **Daily cron** `wp_mcp_ai_price_alert_check_daily` (scheduled in the financial
  init with a `wp_next_scheduled()` guard; unscheduled on toolkit-disable):
  evaluate every alert via batch prices (resilient service), fire the filterable
  `wp_mcp_ai_price_alert_triggered` hook for each newly-triggered alert (the
  integration seam for result delivery — Telegram/email/schedules), record
  trigger history per alert, and dedupe repeated triggers per day.

### Cluster G — Tests + docs

- `addons/pro/tests/test-financial-resilience.php` — provider helper (stooq CSV
  parsing via mocked `wp_remote_get`, fallback ordering, SWR stale serve,
  API-key pass-through).
- `addons/pro/tests/test-financial-market-data-tools.php` — 6 new tools:
  surfaces, gates, argument validation, mocked-HTTP execute paths.
- `addons/pro/tests/test-technical-indicators.php` — deterministic OHLCV series
  (SMA/EMA/RSI/MACD known values, insufficient-data degradation).
- `addons/pro/tests/test-financial-portfolio-transactions.php` — CPT registration,
  add/list/remove, position summary math, realized/unrealized P&L.
- `addons/pro/tests/test-financial-price-alerts.php` — CRUD, cap, check_now with
  mocked prices, cron scheduling guard, trigger hook.
- Extend `test-financial-market-analysis-tools.php` — news dedup contract +
  `stock_data_fetcher` indicators action surface.
- Docs: `financial-planning/TOOL_INDEX.md`, `financial-planning/README.md`,
  `docs/reference/tools/tool-reference.md` catalogue entries.

### Cluster H — Validation

- `php -l` every touched file; phpcs (`phpcs.xml.dist` + CG Pro standard) on
  changed files; Docker PHPUnit runs (WP 6.9 + WP 7.1, no concurrent runs);
  targeted suites first, then the surrounding financial suites.

### Cluster I — CG Pro ecosystem port (follow-up sub-cluster)

Per `.agents/skills/mcp-ai-wpoos-ecosystem-port/SKILL.md`: port the changed/new
files byte-identically into `plugins/nvoos-content-graph-pro/src/` with the
documented transforms (port header, strict types, text domain, path swaps),
extend the financial init's tool filter + ecosystem registration, add
characterization tests, update `docs/project/ecosystem-port-tracker.md`
(financial row tail), validate both matrices, open the port PR.

## Out of scope (documented)

- The React widget dashboard / Express+SQLite backend — architecture, not a lesson.
- Provider licensing is unchanged: all sources are public endpoints used
  for educational purposes only; existing disclaimers remain and new tools
  carry the same wording.
- CG Pro port of the microservice itself (Python service is deployment-only,
  not part of the WordPress addon tree).
