<?php

/**
 * Expected use of an ingredient for a month.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Expected_Use` (§164.74): M-16 with the ingredient
 * and month echoed back. Both arguments are required — the recipe quantity is
 * multiplied by a month's sales, so neither can be defaulted without inventing a
 * period or a subject.
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

class FnbExpectedUseTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_expected_use';
    }

    public function getName(): string
    {
        return 'F&B Expected Use';
    }

    public function getDescription(): string
    {
        return 'Calculate expected use of an ingredient for a month: units sold × recipe quantity per portion (M-16).';
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
                'month'         => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m).',
                ),
            ),
            'required'             => array('ingredient_id', 'month'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $ingredient = Text::singleLine($this->scalar($arguments, 'ingredient_id'));
        $month      = $this->monthArg($arguments);

        if ('' === $ingredient || '' === $month) {
            return $this->errors->validationFailed(
                'ingredient_id and month (Y-m) are required.',
                array(
                    'ingredient_id' => array('Give the ingredient.'),
                    'month'         => array('Give the month.'),
                ),
            );
        }

        $result = $this->metrics->m16ExpectedUse($ingredient, $month);

        return $this->success(
            sprintf('Expected use of %s for %s: %s.', $ingredient, $month, (string) $result['value']),
            array_merge(
                array(
                    'ingredient_id' => $ingredient,
                    'month'         => $month,
                ),
                $result
            )
        );
    }
}
