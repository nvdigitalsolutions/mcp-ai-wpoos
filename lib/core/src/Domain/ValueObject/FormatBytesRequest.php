<?php
/**
 * FormatBytesRequest — the parsed input of the format_bytes tool.
 *
 * An example of "parse, don't validate": the raw `$arguments` array is
 * narrowed once at the boundary into a typed value, and the pure handler
 * downstream never touches the untyped shape again.
 *
 * The conversions deliberately mirror AbstractTool::intParam() and
 * boolParam() so the parsed request is behaviourally identical to the
 * pre-refactor tool.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final readonly class FormatBytesRequest {

	/**
	 * @param int  $bytes    Byte count as supplied (may be out of range; the
	 *                       handler decides whether that is a failure).
	 * @param int  $decimals Decimal places.
	 * @param bool $binary   Whether to use 1024-based units.
	 */
	public function __construct(
		public int $bytes,
		public int $decimals = 1,
		public bool $binary = false,
	) {}

	/**
	 * Parse raw tool arguments into a request.
	 *
	 * Total function: it never fails. Validation that depends on domain
	 * rules (e.g. `bytes >= 0`) belongs to the handler so it can return a
	 * structured failure instead of throwing.
	 *
	 * @param array<string, mixed> $arguments Raw tool arguments.
	 */
	public static function fromArguments( array $arguments ): self {
		return new self(
			self::intArgument( $arguments, 'bytes', 0 ),
			self::intArgument( $arguments, 'decimals', 1 ),
			self::boolArgument( $arguments, 'binary', false ),
		);
	}

	/**
	 * Mirror of AbstractTool::intParam().
	 *
	 * @param array<string, mixed> $arguments
	 */
	private static function intArgument( array $arguments, string $key, int $default ): int {
		return isset( $arguments[ $key ] ) ? (int) $arguments[ $key ] : $default;
	}

	/**
	 * Mirror of AbstractTool::boolParam().
	 *
	 * @param array<string, mixed> $arguments
	 */
	private static function boolArgument( array $arguments, string $key, bool $default ): bool {
		$value = $arguments[ $key ] ?? $default;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}

		return (bool) $value;
	}
}
