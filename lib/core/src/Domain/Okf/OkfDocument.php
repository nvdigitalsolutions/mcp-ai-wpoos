<?php
/**
 * Parsed OKF document: frontmatter plus body.
 *
 * The value object is deliberately thin. Frontmatter stays an associative
 * array because OKF concepts are open-ended — v0.2 defines a handful of
 * well-known keys and explicitly permits others — so a typed property list
 * would reject valid documents.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

final class OkfDocument {

	/**
	 * @param array<string, mixed> $frontmatter Parsed YAML frontmatter.
	 * @param string               $body        Markdown after the closing delimiter.
	 */
	public function __construct(
		public readonly array $frontmatter,
		public readonly string $body,
	) {}

	/**
	 * Array shape matching the WordPress parser's return value.
	 *
	 * Kept explicit so the differential test compares like with like rather
	 * than relying on property order.
	 *
	 * @return array{frontmatter: array<string, mixed>, body: string}
	 */
	public function toArray(): array {
		return array(
			'frontmatter' => $this->frontmatter,
			'body'        => $this->body,
		);
	}
}
