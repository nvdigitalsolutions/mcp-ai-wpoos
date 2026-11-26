<?php

/**
 * Set the date the next periodic review is due.
 *
 * ISO 9001 §7.5.2 expects documented information to be reviewed at planned
 * intervals. A pending review is **not** a lifecycle state — a released document
 * stays current while its review is outstanding — so this writes the date without
 * moving the document.
 *
 * A date that cannot be parsed is refused rather than stored, because an
 * unreadable date makes "is this review overdue?" unanswerable, which is the only
 * question the field exists to answer.
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

class QmsScheduleReviewTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ControlledDocumentInterface $documents,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'qms_schedule_review';
    }

    public function getName(): string
    {
        return 'QMS: Schedule Review';
    }

    public function getDescription(): string
    {
        return 'Record when a controlled document is next due for periodic review. Does not change the lifecycle state.';
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
                'review_due' => array(
                    'type' => 'string',
                    'description' => 'ISO 8601 date, e.g. "2027-03-31".',
                ),
                'comment' => array(
                    'type' => 'string',
                    'description' => 'Optional note recorded in the audit trail.',
                ),
            ),
            'required' => array('path', 'review_due'),
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
        $due = $this->stringParam($arguments, 'review_due');

        if ('' === $path || '' === $due) {
            return $this->errors->validationFailed(
                'A path and a review date are required.',
                array('review_due' => array('Give an ISO 8601 date.')),
            );
        }

        try {
            $result = $this->documents->scheduleReview($path, $due, array(
                'comment' => $this->stringParam($arguments, 'comment'),
                'user_id' => isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : 0,
            ));
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('qms_schedule_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Review of "%s" is due %s.', $result['title'], $result['review_due']),
            $result,
        );
    }
}
