# Authentication with Simple JWT Login

WP MCP AI ships with multiple credential flows. Auth0 remains the default for remote assistants, while WordPress cookies and REST nonces power the dashboard experience. The plugin can also trust bearer tokens minted by the [Simple JWT Login](https://wordpress.org/plugins/simple-jwt-login/) plugin. This guide explains how to enable the integration, configure Simple JWT Login, mint JSON Web Tokens (JWTs), and present them to MCP endpoints.

## Enable the integration

1. **Install and activate Simple JWT Login.** Use the standard WordPress plugin installer or deploy it via Composer/MU plugins. The integration toggle remains disabled until WordPress detects `simple-jwt-login/simple-jwt-login.php` on the site. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
2. **Visit `Settings → WP MCP AI → Authentication`.** Check **Enable Simple JWT Login tokens** to allow bearer tokens validated by Simple JWT Login to reach the MCP REST layer. Save your changes to persist the setting. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
3. **Confirm the dependency stays active.** The integration watches for plugin activation on every request. If Simple JWT Login is deactivated, Simple JWT tokens are ignored and MCP authentication falls back to Auth0/assistant credentials. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L31-L97】

## Configure Simple JWT Login

Simple JWT Login must know how to sign and validate tokens before WP MCP AI will accept them.

* **Secrets and key pairs.** Provide the private key or secret used to sign tokens under **Simple JWT Login → General → JWT Settings**. WP MCP AI reads the configured verification algorithm and public key to validate inbound tokens. Missing keys trigger `wp_mcp_ai_simple_jwt_missing_keys`. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L120-L211】
* **Allowed IPs / hosts.** Restrict the integration to trusted clients with the plugin’s **Allowed IPs** list. Requests from other networks fail with `wp_mcp_ai_simple_jwt_disallowed_ip`. Use CIDR blocks or comma-separated addresses to cover remote assistant infrastructure. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L147-L169】
* **User resolution.** Choose how tokens should map back to WordPress accounts (`email`, `username`, or `user ID`). The integration reuses the plugin’s login settings to locate the user identified in the JWT payload. Tokens pointing to missing users return `wp_mcp_ai_simple_jwt_user_not_found`. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L212-L308】
* **Assistant scoping claims.** Add an `assistant_id` (or `assistantId`/`assistant.id`) claim so WP MCP AI can scope requests to a specific assistant without allowing the client to override that context. Additional scope/permission claims can also be embedded and will propagate to downstream tooling. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L309-L381】

Refer to the [Simple JWT Login documentation](https://docs.simplejwtlogin.com/) for complete setup instructions, including configuring the REST endpoints, JWT payload templates, and hardening recommendations.

## Mint tokens via REST or CLI

Simple JWT Login exposes helpers for generating JWTs:

* **REST helper.** Send a POST request to `https://example.com/wp-json/simple-jwt-login/v1/token` with the credentials defined in the plugin settings. Include any extra claims (such as `assistant_id` or `scope`) in the body/payload configuration. Review the plugin documentation for field names and example payloads.
* **WP-CLI helper.** Run `wp simple-jwt-login generate_token --user=<user_login> --claims='{"assistant_id":123}'` from the WordPress root to mint a token locally. Consult the Simple JWT Login CLI reference for supported arguments and claim injection syntax.

Store minted tokens securely and rotate them according to your security policy.

## Call MCP REST endpoints with JWTs

Once a token is available, add it to the `Authorization` header for any MCP REST route. The REST controller also double-checks that the header matches any token payload provided through other channels, so always use the same bearer string everywhere. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L58-L139】

```bash
curl \
  -H "Authorization: Bearer <JWT>" \
  https://example.com/wp-json/mcp-ai/v1/assistants
```

Tool invocations follow the same pattern:

```bash
curl \
  -X POST \
  -H "Authorization: Bearer <JWT>" \
  -H "Content-Type: application/json" \
  -d '{"assistant_id":123,"messages":[{"role":"user","content":"Hello"}]}' \
  https://example.com/wp-json/mcp-ai/v1/conversations
```

Assistant-scoped tokens ignore attempts to override `assistant_id` in the body: the integration rewrites the request context to match the claim extracted from the JWT payload. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L309-L360】

## Troubleshooting

* **Token expiration.** Expired tokens surface `wp_mcp_ai_simple_jwt_validation_failed` responses from the REST layer. Regenerate the JWT or shorten cache lifetimes if your assistants re-use credentials for too long. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L188-L211】
* **Audience or scope mismatches.** Ensure the claims embedded in the JWT align with the expectations of both Simple JWT Login and WP MCP AI. Missing assistant identifiers or scopes prevent the request from inheriting the proper context and may lead to `wp_mcp_ai_simple_jwt_missing_claim` or authorization failures. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L273-L360】
* **Revoked credentials.** Administrators can revoke tokens inside Simple JWT Login. Revoked tokens return `wp_mcp_ai_simple_jwt_revoked`; generate a fresh token or re-issue access. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L234-L269】
* **Plugin not initialised.** If Simple JWT Login throws configuration errors, WP MCP AI returns `wp_mcp_ai_simple_jwt_login_configuration` or `wp_mcp_ai_simple_jwt_login_invalid_token`. Verify keys, allowed IPs, and the active plugin state. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L98-L219】

For deeper diagnostics, enable WP_DEBUG logging and monitor the REST responses returned by `/wp-json/mcp-ai/v1/...`. The response payloads include actionable `code` and `message` fields alongside remediation hints.
