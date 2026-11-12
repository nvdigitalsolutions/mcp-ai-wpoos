<?php
/**
 * Test bootstrap — isolate the suite from container-provided environment.
 *
 * Docker Compose exports DB_/QUEUE_/CACHE_ variables into $_SERVER, which
 * Laravel's Env repository can read even when phpunit.xml sets a value.
 * Force the test values in all three stores before the framework boots.
 */

declare(strict_types=1);

$env = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'QUEUE_CONNECTION' => 'sync',
    'NVOOS_QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
];

foreach ($env as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
