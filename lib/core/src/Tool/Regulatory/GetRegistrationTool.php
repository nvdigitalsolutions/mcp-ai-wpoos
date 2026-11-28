<?php

/**
 * Read one registration — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Get_Registration` (Proposal 164 section 164.146): the
 * full row, the expiry arithmetic, and the two optional expansions.
 *
 * ## The expiry flags are computed here, and the clock is the server's
 *
 * WordPress computed `days_to_expiry` as `floor( ( strtotime( expiry ) - time() )
 * / DAY_IN_SECONDS )` and derived `is_expired` and `expiring_soon` (within 90
 * days) from it. The port does the same arithmetic, so a row assembled here and
 * a row assembled there agree on the same day. A date that cannot be parsed
 * yields no flags rather than a wrong one: WordPress would have compared `false`
 * and reported a date decades in the past, which is the one failure mode that
 * matters in an expiry system.
 *
 * ## Documents are found by `registration_id`, not `product_id`
 *
 * A document may carry either reference — `duplicate_reg_product` copies the
 * product one — but this tool lists the documents attached to *this
 * registration*, which is the `registration_id` meta. The rows keep their
 * WordPress shape: `type` is the `document_type` meta and `status` is the
 * document's own `status` meta, not a term.
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

class GetRegistrationTool extends RegulatoryTool
{
    /** How many days ahead counts as expiring soon, as WordPress defined it. */
    private const EXPIRING_SOON_DAYS = 90;

    /** The most documents one call will list. */
    private const MAX_DOCUMENTS = 1000;

    /** The store's per-page ceiling. */
    private const DOCUMENT_PAGE = 200;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_registration';
    }

    public function getName(): string
    {
        return 'Get Registration';
    }

    public function getDescription(): string
    {
        return 'Returns one registration with status, dates, expiry flags and note body, optionally with its product summary and its documents.';
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
                'registration_id'   => array(
                    'type'        => 'integer',
                    'description' => 'The registration id.',
                    'minimum'     => 1,
                ),
                'include_product'   => array(
                    'type'        => 'boolean',
                    'description' => 'Append the product summary. Default false.',
                    'default'     => false,
                ),
                'include_documents' => array(
                    'type'        => 'boolean',
                    'description' => 'Append the documents attached to this registration. Default false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('registration_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));

        if ($registrationId <= 0) {
            return $this->errors->validationFailed(
                'A registration id is required.',
                array('registration_id' => array('The id shown by list_registrations.'))
            );
        }

        $item = $this->content->find($registrationId);

        if (null === $item || self::REGISTRATION_TYPE !== $item->type) {
            return $this->errors->notFound('Registration not found.');
        }

        $row = $this->registrationRow($item, $item->meta, $this->terms($item));
        $row = $this->withExpiryFlags($row);

        if ($this->boolParam($arguments, 'include_product') && $row['product_id'] > 0) {
            $product = $this->content->find($row['product_id']);

            if ($product instanceof ContentItem && self::PRODUCT_TYPE === $product->type) {
                $row['product'] = array(
                    'id'           => $product->id,
                    'name'         => $product->title,
                    'brand'        => $this->text($product->meta['brand'] ?? null),
                    'manufacturer' => $this->text($product->meta['manufacturer'] ?? null),
                );
            }
        }

        if ($this->boolParam($arguments, 'include_documents')) {
            $documents = $this->documentsFor($registrationId);

            $row['documents']      = $documents;
            $row['document_count'] = count($documents);
        }

        return $this->success('Registration retrieved.', array('registration' => $row));
    }

    /**
     * Add the expiry flags a row with an expiry date carries.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withExpiryFlags(array $row): array
    {
        $expiry = $row['expiry_date'] ?? '';

        if (! is_string($expiry) || '' === $expiry) {
            return $row;
        }

        $stamp = strtotime($expiry);

        if (false === $stamp) {
            return $row;
        }

        $days = (int) floor(( $stamp - time() ) / 86400);

        $row['days_to_expiry'] = $days;
        $row['is_expired']     = $days < 0;
        $row['expiring_soon']  = $days >= 0 && $days <= self::EXPIRING_SOON_DAYS;

        return $row;
    }

    /**
     * The documents attached to one registration.
     *
     * @return list<array<string, mixed>>
     */
    private function documentsFor(int $registrationId): array
    {
        $rows = array();
        $page = 1;

        while (count($rows) < self::MAX_DOCUMENTS) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: array(self::DOCUMENT_TYPE),
                        statuses: array('publish'),
                        metaQuery: array(
                            array('key' => 'registration_id', 'value' => $registrationId, 'compare' => '='),
                        ),
                        orderBy: 'date',
                        order: 'DESC',
                        page: $page,
                        perPage: self::DOCUMENT_PAGE,
                    )
                );
            } catch (\Throwable $e) {
                break;
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::DOCUMENT_TYPE !== $item->type) {
                    continue;
                }

                $meta = $item->meta;

                $rows[] = array(
                    'id'          => $item->id,
                    'title'       => $item->title,
                    'type'        => $this->text($meta['document_type'] ?? null),
                    'status'      => $this->text($meta['status'] ?? null),
                    'issue_date'  => $this->text($meta['issue_date'] ?? null),
                    'expiry_date' => $this->text($meta['expiry_date'] ?? null),
                );

                ++$fetched;

                if (count($rows) >= self::MAX_DOCUMENTS) {
                    break;
                }
            }

            if ($fetched < self::DOCUMENT_PAGE) {
                break;
            }

            ++$page;
        }

        return $rows;
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
