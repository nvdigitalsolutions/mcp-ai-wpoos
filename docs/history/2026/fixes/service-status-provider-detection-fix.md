# Service Status AI-Provider Detection — Fix Details

## Problem Description

The Service Status dashboard's AI-provider detection reported the wrong
provider set. Providers configured through non-settings sources — WordPress
7.0+ Connectors, environment variables, or PHP constants — appeared as
"not configured" even though the plugin could use them, while providers with
credentials stored in the separate credentials option were missed entirely
when only the merged settings array was consulted.

## Root Cause

`WP_MCP_AI_Service_Status_AI_Providers::get_configured_providers()` read the
raw `wp_mcp_ai_settings` option and checked a hardcoded list of
`{slug}_api_key` keys. That approach:

- ignored the credentials option merged by
  `WP_MCP_AI_Admin_Settings_Base::get_settings()`,
- ignored WP 7.0 Connectors, environment variables, and PHP constants that the
  canonical `WP_MCP_AI_Credential_Resolver` supports,
- had no early-bootstrap fallback when the resolver class was not loaded yet.

## Solution Implemented

File: `includes/services/class-wp-mcp-ai-service-status-default-sources.php`

1. **Canonical source first** — when `WP_MCP_AI_Credential_Resolver` is
   loaded, provider detection calls `has_credentials( $slug )` for each
   keyed provider (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter), which
   resolves plugin settings, WP 7.0 Connectors, environment variables, and
   PHP constants.
2. **Early-bootstrap fallback** — before the resolver is available, the merged
   settings array (`WP_MCP_AI_Admin_Settings_Base::get_settings()`, credentials
   folded in and decrypted) is read directly with the `{slug}_api_key` keys.
3. **Ollama stays keyless** — detected by the presence of a base URL, as
   before.

## Test Coverage

New `tests/test-service-status-provider-detection.php` (135 lines):

- provider detection via plugin settings with and without the resolver,
- credentials-option fallback path,
- Ollama keyless detection,
- WP 7.0 bridge opt-out isolation so the test suite's WP 7.0 shim does not
  interfere.

## Related

- [PR #5874](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5874)
