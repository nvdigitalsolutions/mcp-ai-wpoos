<?php

/**
 * Delete a regulatory product — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Delete_Reg_Product` (Proposal 164 section 164.145):
 * same slug, same single argument, and the `delete_posts` capability WordPress
 * checked rather than the family's `edit_posts`.
 *
 * ## The description no longer claims a cascade that does not happen
 *
 * WordPress said the delete "will also delete associated registrations and
 * documents". It does not: `wp_delete_post( $id, true )` removes one post, and
 * a registration or document that references the id in meta keeps existing with
 * a pointer to a record that is gone. In a compliance system an orphaned
 * registration is worse than a documented non-cascade, so the description says
 * what actually happens and a caller who wants the satellite records removed
 * does it explicitly.
 *
 * ## No `confirm` argument, and why that is not an oversight
 *
 * The WordPress tool did not ask for one, and unlike the Cloudways family there
 * is no stricter precedent to follow here. The annotation carries
 * `destructiveHint: true`, which is the mechanism a host uses to ask its user
 * before running the call; a second local flag would duplicate that gate rather
 * than strengthen it.
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class DeleteRegProductTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'delete_reg_product';
    }

    public function getName(): string
    {
        return 'Delete Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Permanently deletes a product master record. Irreversible. Registrations and documents that reference the product are not removed and become orphaned, so delete them first if they should go.';
    }

    public function getRequiredCapability(): string
    {
        return 'delete_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id' => array(
                    'type'        => 'integer',
                    'description' => 'The product id to delete.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'delete_posts');

        $user_id   = (int) ($context['user_id'] ?? 0);
        $productId = max(0, $this->intParam($arguments, 'product_id'));

        if ($productId <= 0) {
            return $this->errors->validationFailed(
                'A product id is required.',
                array('product_id' => array('The id shown by get_reg_product.'))
            );
        }

        $item = $this->content->find($productId);

        if (null === $item || self::PRODUCT_TYPE !== $item->type) {
            return $this->errors->notFound('Product not found.');
        }

        try {
            $this->content->delete($productId, $user_id);
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Product not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('delete_reg_product_failed', $e->getMessage());
        }

        return $this->success('Product deleted successfully.', array('product_id' => $productId));
    }
}
