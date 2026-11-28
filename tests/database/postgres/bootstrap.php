<?php

/**
 * Bootstrap for the Postgres-dependent suite.
 *
 * Reuses the portable harness (WordPress stubs + adapters) and adds the fixture
 * trait. Nothing here requires WordPress itself.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../portable/bootstrap.php';
require_once __DIR__ . '/PostgresFixture.php';

// The core package, for the tools the Laravel binding serves. Same guard as the
// parity bootstrap: Composer when the package is installed, a PSR-4 fallback
// otherwise, so the suite does not depend on which vendor tree exists.
$nvoosRoot = dirname( __DIR__, 3 );

if ( is_file( $nvoosRoot . '/lib/core/vendor/autoload.php' ) ) {
	require_once $nvoosRoot . '/lib/core/vendor/autoload.php';
}

spl_autoload_register(
	static function ( string $class_name ) use ( $nvoosRoot ): void {
		$prefixes = array(
			'Nvoos\\Core\\Tests\\' => '/lib/core/tests/',
			'Nvoos\\Core\\'        => '/lib/core/src/',
		);

		foreach ( $prefixes as $prefix => $path ) {
			$length = strlen( $prefix );

			if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
				continue;
			}

			$file = $nvoosRoot . $path . str_replace( '\\', '/', substr( $class_name, $length ) ) . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}

			return;
		}
	}
);
