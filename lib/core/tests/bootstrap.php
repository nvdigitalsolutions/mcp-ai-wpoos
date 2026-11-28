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

// Optional sibling packages, when this checkout has them.
//
// `nvoos/core` does not require `nvoos/database-kernel` or `nvoos/laravel-adapter`:
// the first ships inside the Pro add-on so a base+pro WordPress install has it, and
// the second is the Laravel host. Both are present in the monorepo, so the
// database toolkit's tests register their prefixes by hand. A consumer that
// installs `nvoos/core` alone simply gets no database tools, which is why the
// tools are declared behind the readiness manifest.
$siblingPrefixes = array(
	'Nvoos\\Database\\' => dirname(__DIR__, 3) . '/addons/pro/lib/database/src/',
	'Nvoos\\Laravel\\'  => dirname(__DIR__, 3) . '/lib/laravel-adapter/src/',
);

spl_autoload_register(
	static function (string $class_name) use ($siblingPrefixes): void {
		foreach ($siblingPrefixes as $prefix => $root) {
			$length = strlen($prefix);

			if (0 !== strncmp($prefix, $class_name, $length)) {
				continue;
			}

			$file = $root . str_replace('\\', '/', substr($class_name, $length)) . '.php';

			if (is_file($file)) {
				require $file;
			}

			return;
		}
	}
);

// Ensure we're on PHP 8.1+ (the core package requires it).
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'nvoos/core tests require PHP 8.1+.' . PHP_EOL);
    exit(1);
}
