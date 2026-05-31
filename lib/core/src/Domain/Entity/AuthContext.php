<?php
/**
 * Immutable authentication context.
 *
 * Returned by AuthProviderInterface::authenticate() to represent
 * the resolved identity and scope of a request.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class AuthContext implements \JsonSerializable {

	/**
	 * @param int      $userId              Authenticated user ID. 0 = guest/unauthenticated.
	 * @param bool     $authenticated       Whether the request was successfully authenticated.
	 * @param string   $tokenType           'bearer', 'nonce', 'mesh', 'guest'.
	 * @param int|null $scopedAssistantId   Token is restricted to a single assistant.
	 * @param string[] $capabilities         Resolved capability strings.
	 * @param array    $metadata             Additional auth metadata (token_context, etc.).
	 */
	public function __construct(
		public int $userId = 0,
		public bool $authenticated = false,
		public string $tokenType = '',
		public ?int $scopedAssistantId = null,
		public array $capabilities = array(),
		public array $metadata = array(),
	) {}

	/**
	 * Whether this is an unauthenticated guest request.
	 */
	public function isGuest(): bool {
		return 'guest' === $this->tokenType || ( 0 === $this->userId && ! $this->authenticated );
	}

	/**
	 * Whether the request was authenticated via a bearer token.
	 */
	public function isTokenAuthenticated(): bool {
		return $this->authenticated && 'bearer' === $this->tokenType;
	}

	/**
	 * Whether the token restricts access to a single assistant.
	 */
	public function isAssistantScoped(): bool {
		return null !== $this->scopedAssistantId;
	}

	public function jsonSerialize(): array {
		return array(
			'user_id'             => $this->userId,
			'authenticated'       => $this->authenticated,
			'token_type'          => $this->tokenType,
			'scoped_assistant_id' => $this->scopedAssistantId,
			'capabilities'        => $this->capabilities,
			'metadata'            => $this->metadata,
		);
	}
}
