<?php

/**
 * Change a GTmetrix page's monitoring frequency.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Set_Monitoring` (Proposal 164 section
 * 164.97). Monitoring is what makes a page produce reports without a caller
 * starting tests, so this is the switch an alert configuration sits behind.
 *
 * ## Corrections
 *
 * 1. **The requested frequency is case-folded before comparison.** WordPress
 *    ran `sanitize_key()`, which lowercases; the port does the same explicitly
 *    so `Daily` and `daily` behave alike, and refuses anything outside the
 *    documented set by name rather than by a generic message.
 * 2. **The stored state is reported separately.** `frequency` echoes the
 *    request and `monitored` is what the API stored — `none` is stored as `no`,
 *    and a plan that refuses `hourly` answers its own state rather than the
 *    request. A caller that only read `frequency` would not see either.
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

class GtmetrixSetMonitoringTool extends GtmetrixTool
{
    /** The values the API accepts; `no` and `none` both disable. */
    public const FREQUENCIES = array('no', 'none', 'hourly', 'daily', 'weekly', 'monthly');

    public function getSlug(): string
    {
        return 'gtmetrix_set_monitoring';
    }

    public function getName(): string
    {
        return 'GTmetrix Set Monitoring';
    }

    public function getDescription(): string
    {
        return 'Enable, disable or change GTmetrix monitoring for a page (no, none, hourly, daily, weekly, monthly). Hourly monitoring and some locations depend on the GTmetrix plan; the reply reports the state the API stored, not only the one requested.';
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'page_id'   => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix page id.',
                    'minLength'   => 1,
                ),
                'frequency' => array(
                    'type'        => 'string',
                    'enum'        => self::FREQUENCIES,
                    'description' => 'Monitoring frequency; "no" or "none" disables monitoring.',
                ),
            ),
            'required'             => array('page_id', 'frequency'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        $pageId = $this->identifierArg($arguments, 'page_id');
        $bad    = array();

        if ('' === $pageId) {
            $bad['page_id'] = array('Invalid page id.');
        }

        $frequency = array_key_exists('frequency', $arguments) && is_scalar($arguments['frequency'])
            ? strtolower(trim((string) $arguments['frequency']))
            : '';

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            $bad['frequency'] = array('Unknown monitoring frequency.');
        }

        if (array() !== $bad) {
            return $this->errors->validationFailed(
                'A page id and a frequency from the documented set are required.',
                $bad
            );
        }

        try {
            $response = $this->client->request(
                'POST',
                'pages/' . rawurlencode($pageId) . '/performance_monitor',
                array(
                    'data' => array(
                        'type'       => 'performance_monitor',
                        'attributes' => array('frequency' => $frequency),
                    ),
                )
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $page   = is_array($response['data']) ? $response['data'] : array();
        $attrs  = $this->attrs($page);
        $stored = $this->text($attrs['monitored'] ?? '');

        return $this->success(
            'Monitoring frequency set to ' . $frequency . '.',
            array(
                'page_id'   => '' !== $this->text($page['id'] ?? '') ? $this->text($page['id']) : $pageId,
                'frequency' => $frequency,
                'monitored' => '' !== $stored ? $stored : $frequency,
            )
        );
    }
}
