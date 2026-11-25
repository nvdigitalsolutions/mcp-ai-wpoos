<?php

/**
 * The shared body of a lifecycle transition tool.
 *
 * Proposal 164 §164.37. Five of the ten `qms_*` tools differ only in which state
 * they move a document to, so they share this instead of five copies of the same
 * forty lines — the state machine is the domain's ({@see DocumentLifecycle}), and
 * none of these tools is allowed to decide what is legal.
 *
 * ## A refused transition is a validation failure, not a system error
 *
 * `InvalidArgumentException` from the register means the caller asked for
 * something the lifecycle forbids — releasing a draft, reopening an obsolete
 * document. That is a bad request and is reported as one, with the register's own
 * message, which names the states that *would* have worked.
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

abstract class AbstractQmsTransitionTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ControlledDocumentInterface $documents,
    ) {
        parent::__construct($e);
    }

    /**
     * The lifecycle state this tool moves a document to.
     */
    abstract protected function target(): string;

    /**
     * Extra schema properties beyond `path`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function extraProperties(): array
    {
        return array();
    }

    /**
     * Extra arguments passed to the register.
     *
     * @param array<string, mixed> $arguments The originating arguments.
     *
     * @return array<string, mixed>
     */
    protected function meta(array $arguments): array
    {
        return array();
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
                'reason' => array(
                    'type' => 'string',
                    'description' => 'Why the transition is being made. Recorded in the append-only audit trail.',
                ),
            ) + $this->extraProperties(),
            'required' => array('path'),
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

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        $meta = $this->meta($arguments);
        $meta['reason'] = $this->stringParam($arguments, 'reason');
        $meta['user_id'] = isset($context['user_id']) && is_numeric($context['user_id'])
            ? (int) $context['user_id']
            : 0;

        try {
            $result = $this->documents->transition($path, $this->target(), $meta);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_transition_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('"%s" moved from %s to %s.', $result['title'], $result['from'], $result['to']),
            $result,
        );
    }
}
