<?php

/**
 * Export an F&B report as CSV (data) or PDF (presentation).
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Export_Report` (Proposal 164 section 164.75).
 * Export writes a stored file, so it asks for `edit_posts` — the capability the
 * WordPress tool also requires — and declares `state-changing`/`write`. The WP
 * base declares `read-only`; exporting is not.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Support\Text;

class FnbExportReportTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbReportExporter $exporter,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_export_report';
    }

    public function getName(): string
    {
        return 'F&B Export Report';
    }

    public function getDescription(): string
    {
        return 'Export an F&B report — including the Monthly sales, Waste & variance, and Monthly cost review templates — as CSV (data) or PDF (presentation). All figures come from the metric engine.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id'  => array(
                    'type'        => 'string',
                    'enum'        => FnbReportExporter::ids(),
                    'description' => 'Report ID (R-01…R-14) or client alias (client-r01 = monthly sales, client-r02 = waste & variance, client-r03 = monthly cost review).',
                ),
                'format'     => array(
                    'type'        => 'string',
                    'enum'        => array('csv', 'pdf'),
                    'description' => 'Output format.',
                ),
                'month'      => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m) for monthly reports.',
                ),
                'date'       => array(
                    'type'        => 'string',
                    'description' => 'Reference date (Y-m-d).',
                ),
                'prev_month' => array(
                    'type'        => 'string',
                    'description' => 'Comparison month for the monthly cost review.',
                ),
            ),
            'required'             => array('report_id', 'format'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $reportId = Text::singleLine($this->scalar($arguments, 'report_id'));
        $format   = Text::singleLine($this->scalar($arguments, 'format'));
        $format   = in_array($format, array('csv', 'pdf'), true) ? $format : 'csv';

        $args = array();

        foreach (array('month', 'date', 'prev_month') as $key) {
            $value = $this->scalar($arguments, $key);

            if ('' !== $value) {
                $args[ $key ] = Text::singleLine($value);
            }
        }

        $result = 'pdf' === $format
            ? $this->exporter->toPdf($reportId, $args, $context)
            : $this->exporter->toCsv($reportId, $args, $context);

        if ($this->errors->isError($result)) {
            return $result;
        }

        return $this->success(
            sprintf('Report exported as %s.', strtoupper($format)),
            $result
        );
    }
}
