<?php
/**
 * Tests for CancellationToken — cooperative cancellation value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\Error\CancelledException;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenTest extends TestCase {

	public function testStartsActive(): void {
		$token = new CancellationToken();

		$this->assertFalse( $token->isCancelled() );
		$this->assertSame( '', $token->reason() );
	}

	public function testCancelSetsReason(): void {
		$token = new CancellationToken();
		$token->cancel( 'user_abort' );

		$this->assertTrue( $token->isCancelled() );
		$this->assertSame( 'user_abort', $token->reason() );
	}

	public function testCancelDefaultsReason(): void {
		$token = new CancellationToken();
		$token->cancel();

		$this->assertTrue( $token->isCancelled() );
		$this->assertSame( 'cancelled', $token->reason() );
	}

	public function testFirstCancelWins(): void {
		$token = new CancellationToken();
		$token->cancel( 'first' );
		$token->cancel( 'second' );

		$this->assertSame( 'first', $token->reason() );
	}

	public function testParentCancellationPropagates(): void {
		$parent = new CancellationToken();
		$child  = new CancellationToken( $parent );

		$this->assertFalse( $child->isCancelled() );

		$parent->cancel( 'parent_abort' );

		$this->assertTrue( $child->isCancelled() );
		$this->assertSame( 'parent_abort', $child->reason() );
	}

	public function testChildCancellationDoesNotAffectParent(): void {
		$parent = new CancellationToken();
		$child  = new CancellationToken( $parent );
		$child->cancel( 'child_abort' );

		$this->assertTrue( $child->isCancelled() );
		$this->assertFalse( $parent->isCancelled() );
	}

	public function testDeadlineExpiry(): void {
		$token = CancellationToken::withDeadline( 0.05 );

		$this->assertFalse( $token->isCancelled() );

		\usleep( 80_000 );

		$this->assertTrue( $token->isCancelled() );
		$this->assertSame( CancellationToken::REASON_DEADLINE, $token->reason() );
	}

	public function testProbeBasedCancellation(): void {
		$cancelled = false;
		$token     = new CancellationToken(
			null,
			static function () use ( &$cancelled ): bool {
				return $cancelled;
			}
		);

		$this->assertFalse( $token->isCancelled() );

		$cancelled = true;

		$this->assertTrue( $token->isCancelled() );
		$this->assertSame( 'cancelled', $token->reason() );
	}

	public function testThrowingProbeFailsOpen(): void {
		$token = new CancellationToken(
			null,
			static function (): bool {
				throw new \RuntimeException( 'probe broken' );
			}
		);

		$this->assertFalse( $token->isCancelled() );
		$this->assertSame( '', $token->reason() );
	}

	public function testThrowIfCancelledThrowsWithReason(): void {
		$token = new CancellationToken();
		$token->cancel( 'deadline_exceeded' );

		$this->expectException( CancelledException::class );
		$this->expectExceptionMessage( 'deadline_exceeded' );

		$token->throwIfCancelled();
	}

	public function testThrowIfCancelledIsNoopWhenActive(): void {
		$token = new CancellationToken();

		$token->throwIfCancelled();

		$this->assertFalse( $token->isCancelled() );
	}
}
