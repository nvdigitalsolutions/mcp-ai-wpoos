<?php
/**
 * Small text normalisers shared by tool ports.
 *
 * These are the framework-free equivalents of the two WordPress helpers tool
 * code reaches for most often. They are deliberately *approximations*, not
 * clones: `sanitize_text_field()` also strips percent-encoded octets, and
 * `sanitize_key()` runs a filter. Neither behaviour is reachable from a domain
 * class, and no captured tool behaviour depends on either.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Support;

final class Text {

	/**
	 * Collapse a value to a single line, the way `sanitize_text_field()` does.
	 *
	 * Tags are removed, runs of whitespace become one space, and the result is
	 * trimmed.
	 */
	public static function singleLine( string $value ): string {
		$value = strip_tags( $value );
		$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( $value );
	}

	/**
	 * Reduce a value to a lowercase key, the way `sanitize_key()` does.
	 *
	 * Everything outside `a-z0-9_-` is dropped, so `"Math Reasoning"` becomes
	 * `"mathreasoning"` and `"Māth"` becomes `"mth"`.
	 */
	public static function sanitizeKey( string $value ): string {
		$value = strtolower( $value );

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}

	/**
	 * Strip markup from a value while keeping its line structure.
	 *
	 * Matches `sanitize_textarea_field()`, including the part PHP's `strip_tags()`
	 * does not do: `<script>` and `<style>` blocks are removed with their
	 * contents, not just their tags.
	 */
	public static function multiline( string $value ): string {
		$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value );
		$value = strip_tags( $value );

		return trim( $value );
	}
}
