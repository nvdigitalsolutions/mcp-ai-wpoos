# Financial Planner Toolkit

> Comprehensive personal- and household-finance tools: budgeting, retirement, investments,
> debt, savings goals, financial literacy, and reporting. 30+ tools.

| | |
|---|---|
| **Activation setting** | `enable_financial_planner_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Financial Planner |
| **Tools** | 30+ across 6 categories |
| **Status** | ⚠️ **Educational / planning aid only — does not constitute financial advice.** |

---

## Tool categories

- **Budgeting & expenses:** `budget_planner`, `expense_tracker`, `cash_flow_analyzer`,
  `bank_account_sync`
- **Retirement & savings:** retirement projections, `emergency_fund_calculator`,
  `college_savings_calculator`
- **Investments:** `asset_allocation_planner`, `investment_return_calculator`, portfolio
  visualization (with optional `yfinance` market data)
- **Debt & credit:** `debt_payoff_calculator`, `credit_score_tracker`,
  `insurance_needs_analyzer`
- **Reports & search:** `financial_report_generator`, `financial_search`,
  `financial_news_aggregator`, `financial_health_score`, `financial_logic_visualizer`

Tool source: `addons/pro/includes/tools/financial-planning/`. The detailed tool list is
in [`TOOL_INDEX.md`](../../includes/tools/financial-planning/TOOL_INDEX.md).

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Financial Planner** under **NV oOS → Settings → Pro Features**.
3. (Optional) Configure a market-data provider (yfinance, Alpha Vantage) and a bank-sync
   service on the toolkit settings page.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/financial-planning/README.md`](../../includes/tools/financial-planning/README.md)
- [`addons/pro/includes/tools/financial-planning/TOOL_INDEX.md`](../../includes/tools/financial-planning/TOOL_INDEX.md)
- [`addons/pro/docs/FINANCIAL_PLANNER_TOOLKIT_PLAN.md`](../FINANCIAL_PLANNER_TOOLKIT_PLAN.md)
- [`addons/pro/docs/YFINANCE_INTEGRATION_GUIDE.md`](../YFINANCE_INTEGRATION_GUIDE.md)
