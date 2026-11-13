<?php
/**
 * MJML compiler contract.
 *
 * Compiling MJML to email HTML needs a JavaScript engine — either a local
 * Node.js process, an HTTP sidecar, or a hosted service. That is an
 * infrastructure concern, so the domain depends on this contract and each
 * runtime supplies an adapter:
 *
 *  - WordPress: wraps `WP_MCP_AI_MJML_Service` (sidecar first, then the
 *    `wp_mcp_ai_mjml_compile` filter, then local Node.js).
 *  - Laravel:   no adapter ships yet; bind the contract to an HTTP client for
 *    the media-worker sidecar when a Laravel app needs HTML output.
 *
 * Markup *building* is pure and lives in
 * `Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder`; this contract covers
 * only the compile and validate steps that cannot be done in PHP.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface MjmlCompilerInterface {

	/**
	 * Whether this runtime can compile MJML right now.
	 *
	 * Callers use this to decide between returning markup-only output and
	 * reporting a missing dependency, so it must reflect runtime state (is the
	 * sidecar configured? is Node.js installed?) rather than configuration.
	 */
	public function isAvailable(): bool;

	/**
	 * Compile MJML to responsive email HTML.
	 *
	 * @param string               $mjml    MJML document.
	 * @param array<string, mixed> $options Optional: minify (bool), beautify (bool),
	 *                                      validationLevel ('strict'|'soft'|'skip').
	 *
	 * @return string Compiled HTML.
	 *
	 * @throws \RuntimeException When the document is empty, the compiler is not
	 *                           available, or compilation fails.
	 */
	public function compile( string $mjml, array $options = array() ): string;

	/**
	 * Validate MJML markup without keeping the compiled output.
	 *
	 * Compilers that cannot validate separately may compile and discard the
	 * result; either way the return shape is the same.
	 *
	 * @param string $mjml MJML document.
	 *
	 * @return array{valid: bool, errors: array<int, string>} Validation outcome.
	 *
	 * @throws \RuntimeException When the compiler itself fails (not when the
	 *                           markup is invalid — that is reported in `valid`).
	 */
	public function validate( string $mjml ): array;
}
