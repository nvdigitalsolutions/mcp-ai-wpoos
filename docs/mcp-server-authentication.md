# MCP Server Authentication

The MCP server ships as part of the plugin's REST API (`/wp-json/mcp-ai/v1`). It accepts the same credentials that WordPress core understands so you can authenticate assistants without bolting on a separate auth layer.

## Credential mechanism

* **Application passwords** are the primary mechanism for remote assistants. Create them under **Users → Profile → Application Passwords** and store the generated password securely.
* **REST nonces** (`X-WP-Nonce`) remain available for in-dashboard UI or same-origin scripts that already have a logged-in session.

## Supplying credentials

| Client | Required headers | Notes |
| --- | --- | --- |
| Remote MCP assistant | `Authorization: Basic base64(USER:APPLICATION_PASSWORD)` | Uses WordPress' built-in Application Password flow. The username is the WordPress login for the account that issued the password. |
| WordPress dashboard / shortcode UI | `X-WP-Nonce: <nonce from wp_create_nonce('wp_rest')>` | Automatically injected by the plugin's UI scripts when rendering the chat interface. |

## Server-side validation

1. WordPress core authenticates the Basic request and exposes the password UUID via `rest_get_authenticated_app_password()`.
2. `WP_MCP_AI_REST::permissions_check()` detects the application password request, then verifies that the authenticated account has the `edit_posts` capability before dispatching the request.
3. Requests that do not include Basic credentials must supply a valid `X-WP-Nonce`. Nonces are verified with `wp_verify_nonce()` and still require the `edit_posts` capability.

## Error surface area

Every authentication failure is returned as a structured JSON error so MCP clients can respond programmatically:

| Error code | HTTP status | When it fires | Recommended action |
| --- | --- | --- | --- |
| `wp_mcp_ai_missing_credentials` | `401` | No Basic credentials and no nonce supplied. | Include the `Authorization` header generated from an application password or add the `X-WP-Nonce` header for same-origin requests. |
| `rest_invalid_nonce` | `401` | Nonce provided but verification failed. | Refresh the user's session to fetch a new nonce before retrying. |
| `wp_mcp_ai_insufficient_permissions` | `403` | Authenticated user lacks the `edit_posts` capability. | Promote the account (e.g., Author/Editor) or generate a password for a different user that has the capability. |

Each error also contains an `actions` array that mirrors these remediation steps so MCP clients can surface actionable guidance to end users.
