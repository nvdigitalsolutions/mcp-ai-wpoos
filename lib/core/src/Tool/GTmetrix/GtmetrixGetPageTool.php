<?php

/**
 * Read one GTmetrix page.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Page` (Proposal 164 section 164.96).
 *
 * A page is GTmetrix's URL registry: one URL plus one analysis-options
 * combination, aggregating every report produced for it. This tool is the
 * lookup that turns a page id into the settings a retest or a monitoring change
 * will reuse.
 *
 * ## Corrections
 *
 * 1. **A missing page id is the requested id.** WordPress reported an empty
 *    `page_id` when the datum omitted one, even though it had the id it asked
 *    for. The requested id is the fallback.
 * 2. **Tags are strings.** `page_tags` is passed through `strings()`, so a
 *    malformed element cannot put an array where a tag id belongs.
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

class GtmetrixGetPageTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_get_page';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Page';
    }

    public function getDescription(): string
    {
        return 'Read a GTmetrix page: URL, browser and location options, the latest report, monitoring frequency, report count and attached tags. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'page_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix page id, as returned by gtmetrix_list_pages.',
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
            $response = $this->client->request('GET', 'pages/' . rawurlencode($pageId));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();

        if (array() === $resource) {
            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix returned no page for that id.'
            );
        }

        $attrs = $this->attrs($resource);
        $id    = $this->text($resource['id'] ?? '');

        return $this->success(
            'GTmetrix page ' . ( '' !== $id ? $id : $pageId ) . '.',
            array(
                'page_id'            => '' !== $id ? $id : $pageId,
                'url'                => $this->text($attrs['url'] ?? ''),
                'browser'            => $this->text($attrs['browser'] ?? ''),
                'location'           => $this->text($attrs['location'] ?? ''),
                'latest_report'      => $this->text($attrs['latest_report'] ?? ''),
                'latest_report_time' => $this->numeric($attrs['latest_report_time'] ?? null),
                'report_count'       => $this->number($attrs['report_count'] ?? 0),
                'monitored'          => '' !== $this->text($attrs['monitored'] ?? '') ? $this->text($attrs['monitored']) : 'no',
                'tags'               => $this->strings($attrs['page_tags'] ?? array()),
            )
        );
    }
}
