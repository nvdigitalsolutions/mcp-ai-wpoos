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

## Typed tool results

Tools have two surfaces. The untyped one (`ToolInterface::execute()`) is the
legacy contract used by the registry pipeline. The typed one
(`ToolResultInterface::executeResult()`) returns a `ToolResult` ADT — success
with a payload, or failure with a `DomainError`. Nothing is optional or
un-inspectable.

```php
use Nvoos\Core\Tool\AbstractResultTool;
use Nvoos\Core\Domain\ValueObject\ToolResult;

final class MyTool extends AbstractResultTool {

	public function getSlug(): string { return 'my_tool'; }
	public function getName(): string { return 'My Tool'; }
	public function getDescription(): string { return 'Does one thing.'; }
	public function getParametersSchema(): array { return array( 'type' => 'object' ); }

	public function executeResult( array $arguments = array(), array $context = array() ): ToolResult {
		$name = $this->optionParam( $arguments, 'name' )
			->map( static fn( $v ) => trim( (string) $v ) )
			->getOrElse( 'world' );

		return ToolResult::success( "Hello, {$name}.", array( 'name' => $name ) );
	}
}
```

`execute()` is inherited and final: it converts the result to the canonical
envelope on success and to the platform error (`WP_Error` in WordPress) on
failure, so guards, waterfalls, observers, and the canonical-envelope sniff all
keep working unchanged.

Typed helpers on the base class:

| Helper | Replaces | Behaviour |
|---|---|---|
| `optionParam()` | `requireParam()` | `Option` — distinguishes absent from present-but-falsy |
| `authorize()` | `requireCapability()` | Throws `AccessDeniedException`; the registry converts it |
| `validationFailure()` | `errors->validationFailed()` | `ToolResult` failure with the same payload (422 + field errors) |
| `unwrapResult()` | — | `ToolResult` → legacy envelope/error |

At the registry edge, `ToolRegistry::executeResult()` returns a `ToolResult`
for any tool (typed or legacy), and `ToolRegistry::toResult()` is the only
place a platform error becomes a `DomainError`.

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

