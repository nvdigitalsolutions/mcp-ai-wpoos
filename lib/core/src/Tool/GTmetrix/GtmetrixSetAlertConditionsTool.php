<?php

/**
 * Replace a GTmetrix page's performance-alert conditions.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Set_Alert_Conditions` (Proposal 164 section
 * 164.97). The endpoint is a full replace: the supplied list becomes the whole
 * condition set, and an empty list removes every alert.
 *
 * ## Corrections
 *
 * 1. **A malformed condition is refused, not skipped.** WordPress `continue`d
 *    past a non-array entry while the ten-condition check counted it, so a
 *    full-replace call could silently drop an alert the caller believed it had
 *    set. Every entry is validated by index and the whole call is refused when
 *    one is wrong — all-or-nothing matches replace semantics.
 * 2. **A threshold must be a number.** WordPress cast with `(float)`, so
 *    `"fast"` became `0` and passed the non-negative check. A non-numeric value
 *    is refused.
 * 3. **The condition count is the stored set.** WordPress counted the list it
 *    sent, even when the reply carried a different set. The reply is counted
 *    when the API returns one.
 * 4. **`send_to` is offered.** The API accepts recipients for Team
 *    organizations, and WordPress never sent the field, so a Team account could
 *    not set recipients through the toolkit at all. It is validated (a valid
 *    e-mail, and the only documented channel, `email`) and sent only when
 *    supplied, because omitting it leaves the existing recipients untouched.
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

class GtmetrixSetAlertConditionsTool extends GtmetrixTool
{
    /** The documented alert metric ids. */
    public const METRICS = array(
        'gtmetrix_score',
        'performance_score',
        'structure_score',
        'first_contentful_paint',
        'largest_contentful_paint',
        'total_blocking_time',
        'cumulative_layout_shift',
        'time_to_interactive',
        'speed_index',
        'redirect_time',
        'connect_time',
        'backend_time',
        'ttfb',
        'onload',
        'fully_loaded',
        'total_size',
        'requests',
        'html_size',
        'first_paint',
        'dom_interactive',
        'dom_content_loaded',
        'dom_content_loaded_time',
        'onload_time',
        'rum_speed_index',
        'pagespeed_score',
        'yslow_score',
    );

    public const OPERATORS      = array('<', '=', '>');
    public const MAX_CONDITIONS = 10;

    /** The only channel the API documents for an alert recipient. */
    public const CHANNELS = array('email');

    public function getSlug(): string
    {
        return 'gtmetrix_set_alert_conditions';
    }

    public function getName(): string
    {
        return 'GTmetrix Set Alert Conditions';
    }

    public function getDescription(): string
    {
        return 'Replace the performance-alert conditions for a GTmetrix page (full replace, at most 10; an empty list removes all alerts). Each condition is a documented metric, an operator (<, = or >) and a threshold in the units the report API uses (ms or bytes). Team organizations may also set the recipients.';
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
                'page_id'      => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix page id.',
                    'minLength'   => 1,
                ),
                'conditions'   => array(
                    'type'        => 'array',
                    'description' => 'The full condition set (at most 10). An empty array removes every condition.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'metric'   => array(
                                'type' => 'string',
                                'enum' => self::METRICS,
                            ),
                            'operator' => array(
                                'type' => 'string',
                                'enum' => self::OPERATORS,
                            ),
                            'value'    => array(
                                'type'    => 'number',
                                'minimum' => 0,
                            ),
                        ),
                        'required'   => array('metric', 'operator', 'value'),
                    ),
                ),
                'create_video' => array(
                    'type'        => 'boolean',
                    'description' => 'Keep a load video when an alert triggers (PRO only).',
                    'default'     => false,
                ),
                'send_to'      => array(
                    'type'        => 'array',
                    'description' => 'Team organizations only: the full recipient list. Omit it to leave the existing recipients unchanged.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'email'   => array('type' => 'string', 'format' => 'email'),
                            'channel' => array('type' => 'string', 'enum' => self::CHANNELS),
                        ),
                        'required'   => array('email'),
                    ),
                ),
            ),
            'required'             => array('page_id', 'conditions'),
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

        if (! array_key_exists('conditions', $arguments) || ! is_array($arguments['conditions'])) {
            $bad['conditions'] = array('A list is required; an empty list clears the alerts.');
            $raw               = array();
        } else {
            $raw = $arguments['conditions'];
        }

        if (count($raw) > self::MAX_CONDITIONS) {
            $bad['conditions'] = array('At most ' . self::MAX_CONDITIONS . ' conditions are allowed.');
        }

        $conditions = array();

        foreach (array_values($raw) as $index => $condition) {
            if (! is_array($condition)) {
                $bad[ 'conditions[' . $index . ']' ] = array('Not an object.');

                continue;
            }

            $metric   = is_scalar($condition['metric'] ?? null) ? strtolower(trim((string) $condition['metric'])) : '';
            $operator = is_scalar($condition['operator'] ?? null) ? trim((string) $condition['operator']) : '';
            $value    = $condition['value'] ?? null;
            $valid    = true;

            if (! in_array($metric, self::METRICS, true)) {
                $bad[ 'conditions[' . $index . '].metric' ] = array('Unknown alert metric.');
                $valid                                      = false;
            }

            if (! in_array($operator, self::OPERATORS, true)) {
                $bad[ 'conditions[' . $index . '].operator' ] = array('Operator must be <, = or >.');
                $valid                                        = false;
            }

            if (! is_numeric($value) || (float) $value < 0) {
                $bad[ 'conditions[' . $index . '].value' ] = array('Threshold must be a non-negative number.');
                $valid                                     = false;
            }

            if ($valid) {
                $conditions[] = array(
                    'metric'   => $metric,
                    'operator' => $operator,
                    'value'    => $value + 0,
                );
            }
        }

        $sendTo = null;

        if (array_key_exists('send_to', $arguments)) {
            $sendTo = array();

            if (! is_array($arguments['send_to'])) {
                $bad['send_to'] = array('A list of recipients is required.');
            } else {
                foreach (array_values($arguments['send_to']) as $index => $recipient) {
                    $email   = is_array($recipient) && is_scalar($recipient['email'] ?? null)
                        ? trim((string) $recipient['email'])
                        : '';
                    $channel = is_array($recipient) && is_scalar($recipient['channel'] ?? null)
                        ? trim((string) $recipient['channel'])
                        : 'email';

                    if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $bad[ 'send_to[' . $index . '].email' ] = array('A valid e-mail address is required.');
                        continue;
                    }

                    if (! in_array($channel, self::CHANNELS, true)) {
                        $bad[ 'send_to[' . $index . '].channel' ] = array('The only supported channel is email.');
                        continue;
                    }

                    $sendTo[] = array('email' => $email, 'channel' => $channel);
                }
            }
        }

        if (array() !== $bad) {
            return $this->errors->validationFailed(
                'Each condition needs a documented metric, an operator and a non-negative threshold; nothing was saved.',
                $bad
            );
        }

        $attributes = array(
            'create_video' => $this->boolParam($arguments, 'create_video', false),
            'conditions'   => $conditions,
        );

        if (null !== $sendTo) {
            $attributes['send_to'] = $sendTo;
        }

        try {
            $response = $this->client->request(
                'POST',
                'pages/' . rawurlencode($pageId) . '/performance_alert_conditions',
                array(
                    'data' => array(
                        'type'       => 'performance_alert_conditions',
                        'attributes' => $attributes,
                    ),
                )
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();
        $attrs    = $this->attrs($resource);
        $stored   = array_key_exists('conditions', $attrs)
            ? $this->scalarRows($attrs['conditions'])
            : $conditions;
        $id       = $this->text($resource['id'] ?? '');
        $data     = array(
            'page_id'      => '' !== $id ? $id : $pageId,
            'conditions'   => $stored,
            'create_video' => array_key_exists('create_video', $attrs)
                ? $this->flag($attrs['create_video'])
                : $attributes['create_video'],
            'count'        => count($stored),
        );

        if (array_key_exists('send_to', $attrs)) {
            $data['send_to'] = $this->scalarRows($attrs['send_to']);
        }

        return $this->success(
            sprintf('%d alert condition(s) saved.', count($stored)),
            $data
        );
    }
}
