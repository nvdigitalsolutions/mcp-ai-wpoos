<?php
/**
 * Thrown when input data fails validation.
 *
 * Carries field-level error details for structured error responses.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class ValidationException extends \RuntimeException {

	/**
	 * @param string                  $message  Summary message.
	 * @param array<string, string[]> $errors   Field name → [error messages].
	 * @param \Throwable|null         $previous
	 */
	public function __construct(
		string $message = 'Validation failed.',
		public readonly array $errors = array(),
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, 422, $previous );
	}

	/**
	 * Whether any field-level errors were recorded.
	 */
	public function hasFieldErrors(): bool {
		return array() !== $this->errors;
	}
}
