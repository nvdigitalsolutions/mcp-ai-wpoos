<?php

/**
 * Check that a product or registration carries every required document.
 *
 * Port of `WP_MCP_AI_Tool_Validate_Document_Checklist` (Proposal 164 section
 * 164.152). A fixed document-type table, filtered by country, against the
 * documents attached to one entity.
 *
 * ## The comparison was case-sensitive against a case-preserving store
 *
 * The required set is lowercase slugs (`loa`, `fsc`, `coa`, ...). A document's
 * type is written with `sanitize_text_field`, which preserves the caller's
 * spelling, and the checklist compared the two with `array_diff`, which is
 * case-sensitive. A document uploaded as `LoA` or `LOA` was therefore reported
 * missing while sitting in the store. Section 164.148 already recorded that the
 * port keeps the caller's spelling for the type; that decision makes the
 * case-sensitive comparison a live defect rather than a theoretical one, so the
 * port folds case before comparing and reports the stored spelling.
 *
 * ## An unparseable expiry date counted as expired
 *
 * `strtotime( $expiry_date ) < time()` is true when `strtotime` returns
 * `false`, so a document whose date could not be read made the checklist
 * non-compliant. That is the safe direction — a document that cannot be shown
 * valid is not shown valid — so the port keeps the verdict and names the value
 * in `unparsed_expiry_dates`, because a caller cannot fix a date it is not told
 * about.
 *
 * ## The entity is chosen, not merged
 *
 * When both ids are supplied, the registration wins. That was WordPress's
 * precedence and it is kept, but it is stated in the schema rather than left
 * for a caller to discover.
 *
 * ## `country` is looked up in upper case and returned as supplied
 *
 * `lk`, `LK` and `Lk` all select the Sri Lanka table; the response echoes what
 * the caller wrote. For a registration, an omitted country is filled from the
 * registration's own `country` meta, which is what makes the tool usable
 * without repeating data the record already holds.
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

class ValidateDocumentChecklistTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'validate_document_checklist';
    }

    public function getName(): string
    {
        return 'Validate Document Checklist';
    }

    public function getDescription(): string
    {
        return 'Checks that a product or registration carries every required document type for its country, reporting the present, missing and expired documents and a completion percentage. A document type is matched regardless of case; when both product_id and registration_id are supplied the registration is used.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress declares `edit_posts`; the port gates on `read` because the
        // tool mutates nothing, as section 164.150 decided for the family.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'      => array(
                    'type'        => 'integer',
                    'description' => 'The product id. Ignored when registration_id is supplied.',
                    'minimum'     => 1,
                ),
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration id. Takes precedence over product_id.',
                    'minimum'     => 1,
                ),
                'country'         => array(
                    'type'        => 'string',
                    'description' => 'Country code selecting the requirement table. Defaults to the registration country; otherwise the shared set applies.',
                    'maxLength'   => 10,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $registrationId = $this->intValue($arguments['registration_id'] ?? null);
        $productId      = $this->intValue($arguments['product_id'] ?? null);

        if ($registrationId < 1 && $productId < 1) {
            return $this->errors->validationFailed(
                'The checklist cannot run.',
                array('product_id' => array('Either product_id or registration_id must be provided.'))
            );
        }

        $entityType = $registrationId >= 1 ? 'registration' : 'product';
        $entityId   = $registrationId >= 1 ? $registrationId : $productId;
        $type       = 'registration' === $entityType ? self::REGISTRATION_TYPE : self::PRODUCT_TYPE;

        $entity = $this->content->find($entityId);

        if (! $entity instanceof ContentItem || $type !== $entity->type) {
            return $this->errors->notFound(sprintf('%s not found.', ucfirst($entityType)));
        }

        $country = trim($this->stringParam($arguments, 'country'));

        if ('registration' === $entityType && '' === $country) {
            $country = $this->text($entity->meta['country'] ?? null);
        }

        $required = $this->requiredDocuments($country);

        $attachKey = 'registration' === $entityType ? 'registration_id' : 'product_id';

        $documents = $this->collectContent(
            array(self::DOCUMENT_TYPE),
            array(array('key' => $attachKey, 'value' => $entityId, 'compare' => '='))
        );

        $present = array();
        $expired = array();
        $unparsed = array();

        foreach ($documents as $document) {
            $documentType = $this->documentType($document);

            if ('' === $documentType) {
                continue;
            }

            $present[] = $documentType;

            $expiry = $this->text($document->meta['expiry_date'] ?? null);

            if ('' === $expiry) {
                continue;
            }

            $stamp = strtotime($expiry);

            if (false === $stamp) {
                $unparsed[] = array(
                    'document_id'   => $document->id,
                    'document_type' => $documentType,
                    'expiry_date'   => $expiry,
                );

                $expired[] = array(
                    'document_id'   => $document->id,
                    'document_type' => $documentType,
                    'expiry_date'   => $expiry,
                    'reason'        => 'Expiry date could not be read; treated as expired.',
                );

                continue;
            }

            if ($stamp < time()) {
                $expired[] = array(
                    'document_id'   => $document->id,
                    'document_type' => $documentType,
                    'expiry_date'   => $expiry,
                );
            }
        }

        $presentKeys = array();

        foreach (array_unique($present) as $documentType) {
            $presentKeys[] = $this->foldDocType($documentType);
        }

        $missing = array();

        foreach ($required as $wanted) {
            if (! in_array($wanted, $presentKeys, true)) {
                $missing[] = $wanted;
            }
        }

        $totalRequired = count($required);
        $totalPresent  = $totalRequired - count($missing);
        $completion    = $totalRequired > 0
            ? round(( $totalPresent / $totalRequired ) * 100, 2)
            : 100.0;

        $isCompliant = array() === $missing && array() === $expired;
        $status      = $isCompliant
            ? 'compliant'
            : ( $totalPresent > 0 ? 'partially_compliant' : 'non_compliant' );

        return $this->success(
            $isCompliant
                ? 'All required documents are present and valid.'
                : sprintf(
                    'Missing %1$d required document(s). %2$d document(s) expired.',
                    count($missing),
                    count($expired)
                ),
            array(
                'entity_type'           => $entityType,
                'entity_id'             => $entityId,
                'country'               => $country,
                'is_compliant'          => $isCompliant,
                'status'                => $status,
                'completion_percentage' => $completion,
                'required_documents'    => $required,
                'present_documents'     => array_values(array_unique($present)),
                'missing_documents'     => $missing,
                'expired_documents'     => $expired,
                'unparsed_expiry_dates' => $unparsed,
                'total_required'        => $totalRequired,
                'total_present'         => $totalPresent,
                'total_missing'         => count($missing),
                'total_expired'         => count($expired),
            )
        );
    }

    /**
     * A document's type: the term when one is attached, else the meta value.
     */
    private function documentType(ContentItem $document): string
    {
        $type = $this->text($document->meta['document_type'] ?? null);

        try {
            $terms = $this->termNames(
                $this->content->getTaxonomyTerms($document->id),
                self::DOC_TYPE_TAX
            );
        } catch (\Throwable $e) {
            $terms = array();
        }

        return array() !== $terms ? $terms[0] : $type;
    }
}
