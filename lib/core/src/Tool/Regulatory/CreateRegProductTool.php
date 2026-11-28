<?php

/**
 * Create a regulatory product master — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Create_Reg_Product` (Proposal 164 section 164.144):
 * same slug, same fourteen arguments, the same unprefixed meta keys, and the
 * same single required field (`product_name`).
 *
 * ## Three decisions
 *
 * - **The category and the brand are written twice, as WordPress wrote them.**
 *   `category` becomes a term in `mcp_ai_reg_category` (lower-cased, as the
 *   WordPress lookup was by slug), and `brand` becomes a term in
 *   `mcp_ai_reg_brand` *and* the `brand` meta key. The term is what
 *   `list_reg_products` filters on; the meta is what a product row reports when
 *   a host has no term store. WordPress also created a missing term; term
 *   creation is the store's policy here, stated rather than assumed.
 * - **`description` is content, not meta.** WordPress stored it as the post body
 *   through `wp_kses_post()`. This runtime has no KSES filter, so the value is
 *   trimmed and passed through; a host that needs HTML sanitisation applies it
 *   at the render boundary, which is the only place it can be applied correctly.
 * - **`edit_url` is present and null.** There is no admin edit screen in this
 *   runtime; the key is kept so a shape-compatible consumer does not have to
 *   distinguish "absent because of the host" from "absent because the tool
 *   changed".
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;

class CreateRegProductTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'create_reg_product';
    }

    public function getName(): string
    {
        return 'Create Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Creates a regulatory product master record with brand, INCI ingredients, HS code, barcode, pack size and origin country. Product name is required.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_name'       => array(
                    'type'        => 'string',
                    'description' => 'Product name.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'brand'              => array(
                    'type'        => 'string',
                    'description' => 'Brand name.',
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
                'category'           => array(
                    'type'        => 'string',
                    'description' => 'Product category.',
                    'enum'        => self::CATEGORIES,
                ),
                'description'        => array(
                    'type'        => 'string',
                    'description' => 'Detailed product description.',
                    'maxLength'   => 5000,
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
            'required'             => array('product_name'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id = (int) ($context['user_id'] ?? 0);
        $name    = trim($this->stringParam($arguments, 'product_name'));

        if ('' === $name) {
            return $this->errors->validationFailed(
                'The product cannot be created.',
                array('product_name' => array('A product name is required.'))
            );
        }

        $meta = array();

        foreach (self::PRODUCT_FIELDS as $argument => $key) {
            $value = trim($this->stringParam($arguments, $argument));

            if ('' !== $value) {
                $meta[ $key ] = $value;
            }
        }

        $taxonomy = array();
        $category = strtolower(trim($this->stringParam($arguments, 'category')));

        if ('' !== $category) {
            $taxonomy[ self::CATEGORY_TAX ] = array($category);
        }

        $brand = trim($this->stringParam($arguments, 'brand'));

        if ('' !== $brand) {
            $taxonomy[ self::BRAND_TAX ] = array($brand);
        }

        try {
            $product = $this->content->create(
                new CreateContentCommand(
                    title: $name,
                    type: self::PRODUCT_TYPE,
                    authorId: $user_id,
                    status: 'publish',
                    content: trim($this->stringParam($arguments, 'description')),
                    meta: $meta,
                    taxonomyInput: $taxonomy,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('create_reg_product_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Product "%s" created successfully.', $name),
            array(
                'product_id' => $product->id,
                'edit_url'   => null,
            )
        );
    }
}
