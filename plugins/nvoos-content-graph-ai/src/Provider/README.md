# Provider

## Purpose

AI provider clients for the content-graph AI addon. Each client implements the nvoos/core provider contract (`AbstractProviderClient` / `OpenAiCompatibleClient`) and is registered into `CoreBridge`'s `ProviderRouter` alongside the 13 core providers. Wave D2 ports the base plugin's provider clients beyond the core 13 (Zai first; Google Maps, RabbitMQ, StdioTransport, OpenAI Realtime ×3 follow).

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\CoreBridge::registerBuiltinProviders()` |
| **Optional dependencies** | `nvoos/core` (lib/core — contracts + OpenAI-compatible base) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Provider\ZaiClient` | `ZaiClient.php` | `CoreBridge::registerBuiltinProviders()` |
| `NvoosContentGraphAi\Provider\GoogleMapsClient` | `GoogleMapsClient.php` | Tools wave (geocode/search-places port); dormant until then |
| `NvoosContentGraphAi\Provider\VoiceProviderInterface` | `VoiceProviderInterface.php` | Implemented by the realtime voice providers |
| `NvoosContentGraphAi\Provider\OpenAiRealtimeClient` | `OpenAiRealtimeClient.php` | Voice surface (D-UI wave); dormant until then |
| `NvoosContentGraphAi\Provider\OpenAiRealtimeTranslateClient` | `OpenAiRealtimeTranslateClient.php` | Voice surface (D-UI wave); dormant until then |
| `NvoosContentGraphAi\Provider\OpenAiRealtimeWhisperClient` | `OpenAiRealtimeWhisperClient.php` | Voice surface (D-UI wave); dormant until then |

## Inputs / Outputs / Neighbors

- **Reads from:** `NvoosContentGraphAi\Adapter\ContentGraphSettingsStore` (`ai_api_key_zai` via `getApiKey('zai')`, `zai_base_url` via `getApiBaseUrl('zai')`, `ai_default_model` via `getDefaultModel()`)
- **Writes to:** Z.AI HTTPS API (`https://api.z.ai/api/paas/v4` — `/chat/completions`, `/models`); SSE streaming via `Nvoos\Core\Infrastructure\Streaming\SseHandler`
- **Upstream callers:** `ProviderRouter` → `ChatOrchestrator` (chat loop)
- **Downstream collaborators:** `Nvoos\Core\Infrastructure\Provider\OpenAiCompatibleClient`
- **Events fired:** None (core-level hooks only)
- **Events listened to:** None
