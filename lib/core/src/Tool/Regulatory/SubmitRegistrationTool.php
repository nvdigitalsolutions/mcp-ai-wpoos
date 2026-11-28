<?php

/**
 * Mark a registration submitted — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Submit_Registration` (Proposal 164 section 164.147).
 * Sets the status to `Submitted` and records the submission date (today when
 * omitted). It records a filing that happened elsewhere; it does not file.
 *
 * Notes are appended as `[Submitted on {date}] …`, the same audit-trail rule
 * `approve_registration` follows: a submission line is an event added to the
 * record, not a replacement for its history.
 *
 * WordPress passed the supplied date through `sanitize_text_field()` only; the
 * port validates it as an ISO date and names the field, because an unparsable
 * submission date breaks the pipeline reports that order by it.
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

class SubmitRegistrationTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'submit_registration';
    }

    public function getName(): string
    {
        return 'Submit Registration';
    }

    public function getDescription(): string
    {
        return 'Marks a registration submitted to its authority and records the submission date. Notes are appended as a dated line. It records the filing; it does not file.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration id.',
                    'minimum'     => 1,
                ),
                'submission_date' => array(
                    'type'        => 'string',
                    'description' => 'Submission date, YYYY-MM-DD. Defaults to today.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Submission notes, appended as a dated line.',
                    'maxLength'   => 5000,
                ),
            ),
            'required'             => array('registration_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id        = (int) ($context['user_id'] ?? 0);
        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));

        if ($registrationId <= 0) {
            return $this->errors->validationFailed(
                'A registration id is required.',
                array('registration_id' => array('The id shown by list_registrations.'))
            );
        }

        $item = $this->content->find($registrationId);

        if (null === $item || self::REGISTRATION_TYPE !== $item->type) {
            return $this->errors->notFound('Registration not found.');
        }

        $submissionDate = trim($this->stringParam($arguments, 'submission_date'));
        $submissionDate = '' !== $submissionDate ? $submissionDate : $this->today();
        $notes          = trim($this->stringParam($arguments, 'notes'));

        if (! $this->isIsoDate($submissionDate)) {
            return $this->errors->validationFailed(
                'One or more dates are unusable.',
                array('submission_date' => array('An ISO date, YYYY-MM-DD.'))
            );
        }

        $content = null;

        if ('' !== $notes) {
            $content = $item->content . "\n\n" . sprintf('[Submitted on %1$s] %2$s', $submissionDate, $notes);
        }

        try {
            $this->content->update(
                $registrationId,
                new UpdateContentCommand(
                    userId: $user_id,
                    content: $content,
                    meta: array('submission_date' => $submissionDate),
                    taxonomyInput: array(self::STATUS_TAX => array($this->statusName('submitted'))),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Registration not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('submit_registration_failed', $e->getMessage());
        }

        return $this->success(
            'Registration marked as submitted successfully.',
            array(
                'registration_id' => $registrationId,
                'status'          => $this->statusName('submitted'),
                'submission_date' => $submissionDate,
            )
        );
    }
}
