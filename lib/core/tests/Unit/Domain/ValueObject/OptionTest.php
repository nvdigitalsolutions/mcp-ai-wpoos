<?php
/**
 * Tests for the Option value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\Option;
use PHPUnit\Framework\TestCase;

final class OptionTest extends TestCase {

	public function testSomeIsSome(): void {
		$option = Option::some( 42 );

		$this->assertTrue( $option->isSome() );
		$this->assertFalse( $option->isNone() );
		$this->assertSame( 42, $option->toNullable() );
	}

	public function testNoneIsNone(): void {
		$option = Option::none();

		$this->assertFalse( $option->isSome() );
		$this->assertTrue( $option->isNone() );
		$this->assertNull( $option->toNullable() );
	}

	/**
	 * some( null ) is populated, which is the whole point of the type: it is
	 * distinguishable from an absent value.
	 */
	public function testSomeNullDiffersFromNone(): void {
		$someNull = Option::some( null );
		$none     = Option::none();

		$this->assertTrue( $someNull->isSome() );
		$this->assertTrue( $none->isNone() );

		// A populated Option holding null does NOT fall back — that is the
		// difference between "absent" and "present but null".
		$this->assertNull( $someNull->getOrElse( 'fallback' ) );
		$this->assertSame( 'fallback', $none->getOrElse( 'fallback' ) );
	}

	public function testOfNormalisesNull(): void {
		$this->assertTrue( Option::of( null )->isNone() );
		$this->assertTrue( Option::of( 0 )->isSome() );
		$this->assertTrue( Option::of( '' )->isSome() );
		$this->assertTrue( Option::of( false )->isSome() );
	}

	public function testMapTransformsSome(): void {
		$this->assertSame( 84, Option::some( 42 )->map( static fn( int $v ): int => $v * 2 )->toNullable() );
	}

	public function testMapShortCircuitsNone(): void {
		$calls = 0;

		$result = Option::none()->map(
			static function ( int $v ) use ( &$calls ): int {
				++$calls;
				return $v;
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $result->isNone() );
	}

	public function testFlatMapDoesNotNest(): void {
		$result = Option::some( 1 )->flatMap(
			static fn( int $v ): Option => Option::some( $v + 1 )
		);

		$this->assertSame( 2, $result->toNullable() );

		$empty = Option::some( 1 )->flatMap(
			static fn( int $v ): Option => Option::none()
		);

		$this->assertTrue( $empty->isNone() );
	}

	public function testEachRunsOnlyForSome(): void {
		$seen = array();

		Option::some( 'a' )->each(
			static function ( string $v ) use ( &$seen ): void {
				$seen[] = $v;
			}
		);
		Option::none()->each(
			static function ( string $v ) use ( &$seen ): void {
				$seen[] = $v;
			}
		);

		$this->assertSame( array( 'a' ), $seen );
	}

	public function testOrFailReturnsValue(): void {
		$this->assertSame( 7, Option::some( 7 )->orFail( 'missing' ) );
	}

	public function testOrFailThrowsOnNone(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'missing' );

		Option::none()->orFail( 'missing' );
	}

	/**
	 * getOrElse must not confuse a populated null with an absent value.
	 */
	public function testGetOrElseDistinguishesSomeNull(): void {
		$this->assertNull( Option::some( null )->getOrElse( 'x' ) );
		$this->assertSame( 'x', Option::none()->getOrElse( 'x' ) );
	}
}
