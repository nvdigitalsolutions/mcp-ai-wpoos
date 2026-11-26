<?php

/**
 * Create a CRM company — `mcp_ai_company`.
 *
 * Ported from `WP_MCP_AI_Tool_Create_Company`: same slug, same sixteen
 * arguments, the same `_company_*` meta keys, and the same two required fields
 * (`company_name`, `industry`). Keeping the meta names is what makes a
 * WordPress export readable here and vice versa; the CRM's own duplicate scan
 * and handover tools already depend on them.
 *
 * Three decisions:
 *
 * - **No default is applied to `target_status`.** The schema declares
 *   `prospect`, but the WordPress handler only stores the field when the caller
 *   supplied it — a default the schema may have applied upstream is not this
 *   tool's to invent. A company created without it has no status, which
 *   `get_companies` reports as an empty string.
 * - **`tags` stays a string.** WordPress stores the comma-separated list as
 *   sanitised text. Splitting it into an array would change the stored shape
 *   and break a synced row; the port keeps the string.
 * - **`revenue` is stored as a number.** WordPress sanitises it as text, so a
 *   synced row holds `"250000"`; the port writes `250000.0` and `get_companies`
 *   reads either. A currency amount that is compared numerically should not be
 *   a string whose sorting depends on its digit count.
 *
 * `edit_url` is returned as null: there is no admin edit screen here, and the
 * key is kept for shape compatibility (see `CrmTool`).
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;

class CreateCompanyTool extends CrmTool
{
    /**
     * Argument name to stored meta key, in the WordPress order.
     *
     * @var array<string, string>
     */
    private const FIELDS = array(
        'industry'      => '_company_industry',
        'company_size'  => '_company_size',
        'website'       => '_company_website',
        'address'       => '_company_address',
        'city'          => '_company_city',
        'state'         => '_company_state',
        'zip'           => '_company_zip',
        'country'       => '_company_country',
        'phone'         => '_company_phone',
        'revenue'       => '_company_revenue',
        'target_status' => '_company_target_status',
        'linkedin'      => '_company_linkedin',
        'twitter'       => '_company_twitter',
        'tags'          => '_company_tags',
        'notes'         => '_company_notes',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'create_company';
    }

    public function getName(): string
    {
        return 'Create Company';
    }

    public function getDescription(): string
    {
        return 'Creates a CRM company record with its industry, size, location, revenue, target status and contact links. Company name and industry are required.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'company_name'  => array(
                    'type'        => 'string',
                    'description' => 'Name of the company or organization.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'industry'      => array(
                    'type'        => 'string',
                    'description' => 'Industry sector, e.g. Technology, Healthcare, Finance, Manufacturing.',
                    'minLength'   => 1,
                    'maxLength'   => 120,
                ),
                'company_size'  => array(
                    'type'        => 'string',
                    'description' => 'Company size range.',
                    'enum'        => array('1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+'),
                ),
                'website'       => array(
                    'type'        => 'string',
                    'description' => 'Company website URL.',
                    'maxLength'   => 500,
                ),
                'description'   => array(
                    'type'        => 'string',
                    'description' => 'Company description or overview.',
                    'maxLength'   => 10000,
                ),
                'address'       => array(
                    'type'        => 'string',
                    'description' => 'Street address.',
                    'maxLength'   => 300,
                ),
                'city'          => array(
                    'type'        => 'string',
                    'description' => 'City.',
                    'maxLength'   => 120,
                ),
                'state'         => array(
                    'type'        => 'string',
                    'description' => 'State or province.',
                    'maxLength'   => 120,
                ),
                'zip'           => array(
                    'type'        => 'string',
                    'description' => 'ZIP or postal code.',
                    'maxLength'   => 30,
                ),
                'country'       => array(
                    'type'        => 'string',
                    'description' => 'Country.',
                    'maxLength'   => 120,
                ),
                'phone'         => array(
                    'type'        => 'string',
                    'description' => 'Main phone number.',
                    'maxLength'   => 60,
                ),
                'revenue'       => array(
                    'type'        => 'number',
                    'description' => 'Annual revenue as a number, without a currency symbol.',
                    'minimum'     => 0,
                ),
                'target_status' => array(
                    'type'        => 'string',
                    'description' => 'Target status in the sales pipeline.',
                    'enum'        => array('prospect', 'target', 'in_discussion', 'client', 'not_interested'),
                ),
                'linkedin'      => array(
                    'type'        => 'string',
                    'description' => 'LinkedIn company page URL.',
                    'maxLength'   => 500,
                ),
                'twitter'       => array(
                    'type'        => 'string',
                    'description' => 'X/Twitter handle or URL.',
                    'maxLength'   => 200,
                ),
                'tags'          => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated tags. Stored as one string, as WordPress does.',
                    'maxLength'   => 500,
                ),
                'notes'         => array(
                    'type'        => 'string',
                    'description' => 'Free-form notes about the company.',
                    'maxLength'   => 10000,
                ),
            ),
            'required'             => array('company_name', 'industry'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id = (int) ($context['user_id'] ?? 0);
        $name    = trim($this->stringParam($arguments, 'company_name'));
        $industry = trim($this->stringParam($arguments, 'industry'));
        $errors  = array();

        if ('' === $name) {
            $errors['company_name'] = array('A company name is required.');
        }

        if ('' === $industry) {
            $errors['industry'] = array('An industry is required.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The company cannot be created.', $errors);
        }

        $meta = array();

        foreach (self::FIELDS as $argument => $key) {
            if ('revenue' === $argument) {
                if (isset($arguments['revenue']) && is_numeric($arguments['revenue'])) {
                    $meta[$key] = max(0.0, (float) $arguments['revenue']);
                }

                continue;
            }

            $value = trim($this->stringParam($arguments, $argument));

            if ('' !== $value) {
                $meta[$key] = $value;
            }
        }

        try {
            $company = $this->content->create(
                new CreateContentCommand(
                    title: $name,
                    type: self::COMPANY_TYPE,
                    authorId: $user_id,
                    status: 'publish',
                    content: trim($this->stringParam($arguments, 'description')),
                    meta: $meta,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('create_company_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Company "%s" created successfully in the CRM.', $name),
            array(
                'company_id'   => $company->id,
                'company_name' => $company->title,
                'industry'     => $industry,
                'edit_url'     => null,
            )
        );
    }
}
