# Financial Planner Toolkit - Tool Index

Quick reference for all 24 financial planning tools.

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

---

## Tool Classification

### By Capability Flags
- **Pro Only**: All 24 tools
- **Computation**: 20 tools (calculators, analyzers, planners)
- **Database Read**: 7 tools (trackers, history-based tools)
- **Database Write**: 7 tools (trackers, history-based tools)
- **External API**: 1 tool (bank_account_sync)

### By Educational Disclaimers
Investment tools (6, 7, 8, 9, 10) include "EDUCATIONAL ONLY" disclaimers
indicating they are not investment advice.

### By Data Persistence
- **User Meta Storage**: expense_tracker, bank_account_sync, credit_score_tracker, savings_goal_planner
- **Session/Input Only**: All calculators and analyzers

---

## Usage Notes

All tools require:
- WordPress Pro version (not base version)
- Financial Planner Toolkit enabled in settings
- User capability: `edit_posts`

Investment/tax tools include appropriate disclaimers.
