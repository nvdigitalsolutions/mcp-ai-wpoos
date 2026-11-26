<?php

/**
 * Dish margin, and the menu-engineering matrix.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Dish_Margin` (§164.74). One tool, two shapes:
 *
 * - **with `item_id`** — M-21 for that dish, plus the item id;
 * - **without it** — the full Kasavana & Smith matrix, which needs a month
 *   because popularity is a share of that month's units.
 *
 * A missing month with no item is the one refusal: the matrix has no sensible
 * default, and guessing "this month" would silently answer about a period the
 * caller did not name.
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

class FnbDishMarginTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_dish_margin';
    }

    public function getName(): string
    {
        return 'F&B Dish Margin';
    }

    public function getDescription(): string
    {
        return 'Dish margin (M-21): menu price − recipe cost at current supplier prices. With a month, also returns the menu-engineering quadrant (Star/Plowhorse/Puzzle/Dog) for every item from popularity share × margin.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'item_id' => array(
                    'type'        => 'string',
                    'description' => 'Menu item ID for a single dish (omit for the full menu-engineering matrix).',
                ),
                'month'   => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m) for popularity share and the full matrix.',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $itemId = Text::singleLine($this->scalar($arguments, 'item_id'));
        $month  = $this->monthArg($arguments);

        if ('' === $itemId) {
            if ('' === $month) {
                return $this->errors->validationFailed(
                    'The full menu-engineering matrix requires a month (Y-m).',
                    array('month' => array('Give the month whose sales decide popularity.')),
                );
            }

            $matrix = $this->metrics->menuEngineering($month);

            return $this->success(
                sprintf('Menu engineering for %s.', $month),
                $matrix
            );
        }

        $margin = $this->metrics->m21DishMargin($itemId, $month);

        return $this->success(
            sprintf('Dish margin for %s.', $itemId),
            array_merge(array('item_id' => $itemId), $margin)
        );
    }
}
