<?php

/**
 * Unexplained variance for one ingredient over a period.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Stock_Variance` (§164.74): M-17 with the
 * ingredient echoed back. The `from` date also decides the month whose expected
 * use is subtracted — the engine derives it — so a variance without `from` has
 * no expected-use term, which the payload shows rather than hides.
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

class FnbStockVarianceTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_stock_variance';
    }

    public function getName(): string
    {
        return 'F&B Stock Variance';
    }

    public function getDescription(): string
    {
        return 'Report unexplained variance for an ingredient: stock used − expected use − logged waste (M-17). Only waste reasons that are logged count (A2-08).';
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
                    'description' => 'Period start (Y-m-d). Also selects the month for expected use.',
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
                array('ingredient_id' => array('Give the ingredient to reconcile.')),
            );
        }

        $from = isset($arguments['from']) ? Text::singleLine($this->scalar($arguments, 'from')) : null;
        $to   = isset($arguments['to']) ? Text::singleLine($this->scalar($arguments, 'to')) : null;

        $result = $this->metrics->m17UnexplainedVariance($ingredient, $from, $to);

        return $this->success(
            sprintf('Unexplained variance for %s: %s.', $ingredient, (string) $result['value']),
            array_merge(array('ingredient_id' => $ingredient), $result)
        );
    }
}
