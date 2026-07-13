<?php
/**
 * Tests for Credential value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\Credential;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class CredentialTest extends TestCase {

	private Credential $credential;

	protected function setUp(): void {
		$this->credential = new Credential(
			id: 'cred_abc123',
			token: 'cred_abc123.secret_token_value',
			secret: '$2y$10$hashed_secret_value_here_very_long',
			assistantId: 42,
			createdAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			expiresAt: new DateTimeImmutable( '2027-01-01T00:00:00Z' ),
			capabilities: array( 'read', 'chat' ),
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertSame( 'cred_abc123', $this->credential->id );
		$this->assertSame( 'cred_abc123.secret_token_value', $this->credential->token );
		$this->assertSame( '$2y$10$hashed_secret_value_here_very_long', $this->credential->secret );
		$this->assertSame( 42, $this->credential->assistantId );
		$this->assertSame( array( 'read', 'chat' ), $this->credential->capabilities );
	}

	public function testIsExpiredReturnsFalseForFutureExpiry(): void {
		$this->assertFalse( $this->credential->isExpired() );
	}

	public function testIsExpiredReturnsTrueForPastExpiry(): void {
		$expired = new Credential(
			id: 'cred_expired',
			token: 'expired.token',
			secret: 'hashed',
			assistantId: 1,
			createdAt: new DateTimeImmutable( '2024-01-01T00:00:00Z' ),
			expiresAt: new DateTimeImmutable( '2025-01-01T00:00:00Z' ),
		);

		$this->assertTrue( $expired->isExpired() );
	}

	public function testIsExpiredReturnsFalseWhenNeverExpires(): void {
		$never = new Credential(
			id: 'cred_never',
			token: 'never.token',
			secret: 'hashed',
			assistantId: 1,
			createdAt: new DateTimeImmutable(),
		);

		$this->assertFalse( $never->isExpired() );
	}

	public function testIsExpiredForExactNow(): void {
		$now = new DateTimeImmutable();
		$exact = new Credential(
			id: 'cred_now',
			token: 'now.token',
			secret: 'hashed',
			assistantId: 1,
			createdAt: new DateTimeImmutable( '2025-01-01T00:00:00Z' ),
			expiresAt: $now,
		);

		// Expires at exactly "now" should be considered expired.
		$this->assertTrue( $exact->isExpired() );
	}

	public function testDefaultValues(): void {
		$cred = new Credential(
			id: 'cred_min',
			token: 'min.token',
			secret: 'min_secret',
			assistantId: 10,
			createdAt: new DateTimeImmutable(),
		);

		$this->assertNull( $cred->expiresAt );
		$this->assertSame( array(), $cred->capabilities );
		$this->assertFalse( $cred->isExpired() );
	}

	public function testJsonSerializeNeverExposesSecret(): void {
		$json = $this->credential->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 'cred_abc123', $json['id'] );
		$this->assertSame( 'cred_abc123.secret_token_value', $json['token'] );
		$this->assertSame( 42, $json['assistant_id'] );
		$this->assertSame( array( 'read', 'chat' ), $json['capabilities'] );
		$this->assertStringContainsString( '2026-01-01', $json['created_at'] );
		$this->assertStringContainsString( '2027-01-01', $json['expires_at'] );

		// The secret must NEVER appear in serialized output.
		$this->assertArrayNotHasKey( 'secret', $json );
	}

	public function testJsonSerializeWhenNeverExpires(): void {
		$never = new Credential(
			id: 'cred_never',
			token: 'never.token',
			secret: 'hashed',
			assistantId: 1,
			createdAt: new DateTimeImmutable( '2026-06-01T00:00:00Z' ),
		);

		$json = $never->jsonSerialize();

		$this->assertNull( $json['expires_at'] );
	}
}
