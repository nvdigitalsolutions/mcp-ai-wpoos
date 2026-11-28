<?php

/**
 * PSR-4 autoloader for the NV oOS database kernel.
 *
 * Proposal 167, decision D15. The kernel ships **inside the Pro add-on** rather
 * than in `lib/core`, because a base+pro install — the WordPress.org base package
 * with this add-on unpacked into `addons/pro/` — has no `lib/` directory at all.
 * The build script strips it (`--exclude '/lib'`), so a kernel that lived there
 * would be unavailable to exactly the customers who bought Pro.
 *
 * Registering the prefix here keeps the package usable on both the complete build
 * and a base+pro install, and keeps the kernel itself free of WordPress symbols:
 * it is plain PHP with a `src/` tree, and the Laravel runtime loads the same files
 * through a Composer path repository.
 *
 * @package Nvoos\Database
 * @since   1.0.0
 * @license proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVOOS_DATABASE_KERNEL_AUTOLOADED' ) ) {
	define( 'NVOOS_DATABASE_KERNEL_AUTOLOADED', true );

	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Nvoos\\Database\\';
			$length = strlen( $prefix );

			if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
				return;
			}

			$relative = substr( $class_name, $length );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);
}
