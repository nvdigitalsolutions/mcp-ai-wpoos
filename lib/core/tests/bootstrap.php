<?php
/**
 * PHPUnit bootstrap for the nvoos/core package.
 *
 * This bootstrap is framework-agnostic — it does NOT load WordPress,
 * Laravel, or any other CMS. The core package is designed to be
 * testable with only Composer's autoloader and mocked adapter
 * implementations.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

// Composer autoloader.
require __DIR__ . '/../vendor/autoload.php';

// Ensure we're on PHP 8.1+ (the core package requires it).
if ( PHP_VERSION_ID < 80100 ) {
	fwrite( STDERR, 'nvoos/core tests require PHP 8.1+.' . PHP_EOL );
	exit( 1 );
}
