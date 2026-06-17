# Financial Planning Blueprints

Curated assistant blueprints for the Financial Planning Toolkit. Each blueprint pre-configures an AI assistant with a specialized system prompt, a hand-picked tool set, and conservative model parameters.

## Blueprints

| File | Blueprint | Focus |
|---|---|---|
| `wealth-advisor.json` | Wealth Management Advisor | Portfolio analysis, rebalancing, tax-loss harvesting, market signals, net worth, IRA/Roth comparisons, insurance gaps |
| `retirement-planner.json` | Retirement Planning Specialist | Retirement projections, Social Security optimization, pension analysis, withdrawal strategies, 529 plans, stress-testing |
| `budget-coach.json` | Personal Budget Coach | Monthly budgets, expense tracking, debt payoff (avalanche/snowball), cash flow, mortgage affordability, credit score |

## Import Tool

Use the `import_financial_planning_blueprint` tool (`class-wp-mcp-ai-tool-import-financial-planning-blueprint.php`) to install any blueprint as a WordPress assistant post. The tool delegates to `WP_MCP_AI_Blueprint_Installer` for file loading, duplicate detection, post insertion, and meta population.

### Parameters

- **blueprint** (required) — One of: `wealth-advisor`, `retirement-planner`, `budget-coach`
- **overwrite** (optional, boolean) — Whether to overwrite an existing assistant with the same name

### Availability

The import tool is only available when the Financial Planner Toolkit is enabled via `wp_mcp_ai_settings['enable_financial_planner_toolkit']`.
