<?php
/**
 * Option — a value that may be absent.
 *
 * A minimal `Option[A]` (Red Book ch. 4) for the few places that need to
 * *chain* absence rather than merely represent it. Plain nullable types
 * (`?int`) remain the default for everything else; this type exists so a
 * caller can distinguish "no value" from "value is null" and compose
 * lookups without nested ternaries.
 *
 * Invariant: `isSome() === ! isNone()`. `some( null )` is a populated
 * Option holding null, which is deliberately distinct from `none()`.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

/**
 * @template T
 */
final class Option {

	/**
	 * @param T $value
	 * @param bool   $isSome Whether the Option is populated (even with null).
	 */
	private function __construct(
		private readonly mixed $value,
		private readonly bool $isSome,
	) {}

	/**
	 * Populated Option — the value may itself be null.
	 *
	 * @template U
	 * @param U $value
	 * @return self<U>
	 */
	public static function some( mixed $value ): self {
		return new self( $value, true );
	}

	/**
	 * Empty Option.
	 *
	 * @return self<mixed>
	 */
	public static function none(): self {
		return new self( null, false );
	}

	/**
	 * Normalise a nullable value into an Option.
	 *
	 * `null` becomes `none()`; anything else becomes `some()`. Use this at a
	 * boundary where "absent" and "null" are the same thing.
	 *
	 * @template U
	 * @param U|null $value
	 * @return self<mixed>
	 */
	public static function of( mixed $value ): self {
		return null === $value ? self::none() : self::some( $value );
	}

	/**
	 * Whether a value is present.
	 */
	public function isSome(): bool {
		return $this->isSome;
	}

	/**
	 * Whether the Option is empty.
	 */
	public function isNone(): bool {
		return ! $this->isSome;
	}

	/**
	 * Transform the contained value.
	 *
	 * @template U
	 * @param callable(T): U $fn
	 * @return self<mixed>
	 */
	public function map( callable $fn ): self {
		return $this->isSome ? self::some( $fn( $this->value ) ) : self::none();
	}

	/**
	 * Transform the contained value into another Option (no nesting).
	 *
	 * @template U
	 * @param callable(T): self<U> $fn
	 * @return self<mixed>
	 */
	public function flatMap( callable $fn ): self {
		return $this->isSome ? $fn( $this->value ) : self::none();
	}

	/**
	 * Return the value, or a fallback when empty.
	 *
	 * @param T $default
	 * @return T
	 */
	public function getOrElse( mixed $default ): mixed {
		return $this->isSome ? $this->value : $default;
	}

	/**
	 * Return the value or fail loudly.
	 *
	 * Use only where an empty Option proves a programming error, never for
	 * user input.
	 *
	 * @return T
	 * @throws \LogicException When the Option is empty.
	 */
	public function orFail( string $message ): mixed {
		if ( ! $this->isSome ) {
			throw new \LogicException( $message );
		}

		return $this->value;
	}

	/**
	 * Run a side effect on the contained value, if any.
	 *
	 * @param callable(T): void $fn
	 */
	public function each( callable $fn ): void {
		if ( $this->isSome ) {
			$fn( $this->value );
		}
	}

	/**
	 * Escape hatch back to a nullable value.
	 *
	 * @return T|null
	 */
	public function toNullable(): mixed {
		return $this->isSome ? $this->value : null;
	}
}
