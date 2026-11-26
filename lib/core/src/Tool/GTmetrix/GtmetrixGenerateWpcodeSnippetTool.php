<?php

/**
 * Generate a WPCode-ready snippet from a report's findings.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Generate_WPCode_Snippet` (Proposal 164
 * section 164.95). Read-only: it returns code and installs nothing. Installing
 * is `gtmetrix_install_wpcode_fix`, which needs a code-snippet store the core
 * does not have and is recorded as deferred in the parity register.
 *
 * ## Corrections
 *
 * 1. **Only a missing Lighthouse resource answers `gtmetrix_no_lighthouse`.**
 *    WordPress replaced every fetch failure — including a network error and a
 *    rate limit — with "this report has no Lighthouse JSON", which hid the
 *    cause. A not-found classification keeps that message; anything else is
 *    returned as the classified API failure it is.
 * 2. **Matched fixes and emitted code blocks are counted apart.** A
 *    configuration-only fix (keep one analytics path) matches a finding but
 *    carries no code, and WordPress counted it in `count` while emitting
 *    nothing, so the reply claimed one more block than the snippet held.
 *    `emitted` counts the blocks that are actually in the code.
 *
 * ## Adaptation
 *
 * `include_risky` uses the tool's boolean coercion, so a string `"false"` from
 * a JSON caller is false rather than PHP-cast true.
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

class GtmetrixGenerateWpcodeSnippetTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_generate_wpcode_snippet';
    }

    public function getName(): string
    {
        return 'GTmetrix Generate WPCode Snippet';
    }

    public function getDescription(): string
    {
        return 'Generate a WPCode-ready PHP optimization snippet containing only the blocks that match a GTmetrix report findings. Safe blocks are enabled; risky blocks are commented with staging warnings. Returns code only - nothing is installed. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id'     => array(
                    'type'        => 'string',
                    'description' => 'The GTmetrix report id whose findings drive the snippet.',
                    'minLength'   => 1,
                ),
                'include_risky' => array(
                    'type'        => 'boolean',
                    'description' => 'Include risky blocks (commented out with staging warnings).',
                    'default'     => true,
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

        $flat = GtmetrixScoreEngine::flatReport($resource);

        try {
            $response = $this->client->request(
                'GET',
                'reports/' . rawurlencode($reportId) . '/resources/lighthouse.json'
            );
        } catch (GtmetrixApiException $error) {
            if ('gtmetrix_not_found' !== $error->errorCode) {
                return $this->failure($error);
            }

            return $this->errors->create(
                'gtmetrix_no_lighthouse',
                'This report has no Lighthouse JSON (metrics-only or legacy report).',
                array(
                    'status'   => $error->statusCode,
                    'api_code' => $error->apiCode,
                )
            );
        }

        $document = $this->resourceBody($response);
        $audits   = is_array($document['audits'] ?? null) ? $document['audits'] : array();
        $matched  = GtmetrixFixEngine::match(GtmetrixScoreEngine::impactOrder($audits));
        $fixes    = $matched['fixes'];

        if (! $this->boolParam($arguments, 'include_risky', true)) {
            $fixes = array_values(
                array_filter(
                    $fixes,
                    static fn (array $fix): bool => 'high' !== ( $fix['risk'] ?? '' )
                )
            );
        }

        if (array() === $fixes) {
            return $this->success(
                'No known fixes matched this report failing audits.',
                array(
                    'report_id' => $reportId,
                    'code'      => '',
                    'blocks'    => array(),
                    'count'     => 0,
                    'emitted'   => 0,
                    'note'      => 'Unfixable and unknown audits are listed separately; they need host or third-party action.',
                    'unfixable' => $matched['unfixable'],
                    'unknown'   => $matched['unknown'],
                )
            );
        }

        $url  = $this->text($flat['url'] ?? '');
        $code = GtmetrixFixEngine::generateSnippet($fixes, $reportId, $url);
        $rows = array();
        $emitted = 0;

        foreach ($fixes as $fix) {
            $block = is_scalar($fix['block'] ?? null) ? (string) $fix['block'] : '';

            if ('' !== $block) {
                ++$emitted;
            }

            $rows[] = array(
                'block'         => $block,
                'risk'          => is_scalar($fix['risk'] ?? null) ? (string) $fix['risk'] : '',
                'symptom'       => is_scalar($fix['symptom'] ?? null) ? (string) $fix['symptom'] : '',
                'matched_audit' => is_scalar($fix['matched_audit'] ?? null) ? (string) $fix['matched_audit'] : '',
            );
        }

        return $this->success(
            sprintf(
                '%d fixes matched; %d code blocks emitted.',
                count($rows),
                $emitted
            ),
            array(
                'report_id' => $reportId,
                'code'      => $code,
                'blocks'    => $rows,
                'count'     => count($rows),
                'emitted'   => $emitted,
                'deploy'    => 'WPCode: Add Snippet, PHP, Insert method: Auto Insert, Run Everywhere. Enable blocks one at a time and re-test.',
            )
        );
    }
}
