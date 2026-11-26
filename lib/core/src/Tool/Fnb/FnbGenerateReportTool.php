<?php

/**
 * Generate an F&B report in its Report-library layout.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Generate_Report` (Proposal 164 section 164.75).
 * The tool is argument handling only: {@see FnbReportBuilder} owns the layout
 * and every figure comes from the metric engine.
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

class FnbGenerateReportTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbReportBuilder $builder,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_generate_report';
    }

    public function getName(): string
    {
        return 'F&B Generate Report';
    }

    public function getDescription(): string
    {
        return 'Generate an F&B report (R-01…R-14) in its Report-library layout. All figures come from the metric engine. Returns structured sections plus a Draft header for fnb_save_draft.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'report_id'     => array(
                    'type'        => 'string',
                    'enum'        => FnbReportBuilder::ids(),
                    'description' => 'Report ID from the Report library.',
                ),
                'month'         => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m) for monthly reports.',
                ),
                'date'          => array(
                    'type'        => 'string',
                    'description' => 'Reference date (Y-m-d) for R-01/R-03/R-13.',
                ),
                'prev_month'    => array(
                    'type'        => 'string',
                    'description' => 'Comparison month for R-08.',
                ),
                'supplier_id'   => array(
                    'type'        => 'string',
                    'description' => 'Supplier ID for R-04.',
                ),
                'ingredient_id' => array(
                    'type'        => 'string',
                    'description' => 'Ingredient ID for R-04.',
                ),
                'quantity'      => array(
                    'type'        => 'number',
                    'description' => 'Quantity for R-04.',
                ),
            ),
            'required'             => array('report_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $reportId = Text::singleLine($this->scalar($arguments, 'report_id'));
        $report   = $this->builder->build($reportId, $arguments);

        if ($this->errors->isError($report)) {
            return $report;
        }

        return $this->success(
            sprintf('%s generated.', (string) $report['title']),
            $report
        );
    }
}
