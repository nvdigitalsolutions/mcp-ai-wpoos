# Developer Toolkit

> Developer-oriented utility tools for code formatting, CLI checks, geospatial analysis, REST API testing, and EML evaluation.

## Purpose

General-purpose developer tools that don't fit into a specific business domain toolkit. These provide utility functions for code quality, system diagnostics, geospatial computation, and email template testing.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Analyze Geospatial | `analyze_geospatial` | Perform geospatial calculations and analysis |
| Check WP CLI | `check_wp_cli` | Verify WP-CLI availability and configuration |
| Evaluate EML | `evaluate_eml` | Parse and evaluate .eml email files |
| Format Code (Prettier) | `format_code_prettier` | Format code using Prettier via Node.js microservice |
| Generic REST API | `generic_rest_api` | Make arbitrary REST API calls to external services |

## Dependencies

- WordPress 6.0+
- Node.js + Prettier (for code formatting)
- WP-CLI (for CLI checks)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [NPM Integration: `addons/pro/includes/npm-integration-filters.php`](../../npm-integration-filters.php)
