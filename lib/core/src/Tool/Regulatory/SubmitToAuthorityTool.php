<?php

/**
 * Record a submission to a regulatory authority.
 *
 * Port of `WP_MCP_AI_Tool_Submit_To_Authority` (Proposal 164 section 164.160).
 * Checks that the registration exists and has at least one linked document,
 * writes the local submission record, moves the registration to the `submitted`
 * status and appends an entry to the submission log.
 *
 * ## WordPress transmitted nothing, and this does not either
 *
 * The handler's own comment said "Placeholder for actual electronic
 * submission", and no HTTP request exists in the tool. What it did was record a
 * local fact: a reference, a date, a status, and a log line. The port keeps that
 * — the local record is real and useful — and says so. The success message
 * claimed a submission to a regulatory authority, which nothing in the code
 * supports; the port's message says the submission was recorded locally and
 * `transmitted` is returned as `false` so a caller cannot read the response as
 * proof of delivery.
 *
 * ## `estimated_review` is not returned
 *
 * WordPress returned `estimated_review` as a fixed `+30 days` from now and
 * nothing more. On a submission that never left the machine that is a promise
 * about a third party's queue, and a planner who scheduled against it would be
 * scheduling against a constant. The field is omitted; the port does not
 * replace it with another guess.
 *
 * ## The reference prefix is the first two characters of the stored country
 *
 * `SUB-{XX}-{id}-{YmdHis}` where `XX` is `strtoupper( substr( $country, 0, 2 ) )`,
 * exactly as WordPress built it. Because the family stores the country as a name
 * in some records and a code in others, "Sri Lanka" produces `SR`, not `LK`. The
 * reference is a local identifier rather than a country claim, so the shape is
 * kept and the quirk is documented instead of being papered over with a mapping
 * table that the source never had.
 *
 * ## The document count is a ceiling, not a total
 *
 * At least one document linked to the registration is required, which is the
 * WordPress rule. The count reported is the number read up to the family's
 * 1000-row ceiling, so a registration with more than 1000 documents reports
 * 1000. Nothing in the source counted differently; it read `found_posts`.
 *
 * ## The log is a read-modify-write on one meta key
 *
 * `_submission_log` is an array in a single meta field, as it was there. Two
 * submissions recorded at the same instant can lose an entry, and the store
 * cannot prevent it because the merge happens before the write. A separate
 * content type would fix it and would also change what an export carries, so
 * the array is kept and the limitation is stated rather than hidden.
 *
 * Gated on `edit_posts`, matching the capability the WordPress handler checked.
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

class SubmitToAuthorityTool extends RegulatoryTool
{
    /** The submission types WordPress accepted. */
    protected const SUBMISSION_TYPES = array('new', 'renewal', 'variation', 'transfer');

    /** The priorities WordPress accepted. */
    protected const PRIORITIES = array('normal', 'expedited', 'fast_track');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'submit_to_authority';
    }

    public function getName(): string
    {
        return 'Submit to Authority';
    }

    public function getDescription(): string
    {
        return 'Records a submission against a registration: requires at least one linked document, writes a submission reference, moves the registration to submitted and appends to the submission log. Nothing is transmitted to an authority; transmitted is false and no review estimate is returned.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration to record a submission against.',
                    'minimum'     => 1,
                ),
                'submission_type' => array(
                    'type'        => 'string',
                    'description' => 'The kind of submission. Default new.',
                    'enum'        => self::SUBMISSION_TYPES,
                    'default'     => 'new',
                ),
                'priority'        => array(
                    'type'        => 'string',
                    'description' => 'The priority recorded with the submission. Default normal.',
                    'enum'        => self::PRIORITIES,
                    'default'     => 'normal',
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Notes recorded with the submission.',
                    'maxLength'   => 2000,
                ),
            ),
            'required'             => array('registration_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'edit_posts');

        $registrationId = $this->intValue($arguments['registration_id'] ?? null);

        $fields = array();

        if ($registrationId < 1) {
            $fields['registration_id'] = array('The registration id.');
        }

        $submissionType = trim($this->stringParam($arguments, 'submission_type'));

        if ('' === $submissionType) {
            $submissionType = 'new';
        }

        if (! in_array($submissionType, self::SUBMISSION_TYPES, true)) {
            $fields['submission_type'] = array(
                'One of: ' . implode(', ', self::SUBMISSION_TYPES) . '.',
            );
        }

        $priority = trim($this->stringParam($arguments, 'priority'));

        if ('' === $priority) {
            $priority = 'normal';
        }

        if (! in_array($priority, self::PRIORITIES, true)) {
            $fields['priority'] = array(
                'One of: ' . implode(', ', self::PRIORITIES) . '.',
            );
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The submission cannot be recorded.', $fields);
        }

        $registration = $this->registration($registrationId);

        if (null === $registration) {
            return $this->errors->notFound('Registration not found.');
        }

        $documents = $this->collectContent(
            array(self::DOCUMENT_TYPE),
            array(array('key' => 'registration_id', 'value' => $registrationId, 'compare' => '='))
        );

        if (array() === $documents) {
            return $this->errors->validationFailed(
                'The submission cannot be recorded.',
                array('registration_id' => array('At least one document is linked to the registration.'))
            );
        }

        $meta       = $registration->meta;
        $country    = $this->text($meta['country'] ?? null);
        $authority  = $this->text($meta['authority'] ?? null);
        $notes      = trim($this->stringParam($arguments, 'notes'));
        $reference  = sprintf(
            'SUB-%s-%d-%s',
            strtoupper(substr($country, 0, 2)),
            $registrationId,
            gmdate('YmdHis')
        );
        $recordedAt = $this->nowStamp();

        $meta['submission_date']       = $recordedAt;
        $meta['_submission_reference'] = $reference;
        $meta['_submission_type']      = $submissionType;
        $meta['_submission_priority']  = $priority;

        if ('' !== $notes) {
            $meta['_submission_notes'] = $notes;
        }

        // The append is against the value read above, so two submissions that
        // read the same log before either writes lose one entry. Kept because a
        // separate content type would change the exported shape.
        $log = $meta['_submission_log'] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'timestamp' => $recordedAt,
            'user_id'   => $userId,
            'type'      => $submissionType,
            'priority'  => $priority,
            'reference' => $reference,
        );

        $meta['_submission_log'] = array_values($log);

        try {
            $this->content->update(
                $registration->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: $meta,
                    taxonomyInput: array(self::STATUS_TAX => array('submitted')),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Registration not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('submit_to_authority_failed', $e->getMessage());
        }

        return $this->success(
            'Submission recorded locally and marked submitted. No data was transmitted to an authority.',
            array(
                'registration_id'      => $registrationId,
                'submission_reference' => $reference,
                'submission_type'      => $submissionType,
                'priority'             => $priority,
                'authority'            => $authority,
                'country'              => $country,
                'documents_submitted'  => count($documents),
                'documents_count_capped' => count($documents) >= self::MAX_COLLECTED,
                'submitted_at'         => $recordedAt,
                'status'               => 'submitted',
                'transmitted'          => false,
            )
        );
    }

    /**
     * A registration by content id, or null.
     */
    private function registration(int $id): ?ContentItem
    {
        try {
            $item = $this->content->find($id);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
            return null;
        }

        return $item;
    }
}
