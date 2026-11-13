<?php
/**
 * Thrown when an OKF document cannot be parsed.
 *
 * Carries the stable, machine-readable code the WordPress implementation
 * returns through WP_Error, so callers on either runtime can branch on the
 * same value without inspecting the message.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

final class OkfParseException extends \RuntimeException {

	/**
	 * @param string          $errorCode Machine-readable code, e.g. `okf_unclosed_frontmatter`.
	 * @param string          $message   Human-readable summary.
	 * @param \Throwable|null $previous  Underlying cause, if any.
	 */
	public function __construct(
		public readonly string $errorCode,
		string $message,
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, 0, $previous );
	}
}
