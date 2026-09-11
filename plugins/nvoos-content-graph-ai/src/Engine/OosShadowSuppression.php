<?php
/**
 * OOS shadow write-class suppression (Wave E6, sub-cluster 1).
 *
 * Aligned port of the shadow-mode suppression waterfall registered by
 * the base's `includes/bootstrap/oos-bridge.php` orchestrator factory:
 * the byte-identical `tools/execute` around-dispatch listener (priority
 * 20, ahead of the parity wrapper), the `shadow_mode` context gate, and
 * the synthetic `(shadow: write-class tool suppressed)` result so a
 * parallel shadow run can never double-execute state-changing tools.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - The base registers the listener inside
 *    `wp_mcp_ai_oos_orchestrator()` (once per factory instance). Here
 *    the listener is a static `wire()` surface, registered standalone-only
 *    by `Plugin::registerEngine()` against `CoreBridge`'s dispatcher and
 *    tool registry (the base factory owns the same wiring monolith).
 *    `register()` is idempotent (documented hardening — the base's
 *    factory guard makes double registration impossible there).
 *  - Write-class classification resolves through
 *    `OosEngineFlags::tool_is_write_class()`.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine;

/**
 * Suppresses write-class tool execution inside shadow runs.
 *
 * @since 1.1.0
 */
final class OosShadowSuppression {

	/**
	 * Wiring state.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Wire the suppression waterfall onto this addon's core dispatcher.
	 *
	 * Standalone-only: the base bridge's orchestrator factory owns the
	 * same listener in monolith installs.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$bridge = \NvoosContentGraphAi\CoreBridge::instance();

		self::wire( $bridge->events, $bridge->tools );
	}

	/**
	 * Attach the suppression listener to a dispatcher/registry pair.
	 *
	 * Public so tests can wire an isolated dispatcher without touching the
	 * addon's singleton bridge.
	 *
	 * @param \Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface $events        Waterfall-capable dispatcher.
	 * @param \Nvoos\Core\Application\Tool\ToolRegistry                     $tool_registry Core tool registry.
	 * @return void
	 */
	public static function wire( \Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface $events, \Nvoos\Core\Application\Tool\ToolRegistry $tool_registry ): void {
		$events->listenWaterfall(
			'tools/execute',
			static function ( object $event, callable $next ) use ( $tool_registry ): mixed {
				if ( empty( $event->context['shadow_mode'] ) ) {
					return $next( $event );
				}

				$tool = $tool_registry->get( (string) $event->slug );
				if ( null !== $tool && OosEngineFlags::tool_is_write_class( $tool ) ) {
					// A parallel shadow run must never execute state-changing
					// tools: reads execute live, writes are suppressed with a
					// synthetic result the diff recorder can identify.
					return array(
						'success' => true,
						'message' => '(shadow: write-class tool suppressed)',
						'data'    => array( 'shadow_suppressed' => true ),
					);
				}

				return $next( $event );
			},
			20
		);
	}
}
