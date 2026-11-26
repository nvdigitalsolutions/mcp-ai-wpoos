<?php

/**
 * Scan the expense ledger for possible duplicate invoices.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Duplicate_Invoice_Scan` (§164.73). Flags only:
 * the note says "verify before concluding", and nothing here asserts that a
 * duplicate *is* a duplicate — two deliveries on one invoice number are normal.
 *
 * Three flags, as in the original:
 *
 * 1. **Same vendor, same invoice number, same amount** seen more than once. The
 *    amount is rounded to a whole unit before it is used in the key, so
 *    1,250.40 and 1,250.00 are the same claim.
 * 2. **A line with no budget line**, unless it is the stock-purchase line the
 *    budget deliberately omits.
 * 3. Nothing else — "large price jumps" in the description are M-19's job, and
 *    the code does not attempt them. Recorded rather than invented.
 *
 * The first 50 unmatched lines are returned; the duplicate list is not capped,
 * because a duplicate is the finding and a 50-cap would silently drop one.
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

class FnbDuplicateInvoiceScanTool extends FnbTool
{
    /** The line the budget deliberately does not carry. */
    public const STOCK_LINE = 'Food and beverage stock';

    /** The unmatched-line cap the original returns. */
    public const UNMATCHED_CAP = 50;

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbTableSource $tables,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_duplicate_invoice_scan';
    }

    public function getName(): string
    {
        return 'F&B Duplicate Invoice Scan';
    }

    public function getDescription(): string
    {
        return 'Scan the expense ledger for possible duplicate invoices — same vendor, same invoice number and same amount appearing more than once. Flag for checking; do not conclude (A3-04). Also flags invoices without a budget line match.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month' => array(
                    'type'        => 'string',
                    'description' => 'Restrict to a month (Y-m). Optional.',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month = Text::singleLine($this->scalar($arguments, 'month'));

        if (1 !== preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = '';
        }

        $ledger = $this->tables->getTable('14.0');

        if ($this->errors->isError($ledger)) {
            return $ledger;
        }

        $headers     = $ledger['header_index'];
        $budgetLines = $this->budgetLines();
        $seen        = array();
        $duplicates  = array();
        $unmatched   = array();

        foreach ($ledger['rows'] as $row) {
            if ('' !== $month && FnbTableCatalogue::monthOf(FnbTableCatalogue::cell('14.0', $headers, $row, 'for_month')) !== $month) {
                continue;
            }

            $vendor  = (string) FnbTableCatalogue::cell('14.0', $headers, $row, 'vendor');
            $invoice = (string) FnbTableCatalogue::cell('14.0', $headers, $row, 'vendor_invoice');
            // Whole units: 1,250.40 and 1,250.00 are the same claim.
            $amount = round(FnbTableCatalogue::floatCell(FnbTableCatalogue::cell('14.0', $headers, $row, 'amount')));
            $line   = (string) FnbTableCatalogue::cell('14.0', $headers, $row, 'expense_line');

            $key = strtolower($vendor . '|' . $invoice . '|' . $amount);

            if (isset($seen[ $key ])) {
                $duplicates[] = array(
                    'vendor'  => $vendor,
                    'invoice' => $invoice,
                    'amount'  => $amount,
                    'flag'    => 'Possible duplicate invoice — flag for checking (A3-04).',
                );
            }

            $seen[ $key ] = true;

            if (! in_array($line, $budgetLines, true) && self::STOCK_LINE !== $line) {
                $unmatched[] = array(
                    'vendor'       => $vendor,
                    'invoice'      => $invoice,
                    'expense_line' => $line,
                    'flag'         => 'No matching budget line.',
                );
            }
        }

        return $this->success(
            sprintf(
                'Found %d possible duplicate%s and %d unmatched line%s.',
                count($duplicates),
                1 === count($duplicates) ? '' : 's',
                count($unmatched),
                1 === count($unmatched) ? '' : 's'
            ),
            array(
                'month'               => '' !== $month ? $month : null,
                'possible_duplicates' => $duplicates,
                'no_budget_line'      => array_slice($unmatched, 0, self::UNMATCHED_CAP),
                'note'                => 'Flags only — verify before concluding.',
                'source'              => 'fnb_duplicate_invoice_scan',
            )
        );
    }

    /**
     * Every budget line the ledger can match, across all months.
     *
     * @return list<string>
     */
    private function budgetLines(): array
    {
        $budgets = $this->tables->getTable('15.0');

        if ($this->errors->isError($budgets)) {
            return array();
        }

        $headers = $budgets['header_index'];
        $lines   = array();

        foreach ($budgets['rows'] as $row) {
            $lines[] = (string) FnbTableCatalogue::cell('15.0', $headers, $row, 'line');
        }

        return $lines;
    }
}
