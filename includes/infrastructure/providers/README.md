# Providers

## Purpose

Contains the 10 concrete AI provider client classes — one file per provider — each implementing `Interface_WP_MCP_AI_Provider_Client` to expose a uniform `chat()` / `stream()` / `list_models()` / `get_provider_slug()` contract to the language-model router.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | DI container via `includes/class-wp-mcp-ai-container.php`; each provider is gated on credentials/availability at runtime |
| **Optional dependencies** | none required (each provider client checks its own API key availability) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Anthropic_Provider_Client` | `class-wp-mcp-ai-anthropic-provider-client.php` | language-model router, chat service |
| `WP_MCP_AI_Baseten_Provider_Client` | `class-wp-mcp-ai-baseten-provider-client.php` | same |
| `WP_MCP_AI_Cloudflare_Provider_Client` | `class-wp-mcp-ai-cloudflare-provider-client.php` | same |
| `WP_MCP_AI_DeepSeek_Provider_Client` | `class-wp-mcp-ai-deepseek-provider-client.php` | same |
| `WP_MCP_AI_DigitalOcean_Provider_Client` | `class-wp-mcp-ai-digitalocean-provider-client.php` | same |
| `WP_MCP_AI_Gemini_Provider_Client` | `class-wp-mcp-ai-gemini-provider-client.php` | same |
| `WP_MCP_AI_Kimi_Provider_Client` | `class-wp-mcp-ai-kimi-provider-client.php` | same |
| `WP_MCP_AI_LM_Studio_Provider_Client` | `class-wp-mcp-ai-lm-studio-provider-client.php` | same |
| `WP_MCP_AI_NVIDIA_Provider_Client` | `class-wp-mcp-ai-nvidia-provider-client.php` | same |
| `WP_MCP_AI_Ollama_Provider_Client` | `class-wp-mcp-ai-ollama-provider-client.php` | same |
| `WP_MCP_AI_OpenAI_Provider_Client` | `class-wp-mcp-ai-openai-provider-client.php` | same |
| `WP_MCP_AI_OpenRouter_Provider_Client` | `class-wp-mcp-ai-openrouter-provider-client.php` | same |
| `WP_MCP_AI_ZAI_Provider_Client` | `class-wp-mcp-ai-zai-provider-client.php` | same |

## Inputs / Outputs / Neighbors

- **Reads from:** provider-specific API keys stored via WordPress options (`repositories/class-wp-mcp-ai-credential-repository.php`).
- **Writes to:** outbound HTTP requests to each provider's REST endpoint (delegated through `WP_MCP_AI_WP_HTTP_Client`).
- **Upstream callers:** `services/class-wp-mcp-ai-chat-service.php`, language-model router, any service needing AI inference.
- **Downstream collaborators:** `infrastructure/http/` (HTTP client), each provider's native SDK/client class (e.g. `WP_MCP_AI_OpenAI_Client`).
- **Events fired:** none directly; failures return `WP_Error` for callers to handle.
- **Events listened to:** none.

## Conventions

- One file per provider. The class name follows `WP_MCP_AI_{Provider}_Provider_Client`.
- Every class implements `Interface_WP_MCP_AI_Provider_Client` — the uniform surface (`chat`, `stream`, `list_models`, `get_provider_slug`).
- Each provider client wraps its own concrete SDK class (e.g. `WP_MCP_AI_OpenAI_Provider_Client` wraps `WP_MCP_AI_OpenAI_Client`). The adapter pattern lets tests swap providers without changing consuming code.
- Provider availability is self-checked at runtime (API key present, SDK class exists); no provider is mandatory.
- Construction happens through the DI container; do not `new` these from `services/` or `tools/`.

## Tests

```bash
vendor/bin/phpunit tests/test-provider-client-adapters.php
```

Provider-specific behaviour is also covered by `tests/test-*-provider*.php` suites.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — outbound HTTP secrets, API key handling (always)
- Parent folder: [`includes/infrastructure/README.md`](../README.md) — full infrastructure layer overview

## See Also

- Upstream parent: [`includes/infrastructure/`](../) — infrastructure adapters layer
- Interface: [`includes/interfaces/interface-wp-mcp-ai-provider-client.php`](../../interfaces/interface-wp-mcp-ai-provider-client.php)
- HTTP transport: [`includes/infrastructure/http/`](../http/) — the HTTP client all providers delegate to
- DI wiring: [`includes/class-wp-mcp-ai-container.php`](../../class-wp-mcp-ai-container.php)
