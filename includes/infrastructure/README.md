# Infrastructure

## Purpose

Houses every WordPress-aware adapter that implements an interface from `includes/interfaces/` — HTTP client, options store, capability checker, and the suite of AI provider clients — and is the single layer permitted to call WordPress and external APIs directly.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php`; wired into the DI container by `includes/class-wp-mcp-ai-container.php` |
| **Optional dependencies** | none required (each provider client is gated on credentials/availability at runtime) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WP_Options_Store` | `wp/class-wp-mcp-ai-wp-options-store.php` | DI container → injected into `services/`, `repositories/` |
| `WP_MCP_AI_WP_Capability_Checker` | `wp/class-wp-mcp-ai-wp-capability-checker.php` | DI container → tools / REST permission callbacks |
| `WP_MCP_AI_WP_HTTP_Client` | `http/class-wp-mcp-ai-wp-http-client.php` | DI container → every provider client + services that fetch external resources |
| `WP_MCP_AI_OpenAI_Provider_Client` | `providers/class-wp-mcp-ai-openai-provider-client.php` | language-model router, `services/class-wp-mcp-ai-chat-service.php` |
| `WP_MCP_AI_Gemini_Provider_Client` | `providers/class-wp-mcp-ai-gemini-provider-client.php` | same |
| `WP_MCP_AI_Anthropic_Provider_Client` | `providers/class-wp-mcp-ai-anthropic-provider-client.php` | same |
| `WP_MCP_AI_Ollama_Provider_Client` | `providers/class-wp-mcp-ai-ollama-provider-client.php` | same |
| `WP_MCP_AI_Cloudflare_Provider_Client` | `providers/class-wp-mcp-ai-cloudflare-provider-client.php` | same |
| `WP_MCP_AI_Nvidia_Provider_Client` | `providers/class-wp-mcp-ai-nvidia-provider-client.php` | same |
| `WP_MCP_AI_LM_Studio_Provider_Client` | `providers/class-wp-mcp-ai-lm-studio-provider-client.php` | same |
| `WP_MCP_AI_OpenRouter_Provider_Client` | `providers/class-wp-mcp-ai-openrouter-provider-client.php` | same |
| `WP_MCP_AI_DigitalOcean_Provider_Client` | `providers/class-wp-mcp-ai-digitalocean-provider-client.php` | same |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress options (via `wp/`), HTTP responses (via `http/` and provider clients), credentials stored by `repositories/class-wp-mcp-ai-credential-repository.php`.
- **Writes to:** WordPress options, transients, and outbound HTTP requests to OpenAI, Google, Anthropic, Cloudflare, NVIDIA, Ollama, LM Studio, OpenRouter, DigitalOcean.
- **Upstream callers:** `services/` (every service that needs WordPress side effects), `repositories/`, `rest/` permission callbacks, `agents/`, `cli/`.
- **Downstream collaborators:** WordPress HTTP API, WordPress options API, third-party AI provider REST endpoints. Each provider client implements `Interface_WP_MCP_AI_Provider_Client` from `interfaces/`.
- **Events fired:** none directly; provider failure modes surface as `WP_Error` instances for the caller to log.
- **Events listened to:** none (this layer is invoked imperatively).

## Conventions

- **Every class here implements an interface from `includes/interfaces/`.** Bare adapters with no contract belong somewhere else.
- Direct WordPress API calls are not only allowed but expected — that is this folder's job. Higher layers (`domain/`, `services/` where possible) should remain WordPress-agnostic; see [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md).
- Subfolder roles are fixed:
  - `wp/` — adapters over WordPress core APIs (`get_option`, `current_user_can`, etc.).
  - `http/` — adapters over `wp_remote_*` and streaming requests.
  - `providers/` — concrete AI provider clients (one file per provider).
- Construction happens exclusively through `WP_MCP_AI_Container::register_default_services()` so callers receive injected adapters and tests can substitute fakes. Do not `new` these classes from inside `services/` or `tools/`.

## Tests

```bash
vendor/bin/phpunit tests/test-wp-options-store.php
vendor/bin/phpunit tests/test-wp-http-client.php
vendor/bin/phpunit tests/test-provider-client-adapters.php
vendor/bin/phpunit tests/test-http-helper-network-interface.php
```

Provider-specific behaviour is also covered by the broader `tests/test-*-provider*.php` suite.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — outbound HTTP, secrets, capability rules
- [`.context/rest-api.md`](../../.context/rest-api.md) — adapters that surface in REST endpoints
- [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — why this layer exists

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`interfaces/`](../interfaces/) (contracts implemented here), [`services/`](../services/) (primary consumer), [`repositories/`](../repositories/) (consume `wp/` adapters)
- DI wiring: [`includes/class-wp-mcp-ai-container.php`](../class-wp-mcp-ai-container.php)
