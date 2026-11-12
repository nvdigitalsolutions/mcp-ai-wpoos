<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pro\ToolkitManifestLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;
use Tests\TestCase;

/**
 * Phase 4.7 — per-family contract suite.
 *
 * Rather than pinning golden JSON (which drifts silently), every test drives
 * each manifest that ships in `resources/pro-toolkits` through the same
 * contract: catalogue → manifest → settings → entity lifecycle → dashboard →
 * duplicates → import. A new family is covered the moment its manifest lands,
 * and a manifest that declares something the engine cannot serve fails here.
 */
class ToolkitContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, ToolkitManifest>
     */
    private function families(): array
    {
        $loader = new ToolkitManifestLoader();

        $this->assertSame([], $loader->errors(), 'Every shipped manifest must validate.');

        return $loader->all();
    }

    private function actAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * Build a valid payload for one entity from its own column definitions.
     *
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(array $entity, string $id, array $overrides = []): array
    {
        $data = [];

        foreach ((array) $entity['columns'] as $column) {
            $key = (string) $column['key'];

            if ($key === (string) $entity['id_field']) {
                $data[$key] = $id;
                continue;
            }

            switch ((string) ($column['type'] ?? 'string')) {
                case 'number':
                    $data[$key] = 1;
                    break;
                case 'currency':
                    $data[$key] = 1.5;
                    break;
                case 'boolean':
                    $data[$key] = true;
                    break;
                case 'date':
                    $data[$key] = '2026-01-01';
                    break;
                case 'enum':
                    $options = array_values((array) ($column['enum'] ?? []));
                    $data[$key] = $options[0] ?? null;
                    break;
                default:
                    if (($column['required'] ?? false) === true) {
                        $data[$key] = 'Contract '.$id;
                    }
            }
        }

        return array_merge($data, $overrides);
    }

    // ------------------------------------------------------------- catalogue

    public function test_every_family_appears_in_the_catalogue(): void
    {
        $this->actAsUser();

        $listed = collect($this->getJson('/api/v1/pro/toolkits')->assertOk()->json('data.toolkits'))
            ->keyBy('slug');

        foreach ($this->families() as $slug => $manifest) {
            $this->assertTrue($listed->has($slug), $slug.' is missing from the catalogue.');
            $this->assertSame($manifest->name(), $listed[$slug]['name']);
            $this->assertSame($manifest->screens(), $listed[$slug]['screens']);
        }
    }

    public function test_every_family_serves_its_manifest(): void
    {
        $this->actAsUser();

        foreach ($this->families() as $slug => $manifest) {
            $toolkit = $this->getJson('/api/v1/pro/toolkits/'.$slug)
                ->assertOk()
                ->json('data.toolkit');

            $this->assertSame($slug, $toolkit['slug']);
            $this->assertSame($manifest->feature(), $toolkit['feature']);
            $this->assertSame($manifest->screens(), $toolkit['screens']);
            $this->assertCount(count($manifest->entities()), $toolkit['entities']);
        }
    }

    public function test_every_family_round_trips_its_settings(): void
    {
        $this->actAsUser();

        foreach ($this->families() as $slug => $manifest) {
            $schema = collect(
                $this->getJson('/api/v1/pro/toolkits/'.$slug.'/settings')
                    ->assertOk()
                    ->json('data.schema')
            )->keyBy('key');

            foreach ($manifest->settings() as $field) {
                $key = (string) $field['key'];

                $this->assertTrue($schema->has($key), $slug.'.'.$key.' is missing from the schema.');
                $this->assertSame(
                    $field['default'] ?? null,
                    $schema[$key]['default'] ?? null,
                    $slug.'.'.$key.' publishes a different default.'
                );
            }
        }
    }

    // -------------------------------------------------------------- entities

    public function test_every_family_supports_the_entity_lifecycle(): void
    {
        $this->actAsUser();

        foreach ($this->families() as $slug => $manifest) {
            $entity = $manifest->entities()[0];
            $entityKey = (string) $entity['key'];
            $id = 'CT-'.$slug;
            $base = '/api/v1/pro/toolkits/'.$slug.'/entities/'.$entityKey;

            $this->postJson($base, ['data' => $this->payloadFor($entity, $id)])
                ->assertCreated()
                ->assertJsonPath('data.record.id', $id);

            $this->getJson($base)
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.records.0.id', $id);

            $this->getJson($base.'/'.$id)
                ->assertOk()
                ->assertJsonPath('data.record.id', $id);

            // Update the first plain string column, which every pilot declares.
            $updatable = null;

            foreach ((array) $entity['columns'] as $column) {
                if ((string) $column['key'] !== (string) $entity['id_field']
                    && (string) ($column['type'] ?? '') === 'string') {
                    $updatable = (string) $column['key'];
                    break;
                }
            }

            if ($updatable !== null) {
                $this->putJson($base.'/'.$id, ['data' => [$updatable => 'Updated']])
                    ->assertOk()
                    ->assertJsonPath('data.record.data.'.$updatable, 'Updated');
            }

            $this->deleteJson($base.'/'.$id)
                ->assertOk()
                ->assertJsonPath('data.deleted', true);

            $this->getJson($base.'/'.$id)->assertNotFound();
        }
    }

    public function test_create_rejects_an_unknown_field(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/pro/toolkits/commerce/entities/product', [
            'data' => ['sku' => 'X-1', 'name' => 'Tee', 'colour' => 'red'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.colour', 'Unknown field.');
    }

    // ------------------------------------------------------------- dashboard

    public function test_every_family_returns_numeric_dashboard_cards(): void
    {
        $this->actAsUser();

        foreach ($this->families() as $slug => $manifest) {
            $declared = array_map(
                static fn (array $card): string => (string) $card['key'],
                $manifest->dashboardCards()
            );

            if ($declared === []) {
                continue;
            }

            $cards = collect(
                $this->getJson('/api/v1/pro/toolkits/'.$slug.'/dashboard')
                    ->assertOk()
                    ->json('data.cards')
            )->keyBy('key');

            foreach ($declared as $key) {
                $this->assertTrue($cards->has($key), $slug.'.'.$key.' is missing from the dashboard.');
                $this->assertIsNumeric($cards[$key]['value']);
            }
        }
    }

    public function test_dashboard_filters_can_bind_to_a_setting(): void
    {
        $this->actAsUser();

        $base = '/api/v1/pro/toolkits/commerce/entities/product';

        $this->postJson($base, ['data' => [
            'sku' => 'LOW-1', 'name' => 'Low', 'stock' => 2, 'status' => 'active',
        ]])->assertCreated();

        $this->postJson($base, ['data' => [
            'sku' => 'HIGH-1', 'name' => 'High', 'stock' => 50, 'status' => 'active',
        ]])->assertCreated();

        $lowStock = static fn (TestResponse $response): mixed => collect(
            $response->json('data.cards')
        )->firstWhere('key', 'low_stock')['value'] ?? null;

        // Default threshold is 5, so only LOW-1 is flagged.
        $this->assertSame(1, $lowStock(
            $this->getJson('/api/v1/pro/toolkits/commerce/dashboard')->assertOk()
        ));

        $this->postJson('/api/v1/pro/toolkits/commerce/settings', [
            'values' => ['low_stock_threshold' => 1],
        ])->assertOk();

        // The card re-reads the setting, so nothing is flagged any more.
        $this->assertSame(0, $lowStock(
            $this->getJson('/api/v1/pro/toolkits/commerce/dashboard')->assertOk()
        ));
    }

    // ------------------------------------------------------------ duplicates

    public function test_duplicates_group_the_whole_entity(): void
    {
        $this->actAsUser();

        $base = '/api/v1/pro/toolkits/commerce/entities/product';

        foreach ([['D-1', 'Tee'], ['D-2', 'Tee'], ['D-3', 'Cap']] as [$sku, $name]) {
            $this->postJson($base, ['data' => ['sku' => $sku, 'name' => $name]])
                ->assertCreated();
        }

        $data = $this->getJson($base.'/duplicates?column=name')
            ->assertOk()
            ->json('data');

        $this->assertSame('name', $data['column']);
        $this->assertSame(3, $data['scanned']);
        $this->assertFalse($data['truncated']);
        $this->assertCount(1, $data['groups']);
        $this->assertSame('Tee', $data['groups'][0]['value']);
        $this->assertSame(2, $data['groups'][0]['count']);
        $this->assertSame(['D-1', 'D-2'], array_column($data['groups'][0]['records'], 'id'));
    }

    public function test_duplicates_reject_a_non_groupable_column(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product/duplicates?column=stock')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields.column', 'Only text or list columns can be grouped.');
    }

    // ---------------------------------------------------------------- import

    public function test_import_previews_then_creates(): void
    {
        $this->actAsUser();

        $base = '/api/v1/pro/toolkits/commerce/entities/product';

        $this->postJson($base, ['data' => ['sku' => 'E-1', 'name' => 'Existing']])
            ->assertCreated();

        $rows = [
            ['sku' => 'E-1', 'name' => 'Existing'],   // duplicate business key
            ['sku' => 'E-2', 'name' => 'New'],        // valid
            ['name' => 'No sku'],                     // invalid
        ];

        $this->postJson($base.'/import', ['rows' => $rows, 'preview' => true])
            ->assertOk()
            ->assertJsonPath('data.preview', true)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.valid', 1)
            ->assertJsonPath('data.duplicates', 1)
            ->assertJsonPath('data.failed.0.fields.sku', 'This field is required.');

        // A preview writes nothing.
        $this->getJson($base)->assertOk()->assertJsonPath('data.total', 1);

        $this->postJson($base.'/import', ['rows' => $rows])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.duplicates', 1);

        $this->getJson($base)->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_import_reports_duplicates_as_failures_when_asked(): void
    {
        $this->actAsUser();

        $base = '/api/v1/pro/toolkits/commerce/entities/product';

        $this->postJson($base, ['data' => ['sku' => 'F-1', 'name' => 'Existing']])
            ->assertCreated();

        $this->postJson($base.'/import', [
            'rows' => [['sku' => 'F-1', 'name' => 'Existing']],
            'skip_existing' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.duplicates', 0)
            ->assertJsonPath('data.failed.0.fields.sku', 'A record with that value already exists.');
    }

    public function test_import_caps_the_batch_size(): void
    {
        $this->actAsUser();

        $rows = array_fill(0, 501, ['sku' => 'BULK', 'name' => 'Bulk']);

        $this->postJson('/api/v1/pro/toolkits/commerce/entities/product/import', ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'too_many_rows');
    }
}
