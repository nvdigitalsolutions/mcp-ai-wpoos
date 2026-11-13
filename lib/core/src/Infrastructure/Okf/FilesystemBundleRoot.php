<?php
/**
 * Bundle root backed by a plain directory.
 *
 * Serves the standalone runtime and tests. WordPress and Laravel supply their
 * own implementations — this one deliberately knows nothing about either.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Okf;

use Nvoos\Core\Domain\Contract\BundleRootInterface;

final class FilesystemBundleRoot implements BundleRootInterface {

	private readonly string $root;

	/**
	 * @param string $root Absolute path to the bundle directory.
	 */
	public function __construct( string $root ) {
		$normalised = rtrim( self::normalise( $root ), '/' );

		$this->root = '' === $normalised ? '/' : $normalised;
	}

	/**
	 * {@inheritDoc}
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * {@inheritDoc}
	 */
	public function pathFor( string $conceptId ): string {
		return self::normalise( $this->root . '/' . ltrim( $conceptId, '/' ) . '.md' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function contains( string $path ): bool {
		$path = self::normalise( $path );

		if ( ! str_starts_with( $path, $this->root . '/' ) ) {
			return false;
		}

		$realRoot = realpath( $this->root );
		$realPath = realpath( $path );

		// A missing path cannot have escaped through a symlink; the lexical
		// check above already proved it is inside the root.
		if ( false === $realRoot || false === $realPath ) {
			return true;
		}

		return $realPath === $realRoot || str_starts_with( $realPath, $realRoot . DIRECTORY_SEPARATOR );
	}

	/**
	 * Normalise separators without resolving `..`.
	 *
	 * Deliberately matches `wp_normalize_path()`: containment depends on
	 * `realpath()` seeing the unresolved path.
	 */
	private static function normalise( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		return (string) preg_replace( '|(?<=.)/+|', '/', $path );
	}
}
