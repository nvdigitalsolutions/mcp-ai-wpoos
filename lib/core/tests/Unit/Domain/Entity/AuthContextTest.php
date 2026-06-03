<?php
/**
 * Tests for AuthContext entity.
 *
 * @package Oos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tests\Unit\Domain\Entity;

use Oos\Core\Domain\Entity\AuthContext;
use PHPUnit\Framework\TestCase;

final class AuthContextTest extends TestCase {

	public function testAuthenticatedUser(): void {
		$context = new AuthContext(
			userId: 5,
			authenticated: true,
			tokenType: 'bearer',
			capabilities: array( 'edit_posts', 'read' ),
		);

		$this->assertSame( 5, $context->userId );
		$this->assertTrue( $context->authenticated );
		$this->assertSame( 'bearer', $context->tokenType );
		$this->assertNull( $context->scopedAssistantId );
		$this->assertSame( array( 'edit_posts', 'read' ), $context->capabilities );
		$this->assertFalse( $context->isGuest() );
		$this->assertTrue( $context->isTokenAuthenticated() );
	}

	public function testGuestUser(): void {
		$context = new AuthContext(
			tokenType: 'guest',
		);

		$this->assertSame( 0, $context->userId );
		$this->assertFalse( $context->authenticated );
		$this->assertTrue( $context->isGuest() );
	}

	public function testIsGuest(): void {
		// Explicit guest token type.
		$this->assertTrue( ( new AuthContext( tokenType: 'guest' ) )->isGuest() );

		// Unauthenticated with userId 0 — also a guest per the fallback rule.
		$this->assertTrue( ( new AuthContext() )->isGuest() );

		// Authenticated bearer token — NOT a guest.
		$this->assertFalse( ( new AuthContext( tokenType: 'bearer', authenticated: true ) )->isGuest() );

		// Unauthenticated mesh with userId 0 — IS a guest (fallback rule).
		$this->assertTrue( ( new AuthContext( tokenType: 'mesh' ) )->isGuest() );
	}

	public function testIsTokenAuthenticated(): void {
		$this->assertTrue(
			( new AuthContext( tokenType: 'bearer', authenticated: true ) )->isTokenAuthenticated()
		);
		$this->assertFalse(
			( new AuthContext( tokenType: 'bearer', authenticated: false ) )->isTokenAuthenticated()
		);
		$this->assertFalse(
			( new AuthContext( tokenType: 'guest', authenticated: true ) )->isTokenAuthenticated()
		);
	}

	public function testIsAssistantScoped(): void {
		$this->assertTrue(
			( new AuthContext( scopedAssistantId: 42 ) )->isAssistantScoped()
		);
		$this->assertFalse(
			( new AuthContext() )->isAssistantScoped()
		);
	}

	public function testDefaultValues(): void {
		$context = new AuthContext();

		$this->assertSame( 0, $context->userId );
		$this->assertFalse( $context->authenticated );
		$this->assertSame( '', $context->tokenType );
		$this->assertNull( $context->scopedAssistantId );
		$this->assertSame( array(), $context->capabilities );
		$this->assertSame( array(), $context->metadata );
	}
}
