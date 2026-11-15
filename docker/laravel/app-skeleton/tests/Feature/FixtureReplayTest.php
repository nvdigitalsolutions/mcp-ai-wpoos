<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Phase 4.7 — replay the committed contract fixtures.
 *
 * The assertion is on **shape**, not values: every key in the fixture must
 * exist with the same type in the live response. Captured rows, totals and
 * timestamps therefore do not have to match the test database, while a renamed
 * key, a dropped field or a stringified number still fails.
 *
 * Fixtures are produced by `php artisan fixtures:capture` (see 090-b §10) and
 * live at `docker/laravel/fixtures/`; the app copy is created by the skeleton
 * sync, and `NVOOS_FIXTURES_DIR` overrides the location in CI.
 */
class FixtureReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_fixture_replays_with_the_same_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $files = $this->fixtureFiles();

        $this->assertNotEmpty($files, 'No fixtures found at '.$this->fixturesDirectory().'.');

        foreach ($files as $file) {
            $fixture = json_decode((string) file_get_contents($file), true);

            $this->assertIsArray($fixture, $file.' is not valid JSON.');
            $this->assertArrayHasKey('request', $fixture, $file.' has no request block.');

            $method = (string) ($fixture['request']['method'] ?? 'GET');
            $uri = (string) ($fixture['request']['uri'] ?? '');

            $this->assertNotSame('', $uri, $file.' has no request URI.');

            $response = $this->json($method, $uri);
            $name = basename($file);

            $this->assertSame(
                (int) $fixture['status'],
                $response->status(),
                $name.' status drifted.'
            );

            $this->assertShape($fixture['body'] ?? null, $response->json(), $name);
        }
    }

    /**
     * Compare the fixture's keys and types against the live value.
     */
    private function assertShape(mixed $expected, mixed $actual, string $path): void
    {
        // A null fixture value means "may be null" — the type is not pinned.
        if ($expected === null) {
            return;
        }

        if (is_array($expected)) {
            $this->assertIsArray($actual, $path.' should be an array.');

            if ($expected === []) {
                return;
            }

            if (array_is_list($expected)) {
                $this->assertTrue(array_is_list($actual), $path.' should be a list.');

                // The test database is empty in CI, so element shape is only
                // checked when both sides have one.
                if ($actual !== []) {
                    $this->assertShape($expected[0], $actual[0], $path.'[0]');
                }

                return;
            }

            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey((string) $key, $actual, $path.'.'.$key.' is missing.');
                $this->assertShape($value, $actual[$key], $path.'.'.$key);
            }

            return;
        }

        if (is_bool($expected)) {
            $this->assertIsBool($actual, $path.' should be a boolean.');

            return;
        }

        if (is_int($expected)) {
            $this->assertIsInt($actual, $path.' should be an integer.');

            return;
        }

        if (is_float($expected)) {
            $this->assertTrue(is_float($actual) || is_int($actual), $path.' should be numeric.');

            return;
        }

        $this->assertIsString($actual, $path.' should be a string.');
    }

    /**
     * @return string[]
     */
    private function fixtureFiles(): array
    {
        $directory = $this->fixturesDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function fixturesDirectory(): string
    {
        $configured = env('NVOOS_FIXTURES_DIR');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return base_path('fixtures');
    }
}
