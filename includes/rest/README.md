# REST

## Purpose

Implements the MCP-compliant REST controllers mounted under `/wp-json/mcp-ai/v1/*` — chat, tools, MCP protocol methods, SSE streaming, A2A, approvals, teams, slash-commands, workflows, transcript mining, analytics, and the multi-method authenticator that gates them all.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | The top-level dispatcher [`includes/class-wp-mcp-ai-rest.php`](../class-wp-mcp-ai-rest.php) instantiates and registers each controller from its `register_routes()`; auxiliary controllers (approvals, workflow CPT/run, triggers) are wired by [`includes/bootstrap/loader.php`](../bootstrap/loader.php); teams via [`includes/class-wp-mcp-ai-container.php`](../class-wp-mcp-ai-container.php) |
| **Optional dependencies** | A2A server (toggled by setting); JetEngine (for CCT-backed responses); Auth0 (for bearer-token auth) |

## Public Surface

All controllers extend `WP_MCP_AI_REST_Controller_Base` and share its `REST_NAMESPACE = 'mcp-ai/v1'`. The folder's contract is the route set, not the controller classes — clients consume `/wp-json/mcp-ai/v1/<route>`.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_REST_Controller_Base` (abstract) | `class-wp-mcp-ai-rest-controller-base.php` | Every controller in this folder + Pro REST controllers |
| `WP_MCP_AI_REST_Authenticator` | `class-wp-mcp-ai-rest-authenticator.php` | All controllers — nonce / bearer credential / Auth0 / guest token / mesh API key |
| `WP_MCP_AI_REST_Validator` | `class-wp-mcp-ai-rest-validator.php` | All controllers — request shape + schema enforcement |
| `WP_MCP_AI_REST_Chat_Controller` | `class-wp-mcp-ai-rest-chat-controller.php` | `POST /chat`, `POST /chat/save`, related transcript routes |
| `WP_MCP_AI_REST_Chat_Memory_Controller` | `class-wp-mcp-ai-rest-chat-memory-controller.php` | `/chat-memory/*` (long-term memory toggle, autosummarise) |
| `WP_MCP_AI_REST_Chat_Session_Stream_Controller` | `class-wp-mcp-ai-rest-chat-session-stream-controller.php` | Per-session SSE stream |
| `WP_MCP_AI_REST_MCP_Controller` | `class-wp-mcp-ai-rest-mcp-controller.php` | MCP protocol JSON-RPC methods |
| `WP_MCP_AI_REST_Tools_Controller` | `class-wp-mcp-ai-rest-tools-controller.php` | `POST /tools`, `GET /tools` |
| `WP_MCP_AI_REST_A2A_Controller` | `class-wp-mcp-ai-rest-a2a-controller.php` | Agent-to-agent JSON-RPC (when enabled) |
| `WP_MCP_AI_REST_Approval_Controller` | `class-wp-mcp-ai-rest-approval-controller.php` | Approval queue routes |
| `WP_MCP_AI_REST_Teams_Controller` | `class-wp-mcp-ai-rest-teams-controller.php` | Teams CRUD + invocation |
| `WP_MCP_AI_REST_Slash_Command_Controller` | `class-wp-mcp-ai-rest-slash-command-controller.php` | Slash-command surface |
| `WP_MCP_AI_REST_Threads_Controller` | `class-wp-mcp-ai-rest-threads-controller.php` | Thread CRUD + messages (9 routes) |
| `WP_MCP_AI_REST_Profiles_Controller` | `class-wp-mcp-ai-rest-profiles-controller.php` | Tool permission profiles (9 routes) |
| `WP_MCP_AI_REST_Checkpoints_Controller` | `class-wp-mcp-ai-rest-checkpoints-controller.php` | State snapshots + diff (4 routes) |
| `WP_MCP_AI_REST_Context_Controller` | `class-wp-mcp-ai-rest-context-controller.php` | @-mention autocomplete (2 routes) |
| `WP_MCP_AI_REST_Commands_Controller` | `class-wp-mcp-ai-rest-commands-controller.php` | Command palette (1 route) |
| `WP_MCP_AI_REST_Workflow_CPT_Controller`, `WP_MCP_AI_REST_Workflow_Run_Controller`, `WP_MCP_AI_REST_Triggers_Controller` | `class-wp-mcp-ai-rest-workflow-*.php`, `class-wp-mcp-ai-rest-triggers-controller.php` | Workflow CRUD + runs + triggers |
| `WP_MCP_AI_REST_Transcript_Mining_Controller` | `class-wp-mcp-ai-rest-transcript-mining-controller.php` | Background transcript-mining jobs |
| `WP_MCP_AI_REST_Restrictions_Controller` | `class-wp-mcp-ai-rest-restrictions-controller.php` | User-restriction routes: `GET /restrictions`, `GET|POST /users/{id}/restrictions`, `DELETE /users/{id}/restrictions/{type}` |
| `WP_MCP_AI_Rate_Limit_Headers` | `class-wp-mcp-ai-rate-limit-headers.php` | IETF rate-limit response headers (`RateLimit-Policy`, `RateLimit`, `Retry-After`) on rate-limited REST responses |
| `WP_MCP_AI_REST_Analytics_Manager`, `WP_MCP_AI_REST_Cost_Manager`, `WP_MCP_AI_REST_Token_Manager` | `class-wp-mcp-ai-rest-{analytics,cost,token}-manager.php` | Admin analytics dashboards |
| `WP_MCP_AI_SSE_Handler` | `class-wp-mcp-ai-sse-handler.php` | Server-Sent Events transport |
| `WP_MCP_AI_Asset_Inventory_REST`, `WP_MCP_AI_Security_Training_REST`, `WP_MCP_AI_Supplier_Security_REST` | `class-wp-mcp-ai-{asset-inventory,security-training,supplier-security}-rest.php` | Compliance dashboard routes |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_REST_Request` (JSON / multipart body, query, headers); the tool registry; assistant CPT meta; the credentials store; settings options; the rate-limit manager; the SSE rate limiter; the markup store
- **Writes to:** `WP_REST_Response` (or SSE frames); credential post meta; chat transcripts (localStorage + optional JetEngine CCT); job queues; the logger
- **Upstream callers:** chat-bubble frontend (`assets/js/chat.js`), Elementor widgets, external MCP clients, Auth0-authenticated apps, mesh peers, guest chat surfaces
- **Downstream collaborators:** [`includes/tools/`](../tools/), [`includes/services/`](../services/), [`includes/repositories/`](../repositories/), [`includes/markup/`](../markup/) (for `markup_elicitation` SSE frames), [`includes/validators/`](../validators/), [`includes/cache/`](../cache/) (REST response cache)
- **Events fired:** `wp_mcp_ai_rest_request_start`, `wp_mcp_ai_rest_request_complete`, `wp_mcp_ai_chat_message_received`, `wp_mcp_ai_tool_invoked_via_rest`, `wp_mcp_ai_rate_limit_exceeded` (fired by the OOS rate-limiter adapter, consumed by the restriction registry), plus standard WP REST filters
- **Events listened to:** `rest_api_init` (each controller registers there), `rest_authentication_errors` (authenticator), `rest_pre_dispatch` (cache lookup), `rest_post_dispatch` (security headers)

## Conventions

Folder-specific deltas (canonical rules in [`.context/rest-api.md`](../../.context/rest-api.md)):

- Every controller extends `WP_MCP_AI_REST_Controller_Base` and uses its `REST_NAMESPACE` constant — never hard-code `'mcp-ai/v1'`.
- Authentication goes through `WP_MCP_AI_REST_Authenticator`, never ad-hoc nonce / token checks. Supports: WP nonce (`X-WP-Nonce`), assistant bearer (`cred_xxxxx.SECRET`), Auth0, mesh API key, guest token (`X-WP-MCP-AI-Guest`).
- Request shape validation goes through `WP_MCP_AI_REST_Validator`; argument-level validation reuses [`includes/validators/`](../validators/).
- Mutating routes MUST declare a `permission_callback` that defers to the authenticator + capability check — never `__return_true`.
- SSE responses MUST go through `WP_MCP_AI_SSE_Handler` so heartbeat, flush, and rate-limit behaviour stay consistent.
- Controllers that extend `WP_REST_Controller` directly (rather than this folder's base) MUST add security headers manually — see `WP_MCP_AI_WebChat_Signaling_REST_Controller` for the documented exception.

## Tests

```bash
vendor/bin/phpunit tests/test-rest-controller-base.php
vendor/bin/phpunit tests/test-rest-authenticator.php
vendor/bin/phpunit tests/test-rest-chat-controller.php
vendor/bin/phpunit tests/test-rest-tools-controller.php
vendor/bin/phpunit tests/test-rest-mcp-controller.php
vendor/bin/phpunit tests/test-rest-restrictions-controller.php
vendor/bin/phpunit tests/test-rate-limit-headers.php
vendor/bin/phpunit tests/test-restriction-registry.php
vendor/bin/phpunit tests/test-restriction-instrumentation.php
vendor/bin/phpunit tests/rest/      # additional REST endpoint suite
vendor/bin/phpunit tests/rest-api/  # REST integration suite
```

Full `tests/test-rest-*.php` set covers permission callbacks, authentication modes, error handling, SSE framing, validator behaviour, and per-route business logic.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability / nonce / sanitisation rules (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — canonical REST patterns, auth modes, error envelope
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — for `/tools` and chat tool-invocation paths
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — for `/chat`, `/sse`, transcript routes
- [`docs/reference/api/rest-api.md`](../../docs/reference/api/rest-api.md) — operator-facing endpoint reference

## See Also

- Sibling surfaces: [`includes/tools/`](../tools/), [`includes/cli/`](../cli/) — the two other entry-point surfaces
- Top-level dispatcher: [`includes/class-wp-mcp-ai-rest.php`](../class-wp-mcp-ai-rest.php)
- Collaborators: [`includes/markup/`](../markup/) (elicitation REST controller), [`includes/validators/`](../validators/), [`includes/services/`](../services/)
