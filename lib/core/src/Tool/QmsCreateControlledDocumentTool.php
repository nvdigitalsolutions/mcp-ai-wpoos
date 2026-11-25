<?php

/**
 * Create a controlled document.
 *
 * The only way a document enters the register, and it always enters in `draft`:
 * a document that could be created already released would have skipped the review
 * ISO 9001 §7.5.2 requires, and the audit trail would show a state with no
 * decision behind it.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ControlledDocumentInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class QmsCreateControlledDocumentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ControlledDocumentInterface $documents,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'qms_create_controlled_document';
    }

    public function getName(): string
    {
        return 'QMS: Create Controlled Document';
    }

    public function getDescription(): string
    {
        return 'Create a document in the controlled register. Always starts in draft; movement through review, approval and release is recorded separately.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'path' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative path, e.g. "qms/sop-001.md".',
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The document body.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Document title.',
                ),
                'revision' => array(
                    'type' => 'string',
                    'description' => 'Revision label. Default "A".',
                ),
                'review_due' => array(
                    'type' => 'string',
                    'description' => 'ISO 8601 date the first periodic review is due.',
                ),
            ),
            'required' => array('path', 'content'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (!$this->documents->isActive()) {
            return $this->errors->create(
                'no_qms_register',
                'No controlled-document register is configured on this host.',
            );
        }

        $path = $this->stringParam($arguments, 'path');
        $content = $arguments['content'] ?? null;

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        if (!is_string($content)) {
            return $this->errors->validationFailed(
                'The content is required.',
                array('content' => array('Give the document body as a string.')),
            );
        }

        try {
            $result = $this->documents->create($path, $content, array(
                'title' => $this->stringParam($arguments, 'title'),
                'revision' => $this->stringParam($arguments, 'revision', 'A'),
                'review_due' => $this->stringParam($arguments, 'review_due'),
                'owner' => isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : 0,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_create_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Created "%s" in draft at revision %s.', $result['title'], $result['revision']),
            $result,
        );
    }
}
