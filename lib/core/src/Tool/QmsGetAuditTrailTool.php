<?php

/**
 * Read a controlled document's audit trail.
 *
 * The trail is the evidence 21 CFR Part 11 §11.10(e) asks for: computer-generated,
 * time-stamped, and refusing to obscure what was recorded before. There is nothing
 * to update and nothing to delete — this tool reads, and only reads.
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
use Nvoos\Core\Domain\Error\NotFoundException;

class QmsGetAuditTrailTool extends AbstractTool
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
        return 'qms_get_audit_trail';
    }

    public function getName(): string
    {
        return 'QMS: Get Audit Trail';
    }

    public function getDescription(): string
    {
        return 'Read the append-only audit trail of a controlled document, oldest first: every creation, transition and signature with its timestamp, actor and reason.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'path' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative path of the controlled document.',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum entries to return. Default 100, capped at ' . self::MAX_LIMIT . '.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ),
            ),
            'required' => array('path'),
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

        $path = $this->stringParam($arguments, 'path');

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', 100)));

        try {
            $result = $this->documents->audit($path, $limit);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_audit_failed', $e->getMessage());
        }

        return $this->collection(
            sprintf('Found %d audit entr%s.', $result['total'], 1 === $result['total'] ? 'y' : 'ies'),
            $result['entries'],
            $result['total'],
        );
    }
}
