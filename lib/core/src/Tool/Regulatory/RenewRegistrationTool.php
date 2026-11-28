<?php

/**
 * Start a renewal from an existing registration — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Renew_Registration` (Proposal 164 section 164.147).
 * Creates a new registration in `Draft` with the source product, country and
 * authority, `registration_type: renewal`, and a pointer back to the original;
 * then stamps the original `renewed_by` and `renewal_date`.
 *
 * ## The original is marked, and that is the point
 *
 * A renewal that only created a new row would leave the original looking
 * current. The two meta keys written to the original — `renewed_by` and
 * `renewal_date` — are what makes "this one was superseded" answerable, and they
 * are written after the renewal exists, so a failed create never marks anything.
 *
 * ## What is carried and what is not
 *
 * Product, country and authority carry forward; the COS number, submission
 * date and approval date do not, because a renewal is a new application. The
 * old COS and expiry are recorded in the note body as
 * `Renewal of COS: … (Expired: …)`, which is how an operator reconciles the two
 * records. The new expiry is an *expected* date, stored under its own key, and
 * validated as an ISO date.
 *
 * ## One addition
 *
 * WordPress performed no capability check here — the third tool in the family
 * without one. The port requires `edit_posts` like its siblings.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;

class RenewRegistrationTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'renew_registration';
    }

    public function getName(): string
    {
        return 'Renew Registration';
    }

    public function getDescription(): string
    {
        return 'Starts a renewal: creates a draft registration that carries the product, country and authority forward, links it to the original and marks the original renewed.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id'      => array(
                    'type'        => 'integer',
                    'description' => 'The original registration id to renew.',
                    'minimum'     => 1,
                ),
                'expected_expiry_date' => array(
                    'type'        => 'string',
                    'description' => 'Expected new expiry date, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'notes'                => array(
                    'type'        => 'string',
                    'description' => 'Notes about the renewal.',
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

        $user_id     = (int) ($context['user_id'] ?? 0);
        $originalId  = max(0, $this->intParam($arguments, 'registration_id'));

        if ($originalId <= 0) {
            return $this->errors->validationFailed(
                'A registration id is required.',
                array('registration_id' => array('The id shown by list_registrations.'))
            );
        }

        $original = $this->content->find($originalId);

        if (null === $original || self::REGISTRATION_TYPE !== $original->type) {
            return $this->errors->notFound('Original registration not found.');
        }

        $expectedExpiry = trim($this->stringParam($arguments, 'expected_expiry_date'));

        if ('' !== $expectedExpiry && ! $this->isIsoDate($expectedExpiry)) {
            return $this->errors->validationFailed(
                'The expected expiry date is unusable.',
                array('expected_expiry_date' => array('An ISO date, YYYY-MM-DD.'))
            );
        }

        $meta        = $original->meta;
        $productId   = $this->intValue($meta['product_id'] ?? null);
        $country     = $this->text($meta['country'] ?? null);
        $authority   = $this->text($meta['authority'] ?? null);
        $oldCos      = $this->text($meta['cos_number'] ?? null);
        $oldExpiry   = $this->text($meta['expiry_date'] ?? null);
        $title       = sprintf('%s - %s Renewal', $original->title, $country);
        $notes       = trim($this->stringParam($arguments, 'notes'));

        if ('' !== $oldExpiry) {
            $notes .= sprintf("\n\nRenewal of COS: %s (Expired: %s)", $oldCos, $oldExpiry);
        }

        $renewalMeta = array(
            'product_id'              => $productId,
            'country'                 => $country,
            'authority'               => $authority,
            'registration_type'       => 'renewal',
            'original_registration_id' => $originalId,
        );

        if ('' !== $expectedExpiry) {
            $renewalMeta['expected_expiry_date'] = $expectedExpiry;
        }

        try {
            $renewal = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::REGISTRATION_TYPE,
                    authorId: $user_id,
                    status: 'publish',
                    content: $notes,
                    meta: $renewalMeta,
                    taxonomyInput: array(self::STATUS_TAX => array($this->statusName('draft'))),
                )
            );

            $this->content->update(
                $originalId,
                new UpdateContentCommand(
                    userId: $user_id,
                    meta: array(
                        'renewed_by'   => $renewal->id,
                        'renewal_date' => $this->nowStamp(),
                    ),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('renew_registration_failed', $e->getMessage());
        }

        return $this->success(
            'Renewal registration created successfully.',
            array(
                'renewal_id'               => $renewal->id,
                'renewal_title'            => $title,
                'original_registration_id' => $originalId,
                'product_id'               => $productId,
                'country'                  => $country,
                'authority'                => $authority,
                'registration_type'        => 'renewal',
            )
        );
    }
}
