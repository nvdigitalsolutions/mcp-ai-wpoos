# Deployment Troubleshooting and Security Checklist

This guide captures the most common issues surfaced while testing the MCP REST
layer and the JetEngine proxy tools. Use it during staging deployments and when
triaging production incidents.

## Authentication failures

1. **Missing credentials (401)** – The REST layer now returns the
   `wp_mcp_ai_missing_credentials` error whenever the `Authorization` header or
   `X-WP-Nonce` header is absent. Instruct remote assistants to either attach an
   Auth0 bearer token or a valid WordPress nonce generated with
   `wp_create_nonce( 'wp_rest' )`.
2. **Invalid nonce (401)** – The REST API validates the nonce before checking
   capabilities. Refresh the WordPress session to obtain a fresh nonce and
   resubmit the call.
3. **Insufficient capability (403)** – WordPress users must retain the
   `edit_posts` capability to interact with MCP endpoints. If a request comes
   from a lower-privileged role (Subscriber, Customer, etc.), upgrade the role or
   create a dedicated integration account with the required permissions.
4. **Revoked local tokens (401)** – Credential tokens issued through the
   assistant editor are invalidated immediately after revocation. Confirm the
   credential status in the *API Credentials* meta box or issue a new token.
5. **JWT validation** – When relying on Auth0, ensure the tenant domain matches
   the MCP configuration and that the hosting environment exposes the PHP
   OpenSSL extension so the JWKS signature check can complete.

## JetEngine CRUD proxy issues

1. **Routes unavailable** – If JetEngine is disabled the MCP layer returns
   `wp_mcp_ai_jetengine_missing`. Activate the JetEngine plugin before exposing
   CRUD tools to remote assistants.
2. **Missing `instance` parameter** – All operations except `search_posts`
   require the JetEngine instance key. Ensure assistants include
   `params.instance` in their payloads.
3. **Item identifiers** – `get_item`, `edit_item`, and `delete_item` require the
   `id` argument. Sanitization trims whitespace, so pass the raw identifier rather
   than relying on padding.
4. **Permission mismatches** – JetEngine continues to enforce its native
   capability checks. If the MCP request originates from a user lacking
   `manage_options`, the JetEngine controller will reject the call. Align the
   assistant’s `user_id` context with an administrator or provide custom
   permissions through JetEngine filters.
5. **Transport fallbacks** – The proxy first attempts an internal REST dispatch.
   If a route is missing it falls back to an HTTP request. Review the WordPress
   debug log for `wp_mcp_ai_jetengine_route_unavailable` notices when diagnosing
   connectivity problems.

## Security recommendations

- **Enforce least privilege** – Limit MCP access to roles with the minimal
  `edit_posts` capability required by the REST layer. For assistants that only
  need JetEngine CRUD access, consider creating a dedicated role with scoped
  capabilities.
- **Rotate credentials** – Reissue assistant tokens on a regular cadence and
  revoke unused credentials to reduce exposure from leaked secrets.
- **Mandate TLS** – Serve all MCP endpoints over HTTPS. JetEngine proxy requests
  inherit the site URL, so downgrading to HTTP exposes credential tokens and
  nonce values.
- **Audit logs** – Enable the plugin’s logging features and forward the
  resulting entries (`wp_mcp_ai_authenticated_with_credential`,
  `wp_mcp_ai_jetengine_*`) into your central logging pipeline for continuous
  monitoring.
- **Protect uploads** – Assistants can attach memory files to chat sessions.
  Ensure the WordPress uploads directory inherits hardened permissions and
  disable public indexing to prevent disclosure of sensitive documents.

Keep this document alongside the existing authentication reference so new team
members can quickly diagnose environment regressions.
