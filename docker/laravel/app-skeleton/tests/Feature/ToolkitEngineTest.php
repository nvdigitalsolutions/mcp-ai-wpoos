<?php

namespace Tests\Feature;

use App\Support\Pro\ToolkitEngine;
use App\Support\Pro\ToolkitException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;
use Tests\TestCase;

/**
 * Proposal 149 §46/P4 — the toolkit engine outside HTTP.
 *
 * `ToolkitsController` is now a thin adapter over {@see ToolkitEngine}. The
 * whole point of the extraction is that a Filament renderer can call the engine
 * directly, so these tests drive it with no request, no route and no HTTP
 * kernel. `ToolkitContractTest` already pins the API behaviour; this file pins
 * the engine's own contract:
 *
 *  - settings read/write uses the store, not an in-memory copy;
 *  - the entity lifecycle works from a manifest alone;
 *  - refusals are typed, with the same codes and statuses the console reads;
 *  - pagination is clamped inside the engine, so two presentations cannot
 *    disagree about what `per_page=1000` means.
 *
 * The `commerce` manifest is used because it declares both a settings screen and
 * an entity (`product`, keyed by `sku`).
 */
final class ToolkitEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_engine_reads_and_writes_settings_without_the_controller(): void
    {
        $engine = $this->engine();
        $manifest = $this->commerce();

        $values = $engine->writeSettings($manifest, array(
            'currency' => 'EUR',
            'low_stock_threshold' => 7,
        ));

        $this->assertSame('EUR', $values['currency']);
        $this->assertSame(7, $values['low_stock_threshold']);

        // A second read proves the settings store, not a cached payload.
        $reread = $engine->settingsValues($manifest);

        $this->assertSame('EUR', $reread['currency']);
        $this->assertSame(7, $reread['low_stock_threshold']);
    }

    public function test_the_engine_refuses_an_invalid_setting_with_a_typed_error(): void
    {
        try {
            $this->engine()->writeSettings($this->commerce(), array('currency' => array('not', 'a', 'string')));
            $this->fail('Expected a ToolkitException.');
        } catch (ToolkitException $error) {
            $this->assertSame('validation_failed', $error->errorCode);
            $this->assertSame(422, $error->status);
            $this->assertArrayHasKey('currency', $error->fields);
        }
    }

    public function test_the_engine_runs_the_entity_lifecycle_without_the_controller(): void
    {
        $engine = $this->engine();
        $manifest = $this->commerce();

        $created = $engine->createRecord($manifest, 'product', $this->payload('SKU-1'));

        $this->assertSame('SKU-1', $created['id']);
        $this->assertSame('Engine tee', $created['data']['name']);

        $page = $engine->listRecords($manifest, 'product');

        $this->assertSame(1, $page['total']);
        $this->assertSame('SKU-1', $page['records'][0]['id']);

        $updated = $engine->updateRecord($manifest, 'product', 'SKU-1', array('name' => 'Renamed'));

        $this->assertSame('Renamed', $updated['data']['name']);
        // A partial update merges: the untouched stock survives.
        $this->assertSame(4, $updated['data']['stock']);

        $this->assertNotNull($engine->findRecord($manifest, 'product', 'SKU-1'));
        $this->assertTrue($engine->deleteRecord($manifest, 'product', 'SKU-1'));
        $this->assertNull($engine->findRecord($manifest, 'product', 'SKU-1'));
    }

    public function test_the_engine_keeps_the_api_error_codes(): void
    {
        $engine = $this->engine();
        $manifest = $this->commerce();

        $engine->createRecord($manifest, 'product', $this->payload('SKU-1'));

        try {
            $engine->createRecord($manifest, 'product', $this->payload('SKU-1'));
            $this->fail('Expected a duplicate refusal.');
        } catch (ToolkitException $error) {
            $this->assertSame('duplicate_record', $error->errorCode);
            $this->assertSame(422, $error->status);
        }

        try {
            $engine->listRecords($manifest, 'not-an-entity');
            $this->fail('Expected an unknown-entity refusal.');
        } catch (ToolkitException $error) {
            $this->assertSame('entity_not_found', $error->errorCode);
            $this->assertSame(404, $error->status);
        }

        try {
            $engine->import(
                $manifest,
                'product',
                array_fill(0, ToolkitEngine::MAX_IMPORT_ROWS + 1, $this->payload('SKU-2')),
            );
            $this->fail('Expected an import-cap refusal.');
        } catch (ToolkitException $error) {
            // Its own code, not `invalid_payload`: the console distinguishes them.
            $this->assertSame('too_many_rows', $error->errorCode);
        }
    }

    public function test_the_engine_clamps_pagination_itself(): void
    {
        $page = $this->engine()->listRecords($this->commerce(), 'product', 1000, 0);

        $this->assertSame(100, $page['per_page']);
        $this->assertSame(1, $page['page']);
    }

    private function engine(): ToolkitEngine
    {
        return $this->app->make(ToolkitEngine::class);
    }

    private function commerce(): ToolkitManifest
    {
        $manifest = $this->engine()->find('commerce');

        $this->assertNotNull($manifest, 'The commerce manifest must ship and validate.');

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $sku): array
    {
        return array(
            'sku' => $sku,
            'name' => 'Engine tee',
            'price' => 12.5,
            'stock' => 4,
            'status' => 'active',
        );
    }
}
