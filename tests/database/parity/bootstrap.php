<?php

/**
 * Bootstrap for the cross-host parity suite.
 *
 * Loads both sides in one process: the WordPress plugin classes (with the two
 * WordPress functions they touch at definition time stubbed) and the
 * framework-agnostic copies in `lib/core` / `lib/laravel-adapter`.
 *
 * No database is opened. The parity questions are structural — same slug, same
 * schema, same session SQL — and a green test here is what makes a duplicated
 * engine profile acceptable at all.
 *
 * @package Nvoos\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);

define('ABSPATH', $root . '/');

if (! function_exists('__')) {
    function __(string $text, ?string $domain = null): string
    {
        unset($domain);

        return $text;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

// The core package under test.
//
// Composer's autoloader when the package has been installed, and a PSR-4
// fallback when it has not — the parity suite only needs to *load* classes, so
// requiring a vendor tree here made the suite fail in any job that installs at
// the repository root instead of inside `lib/core`. That was a real CI failure,
// not a hypothetical one.
if (is_file($root . '/lib/core/vendor/autoload.php')) {
    require_once $root . '/lib/core/vendor/autoload.php';
}

spl_autoload_register(
    static function (string $class_name) use ($root): void {
        $prefix = 'Nvoos\\Core\\';
        $length = strlen($prefix);

        if (0 !== strncmp($prefix, $class_name, $length)) {
            return;
        }

        $file = $root . '/lib/core/src/' . str_replace('\\', '/', substr($class_name, $length)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);

// The database kernel.
require_once $root . '/addons/pro/lib/database/autoload.php';

// The Laravel adapter (framework-free classes only; nothing here boots Laravel).
spl_autoload_register(
    static function (string $class_name) use ($root): void {
        $prefix = 'Nvoos\\Laravel\\';
        $length = strlen($prefix);

        if (0 !== strncmp($prefix, $class_name, $length)) {
            return;
        }

        $file = $root . '/lib/laravel-adapter/src/' . str_replace('\\', '/', substr($class_name, $length)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);

// WordPress tool contracts.
require_once $root . '/includes/interfaces/interface-wp-mcp-ai-tool.php';

$tools = $root . '/addons/pro/includes/tools/database/';

require_once $tools . 'class-wp-mcp-ai-database-tool-base.php';

foreach (glob($tools . 'class-wp-mcp-ai-tool-db-*.php') as $file) {
    require_once $file;
}

// WordPress engine profiles, in dependency order.
$adapters = $root . '/includes/database/';

require_once $adapters . 'class-wp-mcp-ai-db-engine.php';

foreach (array('pgsql', 'mysql', 'mariadb', 'sqlite') as $engine) {
    require_once $adapters . 'class-wp-mcp-ai-db-engine-' . $engine . '.php';
}
