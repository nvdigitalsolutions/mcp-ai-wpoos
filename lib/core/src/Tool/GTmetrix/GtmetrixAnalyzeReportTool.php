<?php

/**
 * Analyse a GTmetrix report into vitals, budgets and fixes.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Analyze_Report` (Proposal 164 section
 * 164.94).
 *
 * Everything is computed in PHP from the report and its Lighthouse resource;
 * nothing here is an LLM judgement, so the same report always yields the same
 * advice.
 *
 * ## Corrections
 *
 * 1. **A missing score is not an F.** WordPress derived
 *    `band_grade( gtmetrix_score / 100 )` unconditionally, so a report without
 *    a score (a metrics-only report has no grade) reported an F. The grade is
 *    derived only when a score exists; otherwise it is empty and the caller can
 *    see the scores are null.
 * 2. **A failed Lighthouse fetch is reported.** WordPress wrapped the fetch in
 *    `if ( ! is_wp_error() )` and answered an empty audit list for both a
 *    metrics-only report and a network failure, which reads as "nothing to
 *    fix". The `lighthouse` block says whether the resource was read and, when
 *    it was not, the classified reason.
 *
 * ## Adaptation
 *
 * WordPress accepted `url` and resolved the newest local snapshot. The core has
 * no snapshot store (section 164.92), so the parameter is not offered and a
 * supplied `url` is refused by name instead of being ignored.
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

class GtmetrixAnalyzeReportTool extends GtmetrixTool
{
    /** How many audits the reply carries; the full list is one call away. */
    public const TOP_AUDITS = 10;

    public function getSlug(): string
    {
        return 'gtmetrix_analyze_report';
    }

    public function getName(): string
    {
        return 'GTmetrix Analyze Report';
    }

    public function getDescription(): string
    {
        return 'Analyze a GTmetrix report against Core Web Vitals thresholds, page-weight budgets and the GTmetrix grade bands, then return an impact-ordered audit list with the WordPress fixes mapped to each finding. Deterministic; INP cannot be certified from lab data (TBT is the proxy). Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id' => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix report id to analyze. This runtime keeps no local snapshots, so a report id is required.',
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

        if (array_key_exists('url', $arguments) && is_scalar($arguments['url']) && '' !== trim((string) $arguments['url'])) {
            return $this->errors->create(
                'gtmetrix_no_snapshot_store',
                'This runtime keeps no local report snapshots; pass report_id instead of url.'
            );
        }

        $reportId = $this->identifierArg($arguments, 'report_id');

        if ('' === $reportId) {
            return $this->errors->validationFailed(
                'A report id is required and may contain only letters, digits, hyphens and underscores.',
                array('report_id' => array('Invalid report id.'))
            );
        }

        try {
            $report = $this->client->request('GET', 'reports/' . rawurlencode($reportId));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $resource = is_array($report['data']) ? $report['data'] : array();

        if (array() === $resource) {
            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix returned no report for that id.'
            );
        }

        $flat   = GtmetrixScoreEngine::flatReport($resource);
        $cwv    = GtmetrixScoreEngine::assessCwv($flat);
        $budget = GtmetrixScoreEngine::budgetClass(
            $flat['page_bytes'] ?? 0,
            $flat['page_requests'] ?? 0
        );

        $audits      = array();
        $lighthouse  = array(
            'available'  => false,
            'error_code' => null,
            'error'      => null,
        );
        $lcpPhases = GtmetrixScoreEngine::lcpPhases(array());

        try {
            $response   = $this->client->request(
                'GET',
                'reports/' . rawurlencode($reportId) . '/resources/lighthouse.json'
            );
            $document   = $this->resourceBody($response);
            $block      = is_array($document['audits'] ?? null) ? $document['audits'] : array();
            $audits     = GtmetrixScoreEngine::impactOrder($block);
            $lcpPhases  = GtmetrixScoreEngine::lcpPhases($document);
            $lighthouse = array(
                'available'  => true,
                'error_code' => null,
                'error'      => null,
            );
        } catch (GtmetrixApiException $error) {
            $lighthouse = array(
                'available'  => false,
                'error_code' => $error->errorCode,
                'error'      => $error->getMessage(),
            );
        }

        $matched = GtmetrixFixEngine::match($audits);
        $grade   = $this->text($flat['gtmetrix_grade'] ?? '');
        $score   = $this->numeric($flat['gtmetrix_score'] ?? null);

        if ('' === $grade && null !== $score) {
            $grade = GtmetrixScoreEngine::bandGrade((float) $score / 100);
        }

        return $this->success(
            sprintf(
                'Report %s: grade %s, Core Web Vitals %s, %d fixes matched.',
                $reportId,
                '' !== $grade ? $grade : 'ungraded',
                $cwv['overall'],
                count($matched['fixes'])
            ),
            array(
                'report_id'       => $reportId,
                'url'             => $this->text($flat['url'] ?? ''),
                'grade'           => $grade,
                'scores'          => array(
                    'gtmetrix'    => $score,
                    'performance' => $this->numeric($flat['performance_score'] ?? null),
                    'structure'   => $this->numeric($flat['structure_score'] ?? null),
                ),
                'core_web_vitals' => $cwv,
                'budget'          => $budget,
                'lcp_phases'      => $lcpPhases,
                'lighthouse'      => $lighthouse,
                'top_audits'      => array_slice($audits, 0, self::TOP_AUDITS),
                'fixes'           => array(
                    'matched'   => $matched['fixes'],
                    'count'     => count($matched['fixes']),
                    'unfixable' => $matched['unfixable'],
                    'unknown'   => $matched['unknown'],
                ),
                'next'            => 'Use gtmetrix_generate_wpcode_snippet to turn the matched fixes into a WPCode-ready snippet.',
            )
        );
    }
}
