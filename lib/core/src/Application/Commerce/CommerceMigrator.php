<?php

/**
 * Copies a product catalog from one {@see ProductCatalogInterface} to another.
 *
 * Proposal 164 section 164.111. This is the WooCommerce → Laravel path stated as
 * code: it reads through the port, writes through the port, and knows neither
 * WooCommerce's postmeta nor Eloquent. An operator migrating a store points it at
 * a Woo-backed adapter as the source and a Laravel-backed adapter as the target.
 *
 * It is written the way an ETL that has to be run twice must be:
 *
 * 1. **Idempotent.** Every product is matched by its source identity
 *    (`externalRef`, or the first variant's SKU when the source has no ref). A
 *    re-run updates what the first run wrote instead of inserting a duplicate.
 *    Matching by storage id would be wrong by construction — the target's ids
 *    are its own.
 * 2. **Resumable by shape, not by cursor.** It pages the source; a run that
 *    fails halfway can simply be run again, because step 1 holds.
 * 3. **Honest about partial data.** A product that fails is recorded and the run
 *    continues; one bad row must not abandon the other 1,203. Option-coherence
 *    problems — a variant naming an option its product does not define, which is
 *    common in Woo stores built by hand — are recorded as warnings rather than
 *    fatal, because refusing them would block an otherwise valid migration.
 * 4. **Never writes in a dry run.** The same walk happens, the same report is
 *    produced, and `save()` is not called.
 *
 * The write rule it relies on is the contract's: `save()` creates variants only
 * with a new product, so an *update* reconciles variants explicitly, reusing the
 * target's ids by identity. That is what stops a second run from duplicating a
 * variant list.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Commerce;

use Nvoos\Core\Domain\Contract\ProductCatalogInterface;
use Nvoos\Core\Domain\Entity\CommerceMigrationReport;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;

final class CommerceMigrator
{
    /**
     * A hard stop for a source adapter that ignores pagination.
     */
    private const MAX_PAGES = 10000;

    public function __construct(
        private readonly ProductCatalogInterface $source,
        private readonly ProductCatalogInterface $target,
    ) {
    }

    /**
     * Walk the source catalog and write it to the target.
     *
     * @param bool $dryRun    Rehearse: read everything, write nothing.
     * @param bool $overwrite Rewrite products that already exist. When false they
     *                        are counted as skipped, which is the safe default for
     *                        a first exploratory run.
     * @param int  $perPage   Source page size, 1-100.
     */
    public function migrate(bool $dryRun = false, bool $overwrite = true, int $perPage = 100): CommerceMigrationReport
    {
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;
        $warnings = 0;
        $entries  = array();

        $page = 1;

        do {
            $collection = $this->source->query(
                new ProductQuery(
                    orderBy: 'id',
                    order: 'ASC',
                    page: $page,
                    perPage: $perPage,
                )
            );

            foreach ($collection->items as $product) {
                if (! $product instanceof Product) {
                    ++$failed;
                    $entries[] = array(
                        'ref'     => '(unknown)',
                        'action'  => 'failed',
                        'message' => 'The source returned an item that is not a Product.',
                    );
                    continue;
                }

                $ref = $this->referenceFor($product);

                if (null === $ref) {
                    ++$failed;
                    $entries[] = array(
                        'ref'     => $product->name,
                        'action'  => 'failed',
                        'message' => 'The product has no external reference and no variant SKU, '
                            . 'so a re-run could not recognise it.',
                    );
                    continue;
                }

                foreach ($product->optionErrors() as $problem) {
                    ++$warnings;
                    $entries[] = array(
                        'ref'     => $ref,
                        'action'  => 'warning',
                        'message' => $problem,
                    );
                }

                try {
                    $outcome = $this->migrateOne($product, $ref, $dryRun, $overwrite);

                    if ('created' === $outcome) {
                        ++$created;
                    } elseif ('updated' === $outcome) {
                        ++$updated;
                    } else {
                        ++$skipped;
                    }

                    $entries[] = array(
                        'ref'     => $ref,
                        'action'  => $outcome,
                        'message' => '',
                    );
                } catch (\Throwable $error) {
                    ++$failed;
                    $entries[] = array(
                        'ref'     => $ref,
                        'action'  => 'failed',
                        'message' => $error->getMessage(),
                    );
                }
            }

            ++$page;
        } while ($collection->hasMorePages() && $page <= self::MAX_PAGES);

        return new CommerceMigrationReport($created, $updated, $skipped, $failed, $warnings, $dryRun, $entries);
    }

    /**
     * Migrate one product.
     *
     * @return string One of `created`, `updated`, `skipped`.
     *
     * @throws \Throwable When the target refuses the write.
     */
    private function migrateOne(Product $product, string $ref, bool $dryRun, bool $overwrite): string
    {
        $existing = $this->findExisting($product, $ref);

        if (null !== $existing && ! $overwrite) {
            return 'skipped';
        }

        if ($dryRun) {
            return null === $existing ? 'created' : 'updated';
        }

        $saved = $this->target->save($this->withIdentity($product, $existing?->id, $ref));

        // A create persists the variants it was given; an update does not touch
        // them, so reconcile explicitly against the ids the target already holds.
        if (null !== $existing) {
            $this->syncVariants($saved, $product, $existing, $ref);
        }

        return null === $existing ? 'created' : 'updated';
    }

    /**
     * The target's copy of this product, by source identity first and SKU second.
     */
    private function findExisting(Product $product, string $ref): ?Product
    {
        $existing = $this->target->findByExternalRef($ref);

        if (null !== $existing) {
            return $existing;
        }

        $sku = $this->firstSku($product);

        if (null === $sku) {
            return null;
        }

        $variant = $this->target->findBySku($sku);

        if (null === $variant || null === $variant->productId) {
            return null;
        }

        return $this->target->find($variant->productId);
    }

    /**
     * Write the source's variants onto the saved product, reusing target ids.
     *
     * @throws \Throwable When the target refuses a variant.
     */
    private function syncVariants(Product $saved, Product $product, Product $existing, string $ref): void
    {
        if (null === $saved->id) {
            return;
        }

        $known = array();

        foreach ($existing->variants as $variant) {
            $known[$variant->identity()] = $variant;

            if (null !== $variant->sku) {
                $known['sku:' . mb_strtolower(trim($variant->sku))] = $variant;
            }
        }

        foreach ($product->variants as $sourceVariant) {
            $match = $known[$sourceVariant->identity()] ?? null;

            $this->target->saveVariant(
                $saved->id,
                $this->variantForSave($sourceVariant, $match?->id, $ref)
            );
        }
    }

    /**
     * The product, relabelled with the target id and the source reference.
     *
     * The variants are passed with no id on purpose. On a create the adapter
     * assigns them; on an update it ignores them — and in neither case should it
     * see an identifier from the *source* store, which is what the incoming
     * product carries. Sending those would invite a naive adapter to overwrite a
     * row it does not own.
     */
    private function withIdentity(Product $product, ?int $id, string $ref): Product
    {
        $variants = array();

        foreach ($product->variants as $variant) {
            $variants[] = $this->variantForSave($variant, null, $ref);
        }

        return new Product(
            id: $id,
            name: $product->name,
            variants: $variants,
            options: $product->options,
            description: $product->description,
            slug: $product->slug,
            status: $product->status,
            vendor: $product->vendor,
            productType: $product->productType,
            tags: $product->tags,
            externalRef: $ref,
            createdAt: $product->createdAt,
            updatedAt: $product->updatedAt,
        );
    }

    /**
     * The variant as the target should store it, with a reference it can be found by.
     */
    private function variantForSave(ProductVariant $variant, ?int $id, string $ref): ProductVariant
    {
        $variantRef = $variant->externalRef;

        if (null === $variantRef || '' === trim($variantRef)) {
            $variantRef = $ref . '#' . $variant->identity();
        }

        return new ProductVariant(
            id: $id,
            sku: $variant->sku,
            price: $variant->price,
            options: $variant->options,
            compareAtPrice: $variant->compareAtPrice,
            cost: $variant->cost,
            barcode: $variant->barcode,
            stock: $variant->stock,
            trackInventory: $variant->trackInventory,
            position: $variant->position,
            imageUrl: $variant->imageUrl,
            externalRef: $variantRef,
            productId: $variant->productId,
        );
    }

    /**
     * The identity a re-run matches on.
     *
     * A source that already carries a reference uses it. Otherwise the first
     * variant's SKU stands in, because a SKU is the one identifier a WooCommerce
     * store and a Laravel store can both be expected to agree on. A product with
     * neither cannot be migrated idempotently and is reported as failed.
     */
    private function referenceFor(Product $product): ?string
    {
        if (null !== $product->externalRef && '' !== trim($product->externalRef)) {
            return trim($product->externalRef);
        }

        $sku = $this->firstSku($product);

        return null === $sku ? null : 'sku:' . mb_strtolower($sku);
    }

    /**
     * The first non-blank SKU among the product's variants.
     */
    private function firstSku(Product $product): ?string
    {
        foreach ($product->variants as $variant) {
            if (null !== $variant->sku && '' !== trim($variant->sku)) {
                return trim($variant->sku);
            }
        }

        return null;
    }
}
