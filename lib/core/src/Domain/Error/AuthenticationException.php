<?php
/**
 * Thrown when authentication fails (invalid/expired token, bad credentials).
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class AuthenticationException extends \RuntimeException {

	/**
	 * @param string          $message
	 * @param string          $reason     Why auth failed: 'expired', 'invalid', 'revoked', 'missing'.
	 * @param \Throwable|null $previous
	 */
	public function __construct(
		string $message = 'Authentication failed.',
		public readonly string $reason = 'invalid',
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, 401, $previous );
	}
}
