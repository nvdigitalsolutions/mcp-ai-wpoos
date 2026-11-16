<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Proposal 149 §14 — the surface-ownership contract.
 *
 * One row per admin surface, so "who may reach this, from where, and through
 * which write path" is data rather than folklore. The contract exists to stop
 * three specific regressions:
 *
 * - a console endpoint with no recorded owner, so nobody knows whether it is a
 *   product surface or an operator surface;
 * - a reduced surface (subset / read-only) with no declared server-side
 *   projection, which is how a "limited" console becomes a UI fiction;
 * - a new Filament page shipped without a row, which is how a customer-facing
 *   admin quietly grows an operator surface.
 *
 * The vocabularies are closed. Adding a state or an audience is a deliberate
 * contract change, not a free-text edit.
 */
class SurfaceOwnershipTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $contract = null;

    public function test_the_contract_parses_and_declares_its_vocabulary(): void
    {
        $contract = $this->contract();

        $this->assertSame(1, $contract['schema_version'] ?? null);

        foreach (['audience', 'state', 'writer', 'plan_gate_prefixes'] as $key) {
            $this->assertIsArray(
                $contract['vocabulary'][$key] ?? null,
                sprintf('vocabulary.%s must be a list.', $key)
            );
            $this->assertNotEmpty($contract['vocabulary'][$key]);
        }

        $this->assertNotEmpty($contract['surfaces'] ?? []);
    }

    public function test_every_console_key_has_exactly_one_surface_row(): void
    {
        $expected = $this->consoleKeys();
        $mapped = [];

        foreach ($this->contract()['surfaces'] as $surface) {
            foreach ((array) ($surface['console']['keys'] ?? []) as $key) {
                $this->assertArrayNotHasKey(
                    $key,
                    $mapped,
                    sprintf(
                        'Console key "%s" is claimed by both "%s" and "%s".',
                        $key,
                        $mapped[$key] ?? '',
                        $surface['id'] ?? '(unnamed)'
                    )
                );

                $mapped[$key] = $surface['id'] ?? '(unnamed)';
            }
        }

        sort($expected);

        $actual = array_keys($mapped);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'Every console endpoint key needs exactly one surface-ownership row, and no row may name a key the console contract does not declare.'
        );
    }

    public function test_every_row_uses_the_closed_vocabularies(): void
    {
        $vocabulary = $this->contract()['vocabulary'];
        $seen = [];

        foreach ($this->contract()['surfaces'] as $surface) {
            $id = (string) ($surface['id'] ?? '');

            $this->assertNotSame('', $id, 'Every surface needs an id.');
            $this->assertArrayNotHasKey($id, $seen, sprintf('Duplicate surface id "%s".', $id));
            $seen[$id] = true;

            $this->assertContains(
                $surface['audience'] ?? null,
                $vocabulary['audience'],
                sprintf('Surface "%s" has an unknown audience.', $id)
            );

            $this->assertContains(
                $surface['writer'] ?? null,
                $vocabulary['writer'],
                sprintf('Surface "%s" has an unknown writer.', $id)
            );

            foreach (['ops', 'admin', 'console'] as $presentation) {
                $node = $surface[$presentation] ?? null;

                if ($node === null) {
                    continue;
                }

                $this->assertIsArray($node, sprintf('Surface "%s" %s must be an object or null.', $id, $presentation));

                $state = $node['state'] ?? null;

                $this->assertContains(
                    $state,
                    $vocabulary['state'],
                    sprintf('Surface "%s" %s has an unknown state.', $id, $presentation)
                );

                if (in_array($state, ['subset', 'read-only'], true)) {
                    $this->assertNotSame(
                        '',
                        trim((string) ($surface['projection'] ?? '')),
                        sprintf(
                            'Surface "%s" is %s on %s, so it must declare the server-side projection it receives.',
                            $id,
                            $state,
                            $presentation
                        )
                    );
                }
            }

            $gate = $surface['plan_gate'] ?? null;

            if ($gate === null) {
                continue;
            }

            $recognised = false;

            foreach ($vocabulary['plan_gate_prefixes'] as $prefix) {
                if (str_starts_with((string) $gate, $prefix)) {
                    $recognised = true;
                    break;
                }
            }

            $this->assertTrue(
                $recognised,
                sprintf('Surface "%s" has an unrecognised plan_gate "%s".', $id, $gate)
            );
        }
    }

    public function test_every_filament_surface_file_is_declared_and_exists(): void
    {
        $declared = [];

        foreach ($this->contract()['surfaces'] as $surface) {
            foreach ((array) ($surface['files'] ?? []) as $file) {
                $declared[] = $file;

                $this->assertFileExists(
                    base_path($file),
                    sprintf('Surface "%s" declares a Filament file that does not exist: %s', $surface['id'] ?? '', $file)
                );
            }
        }

        $found = $this->filamentSurfaceFiles();

        sort($declared);

        $this->assertSame(
            $found,
            $declared,
            'Every Filament surface needs a contract row, and every declared file must exist. Add the row before the page.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        if ($this->contract === null) {
            $path = base_path('contracts/surface-ownership.json');

            $this->assertFileExists($path);

            $decoded = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($decoded, 'surface-ownership.json must decode to an object.');

            $this->contract = $decoded;
        }

        return $this->contract;
    }

    /**
     * @return list<string>
     */
    private function consoleKeys(): array
    {
        $path = base_path('contracts/console-endpoints.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $keys = $decoded['route_backed'] ?? [];

        $this->assertIsArray($keys, 'console-endpoints.json must declare route_backed keys.');

        return array_values(array_map('strval', $keys));
    }

    /**
     * Surface classes, not resource-page companions: Filament derives the
     * Manage* pages from a Resource, so only the Resource itself is a row.
     *
     * @return list<string>
     */
    private function filamentSurfaceFiles(): array
    {
        $root = app_path('Filament');

        if (! is_dir($root)) {
            return [];
        }

        $patterns = [
            $root.'/*/Resources/*/*Resource.php',
            $root.'/*/Pages/*.php',
        ];

        $found = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $found[] = $this->relative($file);
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalised = str_replace('\\', '/', $path);

        return str_replace($base, '', $normalised);
    }
}
