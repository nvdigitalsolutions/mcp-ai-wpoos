<?php

/**
 * Tests for the catalog migrator.
 *
 * The property that makes this worth having is idempotency: a store operator
 * will run a migration more than once, and a second run must not double the
 * catalog. So the central case runs twice and asserts the target did not grow.
 * The others pin the four promises the class makes — a dry run writes nothing, a
 * failed item does not abandon the run, incoherent options warn rather than
 * block, and the source is paged rather than assumed to fit in one response.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Commerce;

use Nvoos\Core\Application\Commerce\CommerceMigrator;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Domain\ValueObject\ProductOption;
use Nvoos\Core\Domain\ValueObject\ProductStatus;
use Nvoos\Core\Tests\Support\MemoryProductCatalog;
use PHPUnit\Framework\TestCase;

final class CommerceMigratorTest extends TestCase
{
    public function test_a_first_run_creates_every_product_with_its_variants(): void
    {
        $target = new MemoryProductCatalog();

        $report = $this->migrator($this->source(), $target)->migrate();

        $this->assertSame(2, $report->created);
        $this->assertSame(0, $report->failed);
        $this->assertSame(2, $report->processed());
        $this->assertTrue($report->isClean());

        $stored = $target->all();
        $this->assertCount(2, $stored);

        $tShirt = $target->findByExternalRef('woocommerce:product:101');

        $this->assertNotNull($tShirt);
        $this->assertCount(2, $tShirt->variants);
        $this->assertSame(7, $tShirt->totalStock());
    }

    public function test_a_second_run_updates_without_duplicating_anything(): void
    {
        $target   = new MemoryProductCatalog();
        $migrator = $this->migrator($this->source(), $target);

        $migrator->migrate();
        $before = $this->variantCount($target);

        $second = $migrator->migrate();

        $this->assertSame(0, $second->created);
        $this->assertSame(2, $second->updated);
        $this->assertCount(2, $target->all());
        $this->assertSame($before, $this->variantCount($target));
    }

    public function test_a_product_saved_without_a_reference_is_found_by_its_sku_on_a_rerun(): void
    {
        $target   = new MemoryProductCatalog();
        $migrator = $this->migrator($this->source(), $target);

        $migrator->migrate();
        $migrator->migrate();

        $mug = $target->findBySku('MUG-1');

        $this->assertNotNull($mug);
        $this->assertNotNull($mug->productId);

        $owner = $target->find($mug->productId);

        $this->assertNotNull($owner);
        $this->assertSame('sku:mug-1', $owner->externalRef);
        $this->assertCount(2, $target->all());
    }

    public function test_a_dry_run_reports_the_work_and_writes_nothing(): void
    {
        $target = new MemoryProductCatalog();

        $report = $this->migrator($this->source(), $target)->migrate(dryRun: true);

        $this->assertTrue($report->dryRun);
        $this->assertSame(2, $report->created);
        $this->assertSame(array(), $target->writes());
        $this->assertCount(0, $target->all());
    }

    public function test_overwrite_off_leaves_an_existing_product_alone(): void
    {
        $target   = new MemoryProductCatalog();
        $migrator = $this->migrator($this->source(), $target);

        $migrator->migrate();
        $writes = $target->writes();

        $second = $migrator->migrate(overwrite: false);

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(2, $second->skipped);
        $this->assertSame($writes, $target->writes());
    }

    public function test_an_unidentifiable_product_fails_alone_and_the_run_continues(): void
    {
        $source = $this->source();
        $source->save($this->unidentifiableProduct());

        $report = $this->migrator($source, new MemoryProductCatalog())->migrate();

        $this->assertSame(2, $report->created);
        $this->assertSame(1, $report->failed);
        $this->assertFalse($report->isClean());

        $failures = array_values(
            array_filter(
                $report->entries,
                static fn (array $entry): bool => 'failed' === $entry['action']
            )
        );

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('no external reference', $failures[0]['message']);
    }

    public function test_option_incoherence_is_a_warning_and_still_migrates(): void
    {
        $source = new MemoryProductCatalog();
        $source->save($this->incoherentProduct());

        $report = $this->migrator($source, new MemoryProductCatalog())->migrate();

        $this->assertSame(1, $report->created);
        $this->assertSame(1, $report->warnings);
        $this->assertSame(0, $report->failed);
        $this->assertTrue($report->isClean());

        $this->assertSame('warning', $report->entries[0]['action']);
    }

    public function test_the_source_is_paged_rather_than_read_in_one_call(): void
    {
        $target = new MemoryProductCatalog();

        $report = $this->migrator($this->source(), $target)->migrate(perPage: 1);

        $this->assertSame(2, $report->processed());
        $this->assertSame(2, $report->created);
        $this->assertCount(2, $target->all());
    }

    public function test_the_report_serialises_its_totals(): void
    {
        $report = $this->migrator($this->source(), new MemoryProductCatalog())->migrate();

        $data = $report->jsonSerialize();

        $this->assertSame(2, $data['created']);
        $this->assertSame(2, $data['changed']);
        $this->assertFalse($data['dry_run']);
        $this->assertCount(2, $data['entries']);
    }

    /**
     * A two-product Woo-shaped source: one variable T-shirt with a source
     * reference, one simple mug that only a SKU can identify.
     */
    private function source(): MemoryProductCatalog
    {
        $source = new MemoryProductCatalog();

        $source->save(
            new Product(
                id: null,
                name: 'T-Shirt',
                variants: array(
                    new ProductVariant(
                        id: null,
                        sku: 'TSHIRT-RED-M',
                        price: Money::fromDecimal('19.99', 'USD'),
                        options: new OptionSelection(array('Colour' => 'Red', 'Size' => 'M')),
                        stock: 3,
                        position: 0,
                    ),
                    new ProductVariant(
                        id: null,
                        sku: 'TSHIRT-RED-L',
                        price: Money::fromDecimal('24.99', 'USD'),
                        options: new OptionSelection(array('Colour' => 'Red', 'Size' => 'L')),
                        stock: 4,
                        position: 1,
                    ),
                ),
                options: array(
                    new ProductOption('Colour', array('Red', 'Blue')),
                    new ProductOption('Size', array('M', 'L')),
                ),
                status: ProductStatus::Published,
                externalRef: 'woocommerce:product:101',
            )
        );

        $source->save(
            new Product(
                id: null,
                name: 'Mug',
                variants: array(
                    new ProductVariant(
                        id: null,
                        sku: 'MUG-1',
                        price: Money::fromDecimal('9.50', 'USD'),
                        options: new OptionSelection(array('Volume' => '350ml')),
                        stock: 12,
                        position: 0,
                    ),
                ),
                options: array(new ProductOption('Volume', array('350ml'))),
                status: ProductStatus::Published,
            )
        );

        return $source;
    }

    private function unidentifiableProduct(): Product
    {
        return new Product(
            id: null,
            name: 'Mystery item',
            variants: array(
                new ProductVariant(
                    id: null,
                    sku: null,
                    price: Money::fromDecimal('1.00', 'USD'),
                    options: new OptionSelection(array('Kind' => 'unknown')),
                ),
            ),
            options: array(new ProductOption('Kind', array('unknown'))),
        );
    }

    private function incoherentProduct(): Product
    {
        return new Product(
            id: null,
            name: 'Hand-built product',
            variants: array(
                new ProductVariant(
                    id: null,
                    sku: 'HAND-1',
                    price: Money::fromDecimal('5.00', 'USD'),
                    options: new OptionSelection(array('Colour' => 'Green')),
                ),
            ),
            options: array(),
        );
    }

    private function migrator(MemoryProductCatalog $source, MemoryProductCatalog $target): CommerceMigrator
    {
        return new CommerceMigrator($source, $target);
    }

    private function variantCount(MemoryProductCatalog $catalog): int
    {
        $count = 0;

        foreach ($catalog->all() as $product) {
            $count += count($product->variants);
        }

        return $count;
    }
}
