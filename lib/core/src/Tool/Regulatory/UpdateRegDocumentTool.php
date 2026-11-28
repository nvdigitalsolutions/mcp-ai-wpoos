<?php

/**
 * Update a document record — `mcp_ai_reg_document`.
 *
 * Port of `WP_MCP_AI_Tool_Update_Reg_Document` (Proposal 164 section 164.149):
 * a partial update of the title, notes, document status, type, dates and
 * version.
 *
 * ## The status argument used to trash records
 *
 * WordPress mapped `approved` to `publish` and **`rejected` to `trash`**. A
 * caller recording a rejection therefore removed the document from every
 * listing that queries published content — the opposite of what the word
 * suggests, and the exact record an audit asks for. The port keeps the mapping,
 * because a rejection that keeps the record published would be a different
 * tool, but it says so in the description and the readiness reason. The other
 * mappings (`draft`, `pending`) are unchanged.
 *
 * ## `updated_fields` is honest about what changed
 *
 * WordPress listed a field as updated whenever the caller *provided* it, even
 * when the value was empty and nothing was written. The port reports only the
 * fields it actually wrote. It also validates the two dates as ISO: WordPress
 * wrote whatever arrived into `issue_date` and `expiry_date`, and those strings
 * drive the expiry classification.
 *
 * The document type term is written as the caller's text rather than a slug,
 * for the reason recorded in §164.148.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class UpdateRegDocumentTool extends RegulatoryTool
{
    /** Caller status to content status. `rejected` hides the record, as it did. */
    public const STATUS_MAP = array(
        'draft'    => 'draft',
        'pending'  => 'pending',
        'approved' => 'publish',
        'rejected' => 'trash',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'update_reg_document';
    }

    public function getName(): string
    {
        return 'Update Regulatory Document';
    }

    public function getDescription(): string
    {
        return 'Updates a document record. Only the fields supplied change. Status approved publishes and status rejected moves the record to trash, removing it from published listings, as the WordPress tool did.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'document_id'   => array(
                    'type'        => 'integer',
                    'description' => 'The document id.',
                    'minimum'     => 1,
                ),
                'title'         => array(
                    'type'        => 'string',
                    'description' => 'Document title.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'document_type' => array(
                    'type'        => 'string',
                    'description' => 'Document type.',
                    'minLength'   => 1,
                    'maxLength'   => 100,
                ),
                'issue_date'    => array(
                    'type'        => 'string',
                    'description' => 'Issue date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'expiry_date'   => array(
                    'type'        => 'string',
                    'description' => 'Expiry date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'version'       => array(
                    'type'        => 'string',
                    'description' => 'Document version.',
                    'maxLength'   => 50,
                ),
                'status'        => array(
                    'type'        => 'string',
                    'description' => 'Document status. rejected moves it to trash.',
                    'enum'        => array('draft', 'pending', 'approved', 'rejected'),
                ),
                'notes'         => array(
                    'type'        => 'string',
                    'description' => 'Notes. Replaces the existing notes.',
                    'maxLength'   => 5000,
                ),
            ),
            'required'             => array('document_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id    = (int) ($context['user_id'] ?? 0);
        $documentId = max(0, $this->intParam($arguments, 'document_id'));

        if ($documentId <= 0) {
            return $this->errors->validationFailed(
                'A document id is required.',
                array('document_id' => array('The id shown by list_reg_documents.'))
            );
        }

        $item = $this->content->find($documentId);

        if (null === $item || self::DOCUMENT_TYPE !== $item->type) {
            return $this->errors->notFound('Document not found.');
        }

        $updated = array();
        $meta    = array();
        $title   = null;
        $content = null;
        $status  = null;

        if (array_key_exists('title', $arguments)) {
            $value = trim($this->stringParam($arguments, 'title'));

            if ('' === $value) {
                return $this->errors->validationFailed(
                    'The document cannot be updated.',
                    array('title' => array('A document title cannot be blank.'))
                );
            }

            $title     = $value;
            $updated[] = 'title';
        }

        if (array_key_exists('notes', $arguments)) {
            $content   = $this->stringParam($arguments, 'notes');
            $updated[] = 'notes';
        }

        if (array_key_exists('status', $arguments)) {
            $requested = trim($this->stringParam($arguments, 'status'));

            if (! isset(self::STATUS_MAP[ $requested ])) {
                return $this->errors->validationFailed(
                    'A valid document status is required.',
                    array('status' => array('One of: ' . implode(', ', array_keys(self::STATUS_MAP)) . '.'))
                );
            }

            $status    = self::STATUS_MAP[ $requested ];
            $updated[] = 'status';
        }

        $errors = array();

        foreach (array('issue_date', 'expiry_date') as $field) {
            if (! array_key_exists($field, $arguments)) {
                continue;
            }

            $value = trim($this->stringParam($arguments, $field));

            if (! $this->isIsoDate($value)) {
                $errors[ $field ] = array('An ISO date, YYYY-MM-DD.');
                continue;
            }

            $meta[ $field ] = $value;
            $updated[]      = $field;
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('One or more dates are unusable.', $errors);
        }

        $taxonomy = array();

        if (array_key_exists('document_type', $arguments)) {
            $type = trim($this->stringParam($arguments, 'document_type'));

            if ('' === $type) {
                return $this->errors->validationFailed(
                    'The document cannot be updated.',
                    array('document_type' => array('A document type cannot be blank.'))
                );
            }

            $meta['document_type']   = $type;
            $taxonomy[ self::DOC_TYPE_TAX ] = array($type);
            $updated[]               = 'document_type';
        }

        if (array_key_exists('version', $arguments)) {
            $meta['version'] = trim($this->stringParam($arguments, 'version'));
            $updated[]       = 'version';
        }

        try {
            $this->content->update(
                $documentId,
                new UpdateContentCommand(
                    userId: $user_id,
                    title: $title,
                    content: $content,
                    status: $status,
                    meta: $meta,
                    taxonomyInput: $taxonomy,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Document not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('update_reg_document_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Document updated successfully. Updated fields: %s', implode(', ', $updated)),
            array(
                'document_id'    => $documentId,
                'updated_fields' => $updated,
            )
        );
    }
}
