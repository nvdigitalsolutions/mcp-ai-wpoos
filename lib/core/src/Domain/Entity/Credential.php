<?php
/**
 * Immutable credential value object.
 *
 * Represents an issued API credential (token) that allows external
 * access to a specific assistant. Returned by AuthProviderInterface::issueCredential().
 *
 * The secret is hashed and should never be returned in API responses
 * after creation. The token is what clients use for authentication.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class Credential implements \JsonSerializable {

	/**
	 * @param string                  $id
	 * @param string                  $token          The bearer token for API authentication.
	 * @param string                  $secret         Hashed secret (do not expose after creation).
	 * @param int                     $assistantId    The assistant this credential grants access to.
	 * @param \DateTimeImmutable      $createdAt
	 * @param \DateTimeImmutable|null $expiresAt     Null = never expires.
	 * @param string[]                $capabilities   Granted capability strings.
	 */
	public function __construct(
		public string $id,
		public string $token,
		public string $secret,
		public int $assistantId,
		public \DateTimeImmutable $createdAt,
		public ?\DateTimeImmutable $expiresAt = null,
		public array $capabilities = array(),
	) {}

	/**
	 * Whether the credential has expired.
	 */
	public function isExpired(): bool {
		return null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable();
	}

	/**
	 * Safe serialization — never includes the secret.
	 */
	public function jsonSerialize(): array {
		return array(
			'id'           => $this->id,
			'token'        => $this->token,
			'assistant_id' => $this->assistantId,
			'created_at'   => $this->createdAt->format( 'c' ),
			'expires_at'   => $this->expiresAt?->format( 'c' ),
			'capabilities' => $this->capabilities,
		);
	}
}
