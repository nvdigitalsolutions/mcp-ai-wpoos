# Provider Clients

## Purpose

AI provider client implementations — one class per AI service. Each handles message format translation, auth header construction, and response normalization to the OpenAI-compatible shape expected by the agentic loop.

## Tier

| | |
|---|---|
| **Distribution** | `oos/core` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | PSR-18 (HTTP Client), `SettingsStoreInterface`, `ErrorFactoryInterface` |

## Public Surface

### Abstract Base Classes

| Symbol | File | Purpose |
|---|---|---|
| `AbstractProviderClient` | `AbstractProviderClient.php` | Constructor injection + common helpers (getApiKey, getBaseUrl, buildAuthHeaders) |
| `OpenAiCompatibleClient` | `OpenAiCompatibleClient.php` | Shared implementation for providers with `/v1/chat/completions` APIs |

### Concrete Providers (12)

| Provider | Client | API Type |
|---|---|---|
| OpenAI | `OpenAiClient.php` | OpenAI-compatible |
| Google Gemini | `GeminiClient.php` | Gemini-native (message format translation) |
| Anthropic Claude | `AnthropicClient.php` | Messages API (message format translation) |
| DeepSeek | `DeepSeekClient.php` | OpenAI-compatible |
| OpenRouter | `OpenRouterClient.php` | OpenAI-compatible gateway |
| Kimi (Moonshot) | `KimiClient.php` | OpenAI-compatible |
| Ollama | `OllamaClient.php` | OpenAI-compatible (local, optional auth) |
| LM Studio | `LmStudioClient.php` | OpenAI-compatible (local, optional auth) |
| DigitalOcean | `DigitalOceanClient.php` | OpenAI-compatible |
| NVIDIA NIM | `NvidiaNimClient.php` | OpenAI-compatible |
| Cloudflare | `CloudflareClient.php` | OpenAI-compatible |
| HuggingFace | `HuggingFaceClient.php` | OpenAI-compatible inference |

## Conventions

- All providers receive `SettingsStoreInterface` + PSR-18 `HttpClientInterface` + `ErrorFactoryInterface` via constructor.
- API keys are resolved from settings by provider slug (e.g., `$settings->getApiKey('openai')`).
- All responses are normalized to the OpenAI-compatible shape: `{ choices: [{ message: { role, content } }], usage, model }`.
- Local providers (Ollama, LM Studio) omit auth headers when no API key is configured.

## Tests

```bash
vendor/bin/phpunit tests/test-openai-client.php
vendor/bin/phpunit tests/test-gemini-client.php
vendor/bin/phpunit tests/test-anthropic-client.php
```

## Also Load

- [`../../Domain/Contract/SettingsStoreInterface.php`](../../Domain/Contract/SettingsStoreInterface.php)
- [`../../Domain/Contract/ErrorFactoryInterface.php`](../../Domain/Contract/ErrorFactoryInterface.php)
- [`../../Application/Provider/README.md`](../../Application/Provider/README.md)
