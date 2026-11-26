<?php
/**
 * Dump the merged settings schema (core + catalogue) as JSON, with the surface
 * flags that decide whether a key is rendered and writable on /admin.
 *
 * Step 1 of the settings-coverage sweep. Run inside the app container so the
 * autoloader sees the same classes and the same catalogue the runtime parses:
 *
 *   docker run --rm --entrypoint php \
 *     -v <worktree>/.playwright-mcp/appsnap:/app \
 *     -v <worktree>:/workspace/oos:ro \
 *     -v oos-laravel_laravel_vendor:/app/vendor \
 *     -v oos-laravel_laravel_storage:/app/storage \
 *     -e APP_KEY=... -w /app oos-laravel-app \
 *     php /workspace/oos/bin/settings-coverage/dump-settings.php /tmp/settings-rows.json
 *
 * `appsnap` is a copy of `docker/laravel/app-skeleton`, because the schema is
 * assembled from `SettingsSchema::coreFields()` (PHP) and
 * `SettingsCatalogue::fields()` (JSON), so neither alone is the answer.
 */

declare(strict_types=1);

require __DIR__.'/../../app/vendor/autoload.php';

$app = require __DIR__.'/../../app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$target = $argv[1] ?? (getenv('SETTINGS_ROWS_OUT') ?: '');

if ('' === $target) {
	fwrite(STDERR, "usage: dump-settings.php <output.json>\n");
	exit(2);
}

$writable = array_column(App\Support\SettingsSchema::writableFor('admin'), 'key');

$rows = array();

foreach (App\Support\SettingsSchema::fields() as $field) {
	$key = (string) ($field['key'] ?? '');

	$rows[] = array(
		'key' => $key,
		'storage' => (string) ($field['storage'] ?? $key),
		'scope' => (string) ($field['scope'] ?? 'tenant'),
		'console' => (string) ($field['console'] ?? 'visible'),
		'tab' => (string) ($field['tab'] ?? ''),
		'section' => (string) ($field['section'] ?? ''),
		'type' => (string) ($field['type'] ?? ''),
		'writable_admin' => in_array($key, $writable, true),
	);
}

file_put_contents($target, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

fwrite(STDERR, sprintf("wrote %s (%d rows)\n", $target, count($rows)));
