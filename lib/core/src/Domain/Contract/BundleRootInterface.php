<?php
/**
 * Contract for the directory an OKF bundle lives in.
 *
 * The reader must never learn how a platform names its storage — WordPress
 * resolves bundles under `wp_upload_dir()`, Laravel under `storage/app`, and
 * a standalone process under whatever directory it was pointed at. Platforms
 * supply an implementation; the domain only ever sees paths.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface BundleRootInterface {

	/**
	 * Absolute path to the bundle root, without a trailing separator.
	 */
	public function root(): string;

	/**
	 * Absolute path of the markdown file for a concept ID.
	 *
	 * Pure string work — it does not check that the file exists.
	 *
	 * @param string $conceptId Concept ID, e.g. `tables/orders`.
	 * @return string Absolute path ending in `.md`.
	 */
	public function pathFor( string $conceptId ): string;

	/**
	 * Whether a path stays inside the bundle root.
	 *
	 * Performs the cheap lexical check, then — when the path exists — a
	 * `realpath()` check so symlinks cannot escape. A *missing* path returns
	 * true when it is lexically contained: callers distinguish "outside the
	 * bundle" from "not there" by checking existence themselves, and folding
	 * the two together would report a typo as a traversal attempt.
	 *
	 * @param string $path Absolute path.
	 */
	public function contains( string $path ): bool;
}
