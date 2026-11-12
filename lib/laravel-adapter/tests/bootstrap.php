<?php
/**
 * Test bootstrap for Laravel adapter tests.
 *
 * Prefers the package's own Composer autoloader (which includes
 * Illuminate/Testbench). Falls back to the core autoloader for a
 * dependency-free syntax/test run.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

$adapterAutoload = __DIR__ . '/../vendor/autoload.php';

if ( file_exists( $adapterAutoload ) ) {
	require $adapterAutoload;
} else {
	$coreAutoload = require __DIR__ . '/../../core/vendor/autoload.php';
	$coreAutoload->addPsr4( 'Nvoos\\Laravel\\', __DIR__ . '/../src/' );
}
