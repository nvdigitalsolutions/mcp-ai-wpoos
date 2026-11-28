<?php

/**
 * Read one regulatory product — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Get_Reg_Product` (Proposal 164 section 164.144): the
 * same row shape, the same optional `include_registrations` expansion, and the
 * `read` capability WordPress gated it on.
 *
 * ## The registration list is bounded, and paged to reach the bound
 *
 * WordPress asked for up to 1000 registrations in a single `WP_Query`. The
 * store contract caps a page at 200, so the port reads pages of 200 until the
 * set is exhausted or 1000 records have been collected — the same ceiling,
 * reached in five requests instead of one. A product with more than 1000
 * registrations gets the first 1000, which is what WordPress returned too.
 *
 * ## The status comes from the term, and a missing term is not an error
 *
 * A registration's status lives in `mcp_ai_reg_status`; WordPress attached it
 * when present and omitted the key otherwise. The port does the same, so a
 * registration row here always has the same keys as one built there.
 *
 * A wrong-type id reads as "not found" rather than as a type error: the store's
 * `find()` already refuses a type mismatch, and telling a caller that record 12
 * exists but is not a product discloses nothing useful.
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
use Nvoos\Core\Domain\Entity\ContentQuery;

class GetRegProductTool extends RegulatoryTool
{
    /** The most registrations one call will list, as WordPress capped it. */
    private const MAX_REGISTRATIONS = 1000;

    /** The store's per-page ceiling. */
    private const REGISTRATION_PAGE = 200;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_reg_product';
    }

    public function getName(): string
    {
        return 'Get Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Returns one regulatory product with its full metadata, category and brand, and optionally its registrations with status, dates and COS numbers.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'            => array(
                    'type'        => 'integer',
                    'description' => 'The product id.',
                    'minimum'     => 1,
                ),
                'include_registrations' => array(
                    'type'        => 'boolean',
                    'description' => 'Append the registrations for this product. Default false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

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

        $row = $this->productRow($item, $item->meta, $this->terms($item));

        if ($this->boolParam($arguments, 'include_registrations')) {
            $registrations = $this->registrationsFor($productId);

            $row['registrations']      = $registrations;
            $row['registration_count'] = count($registrations);
        }

        return $this->success('Product retrieved.', array('product' => $row));
    }

    /**
     * The registrations that point at one product.
     *
     * @return list<array<string, mixed>>
     */
    private function registrationsFor(int $productId): array
    {
        $rows = array();
        $page = 1;

        while (count($rows) < self::MAX_REGISTRATIONS) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: array(self::REGISTRATION_TYPE),
                        statuses: array('publish'),
                        metaQuery: array(
                            array('key' => 'product_id', 'value' => $productId, 'compare' => '='),
                        ),
                        orderBy: 'date',
                        order: 'DESC',
                        page: $page,
                        perPage: self::REGISTRATION_PAGE,
                    )
                );
            } catch (\Throwable $e) {
                break;
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
                    continue;
                }

                $rows[] = $this->registrationExpansionRow($item);
                ++$fetched;

                if (count($rows) >= self::MAX_REGISTRATIONS) {
                    break;
                }
            }

            if ($fetched < self::REGISTRATION_PAGE) {
                break;
            }

            ++$page;
        }

        return $rows;
    }

    /**
     * One registration row, in the shape `get_reg_product` has always used.
     *
     * @return array<string, mixed>
     */
    private function registrationExpansionRow(ContentItem $item): array
    {
        $meta = $item->meta;

        $row = array(
            'id'                => $item->id,
            'title'             => $item->title,
            'country'           => $this->text($meta['country'] ?? null),
            'authority'         => $this->text($meta['authority'] ?? null),
            'registration_type' => $this->text($meta['registration_type'] ?? null),
            'cos_number'        => $this->text($meta['cos_number'] ?? null),
            'submission_date'   => $this->text($meta['submission_date'] ?? null),
            'approval_date'     => $this->text($meta['approval_date'] ?? null),
            'expiry_date'       => $this->text($meta['expiry_date'] ?? null),
        );

        $statuses = $this->termNames($this->terms($item), self::STATUS_TAX);

        if (array() !== $statuses) {
            $row['status'] = $statuses[0];
        }

        return $row;
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
