# CRE Debt & Securitization Toolkit

> Commercial real-estate debt analytics: originations, underwriting, debt funds, CMBS,
> and asset-management — 57 tools aligned with **CREFC**, **MBA/CMB**, **ARGUS**,
> **CCIM**, and **CFA/CAIA** industry standards.

| | |
|---|---|
| **Activation setting** | `enable_cre_debt_toolkit` |
| **Per-toolkit settings** | `wp_mcp_ai_cre_debt_settings` |
| **Admin location** | NV oOS → Settings → Pro Features → CRE Debt Toolkit |
| **Tools** | **57** across 5 modules |
| **Custom Post Types** | 2 (Loans, Properties) |
| **Available since** | Pro v2.0.0 |
| **Status** | ⚠️ **Analytical output only — does not constitute investment advice.** |

---

## What it provides

The CRE Debt & Securitization Toolkit is built for institutional-quality commercial
real-estate debt workflow. It targets:

- CRE lenders and originators
- Debt-fund and bridge-lender portfolio managers
- CMBS issuers, B-piece buyers and special servicers
- Asset managers, surveillance teams, and analysts at REITs and real-estate PE shops

It registers two CPTs (`mcp_ai_cre_loan` and `mcp_ai_cre_property`), three admin pages
(Settings, Portfolio Dashboard, Research & Add), and 57 specialized tools organized into
five vertical modules.

The full tool reference table — every slug, name and capability flag — lives in
[`addons/pro/includes/tools/cre-debt/README.md`](../../includes/tools/cre-debt/README.md).
This page is the orientation summary.

---

## Custom post types & admin pages

Registered by `WP_MCP_AI_CRE_Debt_CPT` (file:
`addons/pro/includes/class-wp-mcp-ai-cre-debt-cpt.php`).

| CPT slug | Singular | Purpose |
|---|---|---|
| `mcp_ai_cre_loan` | Loan | Senior, mezz, bridge, construction, CMBS loans |
| `mcp_ai_cre_property` | Property | Office, multifamily, retail, industrial, hospitality |

Admin pages:

| Page | Class | Loaded when… |
|---|---|---|
| Settings | `WP_MCP_AI_CRE_Debt_Settings_Page` | Toolkit enabled |
| Research & Add | `WP_MCP_AI_CRE_Debt_Research_Page` | Toolkit enabled |
| Portfolio Dashboard | `WP_MCP_AI_CRE_Debt_Dashboard_Page` | `wp_mcp_ai_cre_debt_settings.enable_portfolio_dashboard` is truthy (default `true`) |

Admin styles (`assets/css/admin-cre-debt-toolkit.css`) load only on the two CRE Debt CPT
screens.

---

## Tool modules (57 tools)

| Module | Folder | Tools | Focus |
|---|---|---|---|
| Originations | `originations/` | 11 | Pipeline, term sheets, market comps, deal screening, rate-lock, closing |
| Underwriting | `underwriting/` | 13 | DCF, NOI, loan sizing, debt yield, cap-rate sensitivity, environmental risk |
| Debt Fund | `debt-fund/` | 11 | LP reporting, capital calls, returns, waterfalls, covenants, concentration limits |
| CMBS / CRE CLO | `cmbs/` | 10 | Pool & bond modeling, defeasance, special servicing, ratings, surveillance |
| Asset Management | `asset-management/` | 12 | Property performance, lease expirations, CapEx, tenant credit, hold/sell, dispositions |

The detailed tool tables (with capability flags such as `read-only`, `cacheable`,
`state-changing`, etc.) are maintained in the tool-level README link above.

---

## Activation

1. Activate the Pro add-on (a valid license is required).
2. Ensure `WP_MCP_AI_BASE_VERSION` is not `true`.
3. Toggle **CRE Debt Toolkit** under **NV oOS → Settings → Pro Features**.
4. Optionally configure the Portfolio Dashboard and Research & Add pages under
   **NV oOS → Settings → CRE Debt** (`wp_mcp_ai_cre_debt_settings`).

---

## Important notes

- **Analysis only.** Outputs are decision-support, not investment recommendations or
  fairness opinions.
- **Data sourcing.** Integrate market-data feeds (Trepp, Real Capital Analytics, CoStar)
  via the [generic REST API tool](../../includes/tools/class-wp-mcp-ai-tool-generic-rest-api.php)
  or custom data stores. The toolkit does not bundle paid market data.
- **Modeling determinism.** Use the `cacheable` flag on tools that always return the same
  output for the same inputs (cap-rate sensitivity, NOI, amortization) so the agent loop
  can deduplicate calls.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/cre-debt/README.md`](../../includes/tools/cre-debt/README.md) — full tool tables
- [`addons/pro/README.md`](../../README.md) — Pro overview
