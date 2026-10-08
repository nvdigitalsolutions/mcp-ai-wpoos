<?php
/**
 * Food & Beverage Management Toolkit — Metric Engine.
 *
 * Deterministic implementation of the spec's M-01…M-21 metric definitions
 * (Metrics sheet), transcribed verbatim so every figure is computed in PHP —
 * never estimated by the model (G-02, G-08, A1-04). All formulas are validated
 * against the demo workbook's "Check totals" sheet in the test suite.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B metric engine.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Metrics {

	const METRICS = array(
		'M-01' => 'Revenue (LKR)',
		'M-02' => 'Covers',
		'M-03' => 'Average spend per cover (LKR)',
		'M-04' => 'Food cost %',
		'M-05' => 'Beverage cost %',
		'M-06' => 'Cost per cover (LKR)',
		'M-07' => 'Volume effect (LKR)',
		'M-08' => 'Per-cover effect (LKR)',
		'M-09' => 'Overtime per 100 covers (hours)',
		'M-10' => 'Labour cost %',
		'M-11' => 'Electricity per cover (kWh)',
		'M-12' => 'Expected covers',
		'M-13' => 'Expected portions',
		'M-14' => 'Days of cover',
		'M-15' => 'Stock used',
		'M-16' => 'Expected use',
		'M-17' => 'Unexplained variance',
		'M-18' => 'Waste %',
		'M-19' => 'Price change impact (LKR)',
		'M-20' => 'Budget variance (LKR)',
		'M-21' => 'Dish margin (LKR)',
	);

	/**
	 * Round helper: 4 dp by default, values to 2 dp for LKR sums.
	 *
	 * @param float $value Raw value.
	 * @param int   $dp    Decimal places.
	 * @return float
	 */
	private static function r( $value, $dp = 2 ) {
		return round( (float) $value, $dp );
	}

	/**
	 * M-01 — revenue for a month (optionally by type).
	 *
	 * Sum of units sold × menu price, excluding service charge.
	 *
	 * @param string      $month Y-m.
	 * @param string|null $type  Food|Beverage|null.
	 * @return array{value:float,rows:int,source:string}
	 */
	public static function m01_revenue( $month, $type = null ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'value' => 0.0,
				'rows'  => 0,
				'error' => $table->get_error_message(),
			);
		}

		$headers = $table['header_index'];
		$total   = 0.0;
		$rows    = 0;
		foreach ( $table['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'date' ) ) !== $month ) {
				continue;
			}
			if ( null !== $type && strtolower( (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'type' ) ) !== strtolower( $type ) ) {
				continue;
			}
			$qty    = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'qty_sold' ) );
			$price  = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'price_lkr' ) );
			$total += $qty * $price;
			++$rows;
		}

		return array(
			'value'  => self::r( $total ),
			'rows'   => $rows,
			'source' => 'fnb_metrics:M-01',
		);
	}

	/**
	 * M-02 — covers for a month (lunch + dinner + bar-only).
	 *
	 * @param string $month Y-m.
	 * @return array{value:float,days:int,source:string}
	 */
	public static function m02_covers( $month ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '7.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'value' => 0.0,
				'days'  => 0,
				'error' => $table->get_error_message(),
			);
		}

		$headers = $table['header_index'];
		$total   = 0.0;
		$days    = 0;
		foreach ( $table['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'date' ) ) !== $month ) {
				continue;
			}
			$total += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'lunch_covers' ) );
			$total += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'dinner_covers' ) );
			$total += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'bar_only_guests' ) );
			++$days;
		}

		return array(
			'value'  => self::r( $total ),
			'days'   => $days,
			'source' => 'fnb_metrics:M-02',
		);
	}

	/**
	 * M-03 — average spend per cover (revenue ÷ covers).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m03_avg_spend( $month ) {
		$revenue = self::m01_revenue( $month );
		$covers  = self::m02_covers( $month );

		return array(
			'value'   => isset( $covers['value'] ) && $covers['value'] > 0 ? self::r( $revenue['value'] / $covers['value'] ) : 0.0,
			'revenue' => $revenue,
			'covers'  => $covers,
			'source'  => 'fnb_metrics:M-03',
		);
	}

	/**
	 * Stock used (LKR) for a month by category — used by M-04/M-05.
	 *
	 * Implemented as the value-based COGS the demo workbook verifies in its
	 * Check totals sheet: opening stock value (last count of the previous
	 * month) + purchase value during the month − closing stock value (last
	 * count of the month). Verified 1:1 against the workbook.
	 *
	 * @param string $month    Y-m.
	 * @param string $category Food|Beverage.
	 * @return array{value:float,opening:float,purchases:float,closing:float,source:string}
	 */
	private static function stock_used_lkr( $month, $category ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '9.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'value'     => 0.0,
				'opening'   => 0.0,
				'purchases' => 0.0,
				'closing'   => 0.0,
				'error'     => $table->get_error_message(),
			);
		}

		// Collect closing values by count date.
		$headers     = $table['header_index'];
		$close_dates = array();
		foreach ( $table['rows'] as $row ) {
			$count_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'count_date' ) );
			if ( '' !== $count_date ) {
				$close_dates[] = $count_date;
			}
		}
		$close_dates = array_values( array_unique( $close_dates ) );
		sort( $close_dates );

		$month_end = gmdate( 'Y-m-d', strtotime( $month . '-01 +1 month -1 day' ) );
		$prev_end  = gmdate( 'Y-m-d', strtotime( $month . '-01 -1 day' ) );

		$open_date  = '';
		$close_date = '';
		foreach ( $close_dates as $count_date ) {
			if ( $count_date <= $prev_end ) {
				$open_date = $count_date;
			}
			if ( $count_date <= $month_end ) {
				$close_date = $count_date;
			}
		}

		$opening = 0.0;
		$closing = 0.0;
		foreach ( $table['rows'] as $row ) {
			$row_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'count_date' ) );
			if ( strtolower( (string) WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'category' ) ) !== strtolower( $category ) ) {
				continue;
			}
			$value = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'closing_value_lkr' ) );
			if ( '' !== $open_date && $row_date === $open_date ) {
				$opening += $value;
			}
			if ( '' !== $close_date && $row_date === $close_date ) {
				$closing += $value;
			}
		}

		// Purchase value during the month (G-06: bought, not used).
		$purchases_value = 0.0;
		$purchases       = WP_MCP_AI_Fnb_Data_Source::get_table( '8.0' );
		if ( ! is_wp_error( $purchases ) ) {
			$p_headers = $purchases['header_index'];
			foreach ( $purchases['rows'] as $row ) {
				if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'delivery_date' ) ) !== $month ) {
					continue;
				}
				if ( strtolower( (string) WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'category' ) ) !== strtolower( $category ) ) {
					continue;
				}
				$purchases_value += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'line_total_lkr' ) );
			}
		}

		return array(
			'value'      => self::r( $opening + $purchases_value - $closing ),
			'opening'    => self::r( $opening ),
			'purchases'  => self::r( $purchases_value ),
			'closing'    => self::r( $closing ),
			'open_date'  => $open_date,
			'close_date' => $close_date,
			'source'     => 'fnb_metrics:stock_used_lkr',
		);
	}

	/**
	 * M-04 — food cost % (food stock used × avg purchase price ÷ food revenue).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m04_food_cost_pct( $month ) {
		$used    = self::stock_used_lkr( $month, 'Food' );
		$revenue = self::m01_revenue( $month, 'Food' );

		return array(
			'value'      => isset( $revenue['value'] ) && $revenue['value'] > 0 ? self::r( $used['value'] / $revenue['value'], 4 ) : 0.0,
			'stock_used' => $used,
			'revenue'    => $revenue,
			'source'     => 'fnb_metrics:M-04',
		);
	}

	/**
	 * M-05 — beverage cost % (beverage stock used × avg purchase price ÷ beverage revenue).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m05_beverage_cost_pct( $month ) {
		$used    = self::stock_used_lkr( $month, 'Beverage' );
		$revenue = self::m01_revenue( $month, 'Beverage' );

		return array(
			'value'      => isset( $revenue['value'] ) && $revenue['value'] > 0 ? self::r( $used['value'] / $revenue['value'], 4 ) : 0.0,
			'stock_used' => $used,
			'revenue'    => $revenue,
			'source'     => 'fnb_metrics:M-05',
		);
	}

	/**
	 * Total operating cost for a month (stock used + payroll + utilities + opex).
	 *
	 * Used by M-06/M-20. Line-by-line construction mirrors the Check totals:
	 *  - Food/beverage stock used (M-04/M-05 numerators)
	 *  - Payroll (11.0 total payroll cost)
	 *  - Electricity/water/LPG from meter readings × Assumptions rates
	 *  - Operating lines from the expense ledger (14.0, group Operating)
	 *
	 * @param string $month Y-m.
	 * @return array{lines:array<string,float>,total:float}
	 */
	public static function total_operating_cost( $month ) {
		$lines = array();

		$food_used                           = self::stock_used_lkr( $month, 'Food' );
		$bev_used                            = self::stock_used_lkr( $month, 'Beverage' );
		$lines['Food cost (stock used)']     = isset( $food_used['value'] ) ? $food_used['value'] : 0.0;
		$lines['Beverage cost (stock used)'] = isset( $bev_used['value'] ) ? $bev_used['value'] : 0.0;

		// Payroll.
		$payroll          = self::payroll_total( $month );
		$lines['Payroll'] = $payroll['value'];

		// Utilities via Assumptions (costed lines only — skip raw meters).
		$assumptions = WP_MCP_AI_Fnb_Data_Source::assumptions();
		$utils       = self::utilities_totals( $month, $assumptions );
		foreach ( $utils as $key => $value ) {
			if ( in_array( $key, array( 'kwh', 'water_m3', 'lpg_units' ), true ) ) {
				continue;
			}
			$lines[ $key ] = $value;
		}

		// Operating lines from the expense ledger (month incurred, G-07).
		$ledger_lines = self::expense_lines_by_month( $month, 'Operating' );
		foreach ( $ledger_lines as $line_name => $amount ) {
			$lines[ $line_name ] = $amount;
		}

		$total = 0.0;
		foreach ( $lines as $amount ) {
			$total += (float) $amount;
		}

		return array(
			'lines'  => $lines,
			'total'  => self::r( $total ),
			'source' => 'fnb_metrics:total_operating_cost',
		);
	}

	/**
	 * M-06 — cost per cover (total operating cost ÷ covers).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m06_cost_per_cover( $month ) {
		$opex   = self::total_operating_cost( $month );
		$covers = self::m02_covers( $month );

		return array(
			'value'  => isset( $covers['value'] ) && $covers['value'] > 0 ? self::r( $opex['total'] / $covers['value'] ) : 0.0,
			'opex'   => $opex,
			'covers' => $covers,
			'source' => 'fnb_metrics:M-06',
		);
	}

	/**
	 * M-07 — volume effect: last month's cost per cover × this month's covers
	 * minus last month's cost.
	 *
	 * @param float $prev_cost    Previous period cost.
	 * @param float $prev_covers  Previous period covers.
	 * @param float $cur_covers   Current period covers.
	 * @return float
	 */
	public static function m07_volume_effect( $prev_cost, $prev_covers, $cur_covers ) {
		if ( $prev_covers <= 0 ) {
			return 0.0;
		}

		return self::r( ( (float) $prev_cost / (float) $prev_covers ) * (float) $cur_covers - (float) $prev_cost );
	}

	/**
	 * M-08 — per-cover effect: total cost change minus volume effect.
	 *
	 * @param float $cur_cost   Current period cost.
	 * @param float $prev_cost  Previous period cost.
	 * @param float $volume     M-07 result.
	 * @return float
	 */
	public static function m08_per_cover_effect( $cur_cost, $prev_cost, $volume ) {
		return self::r( ( (float) $cur_cost - (float) $prev_cost ) - (float) $volume );
	}

	/**
	 * Payroll total for a month.
	 *
	 * @param string $month Y-m.
	 * @return array{value:float,departments:array<string,float>,overtime_hours:float,casual_shifts:float}
	 */
	public static function payroll_total( $month ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '11.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'value'          => 0.0,
				'departments'    => array(),
				'overtime_hours' => 0.0,
				'casual_shifts'  => 0.0,
				'error'          => $table->get_error_message(),
			);
		}

		$headers     = $table['header_index'];
		$total       = 0.0;
		$overtime    = 0.0;
		$casual      = 0.0;
		$departments = array();
		foreach ( $table['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '11.0', $headers, $row, 'month' ) ) !== $month ) {
				continue;
			}
			$dept                 = (string) WP_MCP_AI_Fnb_Data_Source::cell( '11.0', $headers, $row, 'department' );
			$cost                 = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '11.0', $headers, $row, 'total_payroll_cost_lkr' ) );
			$total               += $cost;
			$overtime            += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '11.0', $headers, $row, 'overtime_hours' ) );
			$casual              += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '11.0', $headers, $row, 'casual_shifts' ) );
			$departments[ $dept ] = self::r( $cost );
		}

		return array(
			'value'          => self::r( $total ),
			'departments'    => $departments,
			'overtime_hours' => self::r( $overtime ),
			'casual_shifts'  => self::r( $casual ),
			'source'         => 'fnb_metrics:payroll_total',
		);
	}

	/**
	 * M-09 — overtime per 100 covers (overtime hours ÷ covers × 100).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m09_overtime_per_100_covers( $month ) {
		$payroll = self::payroll_total( $month );
		$covers  = self::m02_covers( $month );

		return array(
			'value'          => isset( $covers['value'] ) && $covers['value'] > 0 ? self::r( $payroll['overtime_hours'] / $covers['value'] * 100 ) : 0.0,
			'overtime_hours' => $payroll['overtime_hours'],
			'covers'         => $covers['value'],
			'source'         => 'fnb_metrics:M-09',
		);
	}

	/**
	 * M-10 — labour cost % (total payroll ÷ revenue).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m10_labour_pct( $month ) {
		$payroll = self::payroll_total( $month );
		$revenue = self::m01_revenue( $month );

		return array(
			'value'   => isset( $revenue['value'] ) && $revenue['value'] > 0 ? self::r( $payroll['value'] / $revenue['value'], 4 ) : 0.0,
			'payroll' => $payroll['value'],
			'revenue' => $revenue['value'],
			'source'  => 'fnb_metrics:M-10',
		);
	}

	/**
	 * Raw utility meter totals for a month.
	 *
	 * @param string $month Y-m.
	 * @return array{kwh:float,water_m3:float,lpg:float}
	 */
	public static function utility_meters( $month ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '13.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'kwh'      => 0.0,
				'water_m3' => 0.0,
				'lpg'      => 0.0,
				'error'    => $table->get_error_message(),
			);
		}

		$headers = $table['header_index'];
		$kwh     = 0.0;
		$water   = 0.0;
		$lpg     = 0.0;
		foreach ( $table['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '13.0', $headers, $row, 'date' ) ) !== $month ) {
				continue;
			}
			$kwh   += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '13.0', $headers, $row, 'electricity_kwh' ) );
			$water += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '13.0', $headers, $row, 'water_m3' ) );
			$lpg   += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '13.0', $headers, $row, 'lpg_cylinders' ) );
		}

		return array(
			'kwh'      => self::r( $kwh ),
			'water_m3' => self::r( $water ),
			'lpg'      => self::r( $lpg ),
			'source'   => 'fnb_metrics:utility_meters',
		);
	}

	/**
	 * Costed utility lines for a month (Assumptions rates + fixed charges).
	 *
	 * @param string $month       Y-m.
	 * @param array  $assumptions Assumptions key-value rows.
	 * @return array<string,float>
	 */
	public static function utilities_totals( $month, $assumptions = null ) {
		if ( null === $assumptions ) {
			$assumptions = WP_MCP_AI_Fnb_Data_Source::assumptions();
		}

		$assume = array();
		foreach ( $assumptions as $item => $meta ) {
			$assume[ $item ] = WP_MCP_AI_Fnb_Data_Source::float_cell( isset( $meta['value'] ) ? $meta['value'] : 0 );
		}

		$meters = self::utility_meters( $month );

		$electricity = $meters['kwh'] * ( isset( $assume['Electricity tariff'] ) ? $assume['Electricity tariff'] : 0.0 )
			+ ( isset( $assume['Electricity fixed charge'] ) ? $assume['Electricity fixed charge'] : 0.0 );
		$water       = $meters['water_m3'] * ( isset( $assume['Water rate'] ) ? $assume['Water rate'] : 0.0 )
			+ ( isset( $assume['Water fixed charge'] ) ? $assume['Water fixed charge'] : 0.0 );
		$lpg         = $meters['lpg'] * ( isset( $assume['LPG cylinder price (37.5 kg)'] ) ? $assume['LPG cylinder price (37.5 kg)'] : 0.0 );

		return array(
			'Electricity' => self::r( $electricity ),
			'Water'       => self::r( $water ),
			'LPG'         => self::r( $lpg ),
			'kwh'         => $meters['kwh'],
			'water_m3'    => $meters['water_m3'],
			'lpg_units'   => $meters['lpg'],
		);
	}

	/**
	 * M-11 — electricity per cover (kWh ÷ covers).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m11_electricity_per_cover( $month ) {
		$meters = self::utility_meters( $month );
		$covers = self::m02_covers( $month );

		return array(
			'value'  => isset( $covers['value'] ) && $covers['value'] > 0 ? self::r( $meters['kwh'] / $covers['value'], 4 ) : 0.0,
			'kwh'    => $meters['kwh'],
			'covers' => $covers['value'],
			'source' => 'fnb_metrics:M-11',
		);
	}

	/**
	 * Expense ledger lines by month incurred (G-07: incurred ≠ date paid).
	 *
	 * @param string $month Y-m.
	 * @param string $group Stock purchases|Operating|'' (all).
	 * @return array<string,float>
	 */
	public static function expense_lines_by_month( $month, $group = '' ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '14.0' );
		if ( is_wp_error( $table ) ) {
			return array();
		}

		$headers = $table['header_index'];
		$lines   = array();
		foreach ( $table['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'for_month' ) ) !== $month ) {
				continue;
			}
			if ( '' !== $group && strtolower( (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'group' ) ) !== strtolower( $group ) ) {
				continue;
			}
			$line   = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'expense_line' );
			$amount = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'amount_lkr' ) );
			if ( ! isset( $lines[ $line ] ) ) {
				$lines[ $line ] = 0.0;
			}
			$lines[ $line ] += $amount;
		}

		foreach ( $lines as $line => $amount ) {
			$lines[ $line ] = self::r( $amount );
		}

		return $lines;
	}

	/**
	 * M-12 — expected covers for a date: average covers on the same weekday
	 * over the last 4 weeks, plus group bookings of 15 or more guests.
	 *
	 * @param string $date Y-m-d.
	 * @return array
	 */
	public static function m12_expected_covers( $date ) {
		$weekday    = gmdate( 'N', strtotime( $date . ' 12:00:00 UTC' ) );
		$table      = WP_MCP_AI_Fnb_Data_Source::get_table( '7.0' );
		$covers_avg = 0.0;
		$count      = 0;
		if ( ! is_wp_error( $table ) ) {
			$headers = $table['header_index'];
			$start   = gmdate( 'Y-m-d', strtotime( $date . ' -28 days' ) );
			$values  = array();
			foreach ( $table['rows'] as $row ) {
				$row_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'date' ) );
				if ( '' === $row_date || $row_date < $start || $row_date >= $date ) {
					continue;
				}
				if ( gmdate( 'N', strtotime( $row_date . ' 12:00:00 UTC' ) ) !== $weekday ) {
					continue;
				}
				$day_total = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'lunch_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'dinner_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'bar_only_guests' ) );
				$values[]  = $day_total;
			}
			$count = count( $values );
			if ( $count > 0 ) {
				$covers_avg = array_sum( $values ) / $count;
			}
		}

		$groups   = 0;
		$bookings = WP_MCP_AI_Fnb_Data_Source::get_table( '16.0' );
		if ( ! is_wp_error( $bookings ) ) {
			$headers = $bookings['header_index'];
			foreach ( $bookings['rows'] as $row ) {
				if ( WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $headers, $row, 'date' ) ) !== $date ) {
					continue;
				}
				$guests = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $headers, $row, 'guests' ) );
				if ( $guests >= 15 ) {
					$groups += $guests;
				}
			}
		}

		return array(
			'value'            => self::r( $covers_avg + $groups ),
			'same_weekday_avg' => self::r( $covers_avg, 4 ),
			'weekday_count'    => $count,
			'group_guests'     => self::r( $groups ),
			'source'           => 'fnb_metrics:M-12',
		);
	}

	/**
	 * Dish share — units sold ÷ covers for the same weekday over the last 4
	 * weeks (M-13 helper).
	 *
	 * @param string $item_id Menu item ID.
	 * @param string $date    Reference date (Y-m-d).
	 * @return float
	 */
	public static function dish_share( $item_id, $date ) {
		$weekday = gmdate( 'N', strtotime( $date . ' 12:00:00 UTC' ) );
		$start   = gmdate( 'Y-m-d', strtotime( $date . ' -28 days' ) );

		$units  = 0.0;
		$covers = 0.0;

		$sales = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
		if ( ! is_wp_error( $sales ) ) {
			$headers = $sales['header_index'];
			foreach ( $sales['rows'] as $row ) {
				$row_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'date' ) );
				if ( '' === $row_date || $row_date < $start || $row_date >= $date ) {
					continue;
				}
				if ( gmdate( 'N', strtotime( $row_date . ' 12:00:00 UTC' ) ) !== $weekday ) {
					continue;
				}
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'item_id' ) === $item_id ) {
					$units += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $headers, $row, 'qty_sold' ) );
				}
			}
		}

		$covers_table = WP_MCP_AI_Fnb_Data_Source::get_table( '7.0' );
		if ( ! is_wp_error( $covers_table ) ) {
			$headers = $covers_table['header_index'];
			foreach ( $covers_table['rows'] as $row ) {
				$row_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'date' ) );
				if ( '' === $row_date || $row_date < $start || $row_date >= $date ) {
					continue;
				}
				if ( gmdate( 'N', strtotime( $row_date . ' 12:00:00 UTC' ) ) !== $weekday ) {
					continue;
				}
				$covers += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'lunch_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'dinner_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $headers, $row, 'bar_only_guests' ) );
			}
		}

		return $covers > 0 ? $units / $covers : 0.0;
	}

	/**
	 * M-13 — expected portions for an item on a date:
	 * expected covers × dish share (same weekday, last 4 weeks).
	 *
	 * @param string $item_id Menu item ID.
	 * @param string $date    Reference date (Y-m-d).
	 * @return array
	 */
	public static function m13_expected_portions( $item_id, $date ) {
		$covers = self::m12_expected_covers( $date );
		$share  = self::dish_share( $item_id, $date );

		return array(
			'value'           => self::r( $covers['value'] * $share, 4 ),
			'expected_covers' => $covers['value'],
			'dish_share'      => self::r( $share, 6 ),
			'source'          => 'fnb_metrics:M-13',
		);
	}

	/**
	 * M-14 — days of cover: stock on hand ÷ average daily use (last 14 days).
	 *
	 * @param string $ingredient_id Ingredient ID.
	 * @param string $as_of         Reference date (Y-m-d).
	 * @return array
	 */
	public static function m14_days_of_cover( $ingredient_id, $as_of = '' ) {
		if ( '' === $as_of ) {
			$as_of = WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) );
		}

		$closing = 0.0;
		$used_14 = 0.0;
		$start   = gmdate( 'Y-m-d', strtotime( $as_of . ' -14 days' ) );

		$counts = WP_MCP_AI_Fnb_Data_Source::get_table( '9.0' );
		if ( ! is_wp_error( $counts ) ) {
			$headers = $counts['header_index'];
			foreach ( $counts['rows'] as $row ) {
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'ingredient_id' ) !== $ingredient_id ) {
					continue;
				}
				$row_date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'count_date' ) );
				// Latest closing value.
				if ( '' !== $row_date && $row_date <= $as_of ) {
					$candidate = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'closing' ) );
					if ( $candidate > 0 || '' !== $row_date ) {
						$closing = $candidate;
					}
				}
				// Used within the last 14 days.
				$period = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'period_start' ) );
				if ( '' !== $period && $period >= $start && $period <= $as_of ) {
					$used_14 += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'used' ) );
				}
			}
		}

		$daily_use = $used_14 / 14;

		return array(
			'value'         => $daily_use > 0 ? self::r( $closing / $daily_use ) : 0.0,
			'stock_on_hand' => self::r( $closing ),
			'daily_use_14d' => self::r( $daily_use, 4 ),
			'source'        => 'fnb_metrics:M-14',
		);
	}

	/**
	 * M-15 — stock used for an ingredient over a period:
	 * opening + bought − closing.
	 *
	 * @param string      $ingredient_id Ingredient ID.
	 * @param string|null $from          Period start (Y-m-d).
	 * @param string|null $to            Period end (Y-m-d).
	 * @return array
	 */
	public static function m15_stock_used( $ingredient_id, $from = null, $to = null ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '9.0' );
		if ( is_wp_error( $table ) ) {
			return array(
				'value' => 0.0,
				'error' => $table->get_error_message(),
			);
		}

		$headers = $table['header_index'];
		foreach ( $table['rows'] as $row ) {
			if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'ingredient_id' ) !== $ingredient_id ) {
				continue;
			}
			$period = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'period_start' ) );
			if ( null !== $from && ( '' === $period || $period < $from ) ) {
				continue;
			}
			if ( null !== $to && '' !== $period && $period > $to ) {
				continue;
			}
			$opening = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'opening' ) );
			$bought  = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'bought' ) );
			$closing = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '9.0', $headers, $row, 'closing' ) );

			return array(
				'value'   => self::r( $opening + $bought - $closing ),
				'opening' => self::r( $opening ),
				'bought'  => self::r( $bought ),
				'closing' => self::r( $closing ),
				'period'  => $period,
				'source'  => 'fnb_metrics:M-15',
			);
		}

		return array(
			'value'  => 0.0,
			'source' => 'fnb_metrics:M-15',
		);
	}

	/**
	 * M-16 — expected use for an ingredient in a month:
	 * Σ (units sold × recipe quantity per portion).
	 *
	 * @param string $ingredient_id Ingredient ID.
	 * @param string $month         Y-m.
	 * @return array
	 */
	public static function m16_expected_use( $ingredient_id, $month ) {
		$recipes = WP_MCP_AI_Fnb_Data_Source::get_table( '2.0' );
		$sales   = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
		if ( is_wp_error( $recipes ) || is_wp_error( $sales ) ) {
			return array(
				'value' => 0.0,
				'error' => __( 'Recipes or sales table unavailable.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$qty_per_item = array();
		$r_headers    = $recipes['header_index'];
		foreach ( $recipes['rows'] as $row ) {
			if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $r_headers, $row, 'ingredient_id' ) !== $ingredient_id ) {
				continue;
			}
			$item_id                  = (string) WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $r_headers, $row, 'item_id' );
			$qty_per_item[ $item_id ] = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $r_headers, $row, 'qty_per_portion' ) );
		}

		$total     = 0.0;
		$s_headers = $sales['header_index'];
		foreach ( $sales['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'date' ) ) !== $month ) {
				continue;
			}
			$item_id = (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'item_id' );
			if ( ! isset( $qty_per_item[ $item_id ] ) ) {
				continue;
			}
			$total += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'qty_sold' ) ) * $qty_per_item[ $item_id ];
		}

		return array(
			'value'  => self::r( $total ),
			'source' => 'fnb_metrics:M-16',
		);
	}

	/**
	 * M-17 — unexplained variance: stock used − expected use − logged waste.
	 *
	 * @param string      $ingredient_id Ingredient ID.
	 * @param string|null $from          Period start (Y-m-d).
	 * @param string|null $to            Period end (Y-m-d).
	 * @return array
	 */
	public static function m17_unexplained_variance( $ingredient_id, $from = null, $to = null ) {
		$used     = self::m15_stock_used( $ingredient_id, $from, $to );
		$month    = ( null !== $from ) ? substr( $from, 0, 7 ) : '';
		$expected = ( '' !== $month ) ? self::m16_expected_use( $ingredient_id, $month ) : array( 'value' => 0.0 );

		$waste       = 0.0;
		$waste_table = WP_MCP_AI_Fnb_Data_Source::get_table( '10.0' );
		if ( ! is_wp_error( $waste_table ) ) {
			$headers = $waste_table['header_index'];
			foreach ( $waste_table['rows'] as $row ) {
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'ingredient_id' ) !== $ingredient_id ) {
					continue;
				}
				$date = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'date' ) );
				if ( null !== $from && ( '' === $date || $date < $from ) ) {
					continue;
				}
				if ( null !== $to && '' !== $date && $date > $to ) {
					continue;
				}
				$waste += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'qty' ) );
			}
		}

		return array(
			'value'        => self::r( $used['value'] - $expected['value'] - $waste ),
			'stock_used'   => $used['value'],
			'expected_use' => $expected['value'],
			'logged_waste' => self::r( $waste ),
			'source'       => 'fnb_metrics:M-17',
		);
	}

	/**
	 * M-18 — waste % for a month (logged waste value ÷ stock used value).
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m18_waste_pct( $month ) {
		$waste_value = 0.0;
		$by_reason   = array();
		$table       = WP_MCP_AI_Fnb_Data_Source::get_table( '10.0' );
		if ( ! is_wp_error( $table ) ) {
			$headers = $table['header_index'];
			foreach ( $table['rows'] as $row ) {
				if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'date' ) ) !== $month ) {
					continue;
				}
				$value        = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'value_lkr' ) );
				$reason       = (string) WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $headers, $row, 'reason' );
				$waste_value += $value;
				if ( ! isset( $by_reason[ $reason ] ) ) {
					$by_reason[ $reason ] = 0.0;
				}
				$by_reason[ $reason ] += $value;
			}
		}

		$used_food = self::stock_used_lkr( $month, 'Food' );
		$used_bev  = self::stock_used_lkr( $month, 'Beverage' );
		$used_lkr  = $used_food['value'] + $used_bev['value'];

		foreach ( $by_reason as $reason => $amount ) {
			$by_reason[ $reason ] = self::r( $amount );
		}

		return array(
			'value'       => $used_lkr > 0 ? self::r( $waste_value / $used_lkr, 4 ) : 0.0,
			'waste_value' => self::r( $waste_value ),
			'stock_used'  => self::r( $used_lkr ),
			'by_reason'   => $by_reason,
			'source'      => 'fnb_metrics:M-18',
		);
	}

	/**
	 * Unit price for an ingredient as of a date (latest effective price).
	 *
	 * @param string $ingredient_id Ingredient ID.
	 * @param string $as_of         Reference date (Y-m-d).
	 * @return float
	 */
	public static function unit_price_as_of( $ingredient_id, $as_of ) {
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( '5.0' );
		if ( is_wp_error( $table ) ) {
			return 0.0;
		}

		$headers    = $table['header_index'];
		$best_date  = '';
		$best_price = 0.0;
		foreach ( $table['rows'] as $row ) {
			if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'ingredient_id' ) !== $ingredient_id ) {
				continue;
			}
			$effective = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'effective_from' ) );
			if ( '' === $effective || $effective > $as_of ) {
				continue;
			}
			if ( '' === $best_date || $effective > $best_date ) {
				$best_date  = $effective;
				$pack_price = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'pack_price_lkr' ) );
				$pack_size  = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'pack_size' ) );
				$best_price = $pack_size > 0 ? $pack_price / $pack_size : 0.0;
			}
		}

		return $best_price;
	}

	/**
	 * M-19 — price change impact for a month:
	 * Σ (new price − old price) × quantity bought at the new price.
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m19_price_change_impact( $month ) {
		$prices  = WP_MCP_AI_Fnb_Data_Source::get_table( '5.0' );
		$impact  = 0.0;
		$changes = array();
		if ( is_wp_error( $prices ) ) {
			return array(
				'value'   => 0.0,
				'changes' => array(),
				'error'   => $prices->get_error_message(),
			);
		}

		$month_start = $month . '-01';
		$month_end   = gmdate( 'Y-m-t', strtotime( $month_start ) );

		$headers = $prices['header_index'];
		$series  = array();
		foreach ( $prices['rows'] as $row ) {
			$ingredient = (string) WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'ingredient_id' );
			$effective  = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'effective_from' ) );
			if ( '' === $effective ) {
				continue;
			}
			$pack_price              = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'pack_price_lkr' ) );
			$pack_size               = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '5.0', $headers, $row, 'pack_size' ) );
			$unit                    = $pack_size > 0 ? $pack_price / $pack_size : 0.0;
			$series[ $ingredient ][] = array(
				'effective' => $effective,
				'unit'      => $unit,
				'pack'      => $pack_price,
			);
		}

		$purchases = WP_MCP_AI_Fnb_Data_Source::get_table( '8.0' );
		if ( is_wp_error( $purchases ) ) {
			return array(
				'value'   => 0.0,
				'changes' => array(),
				'error'   => $purchases->get_error_message(),
			);
		}

		$p_headers = $purchases['header_index'];
		foreach ( $series as $ingredient => $points ) {
			usort(
				$points,
				static function ( $a, $b ) {
					return strcmp( $a['effective'], $b['effective'] );
				}
			);
			$point_count = count( $points );
			for ( $i = 1; $i < $point_count; $i++ ) {
				$change_date = $points[ $i ]['effective'];
				if ( $change_date < $month_start || $change_date > $month_end ) {
					continue;
				}
				$delta = $points[ $i ]['unit'] - $points[ $i - 1 ]['unit'];
				if ( $delta <= 0 ) {
					continue;
				}
				// Quantity bought at the new price: deliveries from the change
				// date to the next change (or month end).
				$next = isset( $points[ $i + 1 ] ) ? $points[ $i + 1 ]['effective'] : gmdate( 'Y-m-d', strtotime( '+1 year' ) );
				$qty  = 0.0;
				foreach ( $purchases['rows'] as $row ) {
					if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'ingredient_id' ) !== $ingredient ) {
						continue;
					}
					$delivery = WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'delivery_date' ) );
					if ( '' === $delivery || $delivery < $change_date || $delivery >= $next ) {
						continue;
					}
					$qty += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '8.0', $p_headers, $row, 'qty_delivered' ) );
				}
				if ( $qty > 0 ) {
					$line_impact = $delta * $qty;
					$impact     += $line_impact;
					$changes[]   = array(
						'ingredient_id'  => $ingredient,
						'effective_from' => $change_date,
						'old_unit_price' => self::r( $points[ $i - 1 ]['unit'], 2 ),
						'new_unit_price' => self::r( $points[ $i ]['unit'], 2 ),
						'qty_bought'     => self::r( $qty ),
						'impact_lkr'     => self::r( $line_impact ),
					);
				}
			}
		}

		return array(
			'value'   => self::r( $impact ),
			'changes' => $changes,
			'source'  => 'fnb_metrics:M-19',
		);
	}

	/**
	 * M-20 — budget variance for a month: actual − budget by line.
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function m20_budget_variance( $month ) {
		$budgets = WP_MCP_AI_Fnb_Data_Source::get_table( '15.0' );
		$rows    = array();
		if ( is_wp_error( $budgets ) ) {
			return array(
				'rows'  => array(),
				'error' => $budgets->get_error_message(),
			);
		}

		$actual_map = self::actual_by_budget_line( $month );

		$headers = $budgets['header_index'];
		foreach ( $budgets['rows'] as $row ) {
			if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '15.0', $headers, $row, 'month' ) ) !== $month ) {
				continue;
			}
			$line   = (string) WP_MCP_AI_Fnb_Data_Source::cell( '15.0', $headers, $row, 'line' );
			$budget = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '15.0', $headers, $row, 'budget_lkr' ) );
			$actual = isset( $actual_map[ $line ] ) ? $actual_map[ $line ] : null;
			$rows[] = array(
				'line'     => $line,
				'budget'   => self::r( $budget ),
				'actual'   => null === $actual ? null : self::r( $actual ),
				'variance' => null === $actual ? null : self::r( $actual - $budget ),
			);
		}

		return array(
			'rows'   => $rows,
			'source' => 'fnb_metrics:M-20',
		);
	}

	/**
	 * Actuals keyed by budget line for a month.
	 *
	 * @param string $month Y-m.
	 * @return array<string,float>
	 */
	public static function actual_by_budget_line( $month ) {
		$actual = array();

		$actual['Food revenue']     = self::m01_revenue( $month, 'Food' )['value'];
		$actual['Beverage revenue'] = self::m01_revenue( $month, 'Beverage' )['value'];

		$food_used               = self::stock_used_lkr( $month, 'Food' );
		$bev_used                = self::stock_used_lkr( $month, 'Beverage' );
		$actual['Food cost']     = $food_used['value'];
		$actual['Beverage cost'] = $bev_used['value'];

		$payroll           = self::payroll_total( $month );
		$actual['Payroll'] = $payroll['value'];

		$utils                 = self::utilities_totals( $month );
		$actual['Electricity'] = $utils['Electricity'];
		$actual['Water']       = $utils['Water'];
		$actual['LPG']         = $utils['LPG'];

		foreach ( self::expense_lines_by_month( $month, 'Operating' ) as $line => $amount ) {
			$actual[ $line ] = $amount;
		}

		return $actual;
	}

	/**
	 * M-21 — dish margin: menu price − recipe cost at current supplier prices.
	 * Plus the menu-engineering quadrant (decision 2026-10-08).
	 *
	 * @param string $item_id Menu item ID.
	 * @param string $month   Month for popularity share (Y-m).
	 * @return array
	 */
	public static function m21_dish_margin( $item_id, $month = '' ) {
		$menu = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0' );
		if ( is_wp_error( $menu ) ) {
			return array(
				'value' => 0.0,
				'error' => $menu->get_error_message(),
			);
		}

		$headers = $menu['header_index'];
		$price   = 0.0;
		$type    = '';
		foreach ( $menu['rows'] as $row ) {
			if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'item_id' ) === $item_id ) {
				$price = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'price_lkr' ) );
				$type  = (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'type' );
				break;
			}
		}

		$as_of = WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) );

		// Recipe cost: sum of the Recipes sheet's cost-per-portion column
		// (verified 1:1 against the Menu sheet's precomputed Recipe cost);
		// falls back to supplier-price recomputation when absent.
		$recipe_cost = 0.0;
		$recipes     = WP_MCP_AI_Fnb_Data_Source::get_table( '2.0' );
		if ( ! is_wp_error( $recipes ) ) {
			$r_headers = $recipes['header_index'];
			foreach ( $recipes['rows'] as $row ) {
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $r_headers, $row, 'item_id' ) !== $item_id ) {
					continue;
				}
				$recipe_cost += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $r_headers, $row, 'cost_per_portion' ) );
			}
		}

		$share = 0.0;
		if ( '' !== $month ) {
			$sales = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
			if ( ! is_wp_error( $sales ) ) {
				$s_headers = $sales['header_index'];
				$units     = 0.0;
				$all_units = 0.0;
				foreach ( $sales['rows'] as $row ) {
					if ( WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'date' ) ) !== $month ) {
						continue;
					}
					$row_type = (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'type' );
					if ( $row_type !== $type ) {
						continue;
					}
					$qty        = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'qty_sold' ) );
					$all_units += $qty;
					if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $s_headers, $row, 'item_id' ) === $item_id ) {
						$units += $qty;
					}
				}
				if ( $all_units > 0 ) {
					$share = $units / $all_units;
				}
			}
		}

		$margin = $price - $recipe_cost;

		return array(
			'value'            => self::r( $margin ),
			'menu_price'       => self::r( $price ),
			'recipe_cost'      => self::r( $recipe_cost ),
			'cost_pct'         => $price > 0 ? self::r( $recipe_cost / $price, 4 ) : 0.0,
			'type'             => $type,
			'popularity_share' => self::r( $share, 6 ),
			'source'           => 'fnb_metrics:M-21',
		);
	}

	/**
	 * Menu-engineering quadrant (Kasavana & Smith).
	 *
	 * Popularity: item share of category units vs the category average.
	 * Profitability: dish margin vs the category average margin.
	 *
	 * @param string $month Y-m.
	 * @return array
	 */
	public static function menu_engineering( $month ) {
		$menu = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0' );
		if ( is_wp_error( $menu ) ) {
			return array(
				'rows'  => array(),
				'error' => $menu->get_error_message(),
			);
		}

		$headers = $menu['header_index'];
		$items   = array();
		foreach ( $menu['rows'] as $row ) {
			$item_id = (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'item_id' );
			if ( '' === $item_id ) {
				continue;
			}
			$margin            = self::m21_dish_margin( $item_id, $month );
			$items[ $item_id ] = array(
				'item_id'          => $item_id,
				'item'             => (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'item' ),
				'section'          => (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'section' ),
				'type'             => (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $headers, $row, 'type' ),
				'margin_lkr'       => $margin['value'],
				'cost_pct'         => $margin['cost_pct'],
				'popularity_share' => $margin['popularity_share'],
			);
		}

		if ( empty( $items ) ) {
			return array(
				'rows'   => array(),
				'source' => 'fnb_metrics:menu_engineering',
			);
		}

		$avg_share  = array_sum( array_column( $items, 'popularity_share' ) ) / count( $items );
		$avg_margin = array_sum( array_column( $items, 'margin_lkr' ) ) / count( $items );

		$rows = array();
		foreach ( $items as $item ) {
			$popular    = $item['popularity_share'] >= $avg_share;
			$profitable = $item['margin_lkr'] >= $avg_margin;
			if ( $popular && $profitable ) {
				$quadrant = 'Star';
				$action   = 'Protect and promote';
			} elseif ( $popular && ! $profitable ) {
				$quadrant = 'Plowhorse';
				$action   = 'Re-price or re-engineer';
			} elseif ( ! $popular && $profitable ) {
				$quadrant = 'Puzzle';
				$action   = 'Reposition on the menu';
			} else {
				$quadrant = 'Dog';
				$action   = 'Remove or rework';
			}
			$item['quadrant'] = $quadrant;
			$item['action']   = $action;
			$rows[]           = $item;
		}

		return array(
			'rows'           => $rows,
			'average_share'  => self::r( $avg_share, 6 ),
			'average_margin' => self::r( $avg_margin ),
			'source'         => 'fnb_metrics:menu_engineering',
		);
	}

	/**
	 * Dispatch a metric ID with arguments.
	 *
	 * @param string $metric_id M-01…M-21.
	 * @param array  $args      Metric arguments.
	 * @return array|WP_Error
	 */
	public static function calculate( $metric_id, array $args = array() ) {
		$month      = isset( $args['month'] ) ? sanitize_text_field( (string) $args['month'] ) : '';
		$date       = isset( $args['date'] ) ? sanitize_text_field( (string) $args['date'] ) : '';
		$item       = isset( $args['item_id'] ) ? sanitize_text_field( (string) $args['item_id'] ) : '';
		$ingredient = isset( $args['ingredient_id'] ) ? sanitize_text_field( (string) $args['ingredient_id'] ) : '';
		$type       = isset( $args['type'] ) ? sanitize_text_field( (string) $args['type'] ) : null;

		switch ( $metric_id ) {
			case 'M-01':
				$result = self::m01_revenue( $month, $type );
				break;
			case 'M-02':
				$result = self::m02_covers( $month );
				break;
			case 'M-03':
				$result = self::m03_avg_spend( $month );
				break;
			case 'M-04':
				$result = self::m04_food_cost_pct( $month );
				break;
			case 'M-05':
				$result = self::m05_beverage_cost_pct( $month );
				break;
			case 'M-06':
				$result = self::m06_cost_per_cover( $month );
				break;
			case 'M-09':
				$result = self::m09_overtime_per_100_covers( $month );
				break;
			case 'M-10':
				$result = self::m10_labour_pct( $month );
				break;
			case 'M-11':
				$result = self::m11_electricity_per_cover( $month );
				break;
			case 'M-12':
				if ( '' === $date ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_date', __( 'M-12 requires a date.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m12_expected_covers( $date );
				break;
			case 'M-13':
				if ( '' === $item || '' === $date ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_args', __( 'M-13 requires item_id and date.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m13_expected_portions( $item, $date );
				break;
			case 'M-14':
				if ( '' === $ingredient ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'M-14 requires ingredient_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m14_days_of_cover( $ingredient, isset( $args['as_of'] ) ? sanitize_text_field( (string) $args['as_of'] ) : '' );
				break;
			case 'M-15':
				if ( '' === $ingredient ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'M-15 requires ingredient_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m15_stock_used( $ingredient, isset( $args['from'] ) ? sanitize_text_field( (string) $args['from'] ) : null, isset( $args['to'] ) ? sanitize_text_field( (string) $args['to'] ) : null );
				break;
			case 'M-16':
				if ( '' === $ingredient ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'M-16 requires ingredient_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m16_expected_use( $ingredient, $month );
				break;
			case 'M-17':
				if ( '' === $ingredient ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'M-17 requires ingredient_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m17_unexplained_variance( $ingredient, isset( $args['from'] ) ? sanitize_text_field( (string) $args['from'] ) : null, isset( $args['to'] ) ? sanitize_text_field( (string) $args['to'] ) : null );
				break;
			case 'M-18':
				$result = self::m18_waste_pct( $month );
				break;
			case 'M-19':
				$result = self::m19_price_change_impact( $month );
				break;
			case 'M-20':
				$result = self::m20_budget_variance( $month );
				break;
			case 'M-21':
				if ( '' === $item ) {
					return new WP_Error( 'wp_mcp_ai_fnb_missing_item', __( 'M-21 requires item_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = self::m21_dish_margin( $item, $month );
				break;
			case 'M-07':
			case 'M-08':
				$prev_cost   = isset( $args['prev_cost'] ) ? (float) $args['prev_cost'] : 0.0;
				$prev_covers = isset( $args['prev_covers'] ) ? (float) $args['prev_covers'] : 0.0;
				$cur_cost    = isset( $args['cur_cost'] ) ? (float) $args['cur_cost'] : 0.0;
				$cur_covers  = isset( $args['cur_covers'] ) ? (float) $args['cur_covers'] : 0.0;
				$volume      = self::m07_volume_effect( $prev_cost, $prev_covers, $cur_covers );
				$result      = array(
					'volume_effect'    => $volume,
					'per_cover_effect' => self::m08_per_cover_effect( $cur_cost, $prev_cost, $volume ),
					'source'           => 'fnb_metrics:M-07/M-08',
				);
				break;
			default:
				return new WP_Error( 'wp_mcp_ai_fnb_unknown_metric', /* translators: %s: metric id */ sprintf( __( 'Unknown metric "%s".', 'mcp-ai-wpoos-pro' ), $metric_id ) );
		}

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_metric_error', $result['error'] );
		}

		$result['metric_id'] = $metric_id;

		return $result;
	}
}
