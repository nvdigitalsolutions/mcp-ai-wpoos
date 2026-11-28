<?php

/**
 * Update a regulatory product — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Update_Reg_Product` (Proposal 164 section 164.145):
 * the same argument names, the same partial-update rule (only fields the caller
 * supplied are touched), and the same `edit_posts` gate.
 *
 * ## Two corrections to how WordPress wrote
 *
 * - **`brand` is written to the meta key as well as the term.** The WordPress
 *   handler listed ten meta fields and left `brand` out of the loop, so an
 *   update changed the term and left the `brand` meta at its creation value.
 *   The row projection prefers the term, which is why the drift was invisible
 *   there — but an export reads the meta, and this toolkit's reports do too.
 *   The port writes both, as `create_reg_product` has always done.
 * - **The category is lower-cased, as create and list already treat it.** The
 *   WordPress update looked the term up with the caller's exact case while the
 *   listing filtered by lower-cased slug, so a category written here could be
 *   invisible to the listing that was meant to find it. One vocabulary now.
 *
 * An empty string for a meta field clears it, which is what WordPress did: the
 * value was written whenever the key was present, empty or not. A `name` of
 * spaces is refused instead of blanking the product, because a renamed product
 * with no name is a record nobody can find again.
 *
 * The response uses the same full projection as `get_reg_product` rather than
 * WordPress's reduced update row (which omitted `created_date`, `categories` and
 * the ingredient lists). A caller that just wrote a record should not have to
 * call a second tool to read what it wrote.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class UpdateRegProductTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'update_reg_product';
    }

    public function getName(): string
    {
        return 'Update Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Updates a regulatory product. Only the fields supplied are changed; an empty string clears a field. Name and description update the record title and body, category and brand update their taxonomies.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'         => array(
                    'type'        => 'integer',
                    'description' => 'The product id.',
                    'minimum'     => 1,
                ),
                'name'               => array(
                    'type'        => 'string',
                    'description' => 'New product name.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description'        => array(
                    'type'        => 'string',
                    'description' => 'New product description.',
                    'maxLength'   => 5000,
                ),
                'category'           => array(
                    'type'        => 'string',
                    'description' => 'New category. An empty string clears it.',
                    'enum'        => self::CATEGORIES,
                ),
                'brand'              => array(
                    'type'        => 'string',
                    'description' => 'New brand name. An empty string clears it.',
                    'maxLength'   => 100,
                ),
                'supplier_reference' => array(
                    'type'        => 'string',
                    'description' => 'Supplier reference code.',
                    'maxLength'   => 100,
                ),
                'item_group'         => array(
                    'type'        => 'string',
                    'description' => 'Item group or family.',
                    'maxLength'   => 100,
                ),
                'origin_country'     => array(
                    'type'        => 'string',
                    'description' => 'Country of origin.',
                    'maxLength'   => 100,
                ),
                'manufacturer'       => array(
                    'type'        => 'string',
                    'description' => 'Manufacturer name.',
                    'maxLength'   => 200,
                ),
                'inci_ingredients'   => array(
                    'type'        => 'string',
                    'description' => 'INCI ingredient list.',
                    'maxLength'   => 5000,
                ),
                'allergens'          => array(
                    'type'        => 'string',
                    'description' => 'Known allergens or restricted substances.',
                    'maxLength'   => 1000,
                ),
                'hs_code'            => array(
                    'type'        => 'string',
                    'description' => 'HS code / customs code.',
                    'maxLength'   => 50,
                ),
                'barcode'            => array(
                    'type'        => 'string',
                    'description' => 'Barcode or GTIN.',
                    'maxLength'   => 50,
                ),
                'pack_size'          => array(
                    'type'        => 'string',
                    'description' => 'Pack size in units.',
                    'maxLength'   => 100,
                ),
                'variant'            => array(
                    'type'        => 'string',
                    'description' => 'Variant such as size or concentration.',
                    'maxLength'   => 100,
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id   = (int) ($context['user_id'] ?? 0);
        $productId = max(0, $this->intParam($arguments, 'product_id'));

        if ($productId <= 0) {
            return $this->errors->validationFailed(
                'A product id is required.',
                array('product_id' => array('The id shown by list_reg_products or search_reg_products.'))
            );
        }

        $item = $this->content->find($productId);

        if (null === $item || self::PRODUCT_TYPE !== $item->type) {
            return $this->errors->notFound('Product not found.');
        }

        $title = null;

        if (array_key_exists('name', $arguments)) {
            $title = trim($this->stringParam($arguments, 'name'));

            if ('' === $title) {
                return $this->errors->validationFailed(
                    'The product cannot be updated.',
                    array('name' => array('A product name cannot be blank.'))
                );
            }
        }

        $content = array_key_exists('description', $arguments)
            ? $this->stringParam($arguments, 'description')
            : null;

        $meta = array();

        foreach (self::PRODUCT_FIELDS as $argument => $key) {
            if (! array_key_exists($argument, $arguments)) {
                continue;
            }

            $meta[ $key ] = trim($this->stringParam($arguments, $argument));
        }

        $taxonomy = array();
        $category = $this->termInput($arguments, 'category');

        if (null !== $category) {
            $taxonomy[ self::CATEGORY_TAX ] = $category;
        }

        $brand = $this->termInput($arguments, 'brand');

        if (null !== $brand) {
            $taxonomy[ self::BRAND_TAX ] = $brand;
        }

        try {
            $this->content->update(
                $productId,
                new UpdateContentCommand(
                    userId: $user_id,
                    title: $title,
                    content: $content,
                    meta: $meta,
                    taxonomyInput: $taxonomy,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Product not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('update_reg_product_failed', $e->getMessage());
        }

        $updated = $this->content->find($productId);
        $row     = $updated instanceof ContentItem && self::PRODUCT_TYPE === $updated->type
            ? $this->productRow($updated, $updated->meta, $this->terms($updated))
            : array('id' => $productId);

        return $this->success('Product updated successfully.', array('product' => $row));
    }

    /**
     * The complete term set for one taxonomy argument, or null when absent.
     *
     * An empty value is an explicit clear: the store contract reads a named
     * taxonomy with an empty list as "no terms", which is what writing an empty
     * string to the WordPress taxonomy did.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    private function termInput(array $arguments, string $argument): ?array
    {
        if (! array_key_exists($argument, $arguments)) {
            return null;
        }

        $value = trim($this->stringParam($arguments, $argument));

        if ('' === $value) {
            return array();
        }

        return array('category' === $argument ? strtolower($value) : $value);
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * @return array<string, array<int, string>>
     */
    private function terms(ContentItem $item): array
    {
        try {
            return $this->content->getTaxonomyTerms($item->id);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
