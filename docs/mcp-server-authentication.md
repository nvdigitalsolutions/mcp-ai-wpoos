# MCP Server Authentication


The MCP server ships as part of the plugin's REST API (`/wp-json/mcp-ai/v1`). Remote assistants authenticate with Auth0 bearer tokens while in-dashboard tooling continues to leverage native WordPress cookies and nonces.

## Credential mechanism

* **Auth0 bearer tokens** are the primary mechanism for remote assistants. Provision them through your Auth0 tenant using the API identifier configured in the plugin settings.
* **REST nonces** (`X-WP-Nonce`) remain available for the built-in shortcode, dashboard UI, or any same-origin script that operates on behalf of a logged-in user.


## Supplying credentials

| Client | Required headers | Notes |
| --- | --- | --- |
| Remote MCP assistant | `Authorization: Bearer <Auth0 access token>` | Issue an Auth0 access token for your MCP API and transmit it with each request. |
| WordPress dashboard / shortcode UI | `X-WP-Nonce: <nonce from wp_create_nonce('wp_rest')>` | Automatically injected by the plugin's UI scripts when rendering the chat interface. |

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
| `wp_mcp_ai_expired_bearer_token` | `401` | Token has expired. | Request a new Auth0 access token and retry. |
| `wp_mcp_ai_invalid_bearer_audience` | `403` | Token was issued for a different API audience. | Request a token that includes the configured audience value. |
| `wp_mcp_ai_insufficient_bearer_scope` | `403` | Token is missing the required scope. | Request a token that includes the configured scope. |
| `rest_invalid_nonce` | `401` | Nonce provided but verification failed. | Refresh the user's session to fetch a new nonce before retrying. |
| `wp_mcp_ai_insufficient_permissions` | `403` | WordPress-authenticated user lacks the `edit_posts` capability. | Promote the account (e.g., Author/Editor) or switch to a different user. |

Each error also contains an `actions` array that mirrors these remediation steps so MCP clients can surface actionable guidance to end users.
