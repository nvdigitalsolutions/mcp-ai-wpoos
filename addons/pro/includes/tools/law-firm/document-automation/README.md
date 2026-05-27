# Document Automation

## Purpose

Houses 10 law-firm document automation tools: brief outline generation, clause library management, contract review, discovery request building, document drafting (10+ types), template management, version tracking, legal citation checking, pleading generation, and redline comparison.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry (law-firm module) |
| **Optional dependencies** | `enable_law_firm_toolkit` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_LF_Brief_Outline_Generator` | `class-wp-mcp-ai-tool-lf-brief-outline-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Clause_Library_Manager` | `class-wp-mcp-ai-tool-lf-clause-library-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Contract_Reviewer` | `class-wp-mcp-ai-tool-lf-contract-reviewer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Discovery_Request_Builder` | `class-wp-mcp-ai-tool-lf-discovery-request-builder.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Document_Drafter` | `class-wp-mcp-ai-tool-lf-document-drafter.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Document_Template_Manager` | `class-wp-mcp-ai-tool-lf-document-template-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Document_Version_Tracker` | `class-wp-mcp-ai-tool-lf-document-version-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Legal_Citation_Checker` | `class-wp-mcp-ai-tool-lf-legal-citation-checker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Pleading_Generator` | `class-wp-mcp-ai-tool-lf-pleading-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Redline_Comparator` | `class-wp-mcp-ai-tool-lf-redline-comparator.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_document` CPT
- **Writes to:** `mcp_ai_lf_document` CPT (draft documents with structured sections)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** None
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- `WP_MCP_AI_Tool_LF_Document_Drafter` supports 10 document types (contract, pleading, motion, brief, memo, letter, agreement, will, trust, discovery) with type-specific section templates.
- Documents are stored as `mcp_ai_lf_document` CPT with taxonomy `lf_document_type`.
- Write tools require `manage_options`; read-only tools require `edit_posts`.
- Every tool carries the `DISCLAIMER` constant.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/document-automation/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
