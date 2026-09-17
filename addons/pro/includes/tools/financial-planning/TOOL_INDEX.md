# Financial Planner Toolkit - Tool Index

Quick reference for all 42 financial planning tools.

## Budget & Expense Tracking (4 tools)
1. `budget_planner` - Create and manage budgets with income/expense tracking
2. `expense_tracker` - Log and categorize expenses with receipt tracking
3. `cash_flow_analyzer` - Analyze income vs expenses with cash flow projections
4. `bank_account_sync` - Connect bank accounts via Plaid API for auto-sync

## Net Worth & Assets (1 tool)
5. `net_worth_calculator` - Track total assets and liabilities over time

## Investment & Portfolio (5 tools - EDUCATIONAL)
6. `portfolio_visualizer` - Visualize portfolio allocation and performance metrics
7. `asset_allocation_planner` - Plan optimal allocation by risk tolerance and age
8. `investment_return_calculator` - Calculate compound returns with contributions
9. `rebalancing_analyzer` - Analyze portfolio drift and rebalancing needs
10. `tax_loss_harvesting_tracker` - Track tax-loss harvesting opportunities

## Retirement Planning (4 tools)
11. `retirement_calculator` - Project retirement savings needs and goals
12. `social_security_optimizer` - Optimize Social Security claiming strategy
13. `withdrawal_strategy_planner` - Plan sustainable retirement withdrawals
14. `ira_roth_comparison` - Compare Traditional IRA vs Roth IRA options

## Pension & Benefits (1 tool)
15. `pension_analyzer` - Analyze pension lump sum vs annuity options

## Debt & Loan Management (3 tools)
16. `debt_payoff_calculator` - Calculate debt payoff using avalanche/snowball
17. `mortgage_calculator` - Calculate mortgage payments and refinance analysis
18. `credit_score_tracker` - Track credit score history and improvement tips

## Goal Planning & Savings (2 tools)
19. `savings_goal_planner` - Plan and track multiple savings goals
20. `emergency_fund_calculator` - Calculate emergency fund needs (3-6 months)

## Financial Literacy & Analysis (4 tools)
21. `financial_health_score` - Comprehensive 0-100 financial health assessment
22. `tax_estimator` - Estimate federal tax liability for planning
23. `college_savings_calculator` - Calculate 529 plan savings needs
24. `insurance_needs_analyzer` - Calculate life/disability insurance needs

## Market Analysis & Research (8 tools)
Inspired by [Awesome-finance-skills](https://github.com/RKiding/Awesome-finance-skills) concepts and industry standards.
25. `financial_news_aggregator` - Aggregate financial news from multiple RSS/API sources with trend analysis
26. `stock_data_fetcher` - Search stock tickers and fetch OHLCV data via YFinance service
27. `market_sentiment_analyzer` - Rule-based financial text sentiment analysis (score: -1.0 to +1.0)
28. `market_forecast_analyzer` - Time-series forecasting (linear regression, moving average, exponential smoothing)
29. `investment_signal_tracker` - Track investment signal evolution (strengthen/weaken/falsify)
30. `financial_logic_visualizer` - Generate Mermaid diagrams for financial transmission chains
31. `financial_report_generator` - Generate structured professional financial reports (6 report types)
32. `financial_search` - Specialized multi-source financial web search (SEC EDGAR, Yahoo, Google, etc.)

## Market Data & Portfolio Ops (10 tools — OpenTerminal lessons, v1.1.80)
33. `market_screener` - Whole-market screener (TradingView scanner API; sector, market cap, % change, volume filters)
34. `macro_data_fetcher` - FRED keyless CSV (Treasury yields, T10Y2Y, VIX, fed funds, CPI, unemployment, index proxies)
35. `economic_calendar_fetcher` - Forex Factory public feed (Fed/ECB/CPI/NFP; impact/currency/date filters)
36. `earnings_calendar_fetcher` - Nasdaq earnings calendar (EPS forecast, market cap, timing; symbol filter)
37. `options_chain_fetcher` - Nasdaq options chain (calls/puts: strike, bid/ask, volume, OI, ITM)
38. `crypto_market_data` - Crypto board/quotes/history (CoinGecko with Binance fallback chain)
39. `portfolio_transaction_log` - Buy/sell ledger; average cost + realized/unrealized P&L (`mcp_ai_fin_txn` CPT)
40. `price_alerts` - Price alerts (above/below) with daily cron + `wp_mcp_ai_price_alert_triggered` delivery hook
41. `stock_data_fetcher` `indicators` action - SMA/EMA/VWAP/Bollinger/RSI/MACD computed in PHP from cached OHLCV
42. `financial_news_aggregator` de-duplication - Cross-source headline dedup with merged `sources` list

---

## Tool Classification

### By Capability Flags
- **Pro Only**: All 42 tools
- **Computation**: 36 tools (calculators, analyzers, planners, forecasters, indicators)
- **Database Read**: 11 tools (trackers, history-based tools, signal tracker, transaction ledger)
- **Database Write**: 11 tools (trackers, history-based tools, signal tracker, transaction ledger, price alerts)
- **External API**: 13 tools (bank_account_sync, financial_news_aggregator, stock_data_fetcher, financial_search, market_forecast_analyzer, market_screener, macro_data_fetcher, economic_calendar_fetcher, earnings_calendar_fetcher, options_chain_fetcher, crypto_market_data, portfolio_transaction_log, price_alerts)

### By Educational Disclaimers
Investment tools (6, 7, 8, 9, 10, 26, 27, 28, 29) include "EDUCATIONAL ONLY" disclaimers
indicating they are not investment advice.

### By Data Persistence
- **User Meta Storage**: expense_tracker, bank_account_sync, credit_score_tracker, savings_goal_planner
- **WP Options Storage**: investment_signal_tracker (per-user signal data), price_alerts (per-user alert data)
- **CPT Storage**: portfolio_transaction_log (`mcp_ai_fin_txn` posts)
- **Transient Cache**: financial_news_aggregator, stock_data_fetcher, financial_search, market_screener, macro_data_fetcher, economic_calendar_fetcher, earnings_calendar_fetcher, options_chain_fetcher, crypto_market_data (plus SWR stale copies in the yfinance service)
- **Session/Input Only**: All calculators and analyzers

---

## Usage Notes

All tools require:
- WordPress Pro version (not base version)
- Financial Planner Toolkit enabled in settings
- User capability: `edit_posts`

Investment/tax tools include appropriate disclaimers.

### Market Analysis Tools
The 8 market analysis tools (25-32) bring Wall Street-grade capabilities to the toolkit,
inspired by the [Awesome-finance-skills](https://github.com/RKiding/Awesome-finance-skills)
project and aligned with industry standards from Gartner, Deloitte, and McKinsey:

- **Real-time News**: Aggregate financial news from multiple sources with trend analysis
- **Stock Data**: Search tickers and retrieve OHLCV data via the YFinance microservice
- **Sentiment Analysis**: Score financial text sentiment from -1.0 (bearish) to +1.0 (bullish)
- **Forecasting**: Statistical time-series forecasting with sentiment-aware adjustments
- **Signal Tracking**: Create, evaluate, and track investment signals over time
- **Logic Visualization**: Generate Mermaid diagrams showing market impact transmission chains
- **Report Generation**: Create professional structured reports (portfolio, thesis, risk, earnings)
- **Financial Search**: Query SEC EDGAR, Yahoo Finance, Google Finance, and more
