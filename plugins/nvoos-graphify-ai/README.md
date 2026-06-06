# NV oOS Graphify — AI

## Purpose

AI chat assistant addon for NV oOS Graphify — adds conversational AI with 13 provider backends, SSE streaming, tool-calling loop, 13 AI-powered tools, and agent memory to the knowledge graph. One install, one API key.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-graphify` |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-graphify-ai.php` → `plugins_loaded` priority 20 (after core plugin at priority 10) |
| **Requires Plugins** | `nvoos-graphify` (WP 6.5+ header) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyAi\Plugin` | `src/Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosGraphifyAi\ProviderRegistry` | `src/ProviderRegistry.php` | Chat, REST, Tools |
| `NvoosGraphifyAi\Settings` | `src/Settings.php` | All subsystems |
| `NvoosGraphifyAi\Contracts\ProviderClient` | `src/Contracts/ProviderClient.php` | All 13 provider implementations |
| `NvoosGraphifyAi\Chat\ChatService` | `src/Chat/ChatService.php` | REST controller, async jobs |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option (AI keys merged via `nvoos_graphify/default_settings` filter), core `NvoosGraphify\ToolRegistry`
- **Writes to:** REST responses, SSE streams, AI provider APIs (OpenAI, Gemini, Ollama, etc.)
- **Upstream callers:** WordPress REST API, `nvoos-graphify` core (tool registration hook)
- **Downstream collaborators:** `nvoos-graphify` core (`ToolRegistry`, `Contracts\Tool`), 13 provider APIs
- **Events fired:** `nvoos_graphify_ai/continue_chat` (Action Scheduler)
- **Events listened to:** `nvoos_graphify/register_tools`, `nvoos_graphify/default_settings`, `rest_api_init`

## Conventions

- Namespace: `NvoosGraphifyAi\` — PSR-4 mapped to `src/`.
- All providers implement `NvoosGraphifyAi\Contracts\ProviderClient` for uniform routing.
- AI settings (API keys, models, temperatures) are merged into the core's grouped `nvoos_graphify_settings` option via the `nvoos_graphify/default_settings` filter — no separate options table.
- `ChatService::process()` implements a tool-calling loop with a max of 5 iterations to prevent runaway loops.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../.context/conventions.md`](../../.context/conventions.md) — naming + style
- [`../../.context/security-checklist.md`](../../.context/security-checklist.md) — API key handling, SSRF
- [`../../.context/rest-api.md`](../../.context/rest-api.md) — REST patterns
- [`../../CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns

## See Also

- Required parent: [`../nvoos-graphify/`](../nvoos-graphify/) — core knowledge graph plugin
- [`src/`](src/) — source code root
