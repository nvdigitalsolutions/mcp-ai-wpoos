<?php
/**
 * Generic domain error — the standalone/oOS-native error type.
 *
 * Used when no framework-specific error type is available (standalone
 * PHP, testing). WordPress uses WP_Error via the adapter. Laravel
 * throws exceptions. This class provides a consistent, serializable
 * error when no framework is present.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

final class DomainError implements \JsonSerializable {

	/**
	 * @param string               $code    Machine-readable error code (snake_case).
	 * @param string               $message Human-readable error message.
	 * @param array<string, mixed> $data    Additional structured error data.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly array $data = array(),
	) {}

	/**
	 * Build from the shape produced by ErrorFactoryInterface::normalize().
	 *
	 * This is the single conversion point from a platform error
	 * (WP_Error, exception payload, DomainError) into the framework-neutral
	 * error type.
	 *
	 * @param array{code?: string, message?: string, data?: mixed} $normalized Normalised error array.
	 */
	public static function fromArray( array $normalized ): self {
		$data = $normalized['data'] ?? array();

		return new self(
			(string) ( $normalized['code'] ?? 'unknown_error' ),
			(string) ( $normalized['message'] ?? 'Unknown error.' ),
			is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Serialise to the shape ErrorFactoryInterface::normalize() returns.
	 *
	 * @return array{code: string, message: string, data: array<string, mixed>}
	 */
	public function toArray(): array {
		return array(
			'code'    => $this->code,
			'message' => $this->message,
			'data'    => $this->data,
		);
	}

	/**
	 * @return array{code: string, message: string, data: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
