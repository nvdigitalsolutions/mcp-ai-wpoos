<?php
/**
 * Event dispatcher contract for the oOS AI orchestration core.
 *
 * Extends PSR-14 (Event Dispatcher) with filter semantics, replacing
 * WordPress action hooks (do_action) and filter hooks (apply_filters).
 * PSR-14 only covers dispatch — this interface adds the filter pattern
 * for backward compatibility with the existing hook-based extensibility.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcher;

interface EventDispatcherInterface extends PsrEventDispatcher {

	/**
	 * Dispatch an event to all registered listeners.
	 *
	 * PSR-14 compatible: returns the event object for potential
	 * modification by listeners (mutable event pattern).
	 *
	 * @template T of object
	 * @param T $event
	 * @return T  The event, possibly modified by listeners.
	 */
	public function dispatch( object $event ): object;

	/**
	 * Filter a value through registered filter listeners.
	 *
	 * Each listener receives the current value and returns a
	 * (potentially modified) value. Listeners are called in priority
	 * order (highest first).
	 *
	 * Replaces: apply_filters('hook_name', $value, ...$args)
	 *
	 * @template T
	 * @param T     $value      The initial value to filter.
	 * @param mixed ...$args    Additional arguments passed to each listener.
	 * @return T                The value after all filters have run.
	 */
	public function filter( string $eventName, mixed $value, mixed ...$args ): mixed;

	/**
	 * Register a listener for a dispatched event.
	 *
	 * @param callable $listener  Signature depends on the event type.
	 *                            For PSR-14 events: function(object $event): void
	 * @param int      $priority  Higher numbers run first (matching WordPress convention).
	 */
	public function listen( string $eventName, callable $listener, int $priority = 10 ): void;

	/**
	 * Register a filter listener.
	 *
	 * Filter listeners receive the current value and additional args,
	 * and must return the (possibly modified) value.
	 *
	 * @param callable $filter   Signature: function(mixed $value, mixed ...$args): mixed
	 * @param int      $priority  Higher numbers run first.
	 */
	public function listenFilter( string $eventName, callable $filter, int $priority = 10 ): void;

	/**
	 * Remove a previously registered listener or filter.
	 *
	 * @return bool  True if a listener was found and removed.
	 */
	public function removeListener( string $eventName, callable $listener ): bool;
}
