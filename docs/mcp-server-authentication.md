# MCP Server Authentication


The MCP server ships as part of the plugin's REST API (`/wp-json/mcp-ai/v1`). Remote assistants authenticate with Auth0 bearer tokens while in-dashboard tooling continues to leverage native WordPress cookies and nonces.

## Credential mechanism

* **Auth0 bearer tokens** are the primary mechanism for remote assistants. Provision them through your Auth0 tenant using the API identifier configured in the plugin settings.
* **Assistant-issued tokens** are minted from the assistant editor’s **API Credentials** meta box, returned once (in the form `cred_xxxxx.SECRET`), and hashed immediately after display so secrets never persist in plain text.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】【F:includes/class-wp-mcp-ai-credentials.php†L94-L135】
* **Simple JWT Login tokens** (optional) reuse the JWTs minted by the Simple JWT Login plugin. WP MCP AI validates them via Simple JWT Login’s services, falls back to manual decoding when necessary, and imports assistant or scope claims so REST requests inherit the correct context.【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L214】【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】
* **REST nonces** (`X-WP-Nonce`) remain available for the built-in shortcode, dashboard UI, or any same-origin script that operates on behalf of a logged-in user.


## Supplying credentials

| Client | Required headers | Notes |
| --- | --- | --- |
| Remote MCP assistant | `Authorization: Bearer <Auth0 access token>` | Issue an Auth0 access token for your MCP API and transmit it with each request. |
| Remote MCP assistant (assistant-issued credential) | `Authorization: Bearer cred_xxxxx.SECRET` | Tokens generated in the assistant editor validate directly against the MCP REST layer and automatically scope the request to the issuing assistant. Compatible with LM Studio, Claude Desktop, and other MCP clients that accept raw bearer secrets.【F:includes/class-wp-mcp-ai-rest.php†L316-L444】【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】 |
| Remote MCP assistant (Simple JWT Login) | `Authorization: Bearer <JWT>` | Available when Simple JWT Login integration is enabled. Tokens are validated via the plugin’s services (with a manual JWT fallback), mapped to WordPress users, and scoped to the assistant encoded in the claims. 【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L214】【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】 |
| WordPress dashboard / shortcode UI | `X-WP-Nonce: <nonce from wp_create_nonce('wp_rest')>` | Automatically injected by the plugin's UI scripts when rendering the chat interface. |
| Guest visitors (chat shortcode, Elementor) | `X-WP-MCP-AI-Guest: <temporary token>` (or `guest_token` query/body param) | Shortcodes that enable `allow_guests="true"` mint a one-hour token and pass it to the REST layer so unauthenticated visitors can continue conversations without exposing privileged credentials.【F:includes/class-wp-mcp-ai-shortcode.php†L31-L226】【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】 |

> **ChatGPT connectors:** OpenAI’s current beta requires an Auth0 tenant for connector authentication, so the assistant-issued credentials described here cannot be supplied directly yet. Until ChatGPT adds native bearer-token support, connect through Auth0 or use MCP clients such as LM Studio or Claude Desktop that accept the plugin’s `cred_xxxxx.SECRET` tokens out of the box.

## Assistant-issued credentials

Use the assistant editor’s **API Credentials** meta box to generate tokens for partners that cannot integrate with Auth0. Only administrators (`manage_options`) can view or manage the credential list, which records who created the token and allows revocation or deletion at any time.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】 Present the issued token to the integrator immediately—once the page reloads the secret is hashed and no longer recoverable.【F:includes/class-wp-mcp-ai-credentials.php†L94-L135】

When a request arrives with `Authorization: Bearer cred_xxxxx.SECRET`, the REST controller validates the token, attaches the issuing assistant to the request context, and blocks attempts to override the `assistant_id` parameter with a different assistant.【F:includes/class-wp-mcp-ai-rest.php†L316-L444】【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】 Revoked tokens fail fast with actionable errors so client applications can prompt operators to issue a replacement.【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】 Simple JWT Login bearer tokens follow the same rules—the integration populates the assistant scope from JWT claims and the REST layer rejects cross-assistant requests automatically.【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】

## Guest access tokens

