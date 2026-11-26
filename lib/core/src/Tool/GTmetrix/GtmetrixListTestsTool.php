<?php

/**
 * Recent GTmetrix tests.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Tests` (Proposal 164 section 164.91).
 * One credit-free `GET /tests`.
 *
 * **Correction:** the WordPress `limit` went through `absint()` and was applied
 * after the request, so it described a page size it never asked the API for and
 * could claim `1000` while returning the first fifty. The port bounds it to
 * 1–100 and sends it as `page[size]`, so the number means what it says.
 *
 * GTmetrix keeps tests for **24 hours**; a report id taken from this list may
 * already be gone, which the description states and the payload does not hide.
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
use Nvoos\Core\Support\Text;

class GtmetrixListTestsTool extends GtmetrixTool
{
    public const STATES = array('queued', 'started', 'error', 'completed');

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function getSlug(): string
    {
        return 'gtmetrix_list_tests';
    }

    public function getName(): string
    {
        return 'GTmetrix List Tests';
    }

    public function getDescription(): string
    {
        return 'Lists recent GTmetrix tests with their state and URL. GTmetrix retains tests for 24 hours only, so an older report id may already have expired. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'state' => array(
                    'type'        => 'string',
                    'enum'        => self::STATES,
                    'description' => 'Filter by test state.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum tests to return.',
                    'default'     => self::DEFAULT_LIMIT,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                ),
            ),
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

        $state = array_key_exists('state', $arguments) && is_scalar($arguments['state'])
            ? Text::sanitizeKey((string) $arguments['state'])
            : '';

        if ('' !== $state && ! in_array($state, self::STATES, true)) {
            return $this->errors->validationFailed(
                'state must be one of: ' . implode(', ', self::STATES) . '.',
                array('state' => array('Unknown state.'))
            );
        }

        $limit = $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT);

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return $this->errors->validationFailed(
                sprintf('limit must be between 1 and %d.', self::MAX_LIMIT),
                array('limit' => array('Out of range.'))
            );
        }

        $query = array('page[size]' => $limit);

        if ('' !== $state) {
            $query['filter[state]'] = $state;
        }

        try {
            $response = $this->client->request('GET', 'tests?' . http_build_query($query));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (array_slice($this->resources($response['data']), 0, $limit) as $resource) {
            $attrs  = $this->attrs($resource);
            $rows[] = array(
                'test_id'   => $this->text($resource['id'] ?? ''),
                'url'       => $this->text($attrs['url'] ?? ''),
                'state'     => $this->text($attrs['state'] ?? ''),
                'report_id' => $this->text($attrs['report'] ?? ''),
                'created'   => is_numeric($attrs['created'] ?? null) ? (int) $attrs['created'] : null,
                'finished'  => is_numeric($attrs['finished'] ?? null) ? (int) $attrs['finished'] : null,
            );
        }

        return $this->success(
            'Recent GTmetrix tests. Tests are retained for 24 hours.',
            array('tests' => $rows, 'count' => count($rows))
        );
    }
}
