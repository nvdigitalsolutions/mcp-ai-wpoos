<?php

/**
 * Week-end prep: expected covers, portions and supplier cut-offs.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Weekend_Demand` (§164.73). Three days — Friday,
 * Saturday, Sunday — each with M-12's expected covers, plus the supplier
 * cut-off and lead time an order has to respect.
 *
 * ## What this tool does and does not do
 *
 * Its description promises expected portions and stock shortfalls; the code
 * returns **neither**. It computes M-12 per day and the supplier list, and
 * points at the report template (`R-03`) that lays the rest out. The port keeps
 * the behaviour and the description's promise is a WordPress claim, not
 * something to invent here — `fnb_calculate_metric` answers M-13 for a dish.
 *
 * ## The default Friday is derived
 *
 * WordPress defaults to `2026-10-09`. A host asking with no arguments should get
 * the weekend it is planning for, so the port defaults to the next Friday on or
 * after today (today, when today is Friday). A caller that passes `friday` gets
 * identical behaviour.
 *
 * A malformed date falls back to that default, as the original falls back to its
 * own — substitution rather than refusal, pinned.
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

class FnbWeekendDemandTool extends FnbTool
{
    /** The report template that lays out the full prep plan. */
    public const REPORT_HINT = 'Run fnb_generate_report with report_id R-03 for the full weekend prep plan layout.';

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
        protected readonly FnbTableSource $tables,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_weekend_demand';
    }

    public function getName(): string
    {
        return 'F&B Weekend Demand';
    }

    public function getDescription(): string
    {
        return 'Weekend prep: expected covers per day (M-12: same-weekday 4-week average + group bookings of 15+), expected portions per dish (M-13), stock shortfalls, and order-by dates per supplier cut-off (A2-04, A2-05).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'friday' => array(
                    'type'        => 'string',
                    'description' => 'Weekend Friday date (Y-m-d). Defaults to the next Friday.',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $friday = Text::singleLine($this->scalar($arguments, 'friday'));

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $friday)) {
            $friday = $this->nextFriday();
        }

        $days = array(
            $friday,
            (string) gmdate('Y-m-d', (int) strtotime($friday . ' +1 day')),
            (string) gmdate('Y-m-d', (int) strtotime($friday . ' +2 days')),
        );

        $perDay = array();

        foreach ($days as $day) {
            $perDay[ $day ] = $this->metrics->m12ExpectedCovers($day);
        }

        return $this->success(
            sprintf('Weekend demand for %s to %s.', $friday, (string) end($days)),
            array(
                'weekend'          => array('friday' => $friday, 'sunday' => (string) end($days)),
                'per_day'          => $perDay,
                'supplier_cutoffs' => $this->supplierCutoffs(),
                'report_hint'      => self::REPORT_HINT,
                'source'           => 'fnb_weekend_demand',
            )
        );
    }

    /**
     * The supplier list an order has to beat, or an empty list when unreadable.
     *
     * @return list<array<string, string>>
     */
    private function supplierCutoffs(): array
    {
        $table = $this->tables->getTable('4.0');

        if ($this->errors->isError($table)) {
            return array();
        }

        $headers = $table['header_index'];
        $out     = array();

        foreach ($table['rows'] as $row) {
            $out[] = array(
                'supplier_id'  => (string) FnbTableCatalogue::cell('4.0', $headers, $row, 'supplier_id'),
                'supplier'     => (string) FnbTableCatalogue::cell('4.0', $headers, $row, 'supplier'),
                'order_cutoff' => (string) FnbTableCatalogue::cell('4.0', $headers, $row, 'order_cutoff'),
                'lead_time'    => (string) FnbTableCatalogue::cell('4.0', $headers, $row, 'lead_time'),
            );
        }

        return $out;
    }

    /**
     * The next Friday on or after today, UTC.
     */
    private function nextFriday(): string
    {
        $today  = (string) gmdate('Y-m-d');
        $number = (int) gmdate('N', (int) strtotime($today . ' 12:00:00 UTC'));
        $offset = ( 5 - $number + 7 ) % 7;

        return (string) gmdate('Y-m-d', (int) strtotime($today . ' +' . $offset . ' days'));
    }
}
