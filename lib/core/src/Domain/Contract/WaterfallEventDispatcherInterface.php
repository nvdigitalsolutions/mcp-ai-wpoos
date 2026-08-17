<?php
/**
 * Optional waterfall/serial dispatch contract for event dispatchers.
 *
 * Extends the base EventDispatcherInterface semantics with Cordis-style
 * dispatch modes (see deepseek-harness docs/cordis-primer.md):
 *
 *  - waterfall: around-middleware. Listeners receive ($event, $next) and
 *    MUST call $next($event) to delegate; returning without calling $next()
 *    short-circuits the chain. The final fallback produces the default
 *    result when no listener short-circuits.
 *  - serial: ordered notification where every listener runs in priority
 *    order and none may short-circuit (used for data-decides gates such
 *    as "turn stopping").
 *
 * Dispatchers that do not implement this interface simply lack waterfall
 * capability — call sites use instanceof feature detection and degrade to
 * emit-only behavior.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface WaterfallEventDispatcherInterface {

	/**
	 * Register a waterfall listener.
	 *
	 * Listener signature: function( object $event, callable $next ): mixed
	 * Higher priorities run first (WordPress convention).
	 */
	public function listenWaterfall( string $eventName, callable $listener, int $priority = 10 ): void;

	/**
	 * Dispatch an event through the waterfall chain.
	 *
	 * @param string   $eventName Event name identifying the listener group.
	 * @param object   $event     The event payload passed to each listener.
	 * @param callable $final     Fallback invoked when every listener
	 *                            delegates: function( object $event ): mixed.
	 * @return mixed  The chain result — either a listener short-circuit
	 *                value or the fallback result.
	 */
	public function waterfall( string $eventName, object $event, callable $final ): mixed;

	/**
	 * Register a serial (ordered, non-short-circuiting) listener.
	 */
	public function listenSerial( string $eventName, callable $listener, int $priority = 10 ): void;

	/**
	 * Dispatch an event serially to every listener in priority order.
	 *
	 * Listeners receive the event and return void; exceptions propagate.
	 */
	public function serial( string $eventName, object $event ): void;
}
