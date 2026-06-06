# Contracts

## Purpose

Defines the single extension interface for AI language-model providers — `ProviderClient` — so the `ChatService` can route requests uniformly across all 13 provider backends.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-graphify-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | Autoloader (PSR-4) — consumed by `ProviderRegistry` and all provider implementations |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyAi\Contracts\ProviderClient` | `ProviderClient.php` | `ProviderRegistry`, all 13 provider implementations, `ChatService` |

## Inputs / Outputs / Neighbors

- **Reads from:** Nothing directly (interface only)
- **Writes to:** Nothing directly (interface only)
- **Upstream callers:** `NvoosGraphifyAi\ProviderRegistry` (type-hint), `NvoosGraphifyAi\Chat\ChatService` (type-hint)
- **Downstream collaborators:** All 13 provider implementations in `src/Providers/`

### Interface Methods

| Method | Purpose |
|---|---|
| `chat($messages, $options)` | Send a non-streaming chat-completion request |
| `stream($messages, $options, $callback)` | Send a streaming chat-completion request with token callback |
| `listModels()` | Return available model identifiers |
| `getProviderSlug()` | Return the unique provider slug (e.g. `openai`, `gemini`) |

## Conventions

- All parameters and return values use plain PHP arrays (no DTOs, no stdClass) to keep the interface dependency-free.
- `chat()` and `stream()` return `array` on success or `WP_Error` on failure.
- Every provider MUST implement this interface — no exceptions.

## Tests

```bash
# No dedicated tests — contracts are exercised through provider tests
vendor/bin/phpunit --filter '/ProviderClient|Provider/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style

## See Also

- Parent: [`../`](../) — src root
- Implementors: [`../Providers/`](../Providers/)
- Consumers: [`../Chat/ChatService.php`](../Chat/ChatService.php), [`../ProviderRegistry.php`](../ProviderRegistry.php)
