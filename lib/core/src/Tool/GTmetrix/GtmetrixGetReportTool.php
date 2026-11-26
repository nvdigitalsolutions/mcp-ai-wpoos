<?php

/**
 * Read a GTmetrix report.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Report` (Proposal 164 section 164.93).
 *
 * ## Corrections
 *
 * 1. **The resource links are read from the datum.** JSON:API puts a report's
 *    links beside `attributes`, and every committed fixture and the published
 *    v2.0 documentation agree; WordPress read `$attrs['links']`, so `resources`
 *    was always empty on a real response. The sibling block is read here.
 * 2. **Numbers keep their type.** WordPress passed attributes through, so a
 *    missing key was a PHP notice and a float score kept its decimals by luck.
 *    Absent values are `null`; a cumulative layout shift of 0.05 stays 0.05.
 *
 * ## Adaptation
 *
 * WordPress preferred a locally snapshotted report and fell back to the API.
 * The snapshots were written by the WP-Cron poller, which the core does not
 * have (section 164.92), so there is nothing to prefer: the report is read
 * live. A host that keeps its own copy can front this tool with its own cache.
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

class GtmetrixGetReportTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_get_report';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Report';
    }

    public function getDescription(): string
    {
        return 'Read a GTmetrix report: grade, scores, Core Web Vitals and page timings, page size and request count, plus the resource names the report offers. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix report id, as returned by gtmetrix_get_test_status or gtmetrix_list_tests.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('report_id'),
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

        $reportId = $this->identifierArg($arguments, 'report_id');

        if ('' === $reportId) {
            return $this->errors->validationFailed(
                'A report id is required and may contain only letters, digits, hyphens and underscores.',
                array('report_id' => array('Invalid report id.'))
            );
        }

        try {
            $response = $this->client->request('GET', 'reports/' . rawurlencode($reportId));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($response['data']) ? $response['data'] : array();

        if (array() === $resource) {
            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix returned no report for that id.'
            );
        }

        $attrs = $this->attrs($resource);
        $links = is_array($resource['links'] ?? null) ? $resource['links'] : array();

        return $this->success(
            'GTmetrix report ' . $reportId . '.',
            array(
                'report_id' => $reportId,
                'url'       => $this->text($attrs['url'] ?? ''),
                'grade'     => $this->text($attrs['gtmetrix_grade'] ?? ''),
                'scores'    => array(
                    'gtmetrix'    => $this->numeric($attrs['gtmetrix_score'] ?? null),
                    'performance' => $this->numeric($attrs['performance_score'] ?? null),
                    'structure'   => $this->numeric($attrs['structure_score'] ?? null),
                    'pagespeed'   => $this->numeric($attrs['pagespeed_score'] ?? null),
                    'yslow'       => $this->numeric($attrs['yslow_score'] ?? null),
                ),
                'metrics'   => array(
                    'ttfb'         => $this->numeric($attrs['time_to_first_byte'] ?? null),
                    'fcp'          => $this->numeric($attrs['first_contentful_paint'] ?? null),
                    'lcp'          => $this->numeric($attrs['largest_contentful_paint'] ?? null),
                    'tti'          => $this->numeric($attrs['time_to_interactive'] ?? null),
                    'tbt'          => $this->numeric($attrs['total_blocking_time'] ?? null),
                    'cls'          => $this->numeric($attrs['cumulative_layout_shift'] ?? null),
                    'speed_index'  => $this->numeric($attrs['speed_index'] ?? null),
                    'onload_time'  => $this->numeric($attrs['onload_time'] ?? null),
                    'fully_loaded' => $this->numeric($attrs['fully_loaded_time'] ?? null),
                ),
                'page'      => array(
                    'bytes'      => $this->numeric($attrs['page_bytes'] ?? null),
                    'requests'   => $this->numeric($attrs['page_requests'] ?? null),
                    'html_bytes' => $this->numeric($attrs['html_bytes'] ?? null),
                ),
                'created'   => $this->numeric($attrs['created'] ?? null),
                'expires'   => $this->numeric($attrs['expires'] ?? null),
                'resources' => array_keys($links),
            )
        );
    }
}
