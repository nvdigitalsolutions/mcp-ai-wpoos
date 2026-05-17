# integrations/

## Purpose

Bridges between NV oOS and individual third-party services (GitHub, Cloudflare, Cloudways, Mailjet, Meta, QuickBooks, Site Kit, Simple JWT Login, Auth0, Gravatar, comments, media) — each integration is gated behind a dependency check and contributes OAuth handlers, REST/webhook endpoints, or auth bridges.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/bootstrap/loader.php` (full base; many entries skipped when `WP_MCP_AI_BASE_VERSION` is `true`) and each `*-integration-init.php` sub-bootstrapper |
| **Optional dependencies** | GitHub PAT/OAuth app, Cloudflare API token, Cloudways API key, Mailjet keys, Meta app, QuickBooks app, Google Site Kit, `simple-jwt-login/simple-jwt-login.php`, Auth0 plugin/tenant — every integration short-circuits when its provider is absent |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OAuth_Manager` | `class-wp-mcp-ai-oauth-manager.php` | base Gmail/Drive/Yahoo flows, admin settings |
| `WP_MCP_AI_Github_Client` | `class-wp-mcp-ai-github-client.php` | `addons/pro/includes/src/Tools/*-github-*.php`, GitHub tools |
| `WP_MCP_AI_Github_OAuth_Handler` | `class-wp-mcp-ai-github-oauth-handler.php` | admin settings, container service `integrations.github_oauth` |
| `WP_MCP_AI_Cloudflare_Connection_Handler` | `class-wp-mcp-ai-cloudflare-connection-handler.php` | `cloudflare-integration-init.php`, connection-test AJAX |
| `WP_MCP_AI_Cloudways_OAuth_Handler` | `class-wp-mcp-ai-cloudways-oauth-handler.php` | `cloudways-integration-init.php` |
| `WP_MCP_AI_Mailjet_OAuth_Handler`, `..._Webhook_Handler` | `class-wp-mcp-ai-mailjet-*-handler.php` | `mailjet-integration-init.php`, REST webhook route |
| `WP_MCP_AI_Meta_OAuth_Handler` | `class-wp-mcp-ai-meta-oauth-handler.php` | `meta-integration-init.php` |
| `WP_MCP_AI_QuickBooks_OAuth_Handler` | `class-wp-mcp-ai-quickbooks-oauth-handler.php` | `quickbooks-integration-init.php` |
| `WP_MCP_AI_SiteKit_Integration` | `class-wp-mcp-ai-sitekit-integration.php` | `sitekit-integration-init.php` |
| `WP_MCP_AI_Integration_Simple_JWT` | `class-wp-mcp-ai-integration-simple-jwt.php` | bearer-token authenticator (`wp_mcp_ai_pre_validate_bearer_token` filter) |
| `WP_MCP_AI_Integration_Auth0_Github` | `class-wp-mcp-ai-integration-auth0-github.php` | Auth0 → WP user mapping |
| `WP_MCP_AI_Integration_WordPress_Gravatar` | `class-wp-mcp-ai-integration-wordpress-gravatar.php` | profile-avatar surfaces |
| `WP_MCP_AI_Media`, `WP_MCP_AI_Comments` | `class-wp-mcp-ai-media.php`, `class-wp-mcp-ai-comments.php` | core attachment/comment hooks |
| `WP_MCP_AI_Custom_Tool_Loader` | `class-wp-mcp-ai-custom-tool-loader.php` | tool registry, third-party tool packs |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress options (per-integration credentials), `$_GET` OAuth state (CSRF-protected), upstream REST request bodies, third-party APIs.
- **Writes to:** WordPress options (tokens, refresh tokens), user meta (Auth0/GitHub identity maps), transients (OAuth state nonces), REST responses, optional log entries.
- **Upstream callers:** `includes/bootstrap/loader.php`, `includes/class-wp-mcp-ai-rest.php` (webhook routes), admin settings screens, container DI definitions (`wp_mcp_ai_register_services`).
- **Downstream collaborators:** `includes/http/` (Symfony HTTP client for outbound calls), `includes/class-wp-mcp-ai-http-helper.php`, `includes/class-wp-mcp-ai-credentials.php`, `includes/class-wp-mcp-ai-logger.php`.
- **Events fired:** `wp_mcp_ai_register_services` consumers register handlers; per-integration filters such as `wp_mcp_ai_pre_validate_bearer_token`, `wp_mcp_ai_github_*`, `wp_mcp_ai_mailjet_webhook_*` (see individual classes).
- **Events listened to:** `plugins_loaded`, `admin_init`, `rest_api_init`, `wp_mcp_ai_pre_validate_bearer_token`.

## Conventions

- **Every integration must self-gate.** Use a `class_exists()` / `function_exists()` / `is_plugin_active()` guard at the top of each `*-integration-init.php` and inside `Integration_*::init()` so the file is safe to load even when the third-party provider is absent.
- OAuth handlers use `state` query parameters for CSRF rather than nonces — document the rationale in the class docblock (existing handlers already do).
- Each integration owns its own option keys; do not reach into another integration's options directly.
- OAuth/webhook secrets must be stored via `WP_MCP_AI_Credentials` (encrypted) — never in plaintext options. See [`.context/security-checklist.md`](../../.context/security-checklist.md).
- The Custom Tool Loader is the only entry-point allowed to dynamically load third-party tool packs into the tool registry.

## Tests

```bash
vendor/bin/phpunit tests/test-cloudflare-connection-handler.php
vendor/bin/phpunit tests/test-cloudways-oauth-handler.php
vendor/bin/phpunit tests/test-github-client.php tests/test-github-oauth-handler.php
vendor/bin/phpunit tests/test-meta-oauth-handler.php
vendor/bin/phpunit tests/test-mailjet-webhook.php tests/test-mailjet-tool.php
vendor/bin/phpunit tests/test-custom-tool-loader.php
vendor/bin/phpunit tests/test-comments-integration.php tests/test-media-integration.php
```

Auth0 / Simple-JWT bridges are covered by `tests/test-auth0-*.php` and the REST authentication test suite under `tests/rest-api/`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — token handling, OAuth state, webhook signing (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — webhook & OAuth-callback routes
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — when an integration should move to Pro

## See Also

- Sibling folders: [`includes/http/`](../http/) (outbound HTTP), [`includes/cache/`](../cache/) (token caches), [`includes/admin/`](../admin/) (settings pages that render these integrations)
- Root-level integration shims: `includes/class-wp-mcp-ai-chatkit-integration.php`, `includes/class-wp-mcp-ai-simple-jwt-login-integration.php`
