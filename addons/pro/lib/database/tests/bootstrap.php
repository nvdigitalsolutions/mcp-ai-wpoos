<?php

/**
 * Standalone bootstrap for the database-kernel suite.
 *
 * Deliberately does not require WordPress, Composer or the Pro add-on's
 * bootstrap. The kernel must be provably host-free: if this file can autoload
 * and run the suite, the domain genuinely has no host dependency.
 *
 * @package Nvoos\Database\Tests
 * @since   1.0.0
 * @license proprietary
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefixes = array(
			'Nvoos\\Database\\Tests\\' => __DIR__ . '/',
			'Nvoos\\Database\\'        => dirname( __DIR__ ) . '/src/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$length = strlen( $prefix );

			if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
				continue;
			}

			$relative = substr( $class_name, $length );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $file ) ) {
				require $file;

				return;
			}
		}
	}
);
