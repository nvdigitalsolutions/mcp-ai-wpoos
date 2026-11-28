<?php

/**
 * Build and store a submission pack for a registration.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Submission_Pack` (Proposal 164 section
 * 164.158). Checks the country's required documents are all attached, then
 * stores a pack manifest on the registration and returns the document list with
 * an optional cover letter and index.
 *
 * ## The flags that build the pack were off by default
 *
 * `include_cover_letter` and `include_index` are declared `true`. The handler
 * read each with `! empty( $arguments[ ... ] )`, so a bare
 * `generate_submission_pack({ registration_id })` returned
 * `cover_letter: null` and `document_index: null` — the two things the tool
 * exists to assemble, absent from the pack it stored. This is the fourth
 * instance of the fault §164.150, §164.151 and §164.152 each fixed in their own
 * tools, and the reason the family's rule is stated once: a default belongs in
 * `boolParam()`, never in `! empty()`.
 *
 * ## The same table, compared the same wrong way
 *
 * WordPress kept two copies of the required-document table — one here, one in
 * `validate_document_checklist` — so the two tools could disagree about what a
 * country requires. Both also compared lowercase slugs against a type stored
 * with the caller's spelling using `array_diff`, so a document uploaded as `LoA`
 * read as missing and the pack was refused. The ports read one table from
 * `RegulatoryTool` and compare case-folded, as §164.152 decided.
 *
 * ## A missing document is a refusal, not a verdict
 *
 * Unlike the validators, this tool cannot produce its artefact without the
 * documents: a pack that omits a required certificate is worse than no pack.
 * WordPress refused with its own error code and the missing list; the port
 * refuses through the canonical envelope, carrying the same list, so a caller
 * sees one error shape.
 *
 * ## What it does not do
 *
 * It writes no file. The cover letter and the index are strings, which is what
 * WordPress produced too; the tool that pretended otherwise was
 * `generate_pdf_dossier`, deferred with the PDF group. This pack is a manifest
 * plus text, and it says so.
 *
 * Gated on `edit_posts`: this tool writes the pack onto the registration.
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

class GenerateSubmissionPackTool extends RegulatoryTool
{
    /** The submission types this tool accepts. */
    private const SUBMISSION_TYPES = array('new', 'renewal', 'variation');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_submission_pack';
    }

    public function getName(): string
    {
        return 'Generate Submission Pack';
    }

    public function getDescription(): string
    {
        return 'Checks that a registration carries every document its country requires, then stores a submission pack manifest and returns the document list with a cover letter and an index. Refuses, naming the missing documents, when a required type is absent. Cover letter and index are included unless an explicit false disables them.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id'      => array(
                    'type'        => 'integer',
                    'description' => 'The registration to pack.',
                    'minimum'     => 1,
                ),
                'submission_type'      => array(
                    'type'        => 'string',
                    'description' => 'The kind of submission. Default new.',
                    'enum'        => self::SUBMISSION_TYPES,
                    'default'     => 'new',
                ),
                'include_cover_letter' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the cover letter. Default true.',
                    'default'     => true,
                ),
                'include_index'        => array(
                    'type'        => 'boolean',
                    'description' => 'Include the document index. Default true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('registration_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'edit_posts');

        $registrationId = $this->intValue($arguments['registration_id'] ?? null);

        if ($registrationId < 1) {
            return $this->errors->validationFailed(
                'The submission pack cannot be built.',
                array('registration_id' => array('A registration id of at least 1.'))
            );
        }

        $submissionType = $this->stringParam($arguments, 'submission_type', 'new');

        if (! in_array($submissionType, self::SUBMISSION_TYPES, true)) {
            return $this->errors->validationFailed(
                'The submission pack cannot be built.',
                array('submission_type' => array('One of: ' . implode(', ', self::SUBMISSION_TYPES) . '.'))
            );
        }

        $includeCoverLetter = $this->boolParam($arguments, 'include_cover_letter', true);
        $includeIndex       = $this->boolParam($arguments, 'include_index', true);

        $registration = $this->content->find($registrationId);

        if (! $registration instanceof ContentItem || self::REGISTRATION_TYPE !== $registration->type) {
            return $this->errors->notFound('Registration not found.');
        }

        $productId = $this->intValue($registration->meta['product_id'] ?? null);
        $country   = $this->text($registration->meta['country'] ?? null);
        $authority = $this->text($registration->meta['authority'] ?? null);

        $product = $productId >= 1 ? $this->content->find($productId) : null;

        if (! $product instanceof ContentItem || self::PRODUCT_TYPE !== $product->type) {
            return $this->errors->notFound('Associated product not found.');
        }

        $documents = $this->collectContent(
            array(self::DOCUMENT_TYPE),
            array(array('key' => 'registration_id', 'value' => $registrationId, 'compare' => '='))
        );

        $list = array();

        foreach ($documents as $document) {
            $list[] = array(
                'document_id'   => $document->id,
                'title'         => $document->title,
                'document_type' => $this->documentType($document),
                'file_url'      => $this->text($document->meta['file_url'] ?? null),
                'version'       => $this->text($document->meta['version'] ?? null),
            );
        }

        $required = $this->requiredDocuments($country);
        $present  = array();

        foreach ($list as $row) {
            if ('' !== $row['document_type']) {
                $present[] = $this->foldDocType($row['document_type']);
            }
        }

        $missing = array();

        foreach ($required as $wanted) {
            if (! in_array($wanted, $present, true)) {
                $missing[] = $wanted;
            }
        }

        if (array() !== $missing) {
            return $this->errors->validationFailed(
                'Cannot build the submission pack: a required document is missing.',
                array('missing_documents' => $missing)
            );
        }

        $generatedAt = $this->nowStamp();

        $pack = array(
            'registration_id' => $registrationId,
            'product_id'      => $productId,
            'country'         => $country,
            'authority'       => $authority,
            'submission_type' => $submissionType,
            'generated_date'  => $generatedAt,
            'document_count'  => count($list),
        );

        try {
            $this->content->update(
                $registrationId,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array(
                        'submission_pack'      => $pack,
                        'submission_pack_date' => $generatedAt,
                    ),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Registration not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('generate_submission_pack_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Submission pack generated with %d document(s).', count($list)),
            array(
                'registration_id' => $registrationId,
                'pack_data'       => $pack,
                'document_count'  => count($list),
                'documents'       => $list,
                'cover_letter'    => $includeCoverLetter
                    ? $this->coverLetter($product, $country, $authority, $submissionType)
                    : null,
                'document_index'  => $includeIndex
                    ? $this->documentIndex($list, $product, $country)
                    : null,
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

    /**
     * The cover letter, as WordPress wrote it.
     */
    private function coverLetter(
        ContentItem $product,
        string $country,
        string $authority,
        string $submissionType
    ): string {
        $date  = gmdate('F j, Y');
        $brand = $this->text($product->meta['brand'] ?? null);

        $letter  = 'Date: ' . $date . "\n\n";
        $letter .= 'To: ' . $authority . "\n";
        $letter .= 'Country: ' . $country . "\n\n";
        $letter .= 'Re: ' . ucfirst($submissionType) . ' Registration Application for ' . $product->title . "\n\n";
        $letter .= "Dear Sir/Madam,\n\n";
        $letter .= 'We hereby submit the complete application dossier for the '
            . strtolower($submissionType) . " registration of our product:\n\n";
        $letter .= 'Product Name: ' . $product->title . "\n";

        if ('' !== $brand) {
            $letter .= 'Brand: ' . $brand . "\n";
        }

        $letter .= "\nThe submission package includes all required documentation as per your regulatory requirements.\n\n";
        $letter .= "We remain available for any clarifications or additional information.\n\n";
        $letter .= "Sincerely,\n";
        $letter .= "[Company Name]\n";

        return $letter;
    }

    /**
     * The document index, as WordPress wrote it.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function documentIndex(array $documents, ContentItem $product, string $country): string
    {
        $index  = "DOCUMENT INDEX\n";
        $index .= "==============\n\n";
        $index .= 'Product: ' . $product->title . "\n";
        $index .= 'Country: ' . $country . "\n";
        $index .= 'Generated: ' . gmdate('F j, Y') . "\n\n";
        $index .= "Documents Included:\n\n";

        $counter = 1;

        foreach ($documents as $document) {
            $index .= sprintf(
                "%d. %s (%s) - Version %s\n",
                $counter,
                (string) $document['title'],
                (string) $document['document_type'],
                (string) $document['version']
            );

            ++$counter;
        }

        $index .= "\nTotal Documents: " . count($documents) . "\n";

        return $index;
    }
}
