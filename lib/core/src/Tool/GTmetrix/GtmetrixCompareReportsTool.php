<?php

/**
 * Compare two GTmetrix reports.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Compare_Reports` (Proposal 164 section
 * 164.94). Eight metrics get a verdict: two scores against a 5-point absolute
 * move, four timings and two sizes against a percentage move, and cumulative
 * layout shift against an absolute 0.05. A metric absent on either side is
 * omitted rather than called steady.
 *
 * ## Adaptation
 *
 * WordPress accepted `url` and compared the two newest local snapshots. The
 * core has no snapshot store (section 164.92), so the parameter is not offered
 * and a supplied `url` is refused by name. The reply names each report by its
 * datum id when the API returned one, falling back to the id that was asked
 * for.
 *
 * Reports are only comparable when both used the same analysis options
 * (location, browser, device); the tool says so rather than guessing.
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

class GtmetrixCompareReportsTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_compare_reports';
    }

    public function getName(): string
    {
        return 'GTmetrix Compare Reports';
    }

    public function getDescription(): string
    {
        return 'Compare two GTmetrix reports and return per-metric deltas with regressed, improved or steady verdicts. Only meaningful when both reports used the same analysis options (location, browser, device). Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id_a' => array(
                    'type'        => 'string',
                    'description' => 'The baseline (before) report id.',
                    'minLength'   => 1,
                ),
                'report_id_b' => array(
                    'type'        => 'string',
                    'description' => 'The follow-up (after) report id.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('report_id_a', 'report_id_b'),
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
                'This runtime keeps no local report snapshots; pass report_id_a and report_id_b instead of url.'
            );
        }

        $idA = $this->identifierArg($arguments, 'report_id_a');
        $idB = $this->identifierArg($arguments, 'report_id_b');
        $bad = array();

        if ('' === $idA) {
            $bad['report_id_a'] = array('Invalid report id.');
        }

        if ('' === $idB) {
            $bad['report_id_b'] = array('Invalid report id.');
        }

        if (array() !== $bad) {
            return $this->errors->validationFailed(
                'Two report ids are required; each may contain only letters, digits, hyphens and underscores.',
                $bad
            );
        }

        try {
            $responseA = $this->client->request('GET', 'reports/' . rawurlencode($idA));
            $responseB = $this->client->request('GET', 'reports/' . rawurlencode($idB));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $datumA = is_array($responseA['data']) ? $responseA['data'] : array();
        $datumB = is_array($responseB['data']) ? $responseB['data'] : array();

        if (array() === $datumA || array() === $datumB) {
            return $this->errors->create(
                'gtmetrix_invalid_response',
                'GTmetrix returned no report for one of those ids.'
            );
        }

        $flatA = GtmetrixScoreEngine::flatReport($datumA);
        $flatB = GtmetrixScoreEngine::flatReport($datumB);
        $deltas = GtmetrixScoreEngine::reportDelta($flatA, $flatB);
        $regressed = 0;

        foreach ($deltas as $row) {
            if ('regressed' === $row['status']) {
                ++$regressed;
            }
        }

        return $this->success(
            0 === $regressed
                ? 'No regressions detected.'
                : sprintf('%d metric(s) regressed — review the deltas.', $regressed),
            array(
                'before'          => $this->summary($flatA, $idA),
                'after'           => $this->summary($flatB, $idB),
                'deltas'          => array_values($deltas),
                'regressed_count' => $regressed,
                'verdict'         => 0 === $regressed
                    ? 'No regressions detected.'
                    : sprintf('%d metric(s) regressed — review the deltas.', $regressed),
            )
        );
    }

    /**
     * The identifying block for one side of the comparison.
     *
     * @param array<string, mixed> $flat     Flat attributes.
     * @param string               $fallback The requested id.
     *
     * @return array{report_id: string, grade: string, score: int|float|null}
     */
    private function summary(array $flat, string $fallback): array
    {
        $id = $this->text($flat['id'] ?? '');

        return array(
            'report_id' => '' !== $id ? $id : $fallback,
            'grade'     => $this->text($flat['gtmetrix_grade'] ?? ''),
            'score'     => $this->numeric($flat['performance_score'] ?? null),
        );
    }
}
