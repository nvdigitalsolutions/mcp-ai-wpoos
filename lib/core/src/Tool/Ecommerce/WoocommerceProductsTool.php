<?php

/**
 * Product CRUD: read one, list or search, create, update, delete.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Woo_Products` (Proposal 164 section 164.177). The
 * register deferred it with "a catalog adapter; once one exists this is a thin
 * wrapper on ProductCatalogInterface" — the adapter exists (164.114), so this is
 * the scoped port that entry anticipated, not the 1,564-line translation it
 * warned against.
 *
 * ## Six actions performed, three refused by name
 *
 * `get`, `list`, `search`, `create`, `update` and `delete` map onto the catalog
 * contract. `manage_categories`, `manage_tags` and `manage_attributes` do not:
 * they manage the WordPress *taxonomies* — creating, renaming and deleting terms
 * and attribute definitions — and this model has no taxonomy store at all. They
 * are refused with that reason rather than dropped.
 *
 * ## What has no representation, and is therefore refused
 *
 * `short_description`, `categories`, `images`, `featured_image`, `attributes`,
 * `weight`, `dimensions`, `virtual`, `downloadable` and `backorders` are all
 * accepted by the reference and none of them has a field here. Each is refused by
 * name. A quiet drop is how a migration loses half a catalogue and reports
 * success. `category` as a *filter* is refused for the same reason, and `type` is
 * a create/update field but not a filter, because `ProductQuery` cannot select on
 * product type — filtering after the page would be the page-size-change defect
 * this family keeps correcting.
 *
 * ## Update is product-level, and says so
 *
 * A price, a SKU or a stock level lives on a variant, not on the product, and the
 * catalog's `save()` deliberately leaves the stored variant list alone (164.114).
 * So `update` writes `name`, `description`, `status`, `type` and `tags`, and
 * refuses a variant field by name, pointing at `update_woo_product_price`,
 * `update_woo_product_qty` and `bulk_update_products` — which already take a
 * scope. Guessing which variant a caller meant would silently reprice one
 * variation of a multi-variant product, which is worse than an unfamiliar refusal.
 *
 * ## The status mapping is a decision
 *
 * WooCommerce has `publish`, `draft`, `pending` and `private`; this model has
 * three states, and `ProductStatus` records why: `pending` and `private` both mean
 * "exists, not visible to shoppers", which is `Draft`. Both map to `draft` on the
 * way in, and `published` and `archived` are accepted so a value read from here
 * can be written back unchanged.
 *
 * ## Create decides the charge from both price fields
 *
 * There is no existing charge to preserve, so `sale_price` is what is charged and
 * `price` becomes the comparison; with only `price` given it is the charge and
 * there is no comparison, which is what WooCommerce does with a regular price and
 * no sale. A price needs a currency because this model stores one with every
 * amount, so `create` requires it. (An *update* of a price goes through the
 * single-product tool, which takes the currency from the variant and never from
 * the caller.)
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Domain\ValueObject\ProductStatus;

final class WoocommerceProductsTool extends AbstractProductVariantTool
{
    /** The actions this tool performs. */
    public const ACTIONS = array('get', 'list', 'search', 'create', 'update', 'delete');

    /**
     * The reference's taxonomy actions, and why this tool does not perform them.
     *
     * @var array<string, string>
     */
    public const REFUSED_ACTIONS = array(
        'manage_categories' => 'It creates, renames and deletes product-category terms. This runtime has no taxonomy store; the product carries tags only.',
        'manage_tags'       => 'It creates, renames and deletes product-tag terms. This runtime stores tags as product strings, with no term store behind them.',
        'manage_attributes' => 'It manages global attribute definitions. This runtime has no attribute model.',
    );

    /**
     * Arguments the reference accepts that have no field here.
     *
     * @var array<string, string>
     */
    public const REFUSED_ARGUMENTS = array(
        'short_description' => 'The product model has one description field and no short description.',
        'category'          => 'The runtime product carries tags and a type, not a category tree.',
        'categories'        => 'The runtime product carries tags and a type, not a category tree.',
        'images'            => 'The product model has no gallery field; a variant carries one image URL.',
        'featured_image'    => 'The product model has no image field; a variant carries one image URL.',
        'attributes'        => 'The product model has options, not WooCommerce attribute definitions with visibility and variation flags.',
        'weight'            => 'The product model has no shipping fields.',
        'dimensions'        => 'The product model has no shipping fields.',
        'virtual'           => 'The product model has no virtual flag.',
        'downloadable'      => 'The product model has no downloadable flag.',
        'backorders'        => 'The runtime variant has no backorder state.',
    );

    /**
     * Variant fields an update refuses, with the tool that performs them.
     *
     * @var array<string, string>
     */
    public const REFUSED_ON_UPDATE = array(
        'price'          => 'A price lives on a variant. Use update_woo_product_price, which takes a scope.',
        'sale_price'     => 'A price lives on a variant. Use update_woo_product_price, which takes a scope.',
        'currency'       => 'A price lives on a variant, and the single-product tool takes its currency from the variant.',
        'sku'            => 'A SKU lives on a variant, where it is unique. Use the catalog adapter or a variant tool.',
        'stock_quantity' => 'Stock lives on a variant. Use update_woo_product_qty, which takes a scope.',
        'stock_status'   => 'Stock lives on a variant. Use update_woo_product_qty, which takes a scope.',
        'manage_stock'   => 'Stock lives on a variant. Use update_woo_product_qty, which takes a scope.',
    );

    /**
     * The statuses a caller may send, and what they mean here.
     *
     * @var array<string, string>
     */
    public const STATUS_MAP = array(
        'publish'   => 'published',
        'published' => 'published',
        'draft'     => 'draft',
        'pending'   => 'draft',
        'private'   => 'draft',
        'archived'  => 'archived',
    );

    /** The arguments this tool reads. */
    public const KNOWN_ARGUMENTS = array(
        'action',
        'product_id',
        'per_page',
        'page',
        'search',
        'status',
        'type',
        'name',
        'sku',
        'price',
        'sale_price',
        'currency',
        'description',
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'tags',
    );

    /** The most products one page will hold. */
    public const MAX_PER_PAGE = 100;

    /** How many products a page holds when the caller does not say. */
    public const DEFAULT_PER_PAGE = 10;

    public function getSlug(): string
    {
        return 'woo_products';
    }

    public function getName(): string
    {
        return 'WooCommerce Products';
    }

    public function getDescription(): string
    {
        return 'Reads and writes catalogue products. Actions: get, list, search, create, delete, and update for the product fields name, description, status, type and tags. A product is created with one variant carrying the price and stock, and a price needs its currency. Variant changes on an existing product go through update_woo_product_price or update_woo_product_qty. Categories, attributes, images and taxonomy management are not supported and are refused by name.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action'         => array(
                    'type'        => 'string',
                    'description' => 'get, list, search, create, update or delete.',
                    'enum'        => self::ACTIONS,
                    'default'     => 'list',
                ),
                'product_id'     => array(
                    'type'        => 'integer',
                    'description' => 'The product to read, update or delete.',
                    'minimum'     => 1,
                ),
                'per_page'       => array(
                    'type'        => 'integer',
                    'description' => 'How many products this page holds. Default 10, at most 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => self::DEFAULT_PER_PAGE,
                ),
                'page'           => array(
                    'type'        => 'integer',
                    'description' => 'Which page to read. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'search'         => array(
                    'type'        => 'string',
                    'description' => 'Free text matched against the product. Required for the search action.',
                ),
                'status'         => array(
                    'type'        => 'string',
                    'description' => 'publish or published, draft, pending or private, archived. pending and private both mean draft here.',
                    'enum'        => array_keys(self::STATUS_MAP),
                ),
                'type'           => array(
                    'type'        => 'string',
                    'description' => 'The product type, stored verbatim on create and update. Not a list filter.',
                ),
                'name'           => array(
                    'type'        => 'string',
                    'description' => 'The product name. Required for create.',
                ),
                'description'    => array(
                    'type'        => 'string',
                    'description' => 'The product description.',
                ),
                'tags'           => array(
                    'type'        => 'array',
                    'description' => 'Tag names to set on the product. Ids cannot be resolved; pass names.',
                    'items'       => array('type' => 'string'),
                ),
                'sku'            => array(
                    'type'        => 'string',
                    'description' => 'The variant SKU, on create.',
                ),
                'price'          => array(
                    'type'        => 'number',
                    'description' => 'The regular price, on create. Becomes the comparison price when sale_price is also given.',
                    'minimum'     => 0,
                ),
                'sale_price'     => array(
                    'type'        => 'number',
                    'description' => 'The sale price, on create: what the customer is charged.',
                    'minimum'     => 0,
                ),
                'currency'       => array(
                    'type'        => 'string',
                    'description' => 'The ISO 4217 currency of price and sale_price on create.',
                ),
                'stock_quantity' => array(
                    'type'        => 'integer',
                    'description' => 'The variant stock quantity, on create.',
                    'minimum'     => 0,
                ),
                'stock_status'   => array(
                    'type'        => 'string',
                    'description' => 'instock or outofstock, on create. Backorder is not representable.',
                    'enum'        => array('instock', 'outofstock'),
                ),
                'manage_stock'   => array(
                    'type'        => 'boolean',
                    'description' => 'Whether the variant tracks stock, on create.',
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $action = strtolower($this->stringParam($arguments, 'action', 'list'));

        if (isset(self::REFUSED_ACTIONS[$action])) {
            return $this->errors->validationFailed(
                'The product action cannot be performed.',
                array('action' => array(self::REFUSED_ACTIONS[$action]))
            );
        }

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'The product action cannot be performed.',
                array('action' => array('One of: ' . implode(', ', self::ACTIONS) . '.'))
            );
        }

        $fields = array();
        $this->refuseUnsupported($arguments, $fields);

        if ('get' === $action) {
            return $this->read($arguments, $fields);
        }

        if ('delete' === $action) {
            return $this->remove($arguments, $fields);
        }

        if ('create' === $action) {
            return $this->create($arguments, $fields);
        }

        if ('update' === $action) {
            return $this->update($arguments, $fields);
        }

        return $this->listing($arguments, $action, $fields);
    }

    /**
     * Record a field error for every argument the tool does not take.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function refuseUnsupported(array $arguments, array &$fields): void
    {
        foreach (array_keys($arguments) as $key) {
            if (in_array($key, self::KNOWN_ARGUMENTS, true)) {
                continue;
            }

            $fields[$key] = array(self::REFUSED_ARGUMENTS[$key] ?? 'Unknown field.');
        }
    }

    /**
     * The `get` action.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function read(array $arguments, array &$fields): mixed
    {
        $id = $this->intParam($arguments, 'product_id', 0);

        if ($id < 1) {
            $fields['product_id'] = array('A positive product id is required for get.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The product cannot be read.', $fields);
        }

        $product = $this->catalog->find($id);

        if (null === $product) {
            return $this->errors->notFound(sprintf('Product %d not found.', $id), array('product_id' => $id));
        }

        return $this->success(sprintf('Product %s.', $product->name), $product->jsonSerialize());
    }

    /**
     * The `delete` action.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function remove(array $arguments, array &$fields): mixed
    {
        $id = $this->intParam($arguments, 'product_id', 0);

        if ($id < 1) {
            $fields['product_id'] = array('A positive product id is required for delete.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The product cannot be deleted.', $fields);
        }

        if (null === $this->catalog->find($id)) {
            return $this->errors->notFound(sprintf('Product %d not found.', $id), array('product_id' => $id));
        }

        $this->catalog->delete($id);

        return $this->success(sprintf('Product %d deleted.', $id), array('product_id' => $id, 'deleted' => true));
    }

    /**
     * The `list` and `search` actions.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function listing(array $arguments, string $action, array &$fields): mixed
    {
        $perPage = $this->intParam($arguments, 'per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            $fields['per_page'] = array(sprintf('1 to %d.', self::MAX_PER_PAGE));
        }

        $page = $this->intParam($arguments, 'page', 1);

        if ($page < 1) {
            $fields['page'] = array('A page number starts at 1.');
        }

        $status = $this->status($arguments, $fields);

        if (isset($arguments['type'])) {
            $fields['type'] = array('The catalog query cannot filter by product type.');
        }

        $search = trim($this->stringParam($arguments, 'search'));

        if ('search' === $action && '' === $search) {
            $fields['search'] = array('The search action needs a term.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The product list cannot be read.', $fields);
        }

        $collection = $this->catalog->query(
            new ProductQuery(
                search: $search,
                status: $status,
                page: $page,
                perPage: $perPage,
            )
        );

        $products = array_map(
            static function (Product $product): array {
                $data = $product->jsonSerialize();
                $count = count($product->variants);

                unset($data['variants']);

                $data['variant_count'] = $count;

                return $data;
            },
            $collection->items
        );

        return $this->success(
            sprintf('Found %d products.', $collection->total),
            array(
                'total_count' => $collection->total,
                'returned'    => count($products),
                'page'        => $collection->page,
                'per_page'    => $collection->perPage,
                'total_pages' => $collection->totalPages,
                'products'    => $products,
            )
        );
    }

    /**
     * The `create` action.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function create(array $arguments, array &$fields): mixed
    {
        $name = trim($this->stringParam($arguments, 'name'));

        if ('' === $name) {
            $fields['name'] = array('A product name is required for create.');
        }

        $status = $this->status($arguments, $fields);
        $tags = $this->tags($arguments, $fields);

        $hasPrice = isset($arguments['price']) && null !== $arguments['price'];
        $hasSale = isset($arguments['sale_price']) && null !== $arguments['sale_price'];

        $price = $hasPrice ? $this->amount($arguments['price']) : null;
        $sale = $hasSale ? $this->amount($arguments['sale_price']) : null;

        if ($hasPrice && null === $price) {
            $fields['price'] = array('A non-negative number.');
        }

        if ($hasSale && null === $sale) {
            $fields['sale_price'] = array('A non-negative number.');
        }

        $currency = strtoupper($this->stringParam($arguments, 'currency'));

        if (! $hasPrice && ! $hasSale) {
            $fields['price'] = array('A price or a sale_price is required for create.');
        } elseif (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $fields['currency'] = array('A price needs the ISO 4217 currency it is expressed in.');
        }

        $stock = null;
        $track = true;

        if (isset($arguments['stock_quantity'])) {
            if (! is_numeric($arguments['stock_quantity']) || (int) $arguments['stock_quantity'] < 0) {
                $fields['stock_quantity'] = array('A non-negative whole number.');
            } else {
                $stock = (int) $arguments['stock_quantity'];
            }
        }

        if (isset($arguments['stock_status'])) {
            $stockStatus = strtolower($this->stringParam($arguments, 'stock_status'));

            if (in_array($stockStatus, array('instock', 'outofstock'), true)) {
                $stock = 'instock' === $stockStatus ? 1 : 0;
            } else {
                $fields['stock_status'] = array('instock or outofstock. The runtime cannot express backorder.');
            }
        }

        if (isset($arguments['manage_stock'])) {
            $track = $this->boolParam($arguments, 'manage_stock', true);

            if (! $track) {
                $stock = null;
            }
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The product cannot be created.', $fields);
        }

        // Reaching here means a price was supplied and the currency parsed.
        $charged = $sale ?? (float) $price;
        $compare = $hasPrice && $hasSale ? $price : null;

        $product = new Product(
            id: null,
            name: $name,
            variants: array(
                new ProductVariant(
                    id: null,
                    sku: isset($arguments['sku']) ? ( trim($this->stringParam($arguments, 'sku')) ?: null ) : null,
                    price: Money::fromDecimal($charged, $currency),
                    options: new OptionSelection(array()),
                    compareAtPrice: null === $compare ? null : Money::fromDecimal($compare, $currency),
                    stock: $stock,
                    trackInventory: $track,
                    position: 0,
                ),
            ),
            description: $this->stringParam($arguments, 'description'),
            status: $status ?? ProductStatus::Draft,
            productType: isset($arguments['type']) ? $this->stringParam($arguments, 'type') : null,
            tags: $tags,
        );

        $saved = $this->catalog->save($product);

        return $this->success(
            sprintf('Product %d created.', (int) $saved->id),
            array(
                'product_id' => $saved->id,
                'name'       => $saved->name,
                'status'     => $saved->status->value,
            )
        );
    }

    /**
     * The `update` action, which writes product-level fields only.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function update(array $arguments, array &$fields): mixed
    {
        $id = $this->intParam($arguments, 'product_id', 0);

        if ($id < 1) {
            $fields['product_id'] = array('A positive product id is required for update.');
        }

        foreach (self::REFUSED_ON_UPDATE as $key => $reason) {
            if (array_key_exists($key, $arguments) && null !== $arguments[$key]) {
                $fields[$key] = array($reason);
            }
        }

        $status = $this->status($arguments, $fields);
        $tags = $this->tags($arguments, $fields);

        $hasProductFields = isset($arguments['name'])
            || isset($arguments['description'])
            || isset($arguments['status'])
            || isset($arguments['type'])
            || isset($arguments['tags']);

        if (! $hasProductFields && array() === $fields) {
            $fields['action'] = array('No updatable field was supplied. An update writes name, description, status, type or tags.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The product cannot be updated.', $fields);
        }

        $product = $this->catalog->find($id);

        if (null === $product) {
            return $this->errors->notFound(sprintf('Product %d not found.', $id), array('product_id' => $id));
        }

        $saved = $this->catalog->save($this->withProductChanges($product, $arguments, $status, $tags));

        return $this->success(
            sprintf('Product %d updated.', $id),
            array(
                'product_id' => $id,
                'name'       => $saved->name,
                'status'     => $saved->status->value,
            )
        );
    }

    /**
     * The canonical status from the raw argument, or null after a field error.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function status(array $arguments, array &$fields): ?ProductStatus
    {
        if (! isset($arguments['status'])) {
            return null;
        }

        $key = strtolower($this->stringParam($arguments, 'status'));
        $mapped = self::STATUS_MAP[$key] ?? null;

        if (null === $mapped) {
            $fields['status'] = array('One of: ' . implode(', ', array_keys(self::STATUS_MAP)) . '.');

            return null;
        }

        return ProductStatus::from($mapped);
    }

    /**
     * The tag names from the raw argument, or an empty list.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     *
     * @return list<string>
     */
    private function tags(array $arguments, array &$fields): array
    {
        if (! isset($arguments['tags'])) {
            return array();
        }

        if (! is_array($arguments['tags'])) {
            $fields['tags'] = array('An array of tag names.');

            return array();
        }

        $tags = array();

        foreach ($arguments['tags'] as $tag) {
            if (! is_string($tag) || '' === trim($tag)) {
                $fields['tags'] = array('Tag names as strings. Ids cannot be resolved, so pass names.');

                return array();
            }

            $tags[] = trim($tag);
        }

        return array_values(array_unique($tags));
    }

    /**
     * A copy of a product with the supplied fields changed.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param ProductStatus|null   $status    Resolved status, when supplied.
     * @param list<string>         $tags      Resolved tags, when supplied.
     */
    private function withProductChanges(
        Product $product,
        array $arguments,
        ?ProductStatus $status,
        array $tags
    ): Product {
        return new Product(
            id: $product->id,
            name: isset($arguments['name']) ? trim($this->stringParam($arguments, 'name')) : $product->name,
            variants: $product->variants,
            options: $product->options,
            description: isset($arguments['description'])
                ? $this->stringParam($arguments, 'description')
                : $product->description,
            slug: $product->slug,
            status: $status ?? $product->status,
            vendor: $product->vendor,
            productType: isset($arguments['type']) ? $this->stringParam($arguments, 'type') : $product->productType,
            tags: array() !== $tags ? $tags : $product->tags,
            externalRef: $product->externalRef,
            createdAt: $product->createdAt,
            updatedAt: $product->updatedAt,
        );
    }
}
