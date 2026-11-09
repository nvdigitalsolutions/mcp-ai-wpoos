<?php
/**
 * ToolResult — the typed result of a tool execution.
 *
 * An algebraic data type (Red Book ch. 3–4) with exactly two inhabitants:
 * a success carrying a message and an optional payload, or a failure carrying
 * a DomainError. It is the typed counterpart of the legacy canonical
 * envelope, and `toEnvelope()` is the single place the array shape is
 * produced.
 *
 * Invariants (asserted by tests):
 *  - `isOk()` is true  ⇔ `error === null`
 *  - `isOk()` is false ⇔ `error !== null`
 *  - an envelope produced by `toEnvelope()` round-trips through
 *    the error factory's `normalize()` without losing code or message.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

use Nvoos\Core\Domain\Error\DomainError;

/**
 * @template T
 */
final readonly class ToolResult {

	/**
	 * @param bool             $ok      Whether the tool succeeded.
	 * @param string           $message Human-readable summary.
	 * @param T                $data    Success payload.
	 * @param DomainError|null $error   Failure details.
	 */
	private function __construct(
		public bool $ok,
		public string $message,
		public mixed $data,
		public ?DomainError $error,
	) {}

	/**
	 * Build a successful result.
	 *
	 * @template U
	 * @param string   $message
	 * @param U        $data
	 * @return self<U>
	 */
	public static function success( string $message, mixed $data = null ): self {
		return new self( true, $message, $data, null );
	}

	/**
	 * Build a failed result.
	 *
	 * @param DomainError $error
	 * @return self<mixed>
	 */
	public static function failure( DomainError $error ): self {
		return new self( false, $error->message, null, $error );
	}

	/**
	 * Whether the tool succeeded.
	 */
	public function isOk(): bool {
		return $this->ok;
	}

	/**
	 * Whether the tool failed.
	 */
	public function isFailure(): bool {
		return ! $this->ok;
	}

	/**
	 * Return the payload or fail loudly.
	 *
	 * Use only where a failure proves a programming error; callers handling
	 * user input should branch on `isOk()` instead.
	 *
	 * @return T
	 * @throws \LogicException When the result is a failure.
	 */
	public function unwrap(): mixed {
		if ( ! $this->ok ) {
			throw new \LogicException(
				'unwrap() called on a failed ToolResult: ' . ( $this->error?->code ?? 'unknown_error' ),
			);
		}

		return $this->data;
	}

	/**
	 * Return the payload or a fallback when the result failed.
	 *
	 * @param T $default
	 * @return T
	 */
	public function getOrElse( mixed $default ): mixed {
		return $this->ok ? $this->data : $default;
	}

	/**
	 * Transform the payload of a successful result.
	 *
	 * Failures pass through untouched, which is what makes chaining safe.
	 *
	 * @template U
	 * @param callable(T): U $fn
	 * @return self<U>
	 */
	public function map( callable $fn ): self {
		if ( ! $this->ok ) {
			return self::failure( $this->error ?? new DomainError( 'unknown_error', 'Unknown error.' ) );
		}

		return self::success( $this->message, $fn( $this->data ) );
	}

	/**
	 * Serialise to the legacy canonical envelope.
	 *
	 * Success:  `array{success: true, message: string, data?: mixed}`
	 * Failure:  `array{success: false, code: string, message: string, data?: array}`
	 *
	 * This is the ONLY producer of that shape in the typed path; the
	 * canonical-envelope PHPCS sniff has exactly one implementation to check.
	 *
	 * @return array{success: bool, message: string, code?: string, data?: mixed}
	 */
	public function toEnvelope(): array {
		if ( $this->ok ) {
			$envelope = array(
				'success' => true,
				'message' => $this->message,
			);

			if ( null !== $this->data ) {
				$envelope['data'] = $this->data;
			}

			return $envelope;
		}

		$error    = $this->error ?? new DomainError( 'unknown_error', $this->message );
		$envelope = array(
			'success' => false,
			'code'    => $error->code,
			'message' => $error->message,
		);

		if ( ! empty( $error->data ) ) {
			$envelope['data'] = $error->data;
		}

		return $envelope;
	}
}
