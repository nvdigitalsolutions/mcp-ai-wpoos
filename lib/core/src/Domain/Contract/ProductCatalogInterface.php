<?php

/**
 * Product catalog contract: the aggregate and its variants, read and written.
 *
 * Proposal 164 section 164.111. This is the port the toolkit could not be built
 * without: twenty-seven of the forty-four `ecommerce` tools read or write a
 * product, a variant, an order or a customer, and until this existed they could
 * only be ported by dragging WooCommerce — or WordPress — into a
 * framework-agnostic core.
 *
 * Each adapter implements this interface with its own storage:
 *
 * - WordPress: `WC_Product` / `WC_Product_Variation`, or the posts and postmeta
 *   WooCommerce actually stores (`product_variation` under its parent, price and
 *   SKU in `_`-prefixed meta, options in `_product_attributes`).
 * - Laravel: `products` / `skus` / `attributes` / `attribute_sku`, the model in
 *   Thornburgh's 2023 article (`attribute_sku.value` holds the per-variant value).
 * - Shopify: the Admin GraphQL API, `product.options[]` and
 *   `variant.selectedOptions[]`.
 *
 * **Authorisation is the caller's job.** Unlike {@see ContentStoreInterface},
 * which filters per item because content permissions are per item, a catalog is
 * managed store-wide: the tool that calls this checks its own capability once
 * and the adapter does not take a user id. An adapter that *can* enforce tenancy
 * should, and is free to throw {@see \Nvoos\Core\Domain\Error\AccessDeniedException}.
 *
 * ## The write rule, and why it exists
 *
 * `save()` writes the product and its **option definitions**, and creates the
 * supplied variants **only when the product is new**. It never deletes a variant
 * it was not told about.
 *
 * That asymmetry is deliberate. A partial payload — an admin form that edits the
 * title, a sync that only knows the price — must not be able to wipe a variant
 * list by omission, which is the failure mode every "upsert the whole aggregate"
 * implementation eventually exhibits. Variant lifecycle after creation is
 * explicit: `saveVariant()` and `deleteVariant()`.
 *
 * `save()` must reject a duplicate `externalRef` (see
 * {@see \Nvoos\Core\Domain\Error\ValidationException}) because a migration matches
 * on it; two products claiming one source identity make every re-run ambiguous.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductCollection;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;

interface ProductCatalogInterface
{
    /**
     * Find one product by storage id, with its options and variants.
     */
    public function find(int $id): ?Product;

    /**
     * Find the product whose source identity is `$externalRef`.
     *
     * This is the migration's idempotency key: a re-run finds what the previous
     * run wrote instead of inserting a second copy. Null when absent.
     */
    public function findByExternalRef(string $externalRef): ?Product;

    /**
     * Find the variant carrying a SKU, across the whole catalog.
     *
     * The returned variant names its product in `productId`. Null when no
     * variant has that SKU; SKUs are compared case-insensitively.
     */
    public function findBySku(string $sku): ?ProductVariant;

    /**
     * Query products with filtering and pagination.
     */
    public function query(ProductQuery $query): ProductCollection;

    /**
     * Create or update a product.
     *
     * On create (`id` null) the supplied variants are persisted and the returned
     * product carries their ids. On update the product's fields and option
     * definitions are replaced; the stored variant list is left alone.
     *
     * @return Product The stored product, with ids assigned.
     *
     * @throws \Nvoos\Core\Domain\Error\NotFoundException When an update names a product that does not exist.
     * @throws \Nvoos\Core\Domain\Error\ValidationException When the payload is invalid, including
     *                                                     a duplicate `externalRef`.
     */
    public function save(Product $product): Product;

    /**
     * Delete a product and every variant it owns.
     *
     * @throws \Nvoos\Core\Domain\Error\NotFoundException When the product does not exist.
     */
    public function delete(int $id): void;

    /**
     * Insert or update one variant of a product.
     *
     * A variant whose `id` is null is inserted; otherwise it is updated in place.
     * An insert whose identity (SKU, or option signature when there is no SKU)
     * already exists on the product is a validation error rather than a second
     * row — see {@see ProductVariant::identity()}.
     *
     * @return ProductVariant The stored variant, with its id and `productId` set.
     *
     * @throws \Nvoos\Core\Domain\Error\NotFoundException When the product or variant does not exist.
     * @throws \Nvoos\Core\Domain\Error\ValidationException When the variant does not fit the product's options.
     */
    public function saveVariant(int $productId, ProductVariant $variant): ProductVariant;

    /**
     * Delete one variant.
     *
     * @throws \Nvoos\Core\Domain\Error\NotFoundException When the variant does not exist.
     */
    public function deleteVariant(int $variantId): void;
}
