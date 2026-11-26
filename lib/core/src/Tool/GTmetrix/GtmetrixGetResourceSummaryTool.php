<?php

/**
 * Read a GTmetrix report's resource summary.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Resource_Summary` (Proposal 164 section
 * 164.93). The API answers seven categories — document, font, image, media,
 * other, script, stylesheet — each with a label, a request count and the
 * transferred and uncompressed byte totals. They are ordered heaviest first,
 * which is the order a page-weight triage wants.
 *
 * ## Corrections
 *
 * 1. **The resource body is read from the document.** WordPress's client
 *    special-cased the unwrapped body; the transport returns the document and
 *    the base class accepts both shapes, so the tool does not depend on which
 *    shape the API chose.
 * 2. **A label that is not a string is ignored.** WordPress passed
 *    `$data['label']` through untouched, so a malformed label became an array
 *    in the reply. A non-scalar label falls back to the category name.
 *
 * Byte counts are non-negative integers; a category whose values are not
 * numeric contributes zero rather than a PHP cast warning.
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

class GtmetrixGetResourceSummaryTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_get_resource_summary';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Resource Summary';
    }

    public function getDescription(): string
    {
        return 'Read the resource summary of a GTmetrix report: counts and transferred/uncompressed sizes per type (HTML, images, scripts, styles, fonts, media, other), heaviest first. Costs no credits.';
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
            $response = $this->client->request(
                'GET',
                'reports/' . rawurlencode($reportId) . '/resources/resource-summary'
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $summary   = $this->resourceBody($response);
        $rows      = array();
        $totalReqs = 0;

        foreach ($summary as $type => $data) {
            if (! is_array($data)) {
                continue;
            }

            $label = $this->text($data['label'] ?? '');
            $count = max(0, $this->number($data['count'] ?? 0));

            $totalReqs += $count;
            $rows[]     = array(
                'type'              => (string) $type,
                'label'             => '' !== $label ? $label : (string) $type,
                'count'             => $count,
                'size_transferred'  => max(0, $this->number($data['size_transferred'] ?? 0)),
                'size_uncompressed' => max(0, $this->number($data['size_uncompressed'] ?? 0)),
            );
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['size_transferred'] <=> $a['size_transferred']
        );

        return $this->success(
            sprintf('%d resource categories, %d requests.', count($rows), $totalReqs),
            array(
                'report_id'      => $reportId,
                'categories'     => $rows,
                'total_requests' => $totalReqs,
            )
        );
    }
}
