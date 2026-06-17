# Law Firm Blueprint Examples

This directory contains four curated assistant blueprints for the Law Firm
Toolkit. Each blueprint models a distinct role within a law firm and comes
pre-configured with the relevant toolset, AI model, and system prompt.

| Blueprint | File | Role Summary |
|---|---|---|
| **Litigation Associate** | `litigation-associate.json` | Civil/commercial litigation: pleadings, discovery, depositions, evidence management, damages, trial prep, and e-discovery. |
| **Managing Partner** | `managing-partner.json` | Firm operations and finance: profitability, utilization, matter pipeline, trust accounts, billing compliance, and competitive benchmarking. |
| **Paralegal Assistant** | `paralegal.json` | Legal support: document drafting and versioning, deadline tracking, client intake, evidence cataloging, citation checking, and template management. |
| **Firm Compliance Officer** | `compliance-officer.json` | Ethics and risk: bar rule compliance, CLE tracking, conflict checks, data privacy, malpractice risk scoring, AI disclosure review, and regulatory monitoring. |

All blueprints use the OpenAI `gpt-4.1` model with low temperatures
(0.1–0.3) to favor deterministic, authoritative outputs appropriate for
legal work.

## Import

Use the `import_law_firm_blueprint` tool (implemented in
`class-wp-mcp-ai-tool-import-law-firm-blueprint.php`) to install a
blueprint programmatically. The tool delegates to the shared
`WP_MCP_AI_Blueprint_Installer` for file loading, duplicate detection,
post insertion, and meta population.

## Schema

All JSON files conform to the schema at:
`https://schemas.nvdigitalsolutions.com/mcp-ai/assistant-blueprint.schema.json`
