<?php

/**
 * Read one document record — `mcp_ai_reg_document`.
 *
 * Port of `WP_MCP_AI_Tool_Get_Reg_Document` (Proposal 164 section 164.148).
 * The WordPress row shape is kept key for key, including the two references it
 * reports as strings and the `taxonomies.doc_types` list — with one correction
 * to that list, noted in `UploadRegDocumentTool`: the terms now hold the name
 * the caller wrote rather than its slug.
 *
 * ## The expiry arithmetic
 *
 * `expiry_status` is `expired`, `expiring_soon` (within 90 days) or `valid`, and
 * `days_to_expiry` is the floor of the day difference, present whenever an
 * expiry date exists and `null` when it does not. A date that cannot be parsed
 * is treated as no date: the WordPress branch would have compared `false` and
 * classified it as `expired`, which is the wrong direction to be wrong in.
 *
 * ## The two expansions
 *
 * `include_product` resolves the product to `{id, title, brand}` from its meta,
 * and `include_registration` resolves the registration to
 * `{id, title, country, cos_number}`. Both are looked up only when the
 * reference exists, so a document attached to one side does not gain an empty
 * object for the other.
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

class GetRegDocumentTool extends RegulatoryTool
{
    /** How many days ahead counts as expiring soon. */
    private const EXPIRING_SOON_DAYS = 90;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_reg_document';
    }

    public function getName(): string
    {
        return 'Get Regulatory Document';
    }

    public function getDescription(): string
    {
        return 'Returns one regulatory document with its type, file URL, version, expiry status and, optionally, its product and registration summaries.';
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
                'document_id'          => array(
                    'type'        => 'integer',
                    'description' => 'The document id.',
                    'minimum'     => 1,
                ),
                'include_product'      => array(
                    'type'        => 'boolean',
                    'description' => 'Append the product summary. Default false.',
                    'default'     => false,
                ),
                'include_registration' => array(
                    'type'        => 'boolean',
                    'description' => 'Append the registration summary. Default false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('document_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $documentId = max(0, $this->intParam($arguments, 'document_id'));

        if ($documentId <= 0) {
            return $this->errors->validationFailed(
                'A document id is required.',
                array('document_id' => array('The id shown by list_reg_documents.'))
            );
        }

        $item = $this->content->find($documentId);

        if (null === $item || self::DOCUMENT_TYPE !== $item->type) {
            return $this->errors->notFound(
                'Document not found.',
                array('document_id' => $documentId)
            );
        }

        $meta   = $item->meta;
        $expiry = $this->text($meta['expiry_date'] ?? null);
        $days   = $this->daysToExpiry($expiry);

        $row = array(
            'document_id'     => $item->id,
            'title'           => $item->title,
            'document_type'   => $this->text($meta['document_type'] ?? null),
            'file_url'        => $this->text($meta['file_url'] ?? null),
            'version'         => $this->text($meta['version'] ?? null),
            'issue_date'      => $this->text($meta['issue_date'] ?? null),
            'expiry_date'     => $expiry,
            'expiry_status'   => $this->expiryStatus($days),
            'days_to_expiry'  => $days,
            'status'          => $item->status,
            'notes'           => $item->content,
            'created_at'      => $item->createdAt->format('Y-m-d H:i:s'),
            'modified_at'     => $item->updatedAt->format('Y-m-d H:i:s'),
            'product_id'      => $this->text($meta['product_id'] ?? null),
            'registration_id' => $this->text($meta['registration_id'] ?? null),
            'taxonomies'      => array(
                'doc_types' => $this->termNames($this->terms($item), self::DOC_TYPE_TAX),
            ),
        );

        $productId = $this->intValue($meta['product_id'] ?? null);

        if ($this->boolParam($arguments, 'include_product') && $productId > 0) {
            $product = $this->content->find($productId);

            if ($product instanceof ContentItem && self::PRODUCT_TYPE === $product->type) {
                $row['product'] = array(
                    'id'    => $product->id,
                    'title' => $product->title,
                    'brand' => $this->text($product->meta['brand'] ?? null),
                );
            }
        }

        $registrationId = $this->intValue($meta['registration_id'] ?? null);

        if ($this->boolParam($arguments, 'include_registration') && $registrationId > 0) {
            $registration = $this->content->find($registrationId);

            if ($registration instanceof ContentItem && self::REGISTRATION_TYPE === $registration->type) {
                $row['registration'] = array(
                    'id'         => $registration->id,
                    'title'      => $registration->title,
                    'country'    => $this->text($registration->meta['country'] ?? null),
                    'cos_number' => $this->text($registration->meta['cos_number'] ?? null),
                );
            }
        }

        return $this->success('Document retrieved.', array('document' => $row));
    }

    /**
     * Whole days until an expiry date, or null when there is no usable one.
     */
    private function daysToExpiry(string $expiry): ?int
    {
        if ('' === $expiry) {
            return null;
        }

        $stamp = strtotime($expiry);

        if (false === $stamp) {
            return null;
        }

        return (int) floor(( $stamp - time() ) / 86400);
    }

    /**
     * The three-way classification, unchanged from WordPress's thresholds.
     */
    private function expiryStatus(?int $days): string
    {
        if (null === $days) {
            return 'valid';
        }

        if ($days < 0) {
            return 'expired';
        }

        return $days <= self::EXPIRING_SOON_DAYS ? 'expiring_soon' : 'valid';
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
