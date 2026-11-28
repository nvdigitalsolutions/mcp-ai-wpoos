<?php

/**
 * Approve a registration — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Approve_Registration` (Proposal 164 section 164.147).
 * Sets the status to `Approved`, records the approval date (today when omitted),
 * and optionally the expiry date and COS number.
 *
 * ## Notes are appended, because this route records an event
 *
 * WordPress appended `[Approved on {date}] {notes}` to the note body, keeping
 * whatever was there. The port does the same: a registration's notes are its
 * audit trail, and replacing them with a single approval line would delete the
 * history that makes the record defensible. `update_registration_status` still
 * replaces, which is what that route always did.
 *
 * ## Two guards added
 *
 * WordPress wrote the supplied dates through `sanitize_text_field()` alone. A
 * malformed approval or expiry date would then sit in the store and quietly
 * break every expiry comparison, so the port validates both as ISO dates and
 * names the field. The approval date defaults to the runtime clock (UTC): a host
 * that needs the site timezone passes the date, which is why the argument is
 * there.
 *
 * The response keeps the flat WordPress shape — the ids and the values written,
 * with `null` for what was not supplied — because an approval receipt is
 * exactly that: what changed, not a second copy of the record.
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

class ApproveRegistrationTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'approve_registration';
    }

    public function getName(): string
    {
        return 'Approve Registration';
    }

    public function getDescription(): string
    {
        return 'Marks a registration approved, recording the approval date, and optionally the expiry date and COS number. Notes are appended as a dated line.';
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
                'approval_date'   => array(
                    'type'        => 'string',
                    'description' => 'Approval date, YYYY-MM-DD. Defaults to today.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'expiry_date'     => array(
                    'type'        => 'string',
                    'description' => 'Expiry or renewal date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'cos_number'      => array(
                    'type'        => 'string',
                    'description' => 'COS or certificate number.',
                    'maxLength'   => 100,
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Approval notes, appended as a dated line.',
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

        $approvalDate = trim($this->stringParam($arguments, 'approval_date'));
        $approvalDate = '' !== $approvalDate ? $approvalDate : $this->today();
        $expiryDate   = trim($this->stringParam($arguments, 'expiry_date'));
        $cosNumber    = trim($this->stringParam($arguments, 'cos_number'));
        $notes        = trim($this->stringParam($arguments, 'notes'));

        $errors = array();

        if (! $this->isIsoDate($approvalDate)) {
            $errors['approval_date'] = array('An ISO date, YYYY-MM-DD.');
        }

        if ('' !== $expiryDate && ! $this->isIsoDate($expiryDate)) {
            $errors['expiry_date'] = array('An ISO date, YYYY-MM-DD.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('One or more dates are unusable.', $errors);
        }

        $meta = array('approval_date' => $approvalDate);

        if ('' !== $expiryDate) {
            $meta['expiry_date'] = $expiryDate;
        }

        if ('' !== $cosNumber) {
            $meta['cos_number'] = $cosNumber;
        }

        $content = null;

        if ('' !== $notes) {
            $content = $item->content . "\n\n" . sprintf('[Approved on %1$s] %2$s', $approvalDate, $notes);
        }

        try {
            $this->content->update(
                $registrationId,
                new UpdateContentCommand(
                    userId: $user_id,
                    content: $content,
                    meta: $meta,
                    taxonomyInput: array(self::STATUS_TAX => array($this->statusName('approved'))),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Registration not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('approve_registration_failed', $e->getMessage());
        }

        return $this->success(
            'Registration approved successfully.',
            array(
                'registration_id' => $registrationId,
                'status'          => $this->statusName('approved'),
                'approval_date'   => $approvalDate,
                'expiry_date'     => '' !== $expiryDate ? $expiryDate : null,
                'cos_number'      => '' !== $cosNumber ? $cosNumber : null,
            )
        );
    }
}
