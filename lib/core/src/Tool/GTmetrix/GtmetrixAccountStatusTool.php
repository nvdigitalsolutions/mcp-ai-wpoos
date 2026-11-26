<?php

/**
 * The GTmetrix account balance, plan and feature flags.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Account_Status` (Proposal 164 section
 * 164.91). One credit-free `GET /status`; the useful correction is that the
 * numeric fields keep their type instead of being stringified, and an absent
 * field stays `null` rather than becoming a zero the caller would read as a
 * real balance.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Error\GtmetrixApiException;

class GtmetrixAccountStatusTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_account_status';
    }

    public function getName(): string
    {
        return 'GTmetrix Account Status';
    }

    public function getDescription(): string
    {
        return 'Reports the GTmetrix API credit balance, refill schedule, plan type and PRO feature access. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        try {
            $response = $this->client->request('GET', 'status');
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();
        $attrs    = $this->attrs($resource);

        return $this->success(
            'GTmetrix account status.',
            array(
                'api_credits'          => is_numeric($attrs['api_credits'] ?? null) ? (float) $attrs['api_credits'] : null,
                'api_refill'           => is_numeric($attrs['api_refill'] ?? null) ? (int) $attrs['api_refill'] : null,
                'api_refill_amount'    => is_numeric($attrs['api_refill_amount'] ?? null) ? (int) $attrs['api_refill_amount'] : null,
                'account_type'         => $this->text($attrs['account_type'] ?? ''),
                'pro_analysis_options' => $this->flag($attrs['account_pro_analysis_options_access'] ?? false),
                'pro_locations'        => $this->flag($attrs['account_pro_locations_access'] ?? false),
                'whitelabel_pdf'       => $this->flag($attrs['account_whitelabel_pdf_access'] ?? false),
            )
        );
    }
}
