<?php

/**
 * Days of cover for an ingredient.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Days_Of_Cover` (§164.73). M-14 gives the days of
 * cover; the tool adds the over-ordering flag, whose cap is the ingredient's
 * shelf life when the ingredients table has one and fourteen days otherwise —
 * the "long-life" rule for ingredients that do not spoil.
 *
 * Pinned from the original:
 *
 * - **The shelf-life lookup is the first matching row**, and an unreadable
 *   ingredients table leaves `shelf_life_days` at zero rather than failing the
 *   call — the flag then uses the 14-day cap. A metric is worth returning even
 *   when the annotation around it is not.
 * - **The flag is a string, not a boolean**, and it is `''` when the cover is
 *   inside the cap. A caller tests truthiness, as WordPress callers do.
 * - **`as_of` is validated as `Y-m-d` or dropped.** A malformed date falls back
 *   to the toolkit's configured as-of date rather than to today, which is what
 *   makes a report reproducible.
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

class FnbDaysOfCoverTool extends FnbTool
{
    /** The long-life cap, in days, when the ingredient has no shelf life. */
    public const LONG_LIFE_CAP = 14.0;

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
        protected readonly FnbTableSource $tables,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_days_of_cover';
    }

    public function getName(): string
    {
        return 'F&B Days of Cover';
    }

    public function getDescription(): string
    {
        return 'Days of cover for an ingredient: stock on hand ÷ average daily use over the last 14 days (M-14). Flags over-ordering where days of cover exceed shelf life, or 14 days for long-life items (A2-06).';
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
                'as_of'         => array(
                    'type'        => 'string',
                    'description' => 'Reference date (Y-m-d), defaults to the configured as-of date.',
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

        $asOf = Text::singleLine($this->scalar($arguments, 'as_of'));

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $asOf = '';
        }

        $result     = $this->metrics->m14DaysOfCover($ingredient, $asOf);
        $shelfLife  = $this->shelfLife($ingredient);
        $cap        = $shelfLife > 0 ? $shelfLife : self::LONG_LIFE_CAP;
        $flag       = '';

        if ((float) $result['value'] > $cap) {
            $flag = sprintf(
                'Over-ordering flag: %.1f days of cover exceeds %s.',
                (float) $result['value'],
                $shelfLife > 0 ? 'shelf life (' . $shelfLife . ' days)' : '14 days (long-life cap)'
            );
        }

        return $this->success(
            sprintf('%s has %.1f days of cover.', $ingredient, (float) $result['value']),
            array(
                'ingredient_id'      => $ingredient,
                'shelf_life_days'    => $shelfLife,
                'over_ordering_flag' => $flag,
                'value'              => $result['value'],
                'stock_on_hand'      => $result['stock_on_hand'],
                'daily_use_14d'      => $result['daily_use_14d'],
                'source'             => $result['source'],
            )
        );
    }

    /**
     * The ingredient's shelf life in days, or zero when it cannot be read.
     */
    private function shelfLife(string $ingredient): float
    {
        $table = $this->tables->getTable('3.0');

        if ($this->errors->isError($table)) {
            return 0.0;
        }

        $headers = $table['header_index'];

        foreach ($table['rows'] as $row) {
            if (FnbTableCatalogue::cell('3.0', $headers, $row, 'ingredient_id') === $ingredient) {
                return FnbTableCatalogue::floatCell(
                    FnbTableCatalogue::cell('3.0', $headers, $row, 'shelf_life_days')
                );
            }
        }

        return 0.0;
    }
}