Temporary guest tokens are generated by the classic chat shortcode and the Elementor widget whenever `allow_guests="true"` is enabled. Each token is scoped to a single assistant, stored for one hour, and automatically attached to REST calls using the `X-WP-MCP-AI-Guest` header (falling back to a `guest_token` parameter for clients that cannot set custom headers). The REST controller recognises these tokens, switches the request to the matching assistant, and bypasses capability checks that would normally require `edit_posts` privileges while still enforcing attachment limits and tool restrictions.【F:includes/class-wp-mcp-ai-shortcode.php†L31-L331】【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】

## Testing credentials quickly

Use the bundled `wp mcp-ai remote` command to verify bearer tokens, guest tokens, or WordPress nonces before handing them to an external partner. Provide the REST base (`https://example.com/wp-json/mcp-ai/v1`) alongside the relevant flags (`--token`, `--guest-token`, `--nonce`, or `--assistant-id`) and the CLI will replay the `/assistants` request with matching headers before issuing a lightweight `/chat` probe. Successful runs echo the detected assistant count, chat probe status, and token scope, while failures surface the remote REST error codes so you know whether the credential, SSL layer, or network path needs attention.【F:includes/class-wp-mcp-ai-cli-command.php†L137-L280】【F:includes/class-wp-mcp-ai-remote-tester.php†L29-L331】

## Server-side validation

1. `WP_MCP_AI_REST::permissions_check()` extracts the bearer token, normalises the Auth0 domain configured in **Settings → WP MCP AI**, and downloads the tenant's JWKS (cached for one hour).
2. The token header and payload are decoded, the signature is verified against the JWKS public key via OpenSSL, and the `iss`, `aud`, and optional scope claims are enforced.
3. If validation succeeds the request proceeds; otherwise, a structured error describing the remediation steps is returned to the client. WordPress-authenticated requests still require an `X-WP-Nonce` header and the `edit_posts` capability.
4. Custom validation logic can hook into `wp_mcp_ai_pre_validate_bearer_token` to short-circuit the process or `wp_mcp_ai_bearer_token_payload` to inspect claims.

## Error surface area

Every authentication failure is returned as a structured JSON error so MCP clients can respond programmatically:

| Error code | HTTP status | When it fires | Recommended action |
| --- | --- | --- | --- |
| `wp_mcp_ai_missing_credentials` | `401` | No bearer token and no nonce supplied. | Include the `Authorization: Bearer` header or add the `X-WP-Nonce` header for same-origin requests. |
| `wp_mcp_ai_invalid_bearer_token` | `401` | Token structure, signature, or issuer is invalid. | Request a new Auth0 access token and retry. |
| `wp_mcp_ai_invalid_token` | `401` | Assistant-issued credential is malformed or does not match stored hashes. | Issue a new assistant credential from the editor and update the client’s configuration.【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】 |
| `wp_mcp_ai_revoked_token` | `401` | Assistant-issued credential was revoked in the editor UI. | Generate a fresh credential or reinstate access as needed.【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】 |
| `wp_mcp_ai_assistant_scope_mismatch` | `403` | Token tried to access a different assistant than the one that issued it. | Remove the overriding `assistant_id` or provide a credential minted for that assistant.【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】 |
| `wp_mcp_ai_expired_bearer_token` | `401` | Token has expired. | Request a new Auth0 access token and retry. |
| `wp_mcp_ai_invalid_bearer_audience` | `403` | Token was issued for a different API audience. | Request a token that includes the configured audience value. |
| `wp_mcp_ai_insufficient_bearer_scope` | `403` | Token is missing the required scope. | Request a token that includes the configured scope. |
| `rest_invalid_nonce` | `401` | Nonce provided but verification failed. | Refresh the user's session to fetch a new nonce before retrying. |
| `wp_mcp_ai_insufficient_permissions` | `403` | WordPress-authenticated user lacks the `edit_posts` capability. | Promote the account (e.g., Author/Editor) or switch to a different user. |

Each error also contains an `actions` array that mirrors these remediation steps so MCP clients can surface actionable guidance to end users.

Refer to [docs/rest-api.md](rest-api.md) for endpoint-specific payload shapes and integration guidance.
