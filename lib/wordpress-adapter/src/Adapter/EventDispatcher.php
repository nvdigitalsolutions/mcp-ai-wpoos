<?php
/**
 * WordPress adapter: EventDispatcherInterface implementation.
 *
 * Wraps WordPress action hooks (do_action) and filter hooks (apply_filters)
 * behind the framework-agnostic EventDispatcherInterface, which extends
 * PSR-14 with filter semantics.
 *
 * This adapter bridges two worlds:
 *  - PSR-14 dispatch → WordPress do_action
 *  - Core filter()    → WordPress apply_filters
 *  - Existing WP hooks (wp_mcp_ai_*) continue to work alongside both.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface {

	/**
	 * Map of PSR-14 event class names to WordPress hook names.
	 *
	 * Translates domain event classes to the wp_mcp_ai_* hook names
	 * that existing subscribers listen on.
	 *
	 * @var array<class-string, string>
	 */
	private array $eventHookMap = array();

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
	 * Map a domain event class to a WordPress hook name.
	 *
	 * Events of this class are dispatched through the given WordPress hook
	 * (via do_action) in addition to PSR-14 listeners.
	 */
	public function mapEventToHook( string $eventClass, string $hookName ): void {
		$this->eventHookMap[ $eventClass ] = $hookName;
	}

	public function dispatch( object $event ): object {
		$eventClass = \get_class( $event );

		// 1. Notify PSR-14 registered listeners.
		if ( isset( $this->listeners[ $eventClass ] ) ) {
			foreach ( $this->getSortedCallbacks( $this->listeners[ $eventClass ] ) as $listener ) {
				$listener( $event );
			}
		}

		// 2. Fire the WordPress action hook for backward compatibility.
		$hookName = $this->eventHookMap[ $eventClass ] ?? null;
		if ( null !== $hookName && \has_action( $hookName ) ) {
			\do_action( $hookName, $event );
		}

		return $event;
	}

	public function filter( string $eventName, mixed $value, mixed ...$args ): mixed {
		// 1. Run registered core filter listeners.
		if ( isset( $this->filters[ $eventName ] ) ) {
			foreach ( $this->getSortedCallbacks( $this->filters[ $eventName ] ) as $filter ) {
				$value = $filter( $value, ...$args );
			}
		}

		// 2. Run WordPress apply_filters for backward compatibility.
		if ( \has_filter( $eventName ) ) {
			$value = \apply_filters( $eventName, $value, ...$args );
		}

		return $value;
	}

	public function listen( string $eventName, callable $listener, int $priority = 10 ): void {
		// If the event name matches a WordPress hook, register on both sides.
		if ( $this->isWordPressHook( $eventName ) ) {
			\add_action( $eventName, $listener, $priority, 99 );
		}

		// PSR-14 listener registration (keyed by event class name).
		if ( \class_exists( $eventName ) || \interface_exists( $eventName ) ) {
			$this->listeners[ $eventName ][ $priority ][] = $listener;
			return;
		}

		// Generic event name — store as a plain listener.
		$this->listeners[ $eventName ][ $priority ][] = $listener;
	}

	public function listenFilter( string $eventName, callable $filter, int $priority = 10 ): void {
		// Register with WordPress filter system for backward compatibility.
		if ( $this->isWordPressHook( $eventName ) ) {
			\add_filter( $eventName, $filter, $priority, 99 );
		}

		$this->filters[ $eventName ][ $priority ][] = $filter;
	}

	public function removeListener( string $eventName, callable $listener ): bool {
		$removed = false;

		// Remove from WordPress hooks.
		if ( $this->isWordPressHook( $eventName ) ) {
			$removed = \remove_action( $eventName, $listener ) || \remove_filter( $eventName, $listener );
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

	/**
	 * Whether the given name matches an existing WordPress hook pattern.
	 */
	private function isWordPressHook( string $name ): bool {
		// WordPress hooks use the wp_mcp_ai_ prefix or common WP patterns.
		return \str_starts_with( $name, 'wp_mcp_ai_' )
			|| \str_starts_with( $name, 'pre_' )
			|| \str_starts_with( $name, 'rest_' );
	}

	/**
	 * Sort callbacks by priority (descending — highest first, WP convention).
	 *
	 * @param array<int, callable[]> $priorityMap
	 * @return callable[]
	 */
	private function getSortedCallbacks( array $priorityMap ): array {
		\krsort( $priorityMap, \SORT_NUMERIC );

		$sorted = array();
		foreach ( $priorityMap as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$sorted[] = $callback;
			}
		}

		return $sorted;
	}
}
