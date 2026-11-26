<?php

/**
 * Supplier price-change impact for a month.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Price_Change_Impact` (§164.74): M-19 with the
 * month echoed back. The engine does the work; the tool's only contract is that
 * a month is required, because an impact has no meaning without a period.
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

class FnbPriceChangeImpactTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_price_change_impact';
    }

    public function getName(): string
    {
        return 'F&B Price Change Impact';
    }

    public function getDescription(): string
    {
        return 'How much supplier price rises cost in a month: for each effective price change, (new price − old price) × quantity bought at the new price (M-19). Prices are matched as-of their effective date.';
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
                array('month' => array('Give the month to measure.')),
            );
        }

        $result = $this->metrics->m19PriceChangeImpact($month);

        return $this->success(
            sprintf('Price change impact for %s.', $month),
            array_merge(array('month' => $month), $result)
        );
    }
}
