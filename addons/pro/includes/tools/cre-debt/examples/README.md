# CRE Debt Toolkit — Assistant Blueprints

Pre-built assistant blueprints for commercial real estate debt workflows. Each blueprint
bundles a curated set of CRE debt toolkits, system prompt, and model configuration
so users can deploy a specialised assistant with a single import.

## Blueprints

| Blueprint | Slug | Focus | Temperature |
|---|---|---|---|
| **CRE Loan Originator** | `loan-originator` | Deal screening, borrower analysis, term sheets, broker pipeline | 0.2 |
| **CMBS Structuring Analyst** | `cmbs-analyst` | Pool analysis, bond cash flow modeling, rating agency stress, surveillance | 0.1 |
| **CRE Debt Fund Manager** | `fund-manager` | Portfolio monitoring, LP reporting, warehouse lines, covenant compliance | 0.2 |

## Usage

Use the **Import CRE Debt Blueprint** tool (`import_cre_debt_blueprint`) to install
any of the three blueprints. The tool delegates to `WP_MCP_AI_Blueprint_Installer` for
file loading, duplicate detection, post creation, and meta population.

### Parameters

- **`blueprint`** (required) — one of `loan-originator`, `cmbs-analyst`, `fund-manager`
- **`overwrite`** (optional, boolean) — whether to overwrite an existing assistant with
  the same name. Defaults to `false`.

### Prerequisites

The CRE Debt Toolkit must be enabled in plugin settings (`enable_cre_debt_toolkit`).
The import tool requires the `edit_posts` capability (or `manage_options` for the
Fund Manager blueprint).

## File format

Each `.json` file follows the `assistant-blueprint.schema.json` schema and contains:

- `blueprint_id` — unique machine-readable identifier
- `name` / `post_title` — human-readable assistant name
- `post_content` — instruction prompt injected into the assistant body
- `meta_input._wp_mcp_ai_tools` — curated tool slugs enabled for the assistant
- `meta_input._wp_mcp_ai_provider`, `_model`, `_temperature` — AI provider configuration
