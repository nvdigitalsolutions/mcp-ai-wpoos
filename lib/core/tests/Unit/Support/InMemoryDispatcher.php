<?php
/**
 * InMemoryDispatcher — test dispatcher implementing both the emit-style
 * EventDispatcherInterface and the Cordis-style WaterfallEventDispatcherInterface.
 *
 * Shared by the ToolRegistry pipeline tests and the ChatOrchestrator
 * loop-waterfall tests; no platform code involved.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Support;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Nvoos\Core\Domain\Event\WaterfallChain;

final class InMemoryDispatcher implements EventDispatcherInterface, WaterfallEventDispatcherInterface {

	/** @var string[] Classes of every dispatched (emit) event. */
	public array $dispatched = array();

	/**
	 * Optional back-reference set by ChatOrchestratorTest's scripted router
	 * so tests can inspect the provider payloads actually sent.
	 */
	public ?object $router = null;

	/** @var array<string, array<int, callable[]>> */
	private array $waterfalls = array();

	/** @var array<string, array<int, callable[]>> */
	private array $serials = array();

	public function dispatch( object $event ): object {
		$this->dispatched[] = \get_class( $event );

		return $event;
	}

	public function filter( string $eventName, mixed $value, mixed ...$args ): mixed {
		return $value;
	}

	public function listen( string $eventName, callable $listener, int $priority = 10 ): void {}

	public function listenFilter( string $eventName, callable $filter, int $priority = 10 ): void {}

	public function removeListener( string $eventName, callable $listener ): bool {
		return false;
	}

	public function listenWaterfall( string $eventName, callable $listener, int $priority = 10 ): void {
		$this->waterfalls[ $eventName ][ $priority ][] = $listener;
	}

	public function waterfall( string $eventName, object $event, callable $final ): mixed {
		$listeners = array();
		foreach ( $this->waterfalls[ $eventName ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$listeners[] = $callback;
			}
		}

		return WaterfallChain::build( $listeners, $final )( $event );
	}

	public function listenSerial( string $eventName, callable $listener, int $priority = 10 ): void {
		$this->serials[ $eventName ][ $priority ][] = $listener;
	}

	public function serial( string $eventName, object $event ): void {
		foreach ( $this->serials[ $eventName ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback( $event );
			}
		}
	}
}
