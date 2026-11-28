<?php

/**
 * Open a registration for a product in a country — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_Create_Registration` (Proposal 164 section 164.146):
 * the same nine arguments, the same defaults (`draft`, `new`), and the same
 * title convention — `"{product} - {country} Registration"`, which is how an
 * operator recognises a record in a list of them.
 *
 * ## The status is a term, and its name is the spelling that matters
 *
 * WordPress addressed the status by a slug derived from the caller's value
 * (`pending_documents` → `pending-documents`) and created the term named
 * `Pending Documents`. The seeded vocabulary uses those names, so the port
 * writes the name. Reading is unchanged: the status a row reports is the term
 * name, which is what every WordPress reader returned.
 *
 * ## Dates are validated locally
 *
 * The schema declares `YYYY-MM-DD` for the three dates and WordPress relied on
 * the schema alone. A malformed date stored here would silently fail every
 * expiry comparison later — `list_registrations` and the forecast tools compare
 * these strings — so a provided value that is not an ISO date is named now,
 * while the caller still knows which field to fix.
 *
 * The referenced product must exist and be a product; WordPress refused an
 * invalid id and the port does the same, naming `product_id` rather than
 * returning a bare "invalid product".
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
use Nvoos\Core\Domain\Error\AccessDeniedException;

class CreateRegistrationTool extends RegulatoryTool
{
    /** The statuses a registration may start in. */
    public const STATUSES = array(
        'draft',
        'pending_documents',
        'ready_for_submission',
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'on_hold',
        'renewal_due',
    );

    /** The registration types the toolkit accepts. */
    public const TYPES = array('new', 'renewal', 'variation');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'create_registration';
    }

    public function getName(): string
    {
        return 'Create Registration';
    }

    public function getDescription(): string
    {
        return 'Opens a registration record for an existing product in a country or under an authority. Status defaults to draft and registration type to new.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'        => array(
                    'type'        => 'integer',
                    'description' => 'The product id to register.',
                    'minimum'     => 1,
                ),
                'country'           => array(
                    'type'        => 'string',
                    'description' => 'Country or region, e.g. Sri Lanka.',
                    'minLength'   => 1,
                    'maxLength'   => 100,
                ),
                'authority'         => array(
                    'type'        => 'string',
                    'description' => 'Regulatory authority, e.g. NMRA.',
                    'maxLength'   => 100,
                ),
                'registration_type' => array(
                    'type'        => 'string',
                    'description' => 'Registration type. Default new.',
                    'enum'        => self::TYPES,
                    'default'     => 'new',
                ),
                'status'            => array(
                    'type'        => 'string',
                    'description' => 'Initial status. Default draft.',
                    'enum'        => self::STATUSES,
                    'default'     => 'draft',
                ),
                'cos_number'        => array(
                    'type'        => 'string',
                    'description' => 'COS or certificate number.',
                    'maxLength'   => 100,
                ),
                'submission_date'   => array(
                    'type'        => 'string',
                    'description' => 'Submission date in ISO 8601 form, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'approval_date'     => array(
                    'type'        => 'string',
                    'description' => 'Approval date in ISO 8601 form, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'expiry_date'       => array(
                    'type'        => 'string',
                    'description' => 'Expiry date in ISO 8601 form, YYYY-MM-DD.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'notes'             => array(
                    'type'        => 'string',
                    'description' => 'Notes or comments.',
                    'maxLength'   => 5000,
                ),
            ),
            'required'             => array('product_id', 'country'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id   = (int) ($context['user_id'] ?? 0);
        $productId = max(0, $this->intParam($arguments, 'product_id'));
        $country   = trim($this->stringParam($arguments, 'country'));
        $errors    = array();

        if ($productId <= 0) {
            $errors['product_id'] = array('The id shown by list_reg_products.');
        }

        if ('' === $country) {
            $errors['country'] = array('The country or region being registered in.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The registration cannot be created.', $errors);
        }

        $product = $this->content->find($productId);

        if (null === $product || self::PRODUCT_TYPE !== $product->type) {
            return $this->errors->validationFailed(
                'The registration cannot be created.',
                array('product_id' => array('No product exists with that id (see list_reg_products).'))
            );
        }

        $type   = trim($this->stringParam($arguments, 'registration_type'));
        $type   = '' !== $type ? $type : 'new';
        $status = trim($this->stringParam($arguments, 'status'));
        $status = '' !== $status ? $status : 'draft';

        if (! in_array($type, self::TYPES, true)) {
            return $this->errors->validationFailed(
                'A valid registration type is required.',
                array('registration_type' => array('One of: ' . implode(', ', self::TYPES) . '.'))
            );
        }

        if (! in_array($status, self::STATUSES, true)) {
            return $this->errors->validationFailed(
                'A valid status is required.',
                array('status' => array('One of: ' . implode(', ', self::STATUSES) . '.'))
            );
        }

        $dates  = array();
        $errors = array();

        foreach (array('submission_date', 'approval_date', 'expiry_date') as $field) {
            $value = trim($this->stringParam($arguments, $field));

            if ('' !== $value && ! $this->isIsoDate($value)) {
                $errors[ $field ] = array('An ISO date, YYYY-MM-DD.');
                continue;
            }

            if ('' !== $value) {
                $dates[ $field ] = $value;
            }
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('One or more dates are unusable.', $errors);
        }

        $meta = array(
            'product_id'        => $productId,
            'country'           => $country,
            'registration_type' => $type,
        );

        $authority = trim($this->stringParam($arguments, 'authority'));
        $cosNumber = trim($this->stringParam($arguments, 'cos_number'));

        if ('' !== $authority) {
            $meta['authority'] = $authority;
        }

        if ('' !== $cosNumber) {
            $meta['cos_number'] = $cosNumber;
        }

        foreach ($dates as $field => $value) {
            $meta[ $field ] = $value;
        }

        $title = sprintf('%s - %s Registration', $product->title, $country);

        try {
            $registration = $this->content->create(
                new CreateContentCommand(
                    title: $title,
                    type: self::REGISTRATION_TYPE,
                    authorId: $user_id,
                    status: 'publish',
                    content: trim($this->stringParam($arguments, 'notes')),
                    meta: $meta,
                    taxonomyInput: array(self::STATUS_TAX => array($this->statusName($status))),
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('create_registration_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Registration for "%1$s" in %2$s created successfully.', $product->title, $country),
            array(
                'registration_id' => $registration->id,
                'edit_url'        => null,
            )
        );
    }
}
