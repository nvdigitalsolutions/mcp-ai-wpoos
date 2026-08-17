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
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Decision\PreToolDecision;
use Nvoos\Core\Domain\Decision\PostToolDecision;
use Nvoos\Core\Domain\Event\BeforeToolExecution;
use Nvoos\Core\Domain\Event\AfterToolExecution;
use Nvoos\Core\Domain\Event\ToolsPreExecute;
use Nvoos\Core\Domain\Event\ToolsExecute;
use Nvoos\Core\Domain\Event\ToolsPostExecute;
use Nvoos\Core\Domain\Event\ToolsRegistered;

class ToolRegistry implements ToolResolverInterface {

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

	/**
	 * Monotonic deny-only guards evaluated after the pre-execute waterfall.
	 *
	 * Ordering cannot turn a denial back into permission — a guard either
	 * returns a denial reason or leaves the call allowed.
	 *
	 * @var ToolGuardInterface[]
	 */
	private array $guards = array();

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
	 * Create a scoped view over this registry.
	 *
	 * Scopes shape tool visibility for one agent/assistant; execution
	 * always happens on this registry (the policy pipeline lives here).
	 */
	public function createScope(): ToolScope {
		return new ToolScope( $this );
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
	 * Register a monotonic deny-only guard.
	 */
	public function addGuard( ToolGuardInterface $guard ): void {
		$this->guards[] = $guard;
	}

	/**
	 * Remove all registered guards (test teardown / runtime reset).
	 */
	public function clearGuards(): void {
		$this->guards = array();
	}

	/**
	 * Execute a tool by slug with arguments and context.
	 *
	 * Pipeline: legacy observer hook → tools/pre_execute waterfall
	 * (allow/deny/ask) → monotonic guards → tools/execute around-dispatch
	 * → tool body → legacy after hook → tools/post_execute waterfall
	 * (accept/replace/block).
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

		// Before hook. A throwing listener (e.g., a platform gate such as the
		// destructive-ops confirmation) must block the tool — never crash the
		// whole agentic loop with an uncaught exception.
		try {
			$this->events->dispatch(
				new BeforeToolExecution(
					toolSlug: $slug,
					arguments: $arguments,
					context: $context,
					startedAtMicros: $startedAt,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'tool_listener_blocked',
				'Tool pre-execution listener blocked or failed: ' . $e->getMessage(),
				array( 'listener' => \get_class( $e ) ),
			);
		}

		// tools/pre_execute waterfall — extensible allow/deny/ask policy.
		if ( $this->events instanceof WaterfallEventDispatcherInterface ) {
			$preDecision = $this->events->waterfall(
				'tools/pre_execute',
				new ToolsPreExecute( slug: $slug, arguments: $arguments, context: $context ),
				static function ( object $event ): PreToolDecision {
					return PreToolDecision::allow();
				},
			);

			if ( ! $preDecision instanceof PreToolDecision ) {
				// A misbehaving listener returned an unexpected type — fail closed.
				$preDecision = PreToolDecision::deny( "Tool '{$slug}' policy returned an invalid decision." );
			}

			if ( PreToolDecision::KIND_DENY === $preDecision->kind ) {
				return $this->errors->create(
					'tool_denied',
					'' !== $preDecision->reason ? $preDecision->reason : "Tool '{$slug}' was denied by policy.",
				);
			}

			if ( PreToolDecision::KIND_ASK === $preDecision->kind ) {
				// No approval service is wired yet — approval asks fail closed.
				return $this->errors->create(
					'approval_required',
					'' !== $preDecision->reason ? $preDecision->reason : "Tool '{$slug}' requires approval.",
				);
			}
		}

		// Monotonic guards — deny-only, ordering-proof.
		foreach ( $this->guards as $guard ) {
			$guardReason = $guard->evaluate( $slug, $arguments, $context );
			if ( null !== $guardReason ) {
				return $this->errors->create( 'tool_guarded', $guardReason );
			}
		}

		// tools/execute around-dispatch — wrappers may wrap or replace the body.
		$executeBody = static fn(): mixed => $tool->execute( $arguments, $context );

		if ( $this->events instanceof WaterfallEventDispatcherInterface ) {
			$result = $this->events->waterfall(
				'tools/execute',
				new ToolsExecute( slug: $slug, arguments: $arguments, context: $context ),
				static function ( object $event ) use ( $executeBody ): mixed {
					return $executeBody();
				},
			);
		} else {
			$result = $executeBody();
		}

		$durationMs = ( \microtime( true ) - $startedAt ) * 1000;

		// After hook. Observability failures must never corrupt the tool result.
		try {
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
		} catch ( \Throwable $e ) {
			// Swallow: after-the-fact observers cannot veto an executed tool.
		}

		// tools/post_execute waterfall — accept, replace content, or block
		// with corrective feedback.
		if ( $this->events instanceof WaterfallEventDispatcherInterface ) {
			$postDecision = $this->events->waterfall(
				'tools/post_execute',
				new ToolsPostExecute(
					slug: $slug,
					arguments: $arguments,
					context: $context,
					result: $result,
					isError: $this->errors->isError( $result ),
				),
				static function ( object $event ): PostToolDecision {
					return PostToolDecision::accept();
				},
			);

			if ( ! $postDecision instanceof PostToolDecision ) {
				// A misbehaving listener returned an unexpected type — keep the
				// dispatch result rather than destroying an executed tool.
				$postDecision = PostToolDecision::accept();
			}

			if ( PostToolDecision::KIND_BLOCK === $postDecision->kind ) {
				return $this->errors->create(
					'tool_blocked_with_feedback',
					'Tool result was blocked by policy. Feedback: ' . ( is_string( $postDecision->content ) ? $postDecision->content : self::jsonEncodeSafe( $postDecision->content ) ),
					array( 'feedback' => $postDecision->content ),
				);
			}

			if ( PostToolDecision::KIND_REPLACE === $postDecision->kind && ! $this->errors->isError( $result ) ) {
				$result = $postDecision->content;
			}
		}

		return $result;
	}

	/**
	 * JSON-encode a value without ever failing hard.
	 */
	private static function jsonEncodeSafe( mixed $value ): string {
		$json = \json_encode( $value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );

		return false === $json ? '(unserializable feedback)' : $json;
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
