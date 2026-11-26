<?php

/**
 * Stock used for one ingredient over a period.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Stock_Used` (§164.74): M-15 with the ingredient
 * echoed back.
 *
 * The payload is the metric's own, merged rather than rebuilt, because a
 * matching count row carries `opening`, `bought`, `closing` and `period` while a
 * miss carries only `value` and `source` — and "no count row for that period" is
 * a legitimate answer that should not look like a zero with the fields removed.
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

class FnbStockUsedTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_stock_used';
    }

    public function getName(): string
    {
        return 'F&B Stock Used';
    }

    public function getDescription(): string
    {
        return 'Calculate stock used for an ingredient over a period: opening + bought − closing (M-15). Keeps stock bought separate from stock used (G-06).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'ingredient_id' => array(
                    'type'        => 'string',
                    'description' => 'Ingredient ID (e.g. ING-001).',
                ),
                'from'          => array(
                    'type'        => 'string',
                    'description' => 'Period start (Y-m-d).',
                ),
                'to'            => array(
                    'type'        => 'string',
                    'description' => 'Period end (Y-m-d).',
                ),
            ),
            'required'             => array('ingredient_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $ingredient = Text::singleLine($this->scalar($arguments, 'ingredient_id'));

        if ('' === $ingredient) {
            return $this->errors->validationFailed(
                'ingredient_id is required.',
                array('ingredient_id' => array('Give the ingredient to measure.')),
            );
        }

        // Present-but-malformed dates are passed through as they are in
        // WordPress: the engine compares strings, and a date that cannot parse
        // simply matches nothing.
        $from = isset($arguments['from']) ? Text::singleLine($this->scalar($arguments, 'from')) : null;
        $to   = isset($arguments['to']) ? Text::singleLine($this->scalar($arguments, 'to')) : null;

        $result = $this->metrics->m15StockUsed($ingredient, $from, $to);

        return $this->success(
            sprintf('Stock used for %s: %s.', $ingredient, (string) $result['value']),
            array_merge(array('ingredient_id' => $ingredient), $result)
        );
    }
}
