<?php
/**
 * Test bootstrap for Laravel adapter tests.
 *
 * Registers the Laravel adapter namespace with the core autoloader
 * so tests can resolve adapter classes without a full Laravel install.
 */

declare(strict_types=1);

// Load the core autoloader.
$coreAutoload = require __DIR__ . '/../../core/vendor/autoload.php';

// Register the Laravel adapter namespace as a PSR-4 prefix.
$coreAutoload->addPsr4( 'Nvoos\\Laravel\\', __DIR__ . '/../src/' );
