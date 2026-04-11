# CRE Debt & Securitization Pro Toolkit

**57 specialized tools** for commercial real estate debt professionals, organized across five vertical modules.

Aligned with **CREFC**, **MBA/CMB**, **ARGUS**, **CCIM**, and **CFA/CAIA** industry standards.

> **ANALYSIS ONLY** — All tool outputs are for analytical purposes only and do not constitute investment advice.

## Settings

- **Settings key:** `enable_cre_debt_toolkit`
- **Location:** NV oOS → Settings → Pro Features

## Modules

### Originations (11 tools)

| Tool Slug | Tool Name | Capability Flags |
|-----------|-----------|-----------------|
| `cre_deal_pipeline_manager` | CRE Deal Pipeline Manager | write, state-changing |
| `cre_borrower_profile_analyzer` | Borrower Profile Analyzer | read-only, cacheable |
| `cre_loan_quote_generator` | Loan Quote Generator | read-only |
| `cre_market_comp_analyzer` | Market Comparable Analyzer | read-only, cacheable |
| `cre_deal_screening_calculator` | Deal Screening Calculator | read-only, cacheable |
| `cre_origination_volume_tracker` | Origination Volume Tracker | read-only |
| `cre_rate_lock_manager` | Rate Lock Manager | read-only |
| `cre_broker_relationship_tracker` | Broker Relationship Tracker | write, state-changing |
| `cre_term_sheet_comparator` | Term Sheet Comparator | read-only, cacheable |
| `cre_execution_strategy_advisor` | Execution Strategy Advisor | read-only |
| `cre_closing_checklist_manager` | Closing Checklist Manager | write, state-changing |

### Underwriting (13 tools)

| Tool Slug | Tool Name | Capability Flags |
|-----------|-----------|-----------------|
| `cre_dcf_modeler` | CRE DCF Modeler | read-only, cacheable |
| `cre_noi_calculator` | NOI Calculator | read-only, cacheable |
| `cre_loan_sizer` | Loan Sizer | read-only, cacheable |
| `cre_amortization_scheduler` | Amortization Schedule Generator | read-only, cacheable |
| `cre_debt_yield_analyzer` | Debt Yield Analyzer | read-only, cacheable |
| `cre_cap_rate_sensitivity` | Cap Rate Sensitivity Analyzer | read-only, cacheable |
| `cre_rent_roll_analyzer` | Rent Roll Analyzer | read-only, cacheable |
| `cre_operating_expense_benchmarker` | Operating Expense Benchmarker | read-only, cacheable |
| `cre_stress_test_modeler` | Stress Test Modeler | read-only, cacheable |
| `cre_leverage_return_analyzer` | Leverage & Return Analyzer | read-only, cacheable |
| `cre_property_valuation_engine` | Property Valuation Engine | read-only, cacheable |
| `cre_environmental_risk_scorer` | Environmental Risk Scorer | read-only, cacheable |
| `cre_underwriting_memo_generator` | Underwriting Memo Generator | read-only |

### CMBS / Securitization (10 tools)

| Tool Slug | Tool Name | Capability Flags |
|-----------|-----------|-----------------|
| `cmbs_deal_structurer` | CMBS Deal Structurer | read-only |
| `cmbs_bond_cash_flow_modeler` | CMBS Bond Cash Flow Modeler | read-only, cacheable |
| `cmbs_pool_analyzer` | CMBS Pool Analyzer | read-only, cacheable |
| `cmbs_surveillance_monitor` | CMBS Surveillance Monitor | read-only |
| `cmbs_special_servicing_tracker` | Special Servicing Tracker | write, state-changing |
| `cre_clo_modeler` | CRE CLO Modeler | read-only, cacheable |
| `cmbs_defeasance_calculator` | CMBS Defeasance Calculator | read-only, cacheable |
| `cmbs_rating_agency_analyzer` | Rating Agency Methodology Analyzer | read-only, cacheable |
| `cmbs_investor_reporting_generator` | CMBS Investor Reporting Generator | read-only |
| `cmbs_maturity_risk_analyzer` | Maturity & Refinancing Risk Analyzer | read-only, cacheable |

### Debt Fund Management (11 tools)

| Tool Slug | Tool Name | Capability Flags |
|-----------|-----------|-----------------|
| `cre_fund_portfolio_dashboard` | Fund Portfolio Dashboard | read-only |
| `cre_debt_waterfall_modeler` | Debt Waterfall Modeler | read-only, cacheable |
| `cre_fund_return_calculator` | Fund Return Calculator | read-only, cacheable |
| `cre_credit_risk_scorer` | Credit Risk Scorer | read-only, cacheable |
| `cre_concentration_limit_monitor` | Concentration Limit Monitor | read-only |
| `cre_warehouse_line_manager` | Warehouse Line Manager | write, state-changing |
| `cre_lp_report_generator` | LP Report Generator | read-only |
| `cre_fund_capital_call_calculator` | Capital Call Calculator | read-only, cacheable |
| `cre_fund_liquidity_analyzer` | Fund Liquidity Analyzer | read-only, cacheable |
| `cre_covenant_compliance_checker` | Covenant Compliance Checker | read-only |
| `cre_fund_scenario_modeler` | Fund Scenario Modeler | read-only, cacheable |

### Asset Management (12 tools)

| Tool Slug | Tool Name | Capability Flags |
|-----------|-----------|-----------------|
| `cre_property_budget_manager` | Property Budget Manager | write, state-changing |
| `cre_lease_expiration_manager` | Lease Expiration Manager | read-only |
| `cre_capex_reserve_planner` | CapEx Reserve Planner | read-only |
| `cre_tenant_credit_analyzer` | Tenant Credit Analyzer | read-only, cacheable |
| `cre_hold_sell_analyzer` | Hold/Sell Decision Analyzer | read-only, cacheable |
| `cre_property_performance_tracker` | Property Performance Tracker | read-only |
| `cre_loan_surveillance_dashboard` | Loan Surveillance Dashboard | read-only |
| `cre_watchlist_manager` | Watchlist Manager | write, state-changing |
| `cre_workout_scenario_modeler` | Workout Scenario Modeler | read-only, cacheable |
| `cre_loan_modification_calculator` | Loan Modification Calculator | read-only, cacheable |
| `cre_servicing_fee_calculator` | Servicing Fee Calculator | read-only, cacheable |
| `cre_asset_disposition_analyzer` | Asset Disposition Analyzer | read-only, cacheable |

## Shared Calculator

`class-wp-mcp-ai-cre-debt-calculator.php` provides core financial math:

- Amortization schedule generation (IO + P&I + balloon)
- DSCR, LTV, debt yield calculations
- NPV, IRR (Newton's method), DCF with terminal value
- Cap rate and NOI math
- Equity waterfall distribution modeling
- Loan sizing against multiple constraints
- Defeasance and yield maintenance calculations

## Required Capabilities

- **Read-only/analysis tools:** `edit_posts`
- **Write/state-changing tools:** `manage_options`
