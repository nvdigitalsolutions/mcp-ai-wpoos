# WP MCP AI Code Review Report

_Last updated: 2025-10-31_

## Summary
- Overall separation between REST controllers, tool orchestration, and vendor SDK wrappers remains clean, with strong argument sanitisation and capability checks across high-risk entry points.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L240-L358】
- The previously identified high-risk issues in the group email automation and OpenAI external action tooling have been remediated with strict header validation, attachment size limits, and case-preserving variable filters.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L320-L640】【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L288-L338】
- Logging utilities now provide predictable storage in WordPress options without overloading the PHP error log, enabling downstream monitoring and UI surfacing.【F:includes/class-wp-mcp-ai-logger.php†L16-L118】【F:includes/class-wp-mcp-ai-logger.php†L388-L470】

## Resolved Findings

### Sanitised custom headers in group email tool (Previously High)
User-provided headers are now stripped of control characters, validated against an alphanumeric whitelist, and trimmed before being de-duplicated, closing the email header injection vector noted in the prior audit.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L582-L644】

### Attachment ingestion size guard (Previously Medium)
`parse_email_definition_attachment()` enforces a configurable 1 MiB ceiling (with a filter override), rejects oversized uploads before reading them into memory, and short-circuits when files are empty or unreadable.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L348-L381】

### Case-preserving external action variables (Previously Medium)
Input keys are now cleaned with a character whitelist while retaining original casing, preventing subtle workflow breakages caused by the earlier `sanitize_key()` usage.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L288-L338】

## Current Findings

### Guest token issuance can create transient churn (Low)
Every guest-enabled page view generates a fresh 32-character token stored as a transient keyed by an MD5 hash. High-traffic public embeds could therefore create sustained transient churn in the `wp_options` table (two rows per token on sites without an external object cache) until the one-hour TTL expires.【F:includes/class-wp-mcp-ai-shortcode.php†L209-L294】【F:includes/class-wp-mcp-ai-shortcode.php†L408-L473】 While the risk is primarily performance-related, rate limiting or token re-use would further harden the deployment against automated scraping.

## Action Items
- Add lightweight rate limiting or cookie-based re-use for guest access tokens so repeated page loads do not create unbounded transient entries on cache-less hosts.【F:includes/class-wp-mcp-ai-shortcode.php†L209-L294】【F:includes/class-wp-mcp-ai-shortcode.php†L408-L473】
- Document in the public README where recent activity and error logs are persisted (`wp_mcp_ai_recent_activity` / `wp_mcp_ai_recent_errors`) to help operators locate them during support escalations.【F:includes/class-wp-mcp-ai-logger.php†L22-L117】【F:includes/class-wp-mcp-ai-logger.php†L388-L470】
