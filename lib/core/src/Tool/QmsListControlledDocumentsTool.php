<?php

/**
 * List controlled documents, optionally by state.
 *
 * Only documents carrying a lifecycle state appear. A file in the corpus that was
 * never created through the register is not a controlled record, and listing it as
 * one would claim a review history that does not exist.
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
use Nvoos\Core\Domain\Qms\DocumentLifecycle;

class QmsListControlledDocumentsTool extends AbstractTool
{
    private const MAX_LIMIT = 500;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ControlledDocumentInterface $documents,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'qms_list_controlled_documents';
    }

    public function getName(): string
    {
        return 'QMS: List Controlled Documents';
    }

    public function getDescription(): string
    {
        return 'List the documents in the controlled register with their state, revision, review due date and signature count. Filter by state.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'status' => array(
                    'type' => 'string',
                    'description' => 'Only documents in this state. Omit for all.',
                    'enum' => DocumentLifecycle::STATES,
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum documents to return. Default 100, capped at ' . self::MAX_LIMIT . '.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (!$this->documents->isActive()) {
            return $this->errors->create(
                'no_qms_register',
                'No controlled-document register is configured on this host.',
            );
        }

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', 100)));

        try {
            $result = $this->documents->list($this->stringParam($arguments, 'status'), $limit);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_list_failed', $e->getMessage());
        }

        return $this->collection(
            sprintf('Found %d controlled document(s).', $result['total']),
            $result['documents'],
            $result['total'],
        );
    }
}
