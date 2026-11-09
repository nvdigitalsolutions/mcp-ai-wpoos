<?php
/**
 * JSON encoding helpers.
 *
 * `json_encode()` is typed `string|false`, so threading its result through
 * request/response code forces every caller to branch on a failure that is
 * almost always fatal (invalid UTF-8, recursion, INF/NAN) - and under
 * `declare(strict_types=1)` passing that `false` into a `string` parameter is
 * a TypeError anyway. These helpers collapse the union at the source with an
 * explicit, documented policy instead of an implicit one.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Support;

use RuntimeException;

/**
 * Total JSON encoding.
 */
final class Json {

	/**
	 * Encode a value, throwing when encoding fails.
	 *
	 * Use where silently sending a degraded payload would be worse than
	 * failing: HTTP request bodies, signed tokens, anything a remote service
	 * will parse strictly.
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $flags Optional json_encode() flags.
	 * @return string Encoded JSON.
	 *
	 * @throws RuntimeException When the value cannot be encoded.
	 */
	public static function encode( mixed $value, int $flags = 0 ): string {
		$json = \json_encode( $value, $flags );

		if ( false === $json ) {
			throw new RuntimeException( 'JSON encoding failed: ' . \json_last_error_msg() );
		}

		return $json;
	}

	/**
	 * Encode a value, falling back to a literal when encoding fails.
	 *
	 * Use for best-effort contexts (LLM-visible text, token estimation, log
	 * payloads) where losing one value must not break the request.
	 *
	 * @param mixed  $value    Value to encode.
	 * @param string $fallback Literal returned when encoding fails.
	 * @param int    $flags    Optional json_encode() flags.
	 * @return string Encoded JSON, or $fallback.
	 */
	public static function encodeOr( mixed $value, string $fallback, int $flags = 0 ): string {
		$json = \json_encode( $value, $flags );

		return false === $json ? $fallback : $json;
	}
}
