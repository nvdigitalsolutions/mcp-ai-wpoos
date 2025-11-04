# WP oOS Code Review Report

_Last updated: 2025-11-01_

## Summary
- The REST controller continues to enforce granular capability checks, token scoping, and payload normalisation before dispatching chat or tool executions, keeping high-risk entry points constrained.【F:includes/class-wp-mcp-ai-rest.php†L1481-L1600】【F:includes/class-wp-mcp-ai-rest.php†L2171-L2244】
- Message attachment helpers cap upload sizes, reuse cached OpenAI file IDs safely, and restrict MIME types, reducing the surface area for file-handling bugs.【F:includes/class-wp-mcp-ai-message-attachments.php†L15-L205】【F:includes/class-wp-mcp-ai-message-attachments.php†L360-L456】
- Logging utilities trim stored buffers and persist recent activity/error snapshots in dedicated options for dashboards without polluting autoloaded settings rows.【F:includes/class-wp-mcp-ai-logger.php†L16-L137】【F:includes/class-wp-mcp-ai-logger.php†L611-L662】

## Resolved Findings

### README now documents log persistence (Previously Low)
The public README explains that recent error and activity buffers live in the `wp_mcp_ai_recent_errors` and `wp_mcp_ai_recent_activity` options, giving operators a clear retrieval path without diving into source code.【F:README.md†L589-L603】

## Current Findings

### OpenAI file downloads are fully buffered in PHP (Medium)
`handle_file_download()` pulls the entire OpenAI response body into memory (`strlen()` is called before streaming), so increasing the attachment size cap via filters can exhaust memory or trigger timeouts when large documents are proxied through WordPress. Chunked streaming or file-system spooling would keep the REST layer stable under heavier workloads.【F:includes/class-wp-mcp-ai-rest.php†L2434-L2554】【F:includes/class-wp-mcp-ai-openai-client.php†L333-L404】

### Crawl4AI job cache can balloon (Low)
Remote Crawl4AI jobs persist the raw argument/context payload, optional `raw_response`, and day-long status snapshots in transients without size limits. Large scrape results or verbose context arrays can therefore bloat the options table on cache-less hosts until the TTL expires.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L35-L128】

### Guest token issuance still churns transients (Low)
Every guest chat render mints a new 32-character token stored as a transient keyed by an MD5 hash and refreshed on each validation. High-traffic public embeds on hosts without external object caches can accumulate transient rows faster than they expire, causing avoidable options-table churn.【F:includes/class-wp-mcp-ai-shortcode.php†L209-L294】【F:includes/class-wp-mcp-ai-shortcode.php†L408-L473】

## Action Items
- Stream or spool OpenAI file downloads so large attachments no longer require buffering the entire payload in memory before sending the REST response.【F:includes/class-wp-mcp-ai-rest.php†L2434-L2554】【F:includes/class-wp-mcp-ai-openai-client.php†L333-L404】
- Introduce guardrails for Crawl4AI job persistence (e.g. cap payload size, strip `raw_response`, or shorten TTL) to prevent transient bloat on cache-less installations.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L35-L128】
- Add lightweight rate limiting or cookie-based re-use for guest access tokens so repeated page loads do not create unbounded transient entries on cache-less hosts.【F:includes/class-wp-mcp-ai-shortcode.php†L209-L294】【F:includes/class-wp-mcp-ai-shortcode.php†L408-L473】
