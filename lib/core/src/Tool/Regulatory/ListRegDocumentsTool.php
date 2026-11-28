<?php

/**
 * List document records — `mcp_ai_reg_document`.
 *
 * Port of `WP_MCP_AI_Tool_List_Reg_Documents` (Proposal 164 section 164.149):
 * product/registration, type and expiry-status filters, newest-modified first,
 * 20 per page and 100 at most.
 *
 * ## The expiry filter was applied after pagination
 *
 * WordPress ran the query with a page size, then skipped rows in PHP by their
 * expiry classification — so `total` and `total_pages` counted rows the caller
 * never saw, and a page could come back partly empty while more matches
 * existed. The port reads the matching set (up to the family's 1000-row cap),
 * filters it, and only then paginates, so the counts describe the result.
 *
 * ## Product and registration are an OR, as they were
 *
 * Providing both ids matches documents attached to *either* one; WordPress
 * built a `relation => OR` group. That is kept, because a document typically
 * carries one reference and an AND would return nothing for a pair.
 *
 * ## `status` is the record status, not a meta key nobody wrote
 *
 * WordPress projected `status` from a `status` post meta that no tool ever
 * set, so the field was always empty in this listing while
 * `get_reg_document` reported the content status. The port reports the content
 * status in both places.
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

class ListRegDocumentsTool extends RegulatoryTool
{
    public const MAX_PER_PAGE = 100;

    /** The most rows one call will classify, as the family's ceiling. */
    private const MAX_ROWS = 1000;

    /** The store's per-page ceiling. */
    private const PAGE_SIZE = 200;

    /** The expiry classifications a caller may filter on. */
    public const STATUSES = array('valid', 'expired', 'expiring_soon');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_reg_documents';
    }

    public function getName(): string
    {
        return 'List Regulatory Documents';
    }

    public function getDescription(): string
    {
        return 'Lists document records newest-modified first, filtered by product, registration, document type or expiry status, with page and per_page pagination.';
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
                'product_id'      => array(
                    'type'        => 'integer',
                    'description' => 'Match documents attached to this product, or to the registration below.',
                    'minimum'     => 1,
                ),
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'Match documents attached to this registration, or to the product above.',
                    'minimum'     => 1,
                ),
                'document_type'   => array(
                    'type'        => 'string',
                    'description' => 'Filter by document type name.',
                    'maxLength'   => 100,
                ),
                'status'          => array(
                    'type'        => 'string',
                    'description' => 'Filter by expiry classification.',
                    'enum'        => self::STATUSES,
                ),
                'page'            => array(
                    'type'        => 'integer',
                    'description' => 'Page number, 1-based.',
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'        => array(
                    'type'        => 'integer',
                    'description' => 'Documents per page. Default 20, maximum 100.',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $page    = max($this->intParam($arguments, 'page', 1), 1);
        $perPage = min(max($this->intParam($arguments, 'per_page', 20), 1), self::MAX_PER_PAGE);
        $productId      = max(0, $this->intParam($arguments, 'product_id'));
        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));
        $documentType   = trim($this->stringParam($arguments, 'document_type'));
        $statusFilter   = trim($this->stringParam($arguments, 'status'));

        if ('' !== $statusFilter && ! in_array($statusFilter, self::STATUSES, true)) {
            return $this->errors->validationFailed(
                'A valid expiry status is required.',
                array('status' => array('One of: ' . implode(', ', self::STATUSES) . '.'))
            );
        }

        $meta = array();

        if ($productId > 0 || $registrationId > 0) {
            $clauses = array('relation' => 'OR');

            if ($productId > 0) {
                $clauses[] = array('key' => 'product_id', 'value' => $productId, 'compare' => '=');
            }

            if ($registrationId > 0) {
                $clauses[] = array('key' => 'registration_id', 'value' => $registrationId, 'compare' => '=');
            }

            $meta[] = $clauses;
        }

        $tax = array();

        if ('' !== $documentType) {
            $tax[] = array('taxonomy' => self::DOC_TYPE_TAX, 'field' => 'name', 'terms' => $documentType);
        }

        $rows  = array();
        $count = 0;
        $paged = 1;

        while ($count < self::MAX_ROWS) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: array(self::DOCUMENT_TYPE),
                        statuses: array('publish'),
                        metaQuery: $meta,
                        taxQuery: $tax,
                        orderBy: 'modified',
                        order: 'DESC',
                        page: $paged,
                        perPage: self::PAGE_SIZE,
                    )
                );
            } catch (\Throwable $e) {
                return $this->errors->create('list_reg_documents_failed', $e->getMessage());
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::DOCUMENT_TYPE !== $item->type) {
                    continue;
                }

                $row = $this->documentRow($item);

                if ('' === $statusFilter || $statusFilter === $row['expiry_status']) {
                    $rows[] = $row;
                }

                ++$count;
                ++$fetched;

                if ($count >= self::MAX_ROWS) {
                    break;
                }
            }

            if ($fetched < self::PAGE_SIZE) {
                break;
            }

            ++$paged;
        }

        $total      = count($rows);
        $totalPages = (int) ceil($total / max(1, $perPage));
        $slice      = array_slice($rows, ( $page - 1 ) * $perPage, $perPage);

        return $this->success(
            sprintf('Found %d documents matching your criteria.', $total),
            array(
                'documents'   => $slice,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
            )
        );
    }

    /**
     * One document row, in the shape this listing has always used.
     *
     * @return array<string, mixed>
     */
    private function documentRow(ContentItem $item): array
    {
        $meta   = $item->meta;
        $expiry = $this->text($meta['expiry_date'] ?? null);
        $days   = null;

        if ('' !== $expiry) {
            $stamp = strtotime($expiry);

            if (false !== $stamp) {
                $days = (int) floor(( $stamp - time() ) / 86400);
            }
        }

        $isExpired    = null !== $days && $days < 0;
        $expiringSoon = null !== $days && $days >= 0 && $days <= 90;
        $documentType = $this->text($meta['document_type'] ?? null);
        $types        = $this->termNames($this->terms($item), self::DOC_TYPE_TAX);

        if (array() !== $types) {
            $documentType = $types[0];
        }

        return array(
            'id'              => $item->id,
            'title'           => $item->title,
            'description'     => $item->content,
            'document_type'   => $documentType,
            'status'          => $item->status,
            'issue_date'      => $this->text($meta['issue_date'] ?? null),
            'expiry_date'     => $expiry,
            'file_url'        => $this->text($meta['file_url'] ?? null),
            'version'         => $this->text($meta['version'] ?? null),
            'product_id'      => $this->intValue($meta['product_id'] ?? null),
            'registration_id' => $this->intValue($meta['registration_id'] ?? null),
            'is_expired'      => $isExpired,
            'expiring_soon'   => $expiringSoon,
            'days_to_expiry'  => $days,
            'expiry_status'   => $isExpired ? 'expired' : ( $expiringSoon ? 'expiring_soon' : 'valid' ),
        );
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
