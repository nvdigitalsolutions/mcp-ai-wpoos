<?php
/**
 * Waterfall/serial dispatch tests.
 *
 * The capability is optional by design: call sites feature-detect it with
 * `instanceof`. The WordPress adapter implemented it and this one did not, so
 * every waterfall hook was skipped in Laravel without an error. These tests pin
 * the same chain semantics the WordPress adapter delegates to WaterfallChain
 * for, so the two runtimes cannot drift.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Nvoos\Laravel\Adapter\EventDispatcher;

final class EventDispatcherWaterfallTest extends TestCase {

	private function dispatcher(): EventDispatcher {
		return new EventDispatcher();
	}

	public function test_it_satisfies_both_contracts(): void {
		$dispatcher = $this->dispatcher();

		$this->assertInstanceOf( EventDispatcherInterface::class, $dispatcher );
		$this->assertInstanceOf( WaterfallEventDispatcherInterface::class, $dispatcher );
	}

	public function test_a_delegating_listener_reaches_the_fallback(): void {
		$dispatcher = $this->dispatcher();
		$seen       = array();

		$dispatcher->listenWaterfall(
			'chain',
			static function ( object $event, callable $next ) use ( &$seen ) {
				$seen[] = $event->id;

				return $next( $event );
			},
		);

		$result = $dispatcher->waterfall(
			'chain',
			(object) array( 'id' => 3 ),
			static fn ( object $event ): string => 'fallback:' . $event->id,
		);

		$this->assertSame( array( 3 ), $seen );
		$this->assertSame( 'fallback:3', $result );
	}

	public function test_a_returning_listener_short_circuits_the_chain(): void {
		$dispatcher = $this->dispatcher();
		$ran        = array();

		$dispatcher->listenWaterfall(
			'chain',
			static function ( object $event, callable $next ) use ( &$ran ): string {
				$ran[] = 'high';

				return 'blocked';
			},
			20,
		);

		$dispatcher->listenWaterfall(
			'chain',
			static function ( object $event, callable $next ) use ( &$ran ) {
				$ran[] = 'low';

				return $next( $event );
			},
			10,
		);

		$result = $dispatcher->waterfall(
			'chain',
			(object) array(),
			static fn ( object $event ): string => 'fallback',
		);

		$this->assertSame( array( 'high' ), $ran );
		$this->assertSame( 'blocked', $result );
	}

	public function test_serial_runs_every_listener_in_priority_order(): void {
		$dispatcher = $this->dispatcher();
		$order      = array();

		$dispatcher->listenSerial(
			'gate',
			static function ( object $event ) use ( &$order ): void {
				$order[] = 'first';
			},
			20,
		);

		$dispatcher->listenSerial(
			'gate',
			static function ( object $event ) use ( &$order ): void {
				$order[] = 'second';
			},
			10,
		);

		$dispatcher->serial( 'gate', (object) array() );

		$this->assertSame( array( 'first', 'second' ), $order );
	}

	public function test_remove_listener_forgets_a_waterfall_registration(): void {
		$dispatcher = $this->dispatcher();

		$listener = static fn ( object $event, callable $next ): string => 'blocked';

		$dispatcher->listenWaterfall( 'chain', $listener );

		$this->assertSame(
			'blocked',
			$dispatcher->waterfall( 'chain', (object) array(), static fn ( object $event ): string => 'fallback' ),
		);

		$this->assertTrue( $dispatcher->removeListener( 'chain', $listener ) );
		$this->assertSame(
			'fallback',
			$dispatcher->waterfall( 'chain', (object) array(), static fn ( object $event ): string => 'fallback' ),
		);
	}
}
