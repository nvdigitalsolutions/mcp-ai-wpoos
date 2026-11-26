<?php

namespace Tests\Feature;

use App\Support\Pro\ToolkitEngine;
use App\Support\Pro\ToolkitException;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;
use Tests\TestCase;

/**
 * Proposal 149 §47 — toolkit records belong to an estate.
 *
 * `nvoos_toolkit_records` shipped without `tenant_id` while every
 * `/pro/toolkits` route already ran `ResolveApiTenant`, so the resolved estate
 * was discarded. Two defects shared one root cause:
 *
 *  - READ: an estate listed, edited and deleted another estate's records;
 *  - COLLISION: the unique key was global on `(toolkit, entity, external_id)`,
 *    so the second estate to create `SKU-1` got a duplicate error about data it
 *    could not see.
 *
 * These tests drive the engine directly — no HTTP — because the engine is now
 * the single writer for both the API and the panel (order item 10), and a scope
 * that lives in middleware would not protect the panel path.
 *
 * The engine is resolved **once per test** and the estate is switched beneath
 * it. That is the point: the estate is a resolver closure read at query time,
 * not a value captured at construction.
 */
final class ToolkitTenancyTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = 'default';

    private const FOREIGN = 'estate-b';

    public function test_two_estates_may_hold_the_same_business_key(): void
    {
        $engine = $this->engine();

        $own = $this->in(self::OWN, fn (): array => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('SKU-1', 'Own tee')
        ));

        // The old global unique key made this throw `duplicate_record`.
        $foreign = $this->in(self::FOREIGN, fn (): array => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('SKU-1', 'Foreign tee')
        ));

        $this->assertSame('Own tee', $own['data']['name']);
        $this->assertSame('Foreign tee', $foreign['data']['name']);

        // Both rows exist, attributed to different estates.
        $this->assertSame(2, DB::table('nvoos_toolkit_records')->where('external_id', 'SKU-1')->count());
        $this->assertSame(
            array(self::OWN, self::FOREIGN),
            DB::table('nvoos_toolkit_records')
                ->where('external_id', 'SKU-1')
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->all()
        );
    }

    public function test_a_list_never_includes_another_estates_records(): void
    {
        $engine = $this->engine();

        $this->in(self::OWN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('OWN-1', 'Own tee')
        ));
        $this->in(self::FOREIGN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('FOREIGN-1', 'Foreign tee')
        ));

        $page = $this->in(self::OWN, fn (): array => $engine->listRecords($this->commerce(), 'product'));

        $this->assertSame(1, $page['total']);
        $this->assertSame('OWN-1', $page['records'][0]['id']);
    }

    public function test_a_foreign_record_is_invisible_and_immutable(): void
    {
        $engine = $this->engine();

        $this->in(self::FOREIGN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('FOREIGN-1', 'Foreign tee')
        ));

        $this->in(self::OWN, function () use ($engine): void {
            $manifest = $this->commerce();

            $this->assertNull($engine->findRecord($manifest, 'product', 'FOREIGN-1'));

            try {
                $engine->updateRecord($manifest, 'product', 'FOREIGN-1', array('name' => 'Hijacked'));
                $this->fail('Expected a not-found refusal.');
            } catch (ToolkitException $error) {
                $this->assertSame('record_not_found', $error->errorCode);
            }

            $this->assertFalse($engine->deleteRecord($manifest, 'product', 'FOREIGN-1'));
        });

        // The foreign row survived, unmodified.
        $this->in(self::FOREIGN, function () use ($engine): void {
            $record = $engine->findRecord($this->commerce(), 'product', 'FOREIGN-1');

            $this->assertNotNull($record);
            $this->assertSame('Foreign tee', $record['data']['name']);
        });
    }

    public function test_import_does_not_treat_another_estates_record_as_a_duplicate(): void
    {
        $engine = $this->engine();

        $this->in(self::FOREIGN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('SKU-1', 'Foreign tee')
        ));

        $result = $this->in(self::OWN, fn (): array => $engine->import(
            $this->commerce(),
            'product',
            array($this->payload('SKU-1', 'Own tee'))
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['duplicates']);

        // And the reverse direction stays idempotent: importing it again is a skip.
        $again = $this->in(self::OWN, fn (): array => $engine->import(
            $this->commerce(),
            'product',
            array($this->payload('SKU-1', 'Own tee'))
        ));

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['duplicates']);
    }

    public function test_the_dashboard_counts_only_the_current_estate(): void
    {
        $engine = $this->engine();

        // Two active products in stock in the own estate; five in the foreign one.
        foreach (array('OWN-1', 'OWN-2') as $sku) {
            $this->in(self::OWN, fn () => $engine->createRecord(
                $this->commerce(),
                'product',
                $this->payload($sku, 'Own tee')
            ));
        }

        foreach (array('F-1', 'F-2', 'F-3', 'F-4', 'F-5') as $sku) {
            $this->in(self::FOREIGN, fn () => $engine->createRecord(
                $this->commerce(),
                'product',
                $this->payload($sku, 'Foreign tee')
            ));
        }

        $cards = $this->in(self::OWN, fn (): array => $engine->dashboard($this->commerce()));
        $byKey = array_column($cards, 'value', 'key');

        $this->assertSame(2, $byKey['total_products']);
        $this->assertSame(2, $byKey['active_products']);
        // `payload()` sets stock to 4, so two records sum to 8, not 28.
        $this->assertEquals(8, $byKey['stock_value']);
    }

    public function test_duplicate_detection_never_groups_across_estates(): void
    {
        $engine = $this->engine();

        $this->in(self::OWN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('OWN-1', 'Same name')
        ));
        $this->in(self::FOREIGN, fn () => $engine->createRecord(
            $this->commerce(),
            'product',
            $this->payload('FOREIGN-1', 'Same name')
        ));

        $result = $this->in(self::OWN, fn (): array => $engine->duplicates(
            $this->commerce(),
            'product',
            'name'
        ));

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(array(), $result['groups']);
    }

    private function engine(): ToolkitEngine
    {
        return $this->app->make(ToolkitEngine::class);
    }

    /**
     * Run a callback with the estate pinned, then restore the previous pin.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function in(string $tenant, callable $callback): mixed
    {
        $previous = TenantContext::isPinned() ? TenantContext::current() : null;

        TenantContext::set($tenant);

        try {
            return $callback();
        } finally {
            TenantContext::set($previous);
        }
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
    private function payload(string $sku, string $name): array
    {
        return array(
            'sku' => $sku,
            'name' => $name,
            'price' => 12.5,
            'stock' => 4,
            'status' => 'active',
        );
    }
}
