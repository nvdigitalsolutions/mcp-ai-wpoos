<?php

/**
 * Read a GTmetrix page's performance-alert event history.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Alert_Events` (Proposal 164 section
 * 164.97). Each event names what fired — a condition with the value that
 * triggered it, or a report-generation failure — and who was notified.
 *
 * ## Corrections
 *
 * 1. **The limit is a page size.** WordPress fetched the default page and
 *    sliced it afterwards, so `limit: 100` still returned at most the default
 *    page. The port sends `page[size]` and bounds it to 1–100.
 * 2. **Rows are projected to scalars.** `alerts` and `recipient_emails` are
 *    arrays of arrays and strings; a nested value is dropped rather than
 *    forwarded, as in the alert-condition reader.
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

class GtmetrixGetAlertEventsTool extends GtmetrixTool
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT     = 100;

    public function getSlug(): string
    {
        return 'gtmetrix_get_alert_events';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Alert Events';
    }

    public function getDescription(): string
    {
        return 'Read the performance-alert events triggered for a GTmetrix page, most recent first: which condition fired, the value that triggered it, and any report-generation failures. Costs no credits.';
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
                'limit'   => array(
                    'type'        => 'integer',
                    'description' => 'Maximum events to return.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
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

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));

        try {
            $response = $this->client->request(
                'GET',
                'pages/' . rawurlencode($pageId) . '/performance_alert_events?page[size]=' . $limit
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (array_slice($this->resources($response['data']), 0, $limit) as $event) {
            $attrs  = $this->attrs($event);
            $rows[] = array(
                'event_id'  => $this->text($event['id'] ?? ''),
                'timestamp' => $this->numeric($attrs['timestamp'] ?? null),
                'report_id' => $this->text($attrs['report_id'] ?? ''),
                'alerts'    => $this->scalarRows($attrs['alerts'] ?? array()),
                'recipients' => $this->strings($attrs['recipient_emails'] ?? array()),
            );
        }

        return $this->success(
            sprintf('%d alert event(s).', count($rows)),
            array(
                'page_id' => $pageId,
                'events'  => $rows,
                'count'   => count($rows),
            )
        );
    }
}
