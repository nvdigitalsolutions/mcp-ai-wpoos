# MCP OAuth resource-server contract

## Purpose

Implements the site-side OAuth 2.1 resource-server surface for the
ChatGPT plugin bridge ([`addons/chatgpt-plugin/`](../../addons/chatgpt-plugin/)):
the RFC 9728 protected-resource metadata document, `WWW-Authenticate`
challenges, the OpenAI plugin domain-verification challenge, per-tool
`securitySchemes`, and the `nvoos_get_profile` profile-tool schema.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/bootstrap/loader.php`](../bootstrap/loader.php) (requires + `::init()`) |
| **Optional dependencies** | Auth0 (settings `auth0_domain`) — every surface stays silent until an authorization server is configured |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OAuth_Resource_Server` (static helpers) | `class-wp-mcp-ai-oauth-server.php` | REST MCP controller (`WWW-Authenticate` on 401, tool-call challenges), `WP_MCP_AI_REST_Authenticator` (audience acceptance), `tools/list` (`securitySchemes`, profile `_meta`), the two well-known endpoints |
| `WP_MCP_AI_Well_Known_OAuth_Protected_Resource` | `class-wp-mcp-ai-well-known-oauth-protected-resource.php` | Serves `GET /.well-known/oauth-protected-resource` |
| `WP_MCP_AI_Well_Known_OpenAI_Challenge` | `class-wp-mcp-ai-well-known-openai-challenge.php` | Serves `GET /.well-known/openai-apps-challenge` (plain-text token, 404 when unset) |

## Inputs / Outputs / Neighbors

- **Reads from:** plugin settings (`auth0_domain`, `auth0_audience`), the
  `wp_mcp_ai_openai_apps_challenge_token` option, tool capability flags.
- **Writes to:** HTTP responses only (`application/json` metadata document,
  `text/plain` challenge, `WWW-Authenticate` header) — no database writes.
- **Upstream callers:** ChatGPT/Codex OAuth clients, MCP clients following
  the MCP authorization spec.
- **Downstream collaborators:** [`includes/rest/`](../rest/) (challenge
  emission), [`includes/tools/`](../tools/) (`nvoos_get_profile` tool),
  [`includes/class-wp-mcp-ai-rest-mcp-methods.php`](../class-wp-mcp-ai-rest-mcp-methods.php)
  (`securitySchemes` in `tools/list`).

## Conventions

- Every public surface is gated on `WP_MCP_AI_OAuth_Resource_Server::is_configured()`
  so sites without Auth0 keep today's behaviour.
- Well-known endpoints follow the Pro addon's `/.well-known/mcp` pattern:
  `add_rewrite_rule` + query var + `template_redirect` (priority 5) +
  `redirect_canonical` guard; cache headers via dedicated filters.
- Rewrite rules are flushed by the plugin's existing deferred activation
  flush (`wp_mcp_ai_flush_rewrite_rules` transient) — no extra activation
  hooks in this folder.

## Tests

```bash
vendor/bin/phpunit tests/rest/test-rest-mcp-oauth-resource-server.php
vendor/bin/phpunit tests/security/test-mcp-oauth-token-validation.php
```

## Also Load

- [`.context/rest-api.md`](../../.context/rest-api.md) — REST patterns and auth modes
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability / escaping rules
- [`docs/project/proposals/053-chatgpt-plugin-addon-implementation-plan.md`](../../docs/project/proposals/053-chatgpt-plugin-addon-implementation-plan.md) — the plan this folder implements
