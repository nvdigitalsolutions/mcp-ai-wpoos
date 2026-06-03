# Pro Providers

## Purpose

Hosts Pro-only AI provider clients — currently the NV oOS Cloud HTTP client and its `Interface_WP_MCP_AI_Provider_Client` adapter — so the Base language-model router can treat the NV-hosted Cloudflare-Worker → OpenRouter gateway uniformly alongside OpenAI, Anthropic, Gemini, and OpenRouter.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`../nv-cloud-init.php`](../nv-cloud-init.php) via `require_once` of both files; the adapter is registered with the Base language-model router through the `wp_mcp_ai_provider_clients` filter in the same init |
| **Optional dependencies** | NV oOS Cloud SaaS account + NV Connect Token; Cloudflare AI Gateway availability; Base [`../../../../includes/infrastructure/providers/`](../../../../includes/infrastructure/providers/) (concrete OpenRouter client that NV Cloud subclasses) |

## Public Surface

The router talks to `WP_MCP_AI_NV_Cloud_Provider_Client`; Pro code that needs raw access to chat-completion / model-list calls instantiates `WP_MCP_AI_NV_Cloud_Client` directly.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_NV_Cloud_Client` | `class-wp-mcp-ai-nv-cloud-client.php` | [`../services/class-wp-mcp-ai-nv-cloud-service.php`](../services/class-wp-mcp-ai-nv-cloud-service.php), [`../services/class-wp-mcp-ai-nv-cloud-billing-observer.php`](../services/class-wp-mcp-ai-nv-cloud-billing-observer.php), the provider-client adapter below |
| `WP_MCP_AI_NV_Cloud_Provider_Client` | `class-wp-mcp-ai-nv-cloud-provider-client.php` | Base language-model router (via `wp_mcp_ai_provider_clients` filter), Pro chat surfaces |

## Inputs / Outputs / Neighbors

- **Reads from:** NV Connect Token from [`../services/class-wp-mcp-ai-nv-cloud-service.php`](../services/class-wp-mcp-ai-nv-cloud-service.php), base URL from the `WP_MCP_AI_NV_CLOUD_BASE_URL` constant / `wp_mcp_ai_nv_cloud_base_url` filter / option fallback, message + tool payloads from the caller (router / chat service).
- **Writes to:** outbound HTTPS to the NV oOS Cloud gateway (default `https://nvoos.cloud/v1`), provider-response telemetry via the Base cost-tracking observer (custom response headers carry wholesale cost so the billing observer can record the 7% retail markup).
- **Upstream callers:** Base language-model router (`WP_MCP_AI_LLM_Router`), Pro chat surfaces, [`../rest/class-wp-mcp-ai-nv-cloud-rest-controller.php`](../rest/class-wp-mcp-ai-nv-cloud-rest-controller.php), [`../admin/class-wp-mcp-ai-nv-cloud-settings-page.php`](../admin/class-wp-mcp-ai-nv-cloud-settings-page.php).
- **Downstream collaborators:** Base [`../../../../includes/infrastructure/providers/`](../../../../includes/infrastructure/providers/) (the OpenRouter client this folder subclasses for request/response shape), Base [`../../../../includes/interfaces/`](../../../../includes/interfaces/) (`Interface_WP_MCP_AI_Provider_Client`), [`../services/`](../services/) (NV Cloud service + billing observer).
- **Events fired:** the standard `wp_mcp_ai_provider_*` request/response triplet inherited from the OpenRouter client, plus `wp_mcp_ai_nv_cloud_cost_calculated` for the billing path.
- **Events listened to:** none directly — wiring is performed by [`../nv-cloud-init.php`](../nv-cloud-init.php).

## Conventions

Folder-specific deltas (canonical rules in [`../../../../.context/conventions.md`](../../../../.context/conventions.md) and [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- Every concrete provider here MUST be paired with an adapter that implements `Interface_WP_MCP_AI_Provider_Client` from Base [`../../../../includes/interfaces/`](../../../../includes/interfaces/), so the router can resolve it uniformly. The adapter delegates; it does not duplicate request logic.
- Wire-format reuse is preferred: NV Cloud is OpenAI-compatible and therefore subclasses `WP_MCP_AI_OpenRouter_Client` from Base infrastructure. New providers should subclass the closest matching Base client rather than reimplementing tool-calling / JSON-mode / error parsing.
- Auth headers, base URLs, and quirky response headers (cost surfacing, request-id mapping) belong in the concrete client; the adapter only translates method signatures.
- Provider clients MUST NOT hold mutable shared state — Cloud-related connection state lives in [`../services/class-wp-mcp-ai-nv-cloud-service.php`](../services/class-wp-mcp-ai-nv-cloud-service.php).
- New Pro providers go here (not in [`../services/`](../services/)) when they expose model inference; SaaS clients without LLM inference belong in [`../services/`](../services/) instead.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-nv-cloud.php
```

The NV Cloud coverage exercises the client, adapter, service, and billing observer together. Add an integration test alongside whenever a new provider client lands here; the Base provider-adapters suite (`tests/test-provider-client-adapters.php`) is a useful reference for the contract surface.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — outbound-HTTP, secret handling (always)
- [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`../../../../CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, provider envelope conventions
- [`../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — adapter layering rationale

## See Also

- Upstream parent: [`../`](../) (Pro `includes/`)
- Base counterpart: [`../../../../includes/infrastructure/providers/`](../../../../includes/infrastructure/providers/) — OpenAI, Anthropic, Gemini, OpenRouter, HuggingFace clients
- Sibling folders: [`../services/`](../services/) (NV Cloud service + billing observer), [`../rest/`](../rest/) (NV Cloud REST controller), [`../admin/`](../admin/) (settings page)
