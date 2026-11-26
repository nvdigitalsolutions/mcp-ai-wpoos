<?php

/**
 * Budget variance by line, largest gap first.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Budget_Variance` (§164.74): M-20, sorted by
 * variance descending with the unrecorded lines last.
 *
 * ## A dead error check, pinned
 *
 * The WordPress tool does
 *
 * ```php
 * $result = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month );
 * if ( is_wp_error( $result ) ) { return $result; }
 * ```
 *
 * but `m20_budget_variance()` never returns a `WP_Error` — on an unreadable
 * budgets table it returns `array( 'rows' => array(), 'error' => … )`. The check
 * is therefore dead, and the original answers **success with no rows** for a
 * month whose budget sheet cannot be read. The port keeps that answer:
 * "there is no budget" and "we could not read the budget" are both reported as
 * an empty row list, and the readiness row says so.
 *
 * The `null` actuals sort to the bottom because `PHP_FLOAT_MIN` stands in for
 * them, which is how the original keeps unrecorded lines out of the "largest
 * overspend" headline.
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

class FnbBudgetVarianceTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_budget_variance';
    }

    public function getName(): string
    {
        return 'F&B Budget Variance';
    }

    public function getDescription(): string
    {
        return 'Budget variance by line for a month: actual − budget (M-20), sorted largest gap first. Actuals are valued on stock used (M-04/M-05) and month-incurred expenses (G-07).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month' => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m).',
                ),
            ),
            'required'             => array('month'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month = $this->monthArg($arguments);

        if ('' === $month) {
            return $this->errors->validationFailed(
                'month (Y-m) is required.',
                array('month' => array('Give the month to compare.')),
            );
        }

        $result = $this->metrics->m20BudgetVariance($month);
        $rows   = $result['rows'];

        usort(
            $rows,
            static function (array $a, array $b): int {
                $left  = null === $a['variance'] ? PHP_FLOAT_MIN : (float) $a['variance'];
                $right = null === $b['variance'] ? PHP_FLOAT_MIN : (float) $b['variance'];

                return $right <=> $left;
            }
        );

        return $this->success(
            sprintf('Budget variance for %s: %d line(s).', $month, count($rows)),
            array(
                'month'  => $month,
                'rows'   => $rows,
                'source' => 'fnb_budget_variance',
            )
        );
    }
}
