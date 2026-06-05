# nvoos/core

Framework-agnostic AI orchestration engine — agentic loop, tool registry,
provider routing, SSE streaming.

## Installation

```bash
composer require nvoos/core
```

Requires PHP 8.1+.

## What's Inside

- **Domain layer** — 9 contracts (ports), 10 immutable entities, 5 typed exceptions, 8 PSR-14 domain events
- **Application layer** — `ChatOrchestrator` (agentic loop), `ProviderRouter` (12-provider routing), `ToolRegistry`, `SkillRegistry`
- **Infrastructure layer** — 12 AI provider clients (OpenAI, Gemini, Anthropic, Ollama, DeepSeek, etc.), SSE streaming handler, cost calculator
- **Tools** — 43 framework-agnostic tool classes (content, cache, files, settings, jobs, etc.)

## Architecture

This package uses **Hexagonal Architecture (Ports & Adapters)**. The core engine
defines interfaces (ports) that platform-specific adapters implement:

| Adapter | Package |
|---|---|
| WordPress | `nvoos/wordpress-adapter` |
| Laravel | `nvoos/laravel-adapter` |
| Craft CMS | `nvoos/craft-adapter` |

## Usage

```php
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;

// Wire up adapters (WordPress example)
$toolRegistry = new ToolRegistry($errorFactory, $eventDispatcher);
$providerRouter = new ProviderRouter($settingsStore, $httpClient, $requestFactory);
$orchestrator = new ChatOrchestrator(
    $toolRegistry,
    $providerRouter,
    $eventDispatcher,
    $errorFactory,
);

// Run a chat
$response = $orchestrator->handleChat($messages, $options);
```

## License

MIT — see [LICENSE](LICENSE).

