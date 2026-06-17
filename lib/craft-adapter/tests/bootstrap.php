<?php
/**
 * Test bootstrap for Craft adapter tests.
 *
 * Registers the Craft adapter namespace with the core autoloader
 * so tests can resolve adapter classes without a full Craft CMS install.
 */

declare(strict_types=1);

// Load the core autoloader.
$coreAutoload = require __DIR__ . '/../../core/vendor/autoload.php';

// Register the Craft adapter namespace as a PSR-4 prefix.
$coreAutoload->addPsr4( 'Nvoos\\Craft\\', __DIR__ . '/../src/' );
