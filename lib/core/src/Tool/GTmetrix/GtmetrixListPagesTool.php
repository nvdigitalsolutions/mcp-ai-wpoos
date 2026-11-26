<?php

/**
 * List GTmetrix pages.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Pages` (Proposal 164 section 164.96).
 * A page is the account's URL registry entry — one URL plus one analysis-options
 * combination — so this is the lookup that yields a page id for a retest, a
 * monitoring change or an alert condition.
 *
 * ## Corrections
 *
 * 1. **The limit is a page size, not a post-fetch slice.** WordPress fetched
 *    the default page (ten pages), applied `absint()` and sliced the result, so
 *    `limit: 100` still returned at most ten. The port sends `page[size]` and
 *    bounds it to 1–500, the documented maximum.
 * 2. **An unknown monitoring value is refused.** WordPress `sanitize_key`d it
 *    and passed whatever survived; an unknown value matched nothing and read as
 *    "no monitored pages". The filter is validated against the documented set.
 * 3. **The URL filter is encoded.** The value is a URL fragment, and it is
 *    `rawurlencode`d into the query string so a `&` or `]` in it cannot add a
 *    parameter.
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

class GtmetrixListPagesTool extends GtmetrixTool
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT     = 500;

    /** The values the API accepts for `filter[monitored]`. */
    public const MONITORED = array('no', 'hourly', 'daily', 'weekly', 'monthly');

    public function getSlug(): string
    {
        return 'gtmetrix_list_pages';
    }

    public function getName(): string
    {
        return 'GTmetrix List Pages';
    }

    public function getDescription(): string
    {
        return 'List the pages in the GTmetrix account with their latest report, monitoring frequency, report count and tags. Use it to find the page id a retest or a monitoring change needs. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'url'       => array(
                    'type'        => 'string',
                    'description' => 'Fuzzy-filter pages by URL fragment (for example example.com/shop).',
                ),
                'monitored' => array(
                    'type'        => 'string',
                    'enum'        => self::MONITORED,
                    'description' => 'Filter by monitoring state.',
                ),
                'limit'     => array(
                    'type'        => 'integer',
                    'description' => 'Maximum pages to return.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
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

        $monitored = '';

        if (array_key_exists('monitored', $arguments) && is_scalar($arguments['monitored'])) {
            $monitored = trim((string) $arguments['monitored']);
        }

        if ('' !== $monitored && ! in_array($monitored, self::MONITORED, true)) {
            return $this->errors->validationFailed(
                'monitored must be one of: ' . implode(', ', self::MONITORED) . '.',
                array('monitored' => array('Unknown monitoring state.'))
            );
        }

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));
        $query = array();

        if (array_key_exists('url', $arguments) && is_scalar($arguments['url']) && '' !== trim((string) $arguments['url'])) {
            $query[] = 'filter[url]=' . rawurlencode(trim((string) $arguments['url']));
        }

        if ('' !== $monitored) {
            $query[] = 'filter[monitored]=' . rawurlencode($monitored);
        }

        $query[] = 'page[size]=' . $limit;

        try {
            $response = $this->client->request('GET', 'pages?' . implode('&', $query));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (array_slice($this->resources($response['data']), 0, $limit) as $page) {
            $attrs  = $this->attrs($page);
            $rows[] = array(
                'page_id'            => $this->text($page['id'] ?? ''),
                'url'                => $this->text($attrs['url'] ?? ''),
                'latest_report'      => $this->text($attrs['latest_report'] ?? ''),
                'latest_report_time' => $this->numeric($attrs['latest_report_time'] ?? null),
                'report_count'       => $this->number($attrs['report_count'] ?? 0),
                'monitored'          => '' !== $this->text($attrs['monitored'] ?? '') ? $this->text($attrs['monitored']) : 'no',
                'tags'               => $this->strings($attrs['page_tags'] ?? array()),
            );
        }

        return $this->success(
            sprintf('%d page(s).', count($rows)),
            array(
                'pages' => $rows,
                'count' => count($rows),
            )
        );
    }
}
