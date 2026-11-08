<?php
/**
 * Tests for the ToolResult value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\Error\DomainError;
use Nvoos\Core\Domain\ValueObject\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase {

	public function testSuccessInvariants(): void {
		$result = ToolResult::success( 'ok', array( 'n' => 1 ) );

		$this->assertTrue( $result->isOk() );
		$this->assertFalse( $result->isFailure() );
		$this->assertNull( $result->error );
		$this->assertSame( 'ok', $result->message );
		$this->assertSame( array( 'n' => 1 ), $result->unwrap() );
	}

	public function testFailureInvariants(): void {
		$error  = new DomainError( 'nope', 'It failed.', array( 'a' => 1 ) );
		$result = ToolResult::failure( $error );

		$this->assertFalse( $result->isOk() );
		$this->assertTrue( $result->isFailure() );
		$this->assertSame( $error, $result->error );
		$this->assertSame( 'It failed.', $result->message );
		$this->assertNull( $result->data );
	}

	/**
	 * The ADT must never be in a state where both/neither branch is set.
	 */
	public function testExactlyOneBranchIsInhabited(): void {
		foreach ( array( ToolResult::success( 'ok' ), ToolResult::failure( new DomainError( 'c', 'm' ) ) ) as $result ) {
			$this->assertSame( $result->isOk(), null === $result->error );
		}
	}

	public function testSuccessEnvelopeMatchesCanonicalShape(): void {
		$envelope = ToolResult::success( 'ok', array( 'n' => 1 ) )->toEnvelope();

		$this->assertSame(
			array(
				'success' => true,
				'message' => 'ok',
				'data'    => array( 'n' => 1 ),
			),
			$envelope
		);
	}

	public function testSuccessEnvelopeOmitsNullData(): void {
		$envelope = ToolResult::success( 'ok' )->toEnvelope();

		$this->assertSame( array( 'success' => true, 'message' => 'ok' ), $envelope );
		$this->assertArrayNotHasKey( 'data', $envelope );
	}

	public function testFailureEnvelopeCarriesCodeAndData(): void {
		$envelope = ToolResult::failure(
			new DomainError( 'validation_failed', 'Bad input.', array( 'errors' => array( 'x' => array( 'Required.' ) ) ) )
		)->toEnvelope();

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'validation_failed', $envelope['code'] );
		$this->assertSame( 'Bad input.', $envelope['message'] );
		$this->assertSame( array( 'errors' => array( 'x' => array( 'Required.' ) ) ), $envelope['data'] );
	}

	public function testFailureEnvelopeOmitsEmptyData(): void {
		$envelope = ToolResult::failure( new DomainError( 'nope', 'No.' ) )->toEnvelope();

		$this->assertArrayNotHasKey( 'data', $envelope );
	}

	public function testMapTransformsSuccessPayload(): void {
		$result = ToolResult::success( 'ok', 2 )->map( static fn( int $n ): int => $n * 3 );

		$this->assertSame( 6, $result->unwrap() );
		$this->assertSame( 'ok', $result->message );
	}

	public function testMapLeavesFailureUntouched(): void {
		$calls = 0;
		$error = new DomainError( 'nope', 'No.' );

		$result = ToolResult::failure( $error )->map(
			static function ( mixed $v ) use ( &$calls ): mixed {
				++$calls;
				return $v;
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $result->isFailure() );
		$this->assertSame( 'nope', $result->error?->code );
	}

	public function testUnwrapThrowsOnFailure(): void {
		$this->expectException( \LogicException::class );

		ToolResult::failure( new DomainError( 'nope', 'No.' ) )->unwrap();
	}

	public function testGetOrElseReturnsFallbackOnFailure(): void {
		$this->assertSame( 'fallback', ToolResult::failure( new DomainError( 'nope', 'No.' ) )->getOrElse( 'fallback' ) );
		$this->assertSame( 'value', ToolResult::success( 'ok', 'value' )->getOrElse( 'fallback' ) );
	}
}
