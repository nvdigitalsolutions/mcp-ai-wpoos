<?php
/**
 * Tool registry — manages the lifecycle of AI tools.
 *
 * Registers, resolves, validates, and executes tools for the agentic loop.
 * Replaces WP_MCP_AI_Tool_Registry with framework-agnostic tool management.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Event\BeforeToolExecution;
use Nvoos\Core\Domain\Event\AfterToolExecution;
use Nvoos\Core\Domain\Event\ToolsRegistered;

class ToolRegistry {

	/**
	 * Registered tools keyed by slug.
	 *
	 * @var array<string, ToolInterface>
	 */
	private array $tools = array();

	/**
	 * Slugs that have been disabled and should not execute.
	 *
	 * @var array<string, bool>
	 */
	private array $disabled = array();

	/**
	 * Deprecated aliases mapping old slug → new slug.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = array();

	public function __construct(
		private readonly EventDispatcherInterface $events,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Register a tool.
	 *
	 * @throws \RuntimeException  When a tool with the same slug already exists.
	 */
	public function register( ToolInterface $tool ): void {
		$slug = $tool->getSlug();

		if ( isset( $this->tools[ $slug ] ) ) {
			throw new \RuntimeException( "Tool '{$slug}' is already registered." );
		}

		$this->tools[ $slug ] = $tool;
	}

	/**
	 * Register a deprecated alias pointing to a current tool slug.
	 */
	public function registerAlias( string $alias, string $targetSlug ): void {
		$this->aliases[ $alias ] = $targetSlug;
	}

	/**
	 * Get a tool by slug, resolving aliases.
	 *
	 * @return ToolInterface|null  Null if not found.
	 */
	public function get( string $slug ): ?ToolInterface {
		// Resolve alias chain.
		$resolved = $this->resolveAlias( $slug );

		return $this->tools[ $resolved ] ?? null;
	}

	/**
	 * Check if a tool is registered (and not disabled).
	 */
	public function has( string $slug ): bool {
		$resolved = $this->resolveAlias( $slug );

		return isset( $this->tools[ $resolved ] ) && ! isset( $this->disabled[ $resolved ] );
	}

	/**
	 * Disable a tool so it won't execute.
	 */
	public function disable( string $slug ): void {
		$this->disabled[ $this->resolveAlias( $slug ) ] = true;
	}

	/**
	 * Re-enable a previously disabled tool.
	 */
	public function enable( string $slug ): void {
		unset( $this->disabled[ $this->resolveAlias( $slug ) ] );
	}

	/**
	 * Get all registered tool slugs.
	 *
	 * @return string[]
	 */
	public function getSlugs(): array {
		return \array_keys( $this->tools );
	}

	/**
	 * Get all registered tools (slug → instance).
	 *
	 * @return array<string, ToolInterface>
	 */
	public function all(): array {
		return $this->tools;
	}

	/**
	 * Get only enabled tools (slug → instance).
	 *
	 * @return array<string, ToolInterface>
	 */
	public function enabled(): array {
		$enabled = array();

		foreach ( $this->tools as $slug => $tool ) {
			if ( ! isset( $this->disabled[ $slug ] ) ) {
				$enabled[ $slug ] = $tool;
			}
		}

		return $enabled;
	}

	/**
	 * Get the number of registered tools.
	 */
	public function count(): int {
		return \count( $this->tools );
	}

	/**
	 * Get the number of enabled tools.
	 */
	public function enabledCount(): int {
		return \count( $this->tools ) - \count( $this->disabled );
	}

	/**
	 * Execute a tool by slug with arguments and context.
	 *
	 * Fires BeforeToolExecution and AfterToolExecution domain events.
	 *
	 * @return mixed  Tool result or error.
	 */
	public function execute( string $slug, array $arguments = array(), array $context = array() ): mixed {
		$tool = $this->get( $slug );

		if ( null === $tool ) {
			return $this->errors->notFound( "Tool '{$slug}' is not registered." );
		}

		if ( isset( $this->disabled[ $tool->getSlug() ] ) ) {
			return $this->errors->forbidden( "Tool '{$slug}' is disabled." );
		}

		// Check capability.
		$capability = $tool->getRequiredCapability();
		if ( '' !== $capability ) {
			$userId = $context['user_id'] ?? 0;
			if ( $userId > 0 && ! ( $context['auth_provider'] ?? null )?->userCan( $userId, $capability ) ) {
				return $this->errors->forbidden(
					"You do not have permission to execute '{$slug}'.",
				);
			}
		}

		$startedAt = \microtime( true );

		// Before hook.
		$this->events->dispatch(
			new BeforeToolExecution(
				toolSlug: $slug,
				arguments: $arguments,
				context: $context,
				startedAtMicros: $startedAt,
			)
		);

		$result = $tool->execute( $arguments, $context );

		$durationMs = ( \microtime( true ) - $startedAt ) * 1000;

		// After hook.
		$this->events->dispatch(
			new AfterToolExecution(
				toolSlug: $slug,
				arguments: $arguments,
				context: $context,
				result: $result,
				isError: $this->errors->isError( $result ),
				durationMs: $durationMs,
			)
		);

		return $result;
	}

	/**
	 * Build OpenAI-compatible tool definitions for all enabled tools.
	 *
	 * @return array  Array of { type: 'function', function: { name, description, parameters } }
	 */
	public function buildToolDefinitions(): array {
		$definitions = array();

		foreach ( $this->enabled() as $slug => $tool ) {
			$definitions[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $slug,
					'description' => $tool->getDescription(),
					'parameters'  => $tool->getParametersSchema(),
				),
			);
		}

		return $definitions;
	}

	/**
	 * Notify that all tools have been registered.
	 *
	 * Call this after registering a batch of tools so observers
	 * (logging, OTEL, admin UI) can react.
	 */
	public function notifyRegistered(): void {
		$this->events->dispatch(
			new ToolsRegistered(
				toolSlugs: $this->getSlugs(),
			)
		);
	}

	/**
	 * Resolve a chain of aliases to a canonical slug.
	 */
	private function resolveAlias( string $slug ): string {
		$seen = array();

		while ( isset( $this->aliases[ $slug ] ) ) {
			if ( isset( $seen[ $slug ] ) ) {
				// Circular alias detected — break.
				break;
			}
			$seen[ $slug ] = true;
			$slug          = $this->aliases[ $slug ];
		}

		return $slug;
	}
}
