# Authentication with Simple JWT Login

WP oOS ships with multiple credential flows. Auth0 remains the default for remote assistants, while WordPress cookies and REST nonces power the dashboard experience. The plugin can also trust bearer tokens minted by the [Simple JWT Login](https://wordpress.org/plugins/simple-jwt-login/) plugin. This guide explains how to enable the integration, configure Simple JWT Login, mint JSON Web Tokens (JWTs), and present them to MCP endpoints.

## Enable the integration

1. **Install and activate Simple JWT Login.** Use the standard WordPress plugin installer or deploy it via Composer/MU plugins. The integration toggle remains disabled until WordPress detects `simple-jwt-login/simple-jwt-login.php` on the site. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
2. **Visit `Settings → WP oOS → Authentication`.** Check **Enable Simple JWT Login tokens** to allow bearer tokens validated by Simple JWT Login to reach the MCP REST layer. Save your changes to persist the setting. 【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1227-L2177】
3. **Confirm the dependency stays active.** The integration watches for plugin activation on every request. If Simple JWT Login is deactivated, Simple JWT tokens are ignored and MCP authentication falls back to Auth0/assistant credentials. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L31-L97】

## Configure Simple JWT Login

Simple JWT Login must know how to sign and validate tokens before WP oOS will accept them. Walk through the configuration wizard under **Simple JWT Login → General → JWT Settings** and confirm each of the following panels:

1. **Route Namespace.** Set the namespace to match the REST route you plan to call (for example, `simple-jwt-login/v1`). This keeps token minting and verification aligned with the REST endpoints the plugin registers. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L58-L139】
2. **JWT Signature block.**
   - **Decryption key source:** Choose **Plugin Settings** so the plugin reads the secret from the fields on this screen.
   - **JWT Decrypt Algorithm:** Pick the algorithm that matches the tokens you will issue (`HS256`, `RS256`, and so on).
   - **JWT Decryption Key:** Paste the shared secret (for HMAC algorithms) or public verification key (for asymmetric algorithms). Keep the strength indicator in the green by using sufficiently long secrets.
   - **JWT Decryption Key is base64 encoded:** Enable this toggle only when you have base64-wrapped the key material; leave it unchecked for raw text secrets.
   The WP oOS integration consumes these exact settings when validating bearer tokens, so an incorrect algorithm or key results in `wp_mcp_ai_simple_jwt_missing_keys` failures. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L120-L211】
3. **Lock down the request origin.** Populate **Allowed IPs** with the networks that should be able to exchange JWTs. Any request that resolves to a disallowed address short-circuits with `wp_mcp_ai_simple_jwt_disallowed_ip`, keeping the REST layer from processing rogue traffic. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L147-L169】
4. **Select a user resolution strategy.** Decide whether Simple JWT Login should look up accounts by email, username, or WordPress user ID. WP oOS mirrors that preference when resolving the caller and fails with `wp_mcp_ai_simple_jwt_user_not_found` if the decoded payload cannot be matched to a user. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L212-L308】
5. **Add contextual claims.** Optional claims—such as `assistant_id`, `assistantId`, or `assistant.id`—help downstream clients remember which assistant issued the token. When present, WP oOS now scopes REST requests to the encoded assistant automatically and rejects attempts to hop between assistants with `wp_mcp_ai_assistant_scope_mismatch` errors. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】

Refer to the [Simple JWT Login documentation](https://docs.simplejwtlogin.com/) for complete setup instructions, including configuring the REST endpoints, JWT payload templates, and hardening recommendations.

### Verify the JWT handshake

After saving the JWT settings, confirm that WP oOS is wiring the authentication layer correctly:

1. **Trigger a bearer validation.** Call any MCP REST endpoint with the new token and observe that the `wp_mcp_ai_pre_validate_bearer_token` filter now runs via `WP_MCP_AI_Simple_JWT_Login_Integration::pre_validate_bearer_token`. The helper first defers to Simple JWT Login’s validation service and, when necessary, falls back to manually decoding the JWT while caching the payload for later steps. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L214】
2. **Confirm user mapping.** When the REST controller translates the bearer token into a WordPress user, the integration’s `map_bearer_to_user_id` hook reuses the cached payload to populate the request context. Requests that cannot be mapped surface the same `wp_mcp_ai_simple_jwt_user_not_found` error you would see during direct validation. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L167-L214】
3. **Inspect REST payload hooks.** Successful validation exposes the decoded JWT payload to REST handlers so tools and chat requests can read contextual claims. Use the `wp_mcp_ai_bearer_token_payload` filter or instrument your own logging to verify that the expected fields (`assistant_id`, scopes, expiry) are present. 【F:includes/class-wp-mcp-ai-rest.php†L1064-L1105】【F:includes/class-wp-mcp-ai-rest.php†L1264-L1287】

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
  -d '{"assistant_id":123,"messages":[{"role":"user","content":[{"type":"text","text":"Hello"}]}]}' \
  https://example.com/wp-json/mcp-ai/v1/chat
```

Assistant overrides are now blocked server-side; if a token authenticates for one assistant and the request targets another, the REST layer returns `wp_mcp_ai_assistant_scope_mismatch` with remediation guidance so clients can prompt for a new credential. 【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】

## Troubleshooting

* **Token expiration.** Expired tokens surface `wp_mcp_ai_simple_jwt_validation_failed` responses from the REST layer. Regenerate the JWT or shorten cache lifetimes if your assistants re-use credentials for too long. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L188-L211】
* **Audience or scope mismatches.** Ensure the claims embedded in the JWT align with the expectations of both Simple JWT Login and WP oOS. Missing assistant identifiers or scopes prevent the request from inheriting the proper context and may lead to `wp_mcp_ai_simple_jwt_missing_claim` or authorization failures. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L273-L360】
* **Revoked credentials.** Administrators can revoke tokens inside Simple JWT Login. Revoked tokens return `wp_mcp_ai_simple_jwt_revoked`; generate a fresh token or re-issue access. 【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L234-L269】
* **Plugin not initialised.** If Simple JWT Login throws configuration errors, WP oOS returns `wp_mcp_ai_simple_jwt_login_configuration` or `wp_mcp_ai_simple_jwt_login_invalid_token`. Verify keys, allowed IPs, and the active plugin state. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L98-L219】
* **Validation service exceptions.** When Simple JWT Login’s validation service fails to produce a response, WP oOS falls back to decoding the JWT directly and mirrors any remaining errors in the REST response. Inspect `wp_mcp_ai_simple_jwt_login_invalid_token` payloads for the captured exception message. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L108-L214】

For deeper diagnostics, enable WP_DEBUG logging and monitor the REST responses returned by `/wp-json/mcp-ai/v1/...`. The response payloads include actionable `code` and `message` fields alongside remediation hints.

# Authentication with WordPress.com/Gravatar Identity Bridge

WP oOS includes a first-class identity bridge for WordPress.com and Gravatar OAuth/OIDC providers. This integration detects verifiable tokens issued by the "new Gravatar identity network" and automatically maps them to WordPress users, enriching the authentication payload with profile fields (display name, avatar URL, Gravatar hash) for clean audit trails.

## How it works

The WordPress/Gravatar bridge operates similarly to the Auth0→GitHub integration:

1. **Subject detection.** When a bearer token payload contains a subject prefixed with `wordpress.com|` or `gravatar|`, the integration enriches the payload with WordPress-specific metadata.
2. **Profile enrichment.** The integration extracts profile fields (email, display name, avatar URL, Gravatar hash) from the token payload and merges them with cached or remote profile data when available.
3. **User mapping.** The integration locates existing WordPress users by matching stored metadata or creates new subscribers when needed, ensuring every authenticated request has a clean audit trail.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L1-L630】

## Enable the integration

1. **Visit `Settings → WP oOS → Authentication`.** Locate the **WordPress.com/Gravatar Bridge** section.
2. **Check the enable toggle.** Activate **Enable WordPress.com/Gravatar identity bridge** to allow tokens with `wordpress.com|` or `gravatar|` subjects to reach the authentication layer.
3. **(Optional) Configure userinfo endpoint.** If your OAuth provider exposes a custom userinfo endpoint, enter it in the **Userinfo Endpoint URL** field. Leave blank to skip remote profile fetching when the token payload contains sufficient information.
4. **Save settings.** The integration activates immediately and begins processing matching bearer tokens.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L175-L201】

## Supported OAuth providers

The integration works with any OAuth 2.0 or OIDC provider that:

* Issues JWT bearer tokens with a subject claim (`sub`) prefixed with `wordpress.com|` or `gravatar|`
* Includes user profile data in the token payload (email, name, username) or exposes a userinfo endpoint
* Follows standard OAuth 2.0 bearer token conventions

Common providers include:

* **WordPress.com OAuth** — Official WordPress.com OAuth service with Gravatar integration
* **Gravatar OAuth** — Gravatar's identity service (when available)
* **Auth0 with WordPress.com connection** — Auth0 configured with a WordPress.com social connection

## Token payload enrichment

When the integration detects a WordPress.com or Gravatar subject, it automatically enriches the token payload with:

* **`wordpress_user_id`** — The unique identifier extracted from the subject (e.g., `12345` from `wordpress.com|12345`)
* **`gravatar_hash`** — MD5 hash of the user's email address for Gravatar avatar lookups, or the hash provided in the token
* **`display_name`** — User's display name from the profile, when available
* **`picture`** — Avatar URL from the profile, mapped to the `picture` claim for compatibility

These enriched claims are available to all MCP tools and chat handlers, allowing for personalized responses and accurate user attribution.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L62-L107】

## User mapping and creation

The integration follows this workflow to map tokens to WordPress users:

1. **Check for existing mapping.** Search for users with stored subject or WordPress ID metadata that matches the token.
2. **Locate by email.** If no metadata match exists, attempt to find users by email address.
3. **Fetch remote profile.** When the token payload lacks required fields (email, username), query the configured userinfo endpoint.
4. **Create new user.** If no existing user is found and the token provides an email address, create a new subscriber account with:
   - **Username** — Derived from the token's username claim or generated from the WordPress ID
   - **Email** — From the token's email claim (required)
   - **Display name** — From the token's name claim or falls back to username
   - **Password** — Randomly generated 32-character password
   - **Role** — Subscriber (can be filtered via WordPress hooks)
5. **Sync metadata.** Store the subject, WordPress ID, and Gravatar hash in user meta for future lookups and profile synchronization.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L113-L166】【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L351-L429】

## Example token payload

A typical WordPress.com OAuth token might look like:

```json
{
  "sub": "wordpress.com|123456789",
  "email": "user@example.com",
  "name": "John Doe",
  "username": "johndoe",
  "picture": "https://gravatar.com/avatar/abc123...",
  "aud": "your-client-id",
  "iat": 1699900000,
  "exp": 1699910000
}
```

After enrichment, the payload includes:

```json
{
  "sub": "wordpress.com|123456789",
  "email": "user@example.com",
  "name": "John Doe",
  "username": "johndoe",
  "picture": "https://gravatar.com/avatar/abc123...",
  "wordpress_user_id": "123456789",
  "gravatar_hash": "5d41402abc4b2a76b9719d911017c592",
  "display_name": "John Doe",
  "aud": "your-client-id",
  "iat": 1699900000,
  "exp": 1699910000
}
```

## Call MCP REST endpoints with WordPress.com tokens

Once your OAuth provider is configured, include the bearer token in the `Authorization` header:

```bash
curl \
  -H "Authorization: Bearer <WORDPRESS_OAUTH_TOKEN>" \
  https://example.com/wp-json/mcp-ai/v1/assistants
```

Chat requests follow the same pattern:

```bash
curl \
  -X POST \
  -H "Authorization: Bearer <WORDPRESS_OAUTH_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"assistant_id":123,"messages":[{"role":"user","content":[{"type":"text","text":"Hello"}]}]}' \
  https://example.com/wp-json/mcp-ai/v1/chat
```

The integration automatically resolves the token to a WordPress user and populates the request context with user ID and profile metadata.

## Stored user metadata

The integration stores the following metadata on mapped WordPress users:

* **`_wp_mcp_ai_wordpress_gravatar_subject`** — Full subject identifier (e.g., `wordpress.com|123456789`)
* **`_wp_mcp_ai_wordpress_id`** — WordPress.com user ID extracted from the subject
* **`_wp_mcp_ai_gravatar_hash`** — MD5 hash of the user's email for Gravatar lookups

These fields allow the integration to quickly locate existing users and synchronize profile updates on subsequent authentications.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L18-L20】【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L430-L469】

## Troubleshooting

* **Missing email address.** User creation fails with `wp_mcp_ai_wordpress_gravatar_missing_email` when the token payload and userinfo endpoint do not provide an email. Ensure your OAuth provider includes the email claim in issued tokens.
* **Userinfo endpoint failures.** If the configured endpoint returns non-200 responses, the integration falls back to the token payload. Verify the endpoint URL and ensure the bearer token has sufficient scope to access profile information.
* **Integration not running.** If WordPress.com/Gravatar tokens are not being processed, verify the **Enable WordPress.com/Gravatar identity bridge** setting is checked and saved in `Settings → WP oOS → Authentication`.
* **User mapping conflicts.** When multiple users have the same email address, the integration returns the first match found. Consider enforcing unique email addresses or implementing custom user resolution logic via the `wp_mcp_ai_map_bearer_to_user_id` filter.

Enable `WP_DEBUG` logging to capture detailed integration activity including subject detection, profile enrichment, and user mapping results.

## Security considerations

* **Token validation.** The WordPress/Gravatar bridge relies on the OAuth introspection MU plugin or your OAuth provider's token validation. Always configure proper token verification (signature validation, expiry checks, audience verification).
* **User creation permissions.** New users are created as subscribers by default. Filter the `wp_insert_user` call if you need to assign different roles based on token claims.
* **Profile synchronization.** The integration updates display names on each authentication. Implement custom logic if you need to preserve manual profile changes or restrict automated updates.
* **Metadata storage.** Subject identifiers and WordPress IDs are stored in user meta. Ensure your privacy policy covers OAuth identity mapping and metadata retention.

【F:includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php†L175-L201】
