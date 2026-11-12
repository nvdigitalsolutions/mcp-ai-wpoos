<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pro\ToolkitManifestLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\ProEntitlement;
use Tests\TestCase;

/**
 * Phase 4 — the manifest-driven Pro toolkit engine.
 *
 * Covers the manifest contract (loader + fail-closed validation) and the
 * generic resource controller that every family shares.
 */
class Phase4Test extends TestCase
{
    use RefreshDatabase;

    private function actAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProduct(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/pro/toolkits/commerce/entities/product', ['data' => $data]);
    }

    // ------------------------------------------------------------- manifests

    public function test_loader_reads_the_pilot_manifests(): void
    {
        $loader = new ToolkitManifestLoader();

        $all = $loader->all();

        $this->assertArrayHasKey('commerce', $all);
        $this->assertArrayHasKey('documents', $all);
        $this->assertSame([], $loader->errors());
        $this->assertSame('product', $all['commerce']->entities()[0]['key']);
    }

    public function test_invalid_manifests_are_skipped_and_reported(): void
    {
        $directory = sys_get_temp_dir().'/nvoos-manifests-'.uniqid();
        mkdir($directory, 0777, true);

        file_put_contents($directory.'/broken.json', '{"slug":"broken"}');
        file_put_contents($directory.'/mismatch.json', json_encode([
            'slug' => 'other-slug',
            'name' => 'Mismatch',
            'version' => '1.0.0',
            'feature' => 'mismatch',
            'entities' => [[
                'key' => 'thing',
                'label' => 'Things',
                'id_field' => 'id',
                'columns' => [['key' => 'id', 'label' => 'ID', 'type' => 'string']],
            ]],
        ]));

        try {
            $loader = new ToolkitManifestLoader($directory);

            $this->assertSame([], $loader->all());
            $this->assertArrayHasKey('broken.json', $loader->errors());
            $this->assertArrayHasKey('mismatch.json', $loader->errors());
            $this->assertStringContainsString('must match the filename', implode(' ', $loader->errors()['mismatch.json']));
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }

    // ------------------------------------------------------------------- api

    public function test_toolkit_list_returns_the_entitled_manifests(): void
    {
        $this->actAsUser();

        $response = $this->getJson('/api/v1/pro/toolkits')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'toolkits' => [['slug', 'name', 'version', 'icon', 'feature', 'screens', 'entities']],
                    'invalid',
                ],
            ]);

        $slugs = array_column($response->json('data.toolkits'), 'slug');

        $this->assertSame(['commerce', 'documents'], $slugs);
    }

    public function test_toolkit_show_returns_the_manifest(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/toolkits/commerce')
            ->assertOk()
            ->assertJsonPath('data.toolkit.slug', 'commerce')
            ->assertJsonPath('data.toolkit.entities.0.id_field', 'sku')
            ->assertJsonCount(3, 'data.toolkit.dashboard.cards');
    }

    public function test_unknown_toolkit_is_404(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/toolkits/nope')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'toolkit_not_found');
    }

    public function test_denied_feature_returns_the_typed_402_and_hides_the_list(): void
    {
        $this->actAsUser();

        $this->app->instance(
            ProEntitlementInterface::class,
            new ProEntitlement('config', 'base', ['documents' => true]),
        );

        $listed = array_column(
            $this->getJson('/api/v1/pro/toolkits')->assertOk()->json('data.toolkits'),
            'slug',
        );

        $this->assertSame(['documents'], $listed);

        $this->getJson('/api/v1/pro/toolkits/commerce')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'pro_entitlement_required')
            ->assertJsonPath('error.feature', 'commerce');
    }

    // -------------------------------------------------------------- settings

    public function test_settings_read_defaults_and_write(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/toolkits/commerce/settings')
            ->assertOk()
            ->assertJsonPath('data.values.currency', 'USD')
            ->assertJsonPath('data.values.low_stock_threshold', 5)
            ->assertJsonStructure(['data' => ['schema' => [['key', 'label', 'type']], 'values']]);

        $this->postJson('/api/v1/pro/toolkits/commerce/settings', [
            'values' => ['currency' => 'EUR', 'low_stock_threshold' => 3],
        ])
            ->assertOk()
            ->assertJsonPath('data.values.currency', 'EUR')
            ->assertJsonPath('data.values.low_stock_threshold', 3);

        $this->getJson('/api/v1/pro/toolkits/commerce/settings')
            ->assertJsonPath('data.values.currency', 'EUR');
    }

    public function test_settings_reject_unknown_and_invalid_values(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/pro/toolkits/commerce/settings', [
            'values' => ['nope' => 'x'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields.nope', 'Unknown setting.');

        $this->postJson('/api/v1/pro/toolkits/commerce/settings', [
            'values' => ['low_stock_threshold' => 'many'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.low_stock_threshold', 'Must be a number.');
    }

    // -------------------------------------------------------------- entities

    public function test_entity_crud_round_trip(): void
    {
        $this->actAsUser();

        $this->createProduct(['sku' => 'TS-1', 'name' => 'Tee', 'price' => 19.5, 'stock' => 10, 'status' => 'active'])
            ->assertCreated()
            ->assertJsonPath('data.record.id', 'TS-1')
            ->assertJsonPath('data.record.data.price', 19.5);

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.entity', 'product')
            ->assertJsonStructure(['data' => ['records' => [['id', 'entity', 'data', 'created_at', 'updated_at']]]]);

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product/TS-1')
            ->assertOk()
            ->assertJsonPath('data.record.data.name', 'Tee');

        $this->putJson('/api/v1/pro/toolkits/commerce/entities/product/TS-1', [
            'data' => ['stock' => 4],
        ])
            ->assertOk()
            // Partial update merges with the stored payload.
            ->assertJsonPath('data.record.data.stock', 4)
            ->assertJsonPath('data.record.data.name', 'Tee');

        $this->deleteJson('/api/v1/pro/toolkits/commerce/entities/product/TS-1')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product/TS-1')->assertNotFound();
    }

    public function test_entity_payload_is_validated_against_the_manifest(): void
    {
        $this->actAsUser();

        $this->createProduct(['name' => 'No SKU'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.sku', 'This field is required.');

        $this->createProduct(['sku' => 'X-1', 'name' => 'X', 'nope' => 'y'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.nope', 'Unknown field.');

        $this->createProduct(['sku' => 'X-2', 'name' => 'X', 'status' => 'live'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.status', 'Must be one of: draft, active, archived.');
    }

    public function test_duplicate_business_keys_are_rejected(): void
    {
        $this->actAsUser();

        $this->createProduct(['sku' => 'D-1', 'name' => 'One'])->assertCreated();

        $this->createProduct(['sku' => 'D-1', 'name' => 'Two'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'duplicate_record');
    }

    public function test_the_id_field_cannot_be_changed(): void
    {
        $this->actAsUser();
        $this->createProduct(['sku' => 'K-1', 'name' => 'One'])->assertCreated();

        $this->putJson('/api/v1/pro/toolkits/commerce/entities/product/K-1', [
            'data' => ['sku' => 'K-2'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'immutable_id');
    }

    public function test_unknown_entity_is_404(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/order')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'entity_not_found');
    }

    public function test_entity_list_supports_search_and_filters(): void
    {
        $this->actAsUser();

        $this->createProduct(['sku' => 'A-1', 'name' => 'Alpine Tee', 'status' => 'active'])->assertCreated();
        $this->createProduct(['sku' => 'B-1', 'name' => 'Beach Hat', 'status' => 'draft'])->assertCreated();

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product?search=Alpine')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.records.0.id', 'A-1');

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product?filter[status]=draft')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.records.0.id', 'B-1');

        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product?filter[colour]=red')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_filter_column');
    }

    // ------------------------------------------------------------- dashboard

    public function test_dashboard_computes_cards_from_the_manifest(): void
    {
        $this->actAsUser();

        $this->createProduct(['sku' => 'A-1', 'name' => 'Active', 'stock' => 10, 'status' => 'active'])->assertCreated();
        $this->createProduct(['sku' => 'D-1', 'name' => 'Draft', 'stock' => 4, 'status' => 'draft'])->assertCreated();

        $this->getJson('/api/v1/pro/toolkits/commerce/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cards.0.key', 'total_products')
            ->assertJsonPath('data.cards.0.value', 2)
            ->assertJsonPath('data.cards.1.key', 'active_products')
            ->assertJsonPath('data.cards.1.value', 1)
            ->assertJsonPath('data.cards.2.key', 'stock_value')
            ->assertJsonPath('data.cards.2.value', 10);
    }

    public function test_toolkit_surfaces_require_authentication(): void
    {
        $this->getJson('/api/v1/pro/toolkits')->assertUnauthorized();
        $this->getJson('/api/v1/pro/toolkits/commerce/entities/product')->assertUnauthorized();
    }
}
