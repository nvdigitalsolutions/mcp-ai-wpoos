<?php

/**
 * Read a GTmetrix page's performance-alert conditions.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Alert_Conditions` (Proposal 164 section
 * 164.97). The reply is the full replace set: what `set_alert_conditions` would
 * send back, including the video flag and the Team-organization recipients.
 *
 * ## Correction
 *
 * **Rows are projected to scalars.** WordPress passed `conditions` and
 * `send_to` through untouched, so a malformed nested value reached the caller
 * as an array inside a field documented as a metric/threshold pair. The port
 * keeps the scalar fields of each row and drops the rest.
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

class GtmetrixGetAlertConditionsTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_get_alert_conditions';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Alert Conditions';
    }

    public function getDescription(): string
    {
        return 'Read the performance-alert conditions configured for a GTmetrix page: which metrics, operators, thresholds and recipients trigger alerts, and whether a video is kept when one fires. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'page_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix page id.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('page_id'),
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

        $pageId = $this->identifierArg($arguments, 'page_id');

        if ('' === $pageId) {
            return $this->errors->validationFailed(
                'A page id is required and may contain only letters, digits, hyphens and underscores.',
                array('page_id' => array('Invalid page id.'))
            );
        }

        try {
            $response = $this->client->request(
                'GET',
                'pages/' . rawurlencode($pageId) . '/performance_alert_conditions'
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();
        $attrs    = $this->attrs($resource);
        $id       = $this->text($resource['id'] ?? '');
        $rows     = $this->scalarRows($attrs['conditions'] ?? array());

        return $this->success(
            sprintf('%d alert condition(s) configured.', count($rows)),
            array(
                'page_id'      => '' !== $id ? $id : $pageId,
                'create_video' => $this->flag($attrs['create_video'] ?? false),
                'conditions'   => $rows,
                'send_to'      => $this->scalarRows($attrs['send_to'] ?? array()),
            )
        );
    }
}
