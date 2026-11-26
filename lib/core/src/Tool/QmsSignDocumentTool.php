<?php

/**
 * Sign a controlled document.
 *
 * Records who signed, when, and — per 21 CFR Part 11 §11.50 — *what the signature
 * means*, from a closed list ({@see \Nvoos\Core\Domain\Qms\DocumentLifecycle::MEANINGS}).
 * A signature does not change the lifecycle state: it is evidence attached to a
 * state, and conflating the two would let a signature move a document it is only
 * supposed to attest.
 *
 * ## The honesty boundary
 *
 * §11.100/§11.200 require a signature to be verified by two distinct components —
 * an identification plus a re-entered credential. This runtime has a user id and
 * no re-authentication ceremony, so what this records is an **attributed action**,
 * not a verified electronic signature. The payload says `attributed: true` so a
 * caller in a regulated environment can see exactly what it has.
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
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Qms\DocumentLifecycle;

class QmsSignDocumentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ControlledDocumentInterface $documents,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'qms_sign_document';
    }

    public function getName(): string
    {
        return 'QMS: Sign Document';
    }

    public function getDescription(): string
    {
        return 'Record an attributed signature against a controlled document, naming the meaning (authored, reviewed, approved, released, witnessed, withdrawn). This is not a verified Part 11 e-signature: the host has no re-authentication ceremony.';
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
                'meaning' => array(
                    'type' => 'string',
                    'description' => 'Why the signature is being applied. Required by 21 CFR Part 11 §11.50.',
                    'enum' => DocumentLifecycle::MEANINGS,
                ),
                'comment' => array(
                    'type' => 'string',
                    'description' => 'Optional note, recorded verbatim in the audit trail.',
                ),
            ),
            'required' => array('path', 'meaning'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * A signature is an append to the record, so it is a write but not destructive.
     *
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
        $meaning = strtolower($this->stringParam($arguments, 'meaning'));

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        if (!DocumentLifecycle::isMeaning($meaning)) {
            return $this->errors->validationFailed(
                'Choose a meaning: ' . implode(', ', DocumentLifecycle::MEANINGS) . '.',
                array('meaning' => array('Unknown or missing meaning.')),
            );
        }

        try {
            $result = $this->documents->sign($path, array(
                'meaning' => $meaning,
                'comment' => $this->stringParam($arguments, 'comment'),
                'user_id' => isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : 0,
            ));
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_sign_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Recorded a "%s" signature at revision %s.', $result['meaning'], $result['revision']),
            $result + array('attributed' => true),
        );
    }
}
