<?php
/**
 * Contract for reading OKF markdown into frontmatter and body.
 *
 * Framework-neutral: no WordPress, no Laravel, no filesystem. Callers hand in
 * the document text and receive either a parsed document or null.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

interface OkfParserInterface {

	/**
	 * Extract YAML frontmatter and body from a markdown string.
	 *
	 * Returns null when the document has no frontmatter block at all — that
	 * is a legitimate OKF body-only concept, not an error.
	 *
	 * @param string $content Raw markdown file content.
	 *
	 * @throws OkfParseException When a frontmatter block is opened but not closed.
	 */
	public function parse( string $content ): ?OkfDocument;
}
