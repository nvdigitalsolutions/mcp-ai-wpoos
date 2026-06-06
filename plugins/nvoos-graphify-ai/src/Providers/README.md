# Providers

## Purpose

Houses all 13 AI language-model provider clients — each implements `NvoosGraphifyAi\Contracts\ProviderClient` and is registered with `NvoosGraphifyAi\ProviderRegistry` for uniform routing by `ChatService`.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-graphify-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosGraphifyAi\Plugin::registerBuiltinProviders()` on `plugins_loaded` priority 20 |
| **Optional dependencies** | Individual providers require their respective API keys (configured in settings) |

## Public Surface

All providers are accessed through `NvoosGraphifyAi\ProviderRegistry` by slug — never instantiated directly by callers.

| Provider slug | Subdirectory | Class | Status |
|---|---|---|---|
| `openai` | `OpenAi/` | `OpenAiProvider` (extends `OpenAiCompatibleProvider`) | Bundled |
| `gemini` | `Gemini/` | `GeminiProvider` | Bundled |
| `ollama` | `Ollama/` | `OllamaProvider` | Bundled |
| `anthropic` | `Anthropic/` | `AnthropicProvider` | Exotic |
| `deepseek` | `DeepSeek/` | `DeepSeekProvider` | Exotic |
| `openrouter` | `OpenRouter/` | `OpenRouterProvider` | Exotic |
| `huggingface` | `HuggingFace/` | `HuggingFaceProvider` | Exotic |
| `cloudflare` | `Cloudflare/` | `CloudflareProvider` | Exotic |
| `lmstudio` | `LMStudio/` | `LMStudioProvider` | Exotic |
| `nvidia` | `Nvidia/` | `NvidiaProvider` | Exotic |
| `digitalocean` | `DigitalOcean/` | `DigitalOceanProvider` | Exotic |
| `kimi` | `Kimi/` | `KimiProvider` | Exotic |
| `baseten` | `Baseten/` | `BasetenProvider` | Exotic |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option (API keys, base URLs, model names)
- **Writes to:** External AI provider APIs (HTTP requests)
- **Upstream callers:** `NvoosGraphifyAi\ProviderRegistry` → `ChatService`
- **Downstream collaborators:** `src/Contracts/ProviderClient` (interface)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- One provider per subdirectory — directory name matches provider slug.
- `OpenAiCompatibleProvider` is a base class for providers that use the OpenAI API format (OpenAI, DeepSeek, OpenRouter, etc.).
- Each provider reads its own API key and configuration from the core's grouped settings.
- All providers use WordPress HTTP API (`wp_remote_get`/`wp_remote_post`) for outbound requests.

## Tests

```bash
vendor/bin/phpunit --filter '/Provider/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — API key handling, SSRF

## See Also

- Parent: [`../`](../) — src root
- Interface: [`../Contracts/ProviderClient.php`](../Contracts/ProviderClient.php)
- Registry: [`../ProviderRegistry.php`](../ProviderRegistry.php)
- Consumer: [`../Chat/ChatService.php`](../Chat/ChatService.php)
