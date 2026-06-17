<?php
/**
 * Craft adapter: EventDispatcherInterface implementation.
 *
 * Wraps Craft's Yii event system behind the framework-agnostic
 * EventDispatcherInterface, which extends PSR-14 with filter semantics.
 *
 * Uses Yii's `Event::on()` for registration and `Event::trigger()`
 * for dispatch. Filter support is implemented via a custom FilterBus.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use yii\base\Event;

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
	 * 2. Triggers the event through Yii's Event system for
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

		// 2. Yii Event trigger for framework-native listeners.
		Event::trigger( $eventClass, 'oos.event', $event );

		return $event;
	}

	/**
	 * Filter a value through registered filter listeners.
	 *
	 * @template T
	 * @param string $eventName  Filter event name.
	 * @param T      $value      Initial value to filter.
	 * @param mixed  ...$args    Additional arguments passed to each filter.
	 * @return T
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
	 * @param string   $eventName  Event class name or plain event name.
	 * @param callable $listener   Signature: function(object $event): void
	 * @param int      $priority   Higher numbers run first.
	 */
	public function listen( string $eventName, callable $listener, int $priority = 10 ): void {
		// Register with Yii's event system if the event name is a class.
		if ( class_exists( $eventName ) || interface_exists( $eventName ) ) {
			Event::on( $eventName, 'oos.event', $listener );
		}

		$this->listeners[ $eventName ][ $priority ][] = $listener;
	}

	/**
	 * Register a filter listener.
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
	 * @param string   $eventName  The event or filter name.
	 * @param callable $listener   The exact callable to remove.
	 * @return bool
	 */
	public function removeListener( string $eventName, callable $listener ): bool {
		$removed = false;

		// Remove from Yii.
		if ( class_exists( $eventName ) || interface_exists( $eventName ) ) {
			Event::off( $eventName, 'oos.event', $listener );
			$removed = true;
		}

		// Remove from PSR-14 listeners.
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
