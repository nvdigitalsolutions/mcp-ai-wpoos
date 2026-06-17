# Cloudways Toolkit — API Client & Helpers

## Purpose

Provides the authenticated HTTP client (`WP_MCP_AI_Cloudways_Client`) and shared helper functions for the Cloudways Pro Toolkit. The client handles OAuth token exchange, caching, automatic refresh, and uniform `WP_Error` mapping for all Cloudways API v2 requests. Helpers provide toolkit-enabled checks, credential validation, and shared parameter schema fragments.

## Tier

- **Tier:** Pro only (requires `enable_cloudways_toolkit` setting + Cloudways account credentials)
- **PHP target:** 7.4
- **Optional deps:** None (uses WordPress `wp_remote_*` and settings API)

## Public Surface

- `WP_MCP_AI_Cloudways_Client::instance()` — singleton accessor
- `WP_MCP_AI_Cloudways_Client::is_configured()` — bool, are credentials present?
- `WP_MCP_AI_Cloudways_Client::get( $path, $query )` — authenticated GET → `array|WP_Error`
- `WP_MCP_AI_Cloudways_Client::post( $path, $body )` — authenticated POST → `array|WP_Error`
- `WP_MCP_AI_Cloudways_Client::put( $path, $body )` — authenticated PUT → `array|WP_Error`
- `WP_MCP_AI_Cloudways_Client::delete( $path, $body )` — authenticated DELETE → `array|WP_Error`
- `WP_MCP_AI_Cloudways_Client::disconnect()` — clear cached tokens
- `wp_mcp_ai_is_cloudways_toolkit_enabled()` — bool
- `wp_mcp_ai_cloudways_has_credentials()` — bool
- `wp_mcp_ai_cloudways_param_*()` — shared JSON Schema parameter fragments

## Inputs / Outputs / Neighbors

- **Inputs:** Reads `wp_mcp_ai_settings` option for `cloudways_email`, `cloudways_api_key`, `cloudways_access_token`, `cloudways_token_expires_at`
- **Outputs:** Writes refreshed `cloudways_access_token` and `cloudways_token_expires_at` back to `wp_mcp_ai_settings`; HTTP requests to `https://api.cloudways.com/api/v2`
- **Neighbors:** Consumed by all tools in `tools/cloudways/` and the admin dashboard
- **Filters:** `wp_mcp_ai_cloudways_api_base`, `wp_mcp_ai_cloudways_oauth_endpoint`

## Conventions

- Singleton (private constructor, `instance()` accessor)
- Token cached in settings with 60s expiry buffer
- All non-2xx HTTP responses map to typed `WP_Error` (`cloudways_unauthorized`, `cloudways_rate_limited`, `cloudways_not_found`)
- Timeout from `WP_MCP_AI_Resource_Manager` or settings
- `Authorization` header redacted in any debug/log path

## Tests

`addons/pro/tests/cloudways/test-cloudways-client.php` — token caching, refresh, error mapping, base URL filter.

## Also Load

- [`.context/security-checklist.md`](../../../../.context/security-checklist.md)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md)
- [`CLAUDE.md`](../../../../CLAUDE.md)
