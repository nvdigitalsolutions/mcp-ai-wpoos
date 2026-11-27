<?php

/**
 * Shopify products: list, get, create and update over the Admin GraphQL API.
 *
 * Proposal 164 section 164.113. Port of `WP_MCP_AI_Pro_Tool_Shopify_Products`
 * (1265 lines, five actions, four API modes), reduced to the one transport that
 * actually writes: the Admin API. The tool's other three modes — the deprecated
 * REST Catalog API and the two keyless UCP catalogs — are product *search*, not
 * product management, and they are a separate tool (`shopify_catalog`) on a
 * separate transport; they are not smuggled in here.
 *
 * ## Corrections
 *
 * 1. **`create` uses the current mutation.** WordPress called
 *    `productCreate(input: ProductInput!)` and passed `variants` inside it. In the
 *    2026-10 schema the `input:` argument is marked deprecated, `ProductInput` has
 *    **no `variants` field at all**, and `productCreate` creates only the initial
 *    variant. So the old create could not have worked: it would have been a
 *    GraphQL error, or a silent fall-forward to an older schema. Here `create`
 *    sends `productCreate(product: ProductCreateInput!)` with `productOptions`
 *    when options are given, and then — only when variants were supplied — a
 *    second `productVariantsBulkCreate` call.
 * 2. **`update` sends `productUpdate(product: ProductUpdateInput!)`**, with the id
 *    inside the input, because that is the current argument name. Options and
 *    variants cannot be changed by it; the variant list has its own mutations, and
 *    pretending otherwise is what produced the broken create.
 * 3. **`search` is `list` with a query.** WordPress had two identical branches;
 *    the alias is kept because prompts use it, but there is one code path.
 * 4. **`body_html` is accepted as an alias for `description_html`.** The
 *    WordPress argument was named for the REST field it never used; prompts in the
 *    wild still say `body_html`, so both work and the new name is documented.
 * 5. **Variants are projected with `options` as a map.** Shopify returns
 *    `selectedOptions[]`; a name→value map is what every other surface in this
 *    repository uses (`OptionSelection`, the commerce contract in section
 *    164.111), and option order is not part of a variant's identity.
 * 6. **Money is the provider's decimal string.** `{amount: "19.99", currency:
 *    "USD"}`, never a float.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

class ShopifyProductsTool extends ShopifyTool
{
    /**
     * The fields every product projection reads.
     */
    private const PRODUCT_FIELDS = '
        id title handle status vendor productType tags createdAt updatedAt
        priceRangeV2 {
            minVariantPrice { amount currencyCode }
            maxVariantPrice { amount currencyCode }
        }
        totalInventory
        variants(first: 10) {
            edges { node { id title sku price compareAtPrice inventoryQuantity selectedOptions { name value } } }
        }
        images(first: 5) { edges { node { id url altText } } }';

    public function getSlug(): string
    {
        return 'shopify_products';
    }

    public function getName(): string
    {
        return 'Shopify Products';
    }

    public function getDescription(): string
    {
        return 'List, search, retrieve, create and update products on a Shopify store over the Admin GraphQL API. '
            . 'Every product carries its variants (SKU, price, stock, option values) and image URLs. '
            . 'Creating a product with options and variants takes two API calls; the variant list is returned with the product.';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'store_url'        => array(
                    'type'        => 'string',
                    'description' => 'Store root, e.g. https://store.myshopify.com. No path.',
                    'minLength'   => 1,
                ),
                'access_token'     => array(
                    'type'        => 'string',
                    'description' => 'Shopify Admin API access token (X-Shopify-Access-Token).',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'api_version'      => array(
                    'type'        => 'string',
                    'description' => 'Admin API version, e.g. 2026-10. Defaults to the latest stable the tool knows.',
                    'pattern'     => '^\\d{4}-(01|04|07|10)$',
                ),
                'action'           => array(
                    'type'        => 'string',
                    'description' => 'list (default), get, create, update, or search (list with a query).',
                    'enum'        => array('list', 'get', 'create', 'update', 'search'),
                ),
                'product_id'       => array(
                    'type'        => 'string',
                    'description' => 'Product GID (gid://shopify/Product/123) or bare numeric id, for get and update.',
                ),
                'first'            => array(
                    'type'        => 'integer',
                    'description' => 'Products per page, 1-250. Defaults to 10.',
                    'minimum'     => 1,
                    'maximum'     => 250,
                ),
                'after'            => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                ),
                'query'            => array(
                    'type'        => 'string',
                    'description' => 'Shopify search syntax, e.g. status:active vendor:Acme.',
                ),
                'title'            => array(
                    'type'        => 'string',
                    'description' => 'Product title, for create (required) and update.',
                ),
                'description_html' => array(
                    'type'        => 'string',
                    'description' => 'Product description HTML, for create and update. body_html is accepted as an alias.',
                ),
                'vendor'           => array('type' => 'string', 'description' => 'Vendor name.'),
                'product_type'     => array('type' => 'string', 'description' => 'Merchant-defined product type.'),
                'handle'           => array('type' => 'string', 'description' => 'URL slug. Generated from the title when omitted.'),
                'tags'             => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Tags. Replaces the existing set on update.',
                ),
                'status'           => array(
                    'type'        => 'string',
                    'description' => 'Product status.',
                    'enum'        => array('ACTIVE', 'DRAFT', 'ARCHIVED'),
                ),
                'options'          => array(
                    'type'        => 'array',
                    'description' => 'Option definitions for create, e.g. [{"name":"Size","values":[{"name":"M"},{"name":"L"}]}].',
                    'items'       => array('type' => 'object'),
                ),
                'variants'         => array(
                    'type'        => 'array',
                    'description' => 'Variants for create, e.g. [{"sku":"T-1","price":"19.99","options":{"Size":"M"}}]. Up to 100.',
                    'items'       => array('type' => 'object'),
                ),
                'seo_title'        => array('type' => 'string', 'description' => 'SEO title.'),
                'seo_description'  => array('type' => 'string', 'description' => 'SEO meta description.'),
                'smart_search'     => array(
                    'type'        => 'boolean',
                    'description' => 'When a query returns nothing, retry with decomposed keywords (default true).',
                ),
            ),
            'required'             => array('store_url', 'access_token', 'action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $store = $this->storeUrl($arguments);

        if ($this->errors->isError($store)) {
            return $store;
        }

        $token = $this->token($arguments);

        if ($this->errors->isError($token)) {
            return $token;
        }

        $version = $this->apiVersion($arguments);

        if ($this->errors->isError($version)) {
            return $version;
        }

        $action = $this->text($arguments['action'] ?? null);

        switch ('' !== $action ? $action : 'list') {
            case 'list':
            case 'search':
                return $this->handleList((string) $store, (string) $token, (string) $version, $arguments);

            case 'get':
                return $this->handleGet((string) $store, (string) $token, (string) $version, $arguments);

            case 'create':
                return $this->handleCreate((string) $store, (string) $token, (string) $version, $arguments);

            case 'update':
                return $this->handleUpdate((string) $store, (string) $token, (string) $version, $arguments);

            default:
                return $this->errors->validationFailed(
                    'Unknown action.',
                    array('action' => array('Expected list, get, create, update or search.'))
                );
        }
    }

    /**
     * One page of products, with progressive relaxation on an empty result.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleList(string $store, string $token, string $version, array $arguments): mixed
    {
        $first = $this->limit($arguments['first'] ?? null, 10);
        $after = $this->cursor($arguments);
        $query = $this->text($arguments['query'] ?? null);

        $page = $this->fetchProducts($store, $token, $version, $first, $after, $query);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $products = is_array($page) ? $page['products'] : array();
        $info     = is_array($page) ? $page['page_info'] : array();
        $note     = '';

        if (array() === $products && '' !== $query && $this->boolParam($arguments, 'smart_search', true)) {
            $subQueries = ShopifySmartSearch::subQueries($query);
            $sets       = array();

            foreach ($subQueries as $subQuery) {
                $subPage = $this->fetchProducts($store, $token, $version, $first, '', $subQuery);

                if ($this->errors->isError($subPage)) {
                    continue;
                }

                $found = is_array($subPage) ? $subPage['products'] : array();

                if (array() !== $found) {
                    $sets[] = $found;
                }
            }

            if (array() !== $sets) {
                $products = ShopifySmartSearch::rank($sets, $first);
                $info     = array('has_next_page' => false, 'next_cursor' => null);
                $note     = sprintf(
                    'The query "%s" returned nothing; smart search decomposed it and found %d product(s).',
                    $query,
                    count($products)
                );
            }
        }

        $data = array(
            'products'  => $products,
            'count'     => count($products),
            'page_info' => $info,
        );

        $data = $this->meta($data, is_array($page) ? ( $page['raw'] ?? null ) : null);

        if ('' !== $note) {
            $data['smart_search'] = true;
            $data['note']         = $note;
        }

        return $this->success(
            sprintf('Found %d product%s.', count($products), 1 === count($products) ? '' : 's'),
            $data
        );
    }

    /**
     * One product, with its full variant and collection detail.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleGet(string $store, string $token, string $version, array $arguments): mixed
    {
        $id = $this->gid($this->text($arguments['product_id'] ?? null), 'Product');

        if (null === $id) {
            return $this->errors->validationFailed(
                'A product id is required for the get action.',
                array('product_id' => array('Pass a GID or a numeric product id.'))
            );
        }

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Product($id: ID!) {
                product(id: $id) {
                    id title handle descriptionHtml status vendor productType tags createdAt updatedAt
                    priceRangeV2 {
                        minVariantPrice { amount currencyCode }
                        maxVariantPrice { amount currencyCode }
                    }
                    totalInventory
                    variants(first: 100) {
                        edges { node { id title sku price compareAtPrice inventoryQuantity barcode selectedOptions { name value } } }
                    }
                    images(first: 20) { edges { node { id url altText } } }
                    collections(first: 10) { edges { node { id title handle } } }
                    seo { title description }
                }
            }',
            array('id' => $id)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $node = is_array($result) ? ( $result['data']['product'] ?? null ) : null;

        if (! is_array($node)) {
            return $this->errors->notFound('No product has that id.', array('product_id' => $id));
        }

        $product = $this->normalizeProduct($node);
        $product['description_html'] = $this->text($node['descriptionHtml'] ?? null);
        $product['collections']      = array();

        foreach ($this->edgesOf($node, 'collections') as $collection) {
            $product['collections'][] = array(
                'id'     => $this->text($collection['id'] ?? null),
                'title'  => $this->text($collection['title'] ?? null),
                'handle' => $this->text($collection['handle'] ?? null),
            );
        }

        $seo = is_array($node['seo'] ?? null) ? $node['seo'] : array();

        $product['seo'] = array(
            'title'       => $this->text($seo['title'] ?? null),
            'description' => $this->text($seo['description'] ?? null),
        );

        return $this->success('Retrieved the product.', $this->meta(array('product' => $product), $result));
    }

    /**
     * Create a product, then its variants when any were supplied.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleCreate(string $store, string $token, string $version, array $arguments): mixed
    {
        $title = $this->text($arguments['title'] ?? null);

        if ('' === $title) {
            return $this->errors->validationFailed(
                'A title is required to create a product.',
                array('title' => array('This field is required.'))
            );
        }

        $input  = $this->productInput($arguments);
        $result = $this->graphql(
            $store,
            $token,
            $version,
            'mutation ProductCreate($product: ProductCreateInput!) {
                productCreate(product: $product) {
                    product {' . self::PRODUCT_FIELDS . '}
                    userErrors { field message }
                }
            }',
            array('product' => $input)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $refusal = $this->userError($result, 'productCreate');

        if (null !== $refusal) {
            return $refusal;
        }

        $node = is_array($result) ? ( $result['data']['productCreate']['product'] ?? null ) : null;

        if (! is_array($node)) {
            return $this->errors->create('shopify_unexpected_response', 'Shopify created nothing and reported no error.');
        }

        $product = $this->normalizeProduct($node);
        $id      = $this->text($node['id'] ?? null);
        $created = array();

        $variants = $this->variantInputs($arguments);

        if (array() !== $variants) {
            $variantResult = $this->graphql(
                $store,
                $token,
                $version,
                'mutation ProductVariantsCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                    productVariantsBulkCreate(productId: $productId, variants: $variants) {
                        productVariants { id title sku price selectedOptions { name value } }
                        userErrors { field message }
                    }
                }',
                array(
                    'productId' => $id,
                    'variants'  => $variants,
                )
            );

            if ($this->errors->isError($variantResult)) {
                return $variantResult;
            }

            $refusal = $this->userError($variantResult, 'productVariantsBulkCreate');

            if (null !== $refusal) {
                return $refusal;
            }

            $rows = is_array($variantResult) ? ( $variantResult['data']['productVariantsBulkCreate']['productVariants'] ?? array() ) : array();

            foreach (is_array($rows) ? $rows : array() as $row) {
                if (is_array($row)) {
                    $created[] = $this->normalizeVariant($row, true);
                }
            }
        }

        $data = array('product' => $product);

        if (array() !== $created) {
            $data['product']['variants'] = array_merge($data['product']['variants'], $created);
        }

        return $this->success(
            sprintf('Created the product%s.', array() !== $created ? ' and its variants' : ''),
            $this->meta($data, $result)
        );
    }

    /**
     * Update a product's own fields.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function handleUpdate(string $store, string $token, string $version, array $arguments): mixed
    {
        $id = $this->gid($this->text($arguments['product_id'] ?? null), 'Product');

        if (null === $id) {
            return $this->errors->validationFailed(
                'A product id is required for the update action.',
                array('product_id' => array('Pass a GID or a numeric product id.'))
            );
        }

        $input = $this->productInput($arguments);

        if (array() === $input) {
            return $this->errors->validationFailed(
                'No product fields were given to update.',
                array('product_id' => array('Pass at least one of title, vendor, tags, status, handle or seo.'))
            );
        }

        $input['id'] = $id;

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'mutation ProductUpdate($product: ProductUpdateInput!) {
                productUpdate(product: $product) {
                    product {' . self::PRODUCT_FIELDS . '}
                    userErrors { field message }
                }
            }',
            array('product' => $input)
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $refusal = $this->userError($result, 'productUpdate');

        if (null !== $refusal) {
            return $refusal;
        }

        $node = is_array($result) ? ( $result['data']['productUpdate']['product'] ?? null ) : null;

        if (! is_array($node)) {
            return $this->errors->create('shopify_unexpected_response', 'Shopify updated nothing and reported no error.');
        }

        return $this->success(
            'Updated the product.',
            $this->meta(array('product' => $this->normalizeProduct($node)), $result)
        );
    }

    /**
     * One page of products, normalised.
     *
     * @return array{products: list<array<string, mixed>>, page_info: array<string, mixed>}|mixed
     */
    private function fetchProducts(
        string $store,
        string $token,
        string $version,
        int $first,
        string $after,
        string $query,
    ): mixed {
        $variables = array('first' => $first);

        if ('' !== $after) {
            $variables['after'] = $after;
        }

        if ('' !== $query) {
            $variables['query'] = $query;
        }

        $result = $this->graphql(
            $store,
            $token,
            $version,
            'query Products($first: Int!, $after: String, $query: String) {
                products(first: $first, after: $after, query: $query) {
                    pageInfo { hasNextPage endCursor }
                    edges {
                        node {' . self::PRODUCT_FIELDS . '}
                    }
                }
            }',
            $variables
        );

        if ($this->errors->isError($result)) {
            return $result;
        }

        $products = array();

        foreach ($this->edges($result, 'products') as $node) {
            $products[] = $this->normalizeProduct($node);
        }

        return array(
            'products'  => $products,
            'page_info' => $this->pageInfo($result, 'products'),
            'raw'       => $result,
        );
    }

    /**
     * The product input for create or update, from the tool arguments.
     *
     * `productOptions` is included only when `options` were given, because
     * `ProductCreateInput` accepts it and `ProductUpdateInput` does not — the
     * update path therefore never sees it, and Shopify would reject it if it did.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>
     */
    private function productInput(array $arguments): array
    {
        $input      = array();
        $title      = $this->text($arguments['title'] ?? null);
        $description = $this->text($arguments['description_html'] ?? null);
        $description = '' !== $description ? $description : $this->text($arguments['body_html'] ?? null);

        if ('' !== $title) {
            $input['title'] = $title;
        }

        if ('' !== $description) {
            $input['descriptionHtml'] = $description;
        }

        foreach (array('vendor' => 'vendor', 'product_type' => 'productType', 'handle' => 'handle') as $from => $to) {
            $value = $this->text($arguments[$from] ?? null);

            if ('' !== $value) {
                $input[$to] = $value;
            }
        }

        $tags = $this->stringListParam($arguments, 'tags');

        if (array() !== $tags) {
            $input['tags'] = $tags;
        }

        $status = strtoupper($this->text($arguments['status'] ?? null));

        if (in_array($status, array('ACTIVE', 'DRAFT', 'ARCHIVED'), true)) {
            $input['status'] = $status;
        }

        $seoTitle       = $this->text($arguments['seo_title'] ?? null);
        $seoDescription = $this->text($arguments['seo_description'] ?? null);

        if ('' !== $seoTitle || '' !== $seoDescription) {
            $input['seo'] = array(
                'title'       => $seoTitle,
                'description' => $seoDescription,
            );
        }

        $options = $this->optionInputs($arguments);

        if (array() !== $options) {
            $input['productOptions'] = $options;
        }

        return $input;
    }

    /**
     * `OptionCreateInput[]` from the `options` argument.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<array{name: string, values: list<array{name: string}>}>
     */
    private function optionInputs(array $arguments): array
    {
        $options = $this->arrayParam($arguments, 'options');
        $inputs  = array();

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = $this->text($option['name'] ?? null);

            if ('' === $name) {
                continue;
            }

            $values = array();

            foreach ($this->arrayParam($option, 'values') as $value) {
                $label = is_array($value) ? $this->text($value['name'] ?? null) : $this->text($value);

                if ('' !== $label) {
                    $values[] = array('name' => $label);
                }
            }

            if (array() !== $values) {
                $inputs[] = array('name' => $name, 'values' => $values);
            }
        }

        return array_slice($inputs, 0, 3);
    }

    /**
     * `ProductVariantsBulkInput[]` from the `variants` argument.
     *
     * The canonical `options` map is translated to Shopify's
     * `optionValues: [{optionName, name}]`, which is the shape the bulk-create
     * mutation documents. A variant is skipped when it names no option value,
     * because Shopify cannot place it in the product's option matrix.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<array<string, mixed>>
     */
    private function variantInputs(array $arguments): array
    {
        $variants = $this->arrayParam($arguments, 'variants');
        $inputs   = array();

        foreach (array_slice($variants, 0, 100) as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $optionValues = array();

            foreach ($this->arrayParam($variant, 'options') as $optionName => $optionValue) {
                $name  = $this->text($optionName);
                $value = $this->text($optionValue);

                if ('' !== $name && '' !== $value) {
                    $optionValues[] = array('optionName' => $name, 'name' => $value);
                }
            }

            if (array() === $optionValues) {
                continue;
            }

            $input = array('optionValues' => $optionValues);

            foreach (array('sku' => 'sku', 'barcode' => 'barcode', 'price' => 'price', 'compare_at_price' => 'compareAtPrice') as $from => $to) {
                $value = $this->text($variant[$from] ?? null);

                if ('' !== $value) {
                    $input[$to] = $value;
                }
            }

            $inputs[] = $input;
        }

        return $inputs;
    }

    /**
     * A product node, projected.
     *
     * @param array<array-key, mixed> $node Raw GraphQL product node.
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $node): array
    {
        $priceRange = is_array($node['priceRangeV2'] ?? null) ? $node['priceRangeV2'] : array();
        $min        = $this->money($priceRange['minVariantPrice'] ?? null);
        $max        = $this->money($priceRange['maxVariantPrice'] ?? null);

        $variants = array();

        foreach ($this->edgesOf($node, 'variants') as $variant) {
            $variants[] = $this->normalizeVariant($variant, false);
        }

        $images = array();

        foreach ($this->edgesOf($node, 'images') as $image) {
            $images[] = array(
                'id'       => $this->text($image['id'] ?? null),
                'url'      => $this->text($image['url'] ?? null),
                'alt_text' => $this->text($image['altText'] ?? null),
            );
        }

        return array(
            'id'              => $this->text($node['id'] ?? null),
            'title'           => $this->text($node['title'] ?? null),
            'handle'          => $this->text($node['handle'] ?? null),
            'status'          => $this->text($node['status'] ?? null),
            'vendor'          => $this->text($node['vendor'] ?? null),
            'product_type'    => $this->text($node['productType'] ?? null),
            'tags'            => $this->stringListFrom($node['tags'] ?? null),
            'created_at'      => $this->text($node['createdAt'] ?? null),
            'updated_at'      => $this->text($node['updatedAt'] ?? null),
            'price_range'     => null === $min || null === $max ? null : array('min' => $min, 'max' => $max),
            'total_inventory' => is_numeric($node['totalInventory'] ?? null) ? (int) $node['totalInventory'] : null,
            'variants'        => $variants,
            'images'          => $images,
        );
    }

    /**
     * A variant node, projected.
     *
     * @param array<array-key, mixed> $node       Raw GraphQL variant node.
     * @param bool                    $bulkCreate Whether the node came from the bulk-create payload.
     *
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $node, bool $bulkCreate): array
    {
        $options = array();

        foreach (is_array($node['selectedOptions'] ?? null) ? $node['selectedOptions'] : array() as $option) {
            if (is_array($option)) {
                $name  = $this->text($option['name'] ?? null);
                $value = $this->text($option['value'] ?? null);

                if ('' !== $name) {
                    $options[$name] = $value;
                }
            }
        }

        $variant = array(
            'id'         => $this->text($node['id'] ?? null),
            'title'      => $this->text($node['title'] ?? null),
            'sku'        => $this->text($node['sku'] ?? null),
            'price'      => $this->text($node['price'] ?? null),
            'options'    => $options,
            'in_stock'   => null,
        );

        if (! $bulkCreate) {
            $variant['compare_at_price'] = '' !== $this->text($node['compareAtPrice'] ?? null)
                ? $this->text($node['compareAtPrice'])
                : null;
            $variant['inventory_quantity'] = is_numeric($node['inventoryQuantity'] ?? null)
                ? (int) $node['inventoryQuantity']
                : null;
            $variant['in_stock'] = is_numeric($node['inventoryQuantity'] ?? null)
                ? (int) $node['inventoryQuantity'] > 0
                : null;
        }

        if (array_key_exists('barcode', $node)) {
            $variant['barcode'] = $this->text($node['barcode'] ?? null);
        }

        return $variant;
    }

    /**
     * A scalar list from a provider field.
     *
     * @param mixed $value Raw list.
     *
     * @return list<string>
     */
    private function stringListFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $list = array();

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }
}
