<?php

/**
 * Move a registration between statuses — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Update_Registration_Status` (Proposal 164 section
 * 164.147). The one tool in the family that writes any status, plus the dates
 * and COS number the authority returned.
 *
 * ## Three corrections
 *
 * - **One status vocabulary.** This tool's schema listed display names
 *   (`Pending Documents`), while `create_registration` and `list_registrations`
 *   take slugs (`pending_documents`), and it passed the caller's string straight
 *   into `term_exists()`. A slug passed here therefore created a *second* term
 *   named `pending_documents` beside `Pending Documents`, splitting the
 *   distribution the reports count. The port accepts either form and normalises
 *   to the stored name.
 * - **An unusable date is refused, not dropped.** WordPress validated each date
 *   and, on failure, simply did not write it — and still returned success. A
 *   caller that sent `05/01/2028` was told the status changed and had no signal
 *   that the expiry did not. The port names the field.
 * - **The response reports the stored status.** WordPress echoed back the
 *   string the caller sent, which after the fix above is not always the term
 *   name. The port returns the full registration row, as `get_registration`
 *   does, so the status in the answer is the status in the store.
 *
 * `notes` replaces the registration's note body here — that is what WordPress
 * did on this route — while `approve_registration` and `submit_registration`
 * append a dated line. The difference is preserved because the routes mean
 * different things: this one records a state change, the others append an
 * event.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class UpdateRegistrationStatusTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'update_registration_status';
    }

    public function getName(): string
    {
        return 'Update Registration Status';
    }

    public function getDescription(): string
    {
        return 'Moves a registration to a new status and optionally records submission, approval or expiry dates and the COS number. Notes replace the note body.';
    }

    public function getParametersSchema(): array
    {
        $statuses = array_merge(
            CreateRegistrationTool::STATUSES,
            array_map(fn (string $slug): string => $this->statusName($slug), CreateRegistrationTool::STATUSES)
        );

        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration id.',
                    'minimum'     => 1,
                ),
                'status'          => array(
                    'type'        => 'string',
                    'description' => 'The new status, as a slug or its display name.',
                    'enum'        => $statuses,
                ),
                'submission_date' => array(
                    'type'        => 'string',
                    'description' => 'Submission date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'approval_date'   => array(
                    'type'        => 'string',
                    'description' => 'Approval date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'expiry_date'     => array(
                    'type'        => 'string',
                    'description' => 'Expiry or renewal date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'cos_number'      => array(
                    'type'        => 'string',
                    'description' => 'COS or certificate number. An empty string clears it.',
                    'maxLength'   => 100,
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Note body. Replaces the existing notes.',
                    'maxLength'   => 5000,
                ),
            ),
            'required'             => array('registration_id', 'status'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id        = (int) ($context['user_id'] ?? 0);
        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));
        $statusInput    = trim($this->stringParam($arguments, 'status'));
        $errors         = array();

        if ($registrationId <= 0) {
            $errors['registration_id'] = array('The id shown by list_registrations.');
        }

        if ('' === $statusInput) {
            $errors['status'] = array('One of the registration statuses.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The status cannot be updated.', $errors);
        }

        $item = $this->content->find($registrationId);

        if (null === $item || self::REGISTRATION_TYPE !== $item->type) {
            return $this->errors->notFound('Registration not found.');
        }

        $key = $this->statusKey($statusInput);

        if (! in_array($key, CreateRegistrationTool::STATUSES, true)) {
            return $this->errors->validationFailed(
                'A valid status is required.',
                array('status' => array('One of: ' . implode(', ', CreateRegistrationTool::STATUSES) . '.'))
            );
        }

        $meta   = array();
        $errors = array();

        foreach (array('submission_date', 'approval_date', 'expiry_date') as $field) {
            if (! array_key_exists($field, $arguments)) {
                continue;
            }

            $value = trim($this->stringParam($arguments, $field));

            if (! $this->isIsoDate($value)) {
                $errors[ $field ] = array('An ISO date, YYYY-MM-DD.');
                continue;
            }

            $meta[ $field ] = $value;
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('One or more dates are unusable.', $errors);
        }

        if (array_key_exists('cos_number', $arguments)) {
            $meta['cos_number'] = trim($this->stringParam($arguments, 'cos_number'));
        }

        $content = array_key_exists('notes', $arguments)
            ? $this->stringParam($arguments, 'notes')
            : null;

        try {
            $this->content->update(
                $registrationId,
                new UpdateContentCommand(
                    userId: $user_id,
                    content: $content,
                    meta: $meta,
                    taxonomyInput: array(self::STATUS_TAX => array($this->statusName($key))),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Registration not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('update_registration_status_failed', $e->getMessage());
        }

        $updated = $this->content->find($registrationId);
        $row     = $updated instanceof ContentItem && self::REGISTRATION_TYPE === $updated->type
            ? $this->registrationRow($updated, $updated->meta, $this->terms($updated))
            : array('id' => $registrationId);

        return $this->success(
            'Registration status updated successfully.',
            array('registration' => $row)
        );
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * @return array<string, array<int, string>>
     */
    private function terms(ContentItem $item): array
    {
        try {
            return $this->content->getTaxonomyTerms($item->id);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
