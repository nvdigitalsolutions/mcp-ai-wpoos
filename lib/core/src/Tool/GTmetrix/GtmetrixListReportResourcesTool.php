<?php

/**
 * List the resources a GTmetrix report offers.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_List_Report_Resources` (Proposal 164 section
 * 164.93).
 *
 * ## Correction
 *
 * **The annotation table is keyed by the API's link names.** WordPress keyed it
 * by *endpoint* names (`har`, `lighthouse.json`, `resource-summary`, `net.har`),
 * but the report datum's `links` object uses short link names (`har`,
 * `lighthouse`, `resource_summary`). Every lookup therefore missed and fell
 * through to the "not sensitive" default, so `har` — a raw network capture the
 * client refuses to fetch — was listed as downloadable. The table now carries
 * both names: `name` is the link key, `resource` is the endpoint a caller would
 * fetch, and the sensitivity and 24-hour-expiry flags are attached to the link
 * key they belong to.
 *
 * The table also gains the links the WordPress table never had:
 * `report_pdf_full`, `report_url` and `filmstrip-frame`.
 *
 * ## Adaptation
 *
 * WordPress read the links from `$report['attributes']['links']`. JSON:API puts
 * them beside `attributes`, so the sibling block is read here — the same
 * correction `gtmetrix_get_report` makes.
 *
 * This tool lists; it does not fetch. The client refuses `analysis-options` and
 * `net.har` by name, and `sensitive` here marks the same two.
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

class GtmetrixListReportResourcesTool extends GtmetrixTool
{
    /**
     * Link name → endpoint, sensitivity and expiry, from the published v2.0
     * report-resource table.
     *
     * @var array<string, array{resource: string, sensitive: bool, expires_24h: bool, notes: string}>
     */
    public const RESOURCES = array(
        'screenshot'       => array(
            'resource'    => 'screenshot.jpg',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Report screenshot image.',
        ),
        'filmstrip'        => array(
            'resource'    => 'filmstrip.json',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Filmstrip frames captured during load.',
        ),
        'filmstrip-frame'  => array(
            'resource'    => 'filmstrip-frame.jpg',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'One filmstrip frame; append ?id=N to the endpoint.',
        ),
        'har'              => array(
            'resource'    => 'net.har',
            'sensitive'   => true,
            'expires_24h' => false,
            'notes'       => 'Raw network capture - never fetched by the toolkit.',
        ),
        'analysis_options' => array(
            'resource'    => 'analysis-options',
            'sensitive'   => true,
            'expires_24h' => false,
            'notes'       => 'Contains HTTP auth credentials and cookies - never fetched by the toolkit.',
        ),
        'video'            => array(
            'resource'    => 'video.mp4',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Page-load video (extra credits when requested).',
        ),
        'optimized_images' => array(
            'resource'    => 'optimized-images.tar',
            'sensitive'   => false,
            'expires_24h' => true,
            'notes'       => 'Optimized image assets; only available for 24 hours.',
        ),
        'report_pdf'       => array(
            'resource'    => 'report.pdf',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'PDF render - costs extra credits.',
        ),
        'report_pdf_full'  => array(
            'resource'    => 'report.pdf',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Full PDF render - costs extra credits.',
        ),
        'report_url'       => array(
            'resource'    => '',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'The human-readable GTmetrix report page.',
        ),
        'lighthouse'       => array(
            'resource'    => 'lighthouse.json',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Lighthouse audit JSON with GTmetrix extensions.',
        ),
        'pagespeed'        => array(
            'resource'    => 'pagespeed.json',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Legacy PageSpeed JSON.',
        ),
        'pagespeed_files'  => array(
            'resource'    => 'pagespeed-files.tar',
            'sensitive'   => false,
            'expires_24h' => true,
            'notes'       => 'Legacy optimized files; only available for 24 hours.',
        ),
        'yslow'            => array(
            'resource'    => 'yslow.json',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Legacy YSlow JSON.',
        ),
        'resource_summary' => array(
            'resource'    => 'resource-summary',
            'sensitive'   => false,
            'expires_24h' => false,
            'notes'       => 'Per-type resource counts and sizes.',
        ),
    );

    public function getSlug(): string
    {
        return 'gtmetrix_list_report_resources';
    }

    public function getName(): string
    {
        return 'GTmetrix List Report Resources';
    }

    public function getDescription(): string
    {
        return 'List the resources a GTmetrix report offers (screenshot, Lighthouse JSON, HAR, optimized images, PDF, video) with the endpoint name, expiry, credit cost and sensitivity. The HAR and analysis options are sensitive and never fetched. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix report id.',
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

        $datum = is_array($response['data']) ? $response['data'] : array();
        $links = is_array($datum['links'] ?? null) ? $datum['links'] : array();
        $rows  = array();

        foreach (array_keys($links) as $name) {
            $name = (string) $name;
            $meta = self::RESOURCES[ $name ] ?? array(
                'resource'    => '',
                'sensitive'   => false,
                'expires_24h' => false,
                'notes'       => '',
            );

            $rows[] = array(
                'name'         => $name,
                'resource'     => $meta['resource'],
                'downloadable' => ! $meta['sensitive'],
                'sensitive'    => $meta['sensitive'],
                'expires_24h'  => $meta['expires_24h'],
                'notes'        => $meta['notes'],
            );
        }

        return $this->success(
            sprintf('%d resources available for report %s.', count($rows), $reportId),
            array(
                'report_id' => $reportId,
                'resources' => $rows,
                'count'     => count($rows),
            )
        );
    }
}
