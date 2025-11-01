# Authentication with Simple JWT Login

WP MCP AI ships with multiple credential flows. Auth0 remains the default for remote assistants, while WordPress cookies and REST nonces power the dashboard experience. The plugin can also trust bearer tokens minted by the [Simple JWT Login](https://wordpress.org/plugins/simple-jwt-login/) plugin. This guide explains how to enable the integration, configure Simple JWT Login, mint JSON Web Tokens (JWTs), and present them to MCP endpoints.

## Enable the integration

1. **Install and activate Simple JWT Login.** Use the standard WordPress plugin installer or deploy it via Composer/MU plugins. The integration toggle remains disabled until WordPress detects `simple-jwt-login/simple-jwt-login.php` on the site. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
2. **Visit `Settings → WP MCP AI → Authentication`.** Check **Enable Simple JWT Login tokens** to allow bearer tokens validated by Simple JWT Login to reach the MCP REST layer. Save your changes to persist the setting. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
3. **Confirm the dependency stays active.** The integration watches for plugin activation on every request. If Simple JWT Login is deactivated, Simple JWT tokens are ignored and MCP authentication falls back to Auth0/assistant credentials. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L31-L97】

## Configure Simple JWT Login

Simple JWT Login must know how to sign and validate tokens before WP MCP AI will accept them. Walk through the configuration wizard under **Simple JWT Login → General → JWT Settings** and confirm each of the following panels:

1. **Route Namespace.** Set the namespace to match the REST route you plan to call (for example, `simple-jwt-login/v1`). This keeps token minting and verification aligned with the REST endpoints the plugin registers. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L58-L139】
2. **JWT Signature block.**
   - **Decryption key source:** Choose **Plugin Settings** so the plugin reads the secret from the fields on this screen.
   - **JWT Decrypt Algorithm:** Pick the algorithm that matches the tokens you will issue (`HS256`, `RS256`, and so on).
   - **JWT Decryption Key:** Paste the shared secret (for HMAC algorithms) or public verification key (for asymmetric algorithms). Keep the strength indicator in the green by using sufficiently long secrets.
   - **JWT Decryption Key is base64 encoded:** Enable this toggle only when you have base64-wrapped the key material; leave it unchecked for raw text secrets.
   The WP MCP AI integration consumes these exact settings when validating bearer tokens, so an incorrect algorithm or key results in `wp_mcp_ai_simple_jwt_missing_keys` failures. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L120-L211】
3. **Lock down the request origin.** Populate **Allowed IPs** with the networks that should be able to exchange JWTs. Any request that resolves to a disallowed address short-circuits with `wp_mcp_ai_simple_jwt_disallowed_ip`, keeping the REST layer from processing rogue traffic. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L147-L169】
4. **Select a user resolution strategy.** Decide whether Simple JWT Login should look up accounts by email, username, or WordPress user ID. WP MCP AI mirrors that preference when resolving the caller and fails with `wp_mcp_ai_simple_jwt_user_not_found` if the decoded payload cannot be matched to a user. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L212-L308】
5. **Add contextual claims.** Optional claims—such as `assistant_id`, `assistantId`, or `assistant.id`—help downstream clients remember which assistant issued the token. The integration validates the token and exposes the decoded payload through the REST filters, but it does not force the REST request to reuse that assistant ID. Harden clients accordingly. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L190】【F:includes/class-wp-mcp-ai-rest.php†L1003-L1287】

Refer to the [Simple JWT Login documentation](https://docs.simplejwtlogin.com/) for complete setup instructions, including configuring the REST endpoints, JWT payload templates, and hardening recommendations.

### Verify the JWT handshake

After saving the JWT settings, confirm that WP MCP AI is wiring the authentication layer correctly:

1. **Trigger a bearer validation.** Call any MCP REST endpoint with the new token and observe that the `wp_mcp_ai_pre_validate_bearer_token` filter now runs via `WP_MCP_AI_Simple_JWT_Login_Integration::pre_validate_bearer_token`. A valid JWT returns `true` and caches the decoded payload for later steps. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L165】
2. **Confirm user mapping.** When the REST controller translates the bearer token into a WordPress user, the integration’s `map_bearer_to_user_id` hook reuses the cached payload to populate the request context. Requests that cannot be mapped surface the same `wp_mcp_ai_simple_jwt_user_not_found` error you would see during direct validation. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L167-L214】
3. **Inspect REST payload hooks.** Successful validation exposes the decoded JWT payload to REST handlers so tools and chat requests can read contextual claims. Use the `wp_mcp_ai_rest_bearer_payload` filter or instrument your own logging to verify that the expected fields (`assistant_id`, scopes, expiry) are present. 【F:includes/class-wp-mcp-ai-rest.php†L912-L1287】

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

> **Caution:** Simple JWT Login tokens do not currently prevent a caller from picking a different `assistant_id` in the request body. The plugin validates the bearer token and resolves the WordPress user, but it does not rewrite the assistant context on your behalf. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L1-L206】

## Troubleshooting

* **Token expiration.** Expired tokens surface `wp_mcp_ai_simple_jwt_validation_failed` responses from the REST layer. Regenerate the JWT or shorten cache lifetimes if your assistants re-use credentials for too long. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L188-L211】
* **Audience or scope mismatches.** Ensure the claims embedded in the JWT align with the expectations of both Simple JWT Login and WP MCP AI. Missing assistant identifiers or scopes prevent the request from inheriting the proper context and may lead to `wp_mcp_ai_simple_jwt_missing_claim` or authorization failures. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L273-L360】
* **Revoked credentials.** Administrators can revoke tokens inside Simple JWT Login. Revoked tokens return `wp_mcp_ai_simple_jwt_revoked`; generate a fresh token or re-issue access. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L234-L269】
* **Plugin not initialised.** If Simple JWT Login throws configuration errors, WP MCP AI returns `wp_mcp_ai_simple_jwt_login_configuration` or `wp_mcp_ai_simple_jwt_login_invalid_token`. Verify keys, allowed IPs, and the active plugin state. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L98-L219】

For deeper diagnostics, enable WP_DEBUG logging and monitor the REST responses returned by `/wp-json/mcp-ai/v1/...`. The response payloads include actionable `code` and `message` fields alongside remediation hints.
