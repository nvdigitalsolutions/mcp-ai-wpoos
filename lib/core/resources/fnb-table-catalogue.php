<?php

/**
 * The Food & Beverage toolkit's data-table catalogue.
 *
 * 18 tables — the 17 spec tables (`1.0` … `17.0`) and the `assumptions` sheet —
 * each with its canonical column keys and the header spellings an operator's
 * export is likely to use, plus the 77-entry header-alias dictionary that maps
 * vendor and unit-suffixed spellings onto those keys.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 *
 * ---------------------------------------------------------------------------
 * GENERATED FILE — do not edit by hand.
 *
 * The registry is extracted from `WP_MCP_AI_Fnb_Data_Source` (Proprietary) by
 * `bin/generate-fnb-table-catalogue.php`, so the core resolves the same columns
 * the WordPress toolkit resolves. Change the Pro class, re-run the generator,
 * and review the diff; a hand edit here will be overwritten and will drift from
 * the toolkit it is supposed to match.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

return
array(
    'tables' => array(
        '1.0' => array(
            'label' => 'Menu items',
            'canonical' => array(
                'item_id',
                'menu',
                'section',
                'item',
                'price',
                'type',
                'source',
                'recipe_cost',
                'gross_margin',
                'cost_pct',
            ),
            'aliases' => array(
                'item_id' => array(
                    'item_id',
                ),
                'price' => array(
                    'price',
                    'price_lkr',
                ),
                'recipe_cost' => array(
                    'recipe_cost',
                    'recipe_cost_lkr',
                ),
                'gross_margin' => array(
                    'gross_margin',
                    'gross_margin_lkr',
                ),
                'cost_pct' => array(
                    'cost_pct',
                    'food_or_drink_cost',
                ),
            ),
        ),
        '2.0' => array(
            'label' => 'Recipes',
            'canonical' => array(
                'item_id',
                'ingredient_id',
                'qty_per_portion',
                'unit',
                'cost_per_portion',
            ),
            'aliases' => array(
                'item_id' => array(
                    'item_id',
                ),
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'qty_per_portion' => array(
                    'qty_per_portion',
                ),
                'cost_per_portion' => array(
                    'cost_per_portion',
                    'cost_per_portion_lkr',
                    'cost_per_portion_at_as_of_date',
                    'cost_per_portion_at_as_of_date_lkr',
                    'recipe_cost',
                    'recipe_cost_lkr',
                ),
            ),
        ),
        '3.0' => array(
            'label' => 'Ingredients',
            'canonical' => array(
                'ingredient_id',
                'ingredient',
                'unit',
                'category',
                'group',
                'storage',
                'shelf_life_days',
                'minimum_level',
                'supplier_id',
            ),
            'aliases' => array(
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'shelf_life_days' => array(
                    'shelf_life_days',
                ),
                'minimum_level' => array(
                    'minimum_level',
                ),
                'supplier_id' => array(
                    'supplier_id',
                ),
            ),
        ),
        '4.0' => array(
            'label' => 'Suppliers',
            'canonical' => array(
                'supplier_id',
                'supplier',
                'supplies',
                'delivery_days',
                'lead_time',
                'order_cutoff',
                'payment_terms_days',
            ),
            'aliases' => array(
                'supplier_id' => array(
                    'supplier_id',
                ),
                'lead_time' => array(
                    'lead_time',
                ),
                'order_cutoff' => array(
                    'order_cutoff',
                    'order_cut_off',
                ),
            ),
        ),
        '5.0' => array(
            'label' => 'Supplier prices',
            'canonical' => array(
                'supplier_id',
                'ingredient_id',
                'pack',
                'pack_size',
                'unit',
                'pack_price',
                'effective_from',
            ),
            'aliases' => array(
                'supplier_id' => array(
                    'supplier_id',
                ),
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'pack_size' => array(
                    'pack_size',
                ),
                'pack_price' => array(
                    'pack_price',
                    'pack_price_lkr',
                ),
                'effective_from' => array(
                    'effective_from',
                ),
            ),
        ),
        '6.0' => array(
            'label' => 'Daily sales',
            'canonical' => array(
                'date',
                'month',
                'item_id',
                'item',
                'type',
                'qty_sold',
                'price',
                'revenue',
            ),
            'aliases' => array(
                'date' => array(
                    'date',
                ),
                'month' => array(
                    'month',
                ),
                'item_id' => array(
                    'item_id',
                ),
                'type' => array(
                    'type',
                ),
                'qty_sold' => array(
                    'qty_sold',
                ),
                'price' => array(
                    'price',
                    'price_lkr',
                ),
                'revenue' => array(
                    'revenue',
                    'revenue_lkr',
                ),
            ),
        ),
        '7.0' => array(
            'label' => 'Daily covers',
            'canonical' => array(
                'date',
                'day',
                'month',
                'lunch_covers',
                'dinner_covers',
                'bar_only_guests',
                'total_covers',
            ),
            'aliases' => array(
                'date' => array(
                    'date',
                ),
                'month' => array(
                    'month',
                ),
                'lunch_covers' => array(
                    'lunch_covers',
                ),
                'dinner_covers' => array(
                    'dinner_covers',
                ),
                'bar_only_guests' => array(
                    'bar_only_guests',
                    'bar_only',
                ),
                'total_covers' => array(
                    'total_covers',
                ),
            ),
        ),
        '8.0' => array(
            'label' => 'Purchases',
            'canonical' => array(
                'delivery_date',
                'month',
                'supplier_id',
                'invoice',
                'ingredient_id',
                'category',
                'packs',
                'pack_size',
                'qty_delivered',
                'pack_price',
                'line_total',
            ),
            'aliases' => array(
                'delivery_date' => array(
                    'delivery_date',
                ),
                'month' => array(
                    'month',
                ),
                'supplier_id' => array(
                    'supplier_id',
                ),
                'invoice' => array(
                    'invoice',
                ),
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'category' => array(
                    'category',
                ),
                'qty_delivered' => array(
                    'qty_delivered',
                ),
                'pack_price' => array(
                    'pack_price',
                    'pack_price_lkr',
                ),
                'line_total' => array(
                    'line_total',
                    'line_total_lkr',
                ),
            ),
        ),
        '9.0' => array(
            'label' => 'Stock counts',
            'canonical' => array(
                'count_date',
                'count_type',
                'period_start',
                'ingredient_id',
                'unit',
                'category',
                'opening',
                'bought',
                'closing',
                'used',
                'unit_cost',
                'closing_value',
            ),
            'aliases' => array(
                'count_date' => array(
                    'count_date',
                ),
                'count_type' => array(
                    'count_type',
                ),
                'period_start' => array(
                    'period_start',
                ),
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'category' => array(
                    'category',
                ),
                'opening' => array(
                    'opening',
                ),
                'bought' => array(
                    'bought',
                ),
                'closing' => array(
                    'closing',
                ),
                'unit_cost' => array(
                    'unit_cost',
                    'unit_cost_lkr',
                ),
                'closing_value' => array(
                    'closing_value',
                    'closing_value_lkr',
                ),
            ),
        ),
        '10.0' => array(
            'label' => 'Waste log',
            'canonical' => array(
                'date',
                'month',
                'ingredient_id',
                'ingredient',
                'qty',
                'unit',
                'reason',
                'logged_by',
                'value',
            ),
            'aliases' => array(
                'date' => array(
                    'date',
                ),
                'month' => array(
                    'month',
                ),
                'ingredient_id' => array(
                    'ingredient_id',
                ),
                'qty' => array(
                    'qty',
                ),
                'reason' => array(
                    'reason',
                ),
                'logged_by' => array(
                    'logged_by',
                ),
                'value' => array(
                    'value',
                    'value_lkr',
                ),
            ),
        ),
        '11.0' => array(
            'label' => 'Payroll',
            'canonical' => array(
                'month',
                'department',
                'basic_pay',
                'epf',
                'etf',
                'overtime_hours',
                'overtime_pay',
                'casual_shifts',
                'casual_pay',
                'total_payroll_cost',
            ),
            'aliases' => array(
                'month' => array(
                    'month',
                ),
                'department' => array(
                    'department',
                ),
                'basic_pay' => array(
                    'basic_pay',
                    'basic_pay_lkr',
                ),
                'epf' => array(
                    'epf',
                    'epf_lkr',
                ),
                'etf' => array(
                    'etf',
                    'etf_lkr',
                ),
                'overtime_hours' => array(
                    'overtime_hours',
                ),
                'overtime_pay' => array(
                    'overtime_pay',
                    'overtime_pay_lkr',
                ),
                'casual_shifts' => array(
                    'casual_shifts',
                ),
                'casual_pay' => array(
                    'casual_pay',
                    'casual_pay_lkr',
                ),
                'total_payroll_cost' => array(
                    'total_payroll_cost',
                    'total_payroll_cost_lkr',
                ),
            ),
        ),
        '12.0' => array(
            'label' => 'Timesheets',
            'canonical' => array(
                'date',
                'month',
                'department',
                'staff_on_duty',
                'contracted_hours',
                'overtime_hours',
                'casual_shifts',
            ),
            'aliases' => array(
                'date' => array(
                    'date',
                ),
                'month' => array(
                    'month',
                ),
                'department' => array(
                    'department',
                ),
                'contracted_hours' => array(
                    'contracted_hours',
                ),
                'overtime_hours' => array(
                    'overtime_hours',
                ),
                'casual_shifts' => array(
                    'casual_shifts',
                ),
            ),
        ),
        '13.0' => array(
            'label' => 'Utilities',
            'canonical' => array(
                'date',
                'month',
                'electricity_kwh',
                'water_m3',
                'lpg_cylinders',
                'total_covers',
            ),
            'aliases' => array(
                'date' => array(
                    'date',
                ),
                'month' => array(
                    'month',
                ),
                'electricity_kwh' => array(
                    'electricity_kwh',
                ),
                'water_m3' => array(
                    'water_m3',
                    'water_m_',
                ),
                'lpg_cylinders' => array(
                    'lpg_cylinders',
                    'lpg_cylinders_changed',
                ),
                'total_covers' => array(
                    'total_covers',
                ),
            ),
        ),
        '14.0' => array(
            'label' => 'Expense ledger',
            'canonical' => array(
                'entry_id',
                'vendor',
                'vendor_invoice',
                'expense_line',
                'group',
                'for_month',
                'invoice_date',
                'amount',
                'due_date',
                'paid_date',
                'status',
            ),
            'aliases' => array(
                'entry_id' => array(
                    'entry_id',
                ),
                'vendor' => array(
                    'vendor',
                ),
                'vendor_invoice' => array(
                    'vendor_invoice',
                ),
                'expense_line' => array(
                    'expense_line',
                ),
                'group' => array(
                    'group',
                ),
                'for_month' => array(
                    'for_month',
                ),
                'invoice_date' => array(
                    'invoice_date',
                ),
                'amount' => array(
                    'amount',
                    'amount_lkr',
                ),
                'due_date' => array(
                    'due_date',
                ),
                'paid_date' => array(
                    'paid_date',
                ),
                'status' => array(
                    'status',
                ),
            ),
        ),
        '15.0' => array(
            'label' => 'Budgets',
            'canonical' => array(
                'month',
                'line',
                'budget',
            ),
            'aliases' => array(
                'month' => array(
                    'month',
                ),
                'line' => array(
                    'line',
                ),
                'budget' => array(
                    'budget',
                    'budget_lkr',
                ),
            ),
        ),
        '16.0' => array(
            'label' => 'Bookings',
            'canonical' => array(
                'booking_id',
                'date',
                'day',
                'time',
                'session',
                'type',
                'guests',
                'notes',
                'status',
            ),
            'aliases' => array(
                'booking_id' => array(
                    'booking_id',
                ),
                'date' => array(
                    'date',
                ),
                'session' => array(
                    'session',
                ),
                'type' => array(
                    'type',
                ),
                'guests' => array(
                    'guests',
                ),
                'status' => array(
                    'status',
                ),
            ),
        ),
        '17.0' => array(
            'label' => 'Asset log',
            'canonical' => array(
                'image_id',
                'deity',
                'created',
                'theme',
                'featured_item',
                'post_date',
                'channel',
                'status',
            ),
            'aliases' => array(
                'image_id' => array(
                    'image_id',
                ),
                'deity' => array(
                    'deity',
                ),
                'post_date' => array(
                    'post_date',
                ),
                'channel' => array(
                    'channel',
                ),
                'status' => array(
                    'status',
                ),
            ),
        ),
        'assumptions' => array(
            'label' => 'Assumptions',
            'canonical' => array(
                'item',
                'value',
                'unit',
                'source',
            ),
            'aliases' => array(),
        ),
    ),
    'header_aliases' => array(
        'amount' => 'amount',
        'bar only guests' => 'bar_only_guests',
        'booking id' => 'booking_id',
        'budget' => 'budget',
        'budget lkr' => 'budget',
        'closing value' => 'closing_value',
        'comments' => 'notes',
        'cost' => 'unit_cost',
        'cost per portion' => 'cost_per_portion',
        'cost per portion at as of date' => 'cost_per_portion',
        'cost per portion at as of date lkr' => 'cost_per_portion',
        'cost per portion lkr' => 'cost_per_portion',
        'count date' => 'count_date',
        'cover count' => 'total_covers',
        'covers' => 'total_covers',
        'date' => 'date',
        'day' => 'day',
        'delivery date' => 'delivery_date',
        'dinner covers' => 'dinner_covers',
        'due date' => 'due_date',
        'effective from' => 'effective_from',
        'entry id' => 'entry_id',
        'expense amount' => 'amount',
        'food cost pct' => 'cost_pct',
        'food or drink cost' => 'cost_pct',
        'food or drink cost pct' => 'cost_pct',
        'for month' => 'for_month',
        'gross' => 'revenue',
        'gross margin' => 'gross_margin',
        'gross margin lkr' => 'gross_margin',
        'ingredient id' => 'ingredient_id',
        'ingredient_id' => 'ingredient_id',
        'inv' => 'invoice',
        'inv no' => 'invoice',
        'invoice' => 'invoice',
        'invoice date' => 'invoice_date',
        'invoice no' => 'invoice',
        'invoice number' => 'invoice',
        'item id' => 'item_id',
        'item_id' => 'item_id',
        'kwh per cover' => 'kwh_per_cover',
        'line total' => 'line_total',
        'logged by' => 'logged_by',
        'lunch covers' => 'lunch_covers',
        'month' => 'month',
        'net' => 'revenue',
        'note' => 'notes',
        'notes' => 'notes',
        'pack price' => 'pack_price',
        'paid date' => 'paid_date',
        'price' => 'price',
        'price lkr' => 'price',
        'product id' => 'item_id',
        'qty' => 'qty',
        'qty delivered' => 'qty_delivered',
        'qty per portion' => 'qty_per_portion',
        'qty sold' => 'qty_sold',
        'quantity' => 'qty',
        'quantity delivered' => 'qty_delivered',
        'quantity sold' => 'qty_sold',
        'reason' => 'reason',
        'recipe cost' => 'recipe_cost',
        'recipe cost lkr' => 'recipe_cost',
        'revenue' => 'revenue',
        'sale price' => 'price',
        'sales' => 'revenue',
        'sku' => 'item_id',
        'status' => 'status',
        'supplier id' => 'supplier_id',
        'supplier_id' => 'supplier_id',
        'total' => 'revenue',
        'total covers' => 'total_covers',
        'unit cost' => 'unit_cost',
        'unit price' => 'price',
        'units' => 'qty',
        'units sold' => 'qty_sold',
        'vendor invoice' => 'vendor_invoice',
    ),
);
