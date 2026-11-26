<?php

/**
 * Read the Lighthouse audits behind a GTmetrix report.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Lighthouse_Audits` (Proposal 164 section
 * 164.93).
 *
 * The ordering is the GTmetrix `_impactScore`, not the Lighthouse score: an
 * audit with a perfect score and no impact is not a fix to make, and an audit
 * with a poor score and high impact is. The band thresholds are the published
 * table (Low ≤ 0.12, Med-Low ≤ 0.24, Med ≤ 0.36, Med-High ≤ 0.48, High above).
 *
 * ## Corrections
 *
 * 1. **An unknown impact band is refused.** WordPress ran `sanitize_text_field`
 *    and treated anything it did not recognise as "no filter", so a typo
 *    returned every audit while reading as a filtered list.
 * 2. **The limit is bounded.** WordPress applied `absint()` with no ceiling,
 *    and a limit of 0 still returned one audit because the bound was checked
 *    after the append. The port clamps to 1–200.
 * 3. **The resource body is unwrapped once.** WordPress's client special-cased
 *    the `attributes` wrapper per call; the transport returns the document and
 *    the base class accepts both shapes.
 *
 * A metrics-only or legacy report has no Lighthouse resource, and the API
 * answers a not-found error that is classified as `gtmetrix_not_found`.
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

class GtmetrixGetLighthouseAuditsTool extends GtmetrixTool
{
    /** How many audits to return by default. */
    public const DEFAULT_LIMIT = 25;

    /** The hard ceiling on one page of audits. */
    public const MAX_LIMIT = 200;

    /** The bands a caller may filter by. */
    public const FILTERABLE = array('Low', 'Med-Low', 'Med', 'Med-High', 'High');

    public function getSlug(): string
    {
        return 'gtmetrix_get_lighthouse_audits';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Lighthouse Audits';
    }

    public function getDescription(): string
    {
        return 'Read the Lighthouse audits behind a GTmetrix report, ordered by GTmetrix impact score, with the LCP phase breakdown. Filter by minimum impact band (Low, Med-Low, Med, Med-High, High). Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id'  => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix report id.',
                    'minLength'   => 1,
                ),
                'min_impact' => array(
                    'type'        => 'string',
                    'description' => 'Only return audits at or above this impact band.',
                    'enum'        => array('Low', 'Med-Low', 'Med', 'Med-High', 'High'),
                ),
                'limit'      => array(
                    'type'        => 'integer',
                    'description' => 'Maximum audits to return.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
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

        $minImpact = '';

        if (array_key_exists('min_impact', $arguments) && is_scalar($arguments['min_impact'])) {
            $minImpact = trim((string) $arguments['min_impact']);
        }

        if ('' !== $minImpact && ! in_array($minImpact, self::FILTERABLE, true)) {
            return $this->errors->validationFailed(
                'min_impact must be one of: ' . implode(', ', self::FILTERABLE) . '.',
                array('min_impact' => array('Unknown impact band.'))
            );
        }

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));

        try {
            $response = $this->client->request(
                'GET',
                'reports/' . rawurlencode($reportId) . '/resources/lighthouse.json'
            );
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $document = $this->resourceBody($response);
        $audits   = is_array($document['audits'] ?? null) ? $document['audits'] : array();
        $rows     = GtmetrixScoreEngine::impactOrder($audits);
        $minIndex = '' === $minImpact ? 0 : GtmetrixScoreEngine::BAND_ORDER[ $minImpact ];

        $filtered = array();

        foreach ($rows as $row) {
            if (GtmetrixScoreEngine::BAND_ORDER[ $row['impact_band'] ] < $minIndex) {
                continue;
            }

            $filtered[] = $row;

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $this->success(
            sprintf(
                '%d of %d Lighthouse audits%s.',
                count($filtered),
                count($rows),
                '' === $minImpact ? '' : ' at ' . $minImpact . ' impact or above'
            ),
            array(
                'report_id'    => $reportId,
                'audits'       => $filtered,
                'count'        => count($filtered),
                'total_audits' => count($rows),
                'lcp_phases'   => GtmetrixScoreEngine::lcpPhases($document),
            )
        );
    }
}
