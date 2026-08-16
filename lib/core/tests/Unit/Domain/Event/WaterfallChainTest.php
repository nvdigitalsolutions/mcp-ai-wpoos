<?php
/**
 * Tests for WaterfallChain — pure middleware chain composition.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Event;

use Nvoos\Core\Domain\Event\WaterfallChain;
use PHPUnit\Framework\TestCase;

final class WaterfallChainTest extends TestCase {

	/**
	 * A trivial payload event.
	 */
	private function event( string $value = '' ): \stdClass {
		$event        = new \stdClass();
		$event->value = $value;

		return $event;
	}

	public function testFallbackRunsWhenNoListeners(): void {
		$chain = WaterfallChain::build(
			array(),
			static function ( object $event ): object {
				$event->value = 'fallback';
				return $event;
			}
		);

		$result = $chain( $this->event() );

		$this->assertSame( 'fallback', $result->value );
	}

	public function testListenersRunInRegistrationOrder(): void {
		$order = array();

		$chain = WaterfallChain::build(
			array(
				static function ( object $event, callable $next ) use ( &$order ): object {
					$order[] = 'first';
					return $next( $event );
				},
				static function ( object $event, callable $next ) use ( &$order ): object {
					$order[] = 'second';
					return $next( $event );
				},
			),
			static function ( object $event ) use ( &$order ): object {
				$order[] = 'fallback';
				return $event;
			}
		);

		$chain( $this->event() );

		$this->assertSame( array( 'first', 'second', 'fallback' ), $order );
	}

	public function testShortCircuitSkipsRemainingListeners(): void {
		$called = array();

		$chain = WaterfallChain::build(
			array(
				static function ( object $event, callable $next ) use ( &$called ): object {
					$called[]       = 'first';
					$event->value   = 'short-circuit';
					return $event; // No $next() — short-circuit.
				},
				static function ( object $event, callable $next ) use ( &$called ): object {
					$called[] = 'second';
					return $next( $event );
				},
			),
			static function ( object $event ) use ( &$called ): object {
				$called[] = 'fallback';
				return $event;
			}
		);

		$result = $chain( $this->event() );

		$this->assertSame( array( 'first' ), $called );
		$this->assertSame( 'short-circuit', $result->value );
	}

	public function testWrapperCanModifyResultAfterDelegation(): void {
		$chain = WaterfallChain::build(
			array(
				static function ( object $event, callable $next ): object {
					$result = $next( $event );
					$result->value .= '-wrapped';
					return $result;
				},
			),
			static function ( object $event ): object {
				$event->value = 'inner';
				return $event;
			}
		);

		$result = $chain( $this->event() );

		$this->assertSame( 'inner-wrapped', $result->value );
	}

	public function testEmptyListenerListIsIdentity(): void {
		$chain = WaterfallChain::build(
			array(),
			static function ( object $event ): object {
				return $event;
			}
		);

		$event = $this->event( 'kept' );

		$this->assertSame( $event, $chain( $event ) );
	}

	public function testChainCarriesNonObjectResults(): void {
		// Tool results are arrays/errors, not objects — the chain must
		// carry mixed values through every listener boundary.
		$chain = WaterfallChain::build(
			array(
				static function ( object $event, callable $next ): mixed {
					$result = $next( $event );

					return array( 'wrapped' => $result );
				},
			),
			static function ( object $event ): array {
				return array( 'success' => true, 'message' => 'done' );
			}
		);

		$result = $chain( $this->event() );

		$this->assertSame(
			array( 'wrapped' => array( 'success' => true, 'message' => 'done' ) ),
			$result,
		);
	}
}
