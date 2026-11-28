<?php

/**
 * Attach a document record — `mcp_ai_reg_document`.
 *
 * Port of `WP_MCP_AI_Tool_Upload_Reg_Document` (Proposal 164 section 164.148).
 * The record is the portable part; the bytes are not.
 *
 * ## `file_url` only, and the refusal says why
 *
 * WordPress accepted either a URL or base64 `file_data` and wrote the decoded
 * bytes into the uploads directory. This runtime has no media or filesystem
 * contract — nothing that owns a file's storage, lifecycle or deletion — so
 * writing bytes here would create files no other layer can account for. The
 * port accepts a URL and refuses `file_data` with a named error telling the
 * caller to upload through the host and pass the resulting URL. That is a
 * declared capability gap, not a stub: the record, its metadata and its
 * relations are fully written.
 *
 * The URL is checked for an http or https scheme. WordPress's `esc_url_raw()`
 * does not restrict the scheme, and a stored `javascript:` or `file:` URL is a
 * payload waiting for whichever surface later renders it as a link.
 *
 * ## One correction to the taxonomy
 *
 * WordPress set the `mcp_ai_doc_type` term to `sanitize_title( $type )` — the
 * slug — while the record's own `document_type` meta held the caller's text. A
 * reader that projected the terms therefore saw `certificate-of-analysis` where
 * the caller wrote `COA`. The port stores the term name as given, so the two
 * projections agree.
 *
 * A document must be attached to a product or a registration, and the target
 * must exist; WordPress refused otherwise and the port names which id failed.
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

class UploadRegDocumentTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'upload_reg_document';
    }

    public function getName(): string
    {
        return 'Upload Regulatory Document';
    }

    public function getDescription(): string
    {
        return 'Creates a regulatory document record attached to a product or registration, from a file URL. Binary file_data is refused in this runtime; upload through the host and pass the URL.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'title'           => array(
                    'type'        => 'string',
                    'description' => 'Document title.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'document_type'   => array(
                    'type'        => 'string',
                    'description' => 'Document type, e.g. LOA, FSC, COA, GMP, ISO, MSDS, artwork.',
                    'minLength'   => 1,
                    'maxLength'   => 100,
                ),
                'file_url'        => array(
                    'type'        => 'string',
                    'description' => 'URL of the stored file. http or https.',
                    'minLength'   => 1,
                    'maxLength'   => 2000,
                ),
                'file_data'       => array(
                    'type'        => 'string',
                    'description' => 'Base64 file data. Refused in this runtime; pass file_url instead.',
                ),
                'file_name'       => array(
                    'type'        => 'string',
                    'description' => 'File name, used with file_data.',
                    'maxLength'   => 200,
                ),
                'product_id'      => array(
                    'type'        => 'integer',
                    'description' => 'Product id to attach to. Provide this or registration_id.',
                    'minimum'     => 1,
                ),
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'Registration id to attach to. Provide this or product_id.',
                    'minimum'     => 1,
                ),
                'issue_date'      => array(
                    'type'        => 'string',
                    'description' => 'Issue date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'expiry_date'     => array(
                    'type'        => 'string',
                    'description' => 'Expiry date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'version'         => array(
                    'type'        => 'string',
                    'description' => 'Document version. Default 1.0.',
                    'default'     => '1.0',
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Notes.',
                    'maxLength'   => 5000,
                ),
            ),
            'required'             => array('title', 'document_type'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id      = (int) ($context['user_id'] ?? 0);
        $title        = trim($this->stringParam($arguments, 'title'));
        $documentType = trim($this->stringParam($arguments, 'document_type'));
        $errors       = array();

        if ('' === $title) {
            $errors['title'] = array('A document title is required.');
        }

        if ('' === $documentType) {
            $errors['document_type'] = array('A document type is required, e.g. LOA or COA.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The document cannot be created.', $errors);
        }

        if ('' !== trim($this->stringParam($arguments, 'file_data'))) {
            return $this->errors->create(
                'reg_document_upload_unavailable',
                'Binary uploads are not available in this runtime. Upload the file through the host and pass its URL as file_url.'
            );
        }

        $fileUrl = trim($this->stringParam($arguments, 'file_url'));

        if ('' === $fileUrl) {
            return $this->errors->validationFailed(
                'The document cannot be created.',
                array('file_url' => array('An http or https URL of the stored file.'))
            );
        }

        if (1 !== preg_match('#^https?://#i', $fileUrl)) {
            return $this->errors->validationFailed(
                'The document cannot be created.',
                array('file_url' => array('Only http and https URLs are accepted.'))
            );
        }

        $productId      = max(0, $this->intParam($arguments, 'product_id'));
        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));

        if ($productId <= 0 && $registrationId <= 0) {
            return $this->errors->validationFailed(
                'The document cannot be created.',
                array('product_id' => array('Provide a product id or a registration id.'))
            );
        }

        if ($productId > 0) {
            $product = $this->content->find($productId);

            if (null === $product || self::PRODUCT_TYPE !== $product->type) {
                return $this->errors->validationFailed(
                    'The document cannot be created.',
                    array('product_id' => array('No product exists with that id.'))
                );
            }
        }

        if ($registrationId > 0) {
            $registration = $this->content->find($registrationId);

            if (null === $registration || self::REGISTRATION_TYPE !== $registration->type) {
                return $this->errors->validationFailed(
                    'The document cannot be created.',
                    array('registration_id' => array('No registration exists with that id.'))
                );
            }
        }

        $meta   = array(
            'document_type' => $documentType,
            'file_url'      => $fileUrl,
            'version'       => '' !== trim($this->stringParam($arguments, 'version'))
                ? trim($this->stringParam($arguments, 'version'))
                : '1.0',
        );
        $errors = array();

        if ($productId > 0) {
            $meta['product_id'] = $productId;
        }

        if ($registrationId > 0) {
            $meta['registration_id'] = $registrationId;
        }

        foreach (array('issue_date', 'expiry_date') as $field) {
            $value = trim($this->stringParam($arguments, $field));

            if ('' === $value) {
                continue;
            }

            if (! $this->isIsoDate($value)) {
                $errors[ $field ] = array('An ISO date, YYYY-MM-DD.');
                continue;
            }

            $meta[ $field ] = $value;
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('One or more dates are unusable.', $errors);
        }

        try {
            $document = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::DOCUMENT_TYPE,
                    authorId: $user_id,
                    status: 'publish',
                    content: trim($this->stringParam($arguments, 'notes')),
                    meta: $meta,
                    taxonomyInput: array(self::DOC_TYPE_TAX => array($documentType)),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('upload_reg_document_failed', $e->getMessage());
        }

        return $this->success(
            'Document uploaded successfully.',
            array(
                'document_id' => $document->id,
                'file_url'    => $fileUrl,
            )
        );
    }
}
