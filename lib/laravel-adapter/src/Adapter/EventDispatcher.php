<?php
/**
 * Laravel adapter: EventDispatcherInterface implementation.
 *
 * Wraps Laravel's Event facade behind the framework-agnostic
 * EventDispatcherInterface, which extends PSR-14 with filter semantics.
 * Translates oOS domain events to Laravel events and provides a
 * FilterBus for the filter() pattern.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Illuminate\Support\Facades\Event;

class EventDispatcher implements EventDispatcherInterface {

	/**
	 * Registered PSR-14 listeners keyed by event class name.
	 *
	 * @var array<class-string, array<int, callable[]>>
	 */
	private array $listeners = array();

	/**
	 * Registered filter listeners keyed by event name.
	 *
	 * @var array<string, array<int, callable[]>>
	 */
	private array $filters = array();

	/**
	 * Dispatch an event to all registered listeners (PSR-14).
	 *
	 * 1. Notifies PSR-14 registered listeners in priority order.
	 * 2. Fires the event through Laravel's Event dispatcher for
	 *    framework-native subscribers.
	 *
	 * @template T of object
	 * @param T $event  The event object.
	 * @return T        The event, possibly modified by listeners.
	 */
	public function dispatch( object $event ): object {
		$eventClass = get_class( $event );

		// 1. PSR-14 registered listeners.
		if ( isset( $this->listeners[ $eventClass ] ) ) {
			foreach ( $this->getSortedCallbacks( $this->listeners[ $eventClass ] ) as $listener ) {
				$listener( $event );
			}
		}

		// 2. Laravel Event dispatcher for framework-native listeners.
		Event::dispatch( $event );

		return $event;
	}

	/**
	 * Filter a value through registered filter listeners.
	 *
	 * Each listener receives the current value and returns a potentially
	 * modified version. Listeners are called in priority order (highest first).
	 *
	 * Replaces: apply_filters('hook_name', $value, ...$args)
	 *
	 * @template T
	 * @param string $eventName  Filter event name.
	 * @param T      $value      Initial value to filter.
	 * @param mixed  ...$args    Additional arguments passed to each filter.
	 * @return T                 Value after all filters have run.
	 */
	public function filter( string $eventName, mixed $value, mixed ...$args ): mixed {
		if ( isset( $this->filters[ $eventName ] ) ) {
			foreach ( $this->getSortedCallbacks( $this->filters[ $eventName ] ) as $filter ) {
				$value = $filter( $value, ...$args );
			}
		}

		return $value;
	}

	/**
	 * Register a listener for a dispatched event.
	 *
	 * If the event name is a FQCN, it is treated as a PSR-14 event class.
	 * Otherwise it is treated as a plain event name.
	 *
	 * @param string   $eventName  Event class name or plain event name.
	 * @param callable $listener   Signature: function(object $event): void
	 * @param int      $priority   Higher numbers run first.
	 */
	public function listen( string $eventName, callable $listener, int $priority = 10 ): void {
		// If the event name is a class, register with Laravel too.
		if ( class_exists( $eventName ) || interface_exists( $eventName ) ) {
			Event::listen( $eventName, $listener );
		}

		$this->listeners[ $eventName ][ $priority ][] = $listener;
	}

	/**
	 * Register a filter listener.
	 *
	 * Filter listeners receive the current value and additional args,
	 * and must return the (possibly modified) value.
	 *
	 * @param string   $eventName  Filter event name.
	 * @param callable $filter     Signature: function(mixed $value, mixed ...$args): mixed
	 * @param int      $priority   Higher numbers run first.
	 */
	public function listenFilter( string $eventName, callable $filter, int $priority = 10 ): void {
		$this->filters[ $eventName ][ $priority ][] = $filter;
	}

	/**
	 * Remove a previously registered listener or filter.
	 *
	 * Searches all listener and filter registrations for the given
	 * callable reference and removes it if found.
	 *
	 * @param string   $eventName  The event or filter name.
	 * @param callable $listener   The exact callable to remove.
	 * @return bool                True if the listener was found and removed.
	 */
	public function removeListener( string $eventName, callable $listener ): bool {
		$removed = false;

		// Remove from PSR-14 / plain listeners.
		if ( isset( $this->listeners[ $eventName ] ) ) {
			foreach ( $this->listeners[ $eventName ] as $priority => &$callbacks ) {
				foreach ( $callbacks as $index => $registered ) {
					if ( $registered === $listener ) {
						unset( $callbacks[ $index ] );
						$removed = true;
					}
				}
				if ( array() === $callbacks ) {
					unset( $this->listeners[ $eventName ][ $priority ] );
				}
			}
		}

		// Remove from filters.
		if ( isset( $this->filters[ $eventName ] ) ) {
			foreach ( $this->filters[ $eventName ] as $priority => &$callbacks ) {
				foreach ( $callbacks as $index => $registered ) {
					if ( $registered === $listener ) {
						unset( $callbacks[ $index ] );
						$removed = true;
					}
				}
				if ( array() === $callbacks ) {
					unset( $this->filters[ $eventName ][ $priority ] );
				}
			}
		}

		return $removed;
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Sort callbacks by priority (descending — highest first).
	 *
	 * @param array<int, callable[]> $priorityMap
	 * @return callable[]
	 */
	private function getSortedCallbacks( array $priorityMap ): array {
		krsort( $priorityMap, SORT_NUMERIC );

		$sorted = array();
		foreach ( $priorityMap as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$sorted[] = $callback;
			}
		}

		return $sorted;
	}
}
