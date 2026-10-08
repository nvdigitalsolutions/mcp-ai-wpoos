<?php
/**
 * Food & Beverage Management Toolkit — Report Builder.
 *
 * Renders the spec's R-01…R-13 reports in their Report-library layouts.
 * Every figure comes from the metric engine; this class only assembles
 * sections (G-09, A1-04).
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
 * F&B report builder.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Report_Builder {

	const REPORTS = array(
		'R-01' => array(
			'title'     => 'Daily trading snapshot',
			'assistant' => 'A1',
			'approver'  => 'Restaurant manager',
		),
		'R-02' => array(
			'title'     => 'Weekly needs attention brief',
			'assistant' => 'A1',
			'approver'  => 'CEO',
		),
		'R-03' => array(
			'title'     => 'Weekend prep plan',
			'assistant' => 'A2',
			'approver'  => 'Head chef, bar manager',
		),
		'R-04' => array(
			'title'     => 'Purchase request',
			'assistant' => 'A2',
			'approver'  => 'Head chef',
		),
		'R-05' => array(
			'title'     => 'Waste and variance report',
			'assistant' => 'A2',
			'approver'  => 'Head chef',
		),
		'R-06' => array(
			'title'     => 'Supplier price watch',
			'assistant' => 'A2',
			'approver'  => 'Head chef, finance',
		),
		'R-07' => array(
			'title'     => 'Menu item margin report',
			'assistant' => 'A3',
			'approver'  => 'CEO, head chef',
		),
		'R-08' => array(
			'title'     => 'Monthly cost review',
			'assistant' => 'A3',
			'approver'  => 'CEO',
		),
		'R-09' => array(
			'title'     => 'Labour report',
			'assistant' => 'A3',
			'approver'  => 'CEO',
		),
		'R-10' => array(
			'title'     => 'Utilities report',
			'assistant' => 'A3',
			'approver'  => 'CEO',
		),
		'R-11' => array(
			'title'     => 'Payables report',
			'assistant' => 'A3',
			'approver'  => 'Finance',
		),
		'R-12' => array(
			'title'     => 'Investor monthly pack',
			'assistant' => 'A3',
			'approver'  => 'CEO',
		),
		'R-13' => array(
			'title'     => 'Weekly content brief',
			'assistant' => 'A4',
			'approver'  => 'CEO',
		),
	);

	/**
	 * Build a report by ID.
	 *
	 * @param string $report_id R-01…R-13.
	 * @param array  $args      month (Y-m), date (Y-m-d), item_id, etc.
	 * @return array|WP_Error array{report_id,title,approver,sections,draft}
	 */
	public static function build( $report_id, array $args = array() ) {
		if ( ! isset( self::REPORTS[ $report_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_unknown_report', /* translators: %s: report id */ sprintf( __( 'Unknown F&B report "%s".', 'mcp-ai-wpoos-pro' ), $report_id ) );
		}

		$month = isset( $args['month'] ) ? sanitize_text_field( (string) $args['month'] ) : '';
		$date  = isset( $args['date'] ) ? sanitize_text_field( (string) $args['date'] ) : '';

		$method = 'build_' . strtolower( str_replace( '-', '_', $report_id ) );
		if ( ! method_exists( __CLASS__, $method ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_report_unimplemented', __( 'Report builder not implemented.', 'mcp-ai-wpoos-pro' ) );
		}

		$meta = self::REPORTS[ $report_id ];
		$body = self::$method( $month, $date, $args );

		return array(
			'report_id' => $report_id,
			'title'     => $meta['title'],
			'assistant' => $meta['assistant'],
			'approver'  => $meta['approver'],
			'sections'  => $body,
			'draft'     => array(
				'status'         => 'Draft',
				'doc_title'      => sprintf( '[%s] %s — %s — Draft — for %s', $report_id, $meta['title'], '' !== $month ? $month : $date, $meta['approver'] ),
				'needs_approval' => $meta['approver'],
			),
		);
	}

	/**
	 * R-01 — daily trading snapshot.
	 *
	 * @return array
	 */
	private static function build_r_01() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		if ( '' === $date ) {
			$date = WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) );
		}
		$m = substr( $date, 0, 7 );

		$covers     = WP_MCP_AI_Fnb_Data_Source::search_table( '7.0', 'date', $date, 'date', $date, $date );
		$day_covers = 0.0;
		if ( ! is_wp_error( $covers ) ) {
			foreach ( $covers['rows'] as $row ) {
				$day_covers += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $covers['header_index'], $row, 'lunch_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $covers['header_index'], $row, 'dinner_covers' ) )
					+ WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '7.0', $covers['header_index'], $row, 'bar_only_guests' ) );
			}
		}

		$sales   = WP_MCP_AI_Fnb_Data_Source::search_table( '6.0', 'date', $date, 'date', $date, $date );
		$revenue = 0.0;
		$by_item = array();
		if ( ! is_wp_error( $sales ) ) {
			foreach ( $sales['rows'] as $row ) {
				$qty              = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $sales['header_index'], $row, 'qty_sold' ) );
				$price            = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $sales['header_index'], $row, 'price_lkr' ) );
				$item             = (string) WP_MCP_AI_Fnb_Data_Source::cell( '6.0', $sales['header_index'], $row, 'item_id' );
				$revenue         += $qty * $price;
				$by_item[ $item ] = isset( $by_item[ $item ] ) ? $by_item[ $item ] + $qty * $price : $qty * $price;
			}
		}
		arsort( $by_item );
		$top    = array_slice( $by_item, 0, 5, true );
		$bottom = array_slice( $by_item, -5, 5, true );

		$waste       = WP_MCP_AI_Fnb_Data_Source::search_table( '10.0', 'date', $date, 'date', $date, $date );
		$waste_value = 0.0;
		if ( ! is_wp_error( $waste ) ) {
			foreach ( $waste['rows'] as $row ) {
				$waste_value += WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '10.0', $waste['header_index'], $row, 'value_lkr' ) );
			}
		}

		return array(
			array(
				'heading' => 'Covers',
				'lines'   => array( 'Covers: ' . round( $day_covers ) . ' guests' ),
			),
			array(
				'heading' => 'Revenue',
				'lines'   => array( 'Revenue: ' . number_format( round( $revenue ), 0 ) . ' LKR (excl. service charge)' ),
			),
			array(
				'heading' => 'Top 5 items (by revenue)',
				'lines'   => self::format_ranking( $top ),
			),
			array(
				'heading' => 'Bottom 5 items (by revenue)',
				'lines'   => self::format_ranking( $bottom ),
			),
			array(
				'heading' => 'Waste logged',
				'lines'   => array( 'Waste value: ' . number_format( round( $waste_value ), 0 ) . ' LKR' ),
			),
		);
	}

	/**
	 * R-02 — weekly needs-attention brief (top 5 issues by LKR impact).
	 *
	 * @return array
	 */
	private static function build_r_02() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$issues                      = array();

		$budget = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month );
		if ( isset( $budget['rows'] ) && is_array( $budget['rows'] ) ) {
			foreach ( $budget['rows'] as $row ) {
				if ( null !== $row['variance'] && $row['variance'] > 0 ) {
					$issues[] = array(
						'what'         => $row['line'] . ' over budget',
						'figure'       => '+ ' . number_format( round( $row['variance'] ), 0 ) . ' LKR vs budget',
						'likely_cause' => 'See R-08 volume vs per-cover split.',
						'next_check'   => 'Review expense ledger invoices for this line.',
						'owner'        => 'Finance',
						'impact'       => $row['variance'],
					);
				}
			}
		}

		$waste = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $month );
		if ( isset( $waste['waste_value'] ) && $waste['waste_value'] > 0 ) {
			$issues[] = array(
				'what'         => 'Waste logged this month',
				'figure'       => number_format( round( $waste['waste_value'] ), 0 ) . ' LKR',
				'likely_cause' => 'See logged reasons: ' . implode( ', ', array_keys( $waste['by_reason'] ) ),
				'next_check'   => 'R-05 waste by item and reason.',
				'owner'        => 'Head chef',
				'impact'       => $waste['waste_value'],
			);
		}

		usort(
			$issues,
			static function ( $a, $b ) {
				return $b['impact'] <=> $a['impact'];
			}
		);
		$issues = array_slice( $issues, 0, 5 );

		$lines = array();
		foreach ( $issues as $i => $issue ) {
			$lines[] = sprintf(
				'%d. %s — %s. Likely cause: %s. Next check: %s. Owner: %s.',
				$i + 1,
				$issue['what'],
				$issue['figure'],
				$issue['likely_cause'],
				$issue['next_check'],
				$issue['owner']
			);
		}
		if ( empty( $lines ) ) {
			$lines[] = 'No issues above threshold this week.';
		}

		return array(
			array(
				'heading' => 'Needs attention (max 5, ranked by LKR impact)',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-03 — weekend prep plan.
	 *
	 * @return array
	 */
	private static function build_r_03() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		if ( '' === $date ) {
			$date = WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) );
		}

		$days     = array( $date, gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ), gmdate( 'Y-m-d', strtotime( $date . ' +2 days' ) ) );
		$sections = array();

		foreach ( $days as $day ) {
			$expected      = WP_MCP_AI_Fnb_Metrics::m12_expected_covers( $day );
			$bookings      = WP_MCP_AI_Fnb_Data_Source::search_table( '16.0', 'date', $day, 'date', $day, $day );
			$booking_lines = array();
			if ( ! is_wp_error( $bookings ) ) {
				foreach ( $bookings['rows'] as $row ) {
					$booking_lines[] = sprintf(
						'%s — %s guests — %s (%s) — %s',
						WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $bookings['header_index'], $row, 'booking_id' ),
						round( WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $bookings['header_index'], $row, 'guests' ) ) ),
						WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $bookings['header_index'], $row, 'session' ),
						WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $bookings['header_index'], $row, 'type' ),
						WP_MCP_AI_Fnb_Data_Source::cell( '16.0', $bookings['header_index'], $row, 'notes' )
					);
				}
			}
			$sections[] = array(
				'heading' => $day,
				'lines'   => array_merge(
					array( 'Expected covers: ' . round( $expected['value'] ) . ' (same-weekday avg ' . round( $expected['same_weekday_avg'] ) . ' + group bookings ' . round( $expected['group_guests'] ) . ')' ),
					$booking_lines ? $booking_lines : array( 'No bookings logged.' )
				),
			);
		}

		$shortfall  = self::weekend_shortfall( $days );
		$sections[] = array(
			'heading' => 'Stock shortfall vs expected portions',
			'lines'   => $shortfall['lines'],
		);

		return $sections;
	}

	/**
	 * Compute weekend shortfall lines (A2-04/A2-05).
	 *
	 * @param array $days Weekend dates.
	 * @return array{lines:array<int,string>}
	 */
	private static function weekend_shortfall( $days ) {
		$lines = array();
		$menu  = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0' );
		if ( is_wp_error( $menu ) ) {
			return array( 'lines' => array( 'Menu table unavailable.' ) );
		}

		// Rank dishes by expected weekend portions (top 10).
		$expectations = array();
		foreach ( $menu['rows'] as $row ) {
			$item_id = (string) WP_MCP_AI_Fnb_Data_Source::cell( '1.0', $menu['header_index'], $row, 'item_id' );
			$total   = 0.0;
			foreach ( $days as $day ) {
				$p      = WP_MCP_AI_Fnb_Metrics::m13_expected_portions( $item_id, $day );
				$total += $p['value'];
			}
			$expectations[ $item_id ] = $total;
		}
		arsort( $expectations );
		$top_items = array_slice( $expectations, 0, 10, true );

		foreach ( $top_items as $item_id => $expected_qty ) {
			$ingredient_lines = array();
			$recipes          = WP_MCP_AI_Fnb_Data_Source::get_table( '2.0' );
			if ( ! is_wp_error( $recipes ) ) {
				foreach ( $recipes['rows'] as $row ) {
					if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $recipes['header_index'], $row, 'item_id' ) !== $item_id ) {
						continue;
					}
					$ingredient         = (string) WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $recipes['header_index'], $row, 'ingredient_id' );
					$per_portion        = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '2.0', $recipes['header_index'], $row, 'qty_per_portion' ) );
					$need               = $expected_qty * $per_portion;
					$days_cover         = WP_MCP_AI_Fnb_Metrics::m14_days_of_cover( $ingredient, $days[0] );
					$ingredient_lines[] = sprintf(
						'%s: need ~%.1f units, stock on hand %.1f, days of cover %.1f',
						$ingredient,
						$need,
						$days_cover['stock_on_hand'],
						$days_cover['value']
					);
				}
			}
			$lines[] = sprintf( '%s — expected %.0f portions: %s', $item_id, $expected_qty, implode( '; ', array_slice( $ingredient_lines, 0, 3 ) ) );
		}

		return array( 'lines' => $lines );
	}

	/**
	 * R-04 — purchase request (A2-07 layout; status Draft).
	 *
	 * @return array
	 */
	private static function build_r_04() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$supplier_id                 = isset( $args['supplier_id'] ) ? sanitize_text_field( (string) $args['supplier_id'] ) : '';
		$ingredient                  = isset( $args['ingredient_id'] ) ? sanitize_text_field( (string) $args['ingredient_id'] ) : '';
		$quantity                    = isset( $args['quantity'] ) ? WP_MCP_AI_Fnb_Data_Source::float_cell( $args['quantity'] ) : 0.0;

		$supplier_line = $supplier_id;
		$cutoff        = '';
		$suppliers     = WP_MCP_AI_Fnb_Data_Source::get_table( '4.0' );
		if ( ! is_wp_error( $suppliers ) ) {
			foreach ( $suppliers['rows'] as $row ) {
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'supplier_id' ) === $supplier_id ) {
					$supplier_line = (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'supplier' );
					$cutoff        = (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'order_cutoff' );
					break;
				}
			}
		}

		$unit_price = WP_MCP_AI_Fnb_Metrics::unit_price_as_of( $ingredient, WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) ) );
		$total      = $unit_price * $quantity;

		return array(
			array(
				'heading' => 'Purchase request lines',
				'lines'   => array(
					'Item: ' . $ingredient,
					'Quantity: ' . round( $quantity, 2 ),
					'Supplier: ' . $supplier_line . ' (' . $supplier_id . ')',
					'Unit price: ' . number_format( round( $unit_price, 2 ), 2 ) . ' LKR',
					'Total: ' . number_format( round( $total ), 0 ) . ' LKR',
					'Order by: ' . $cutoff,
					'Needed by: ' . ( '' !== $date ? $date : 'not specified' ),
					'Status: Draft',
				),
			),
		);
	}

	/**
	 * R-05 — waste and variance report.
	 *
	 * @return array
	 */
	private static function build_r_05() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$waste                       = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $month );
		$lines                       = array( 'Waste % (logged waste ÷ stock used): ' . round( $waste['value'] * 100, 2 ) . '%' );
		foreach ( $waste['by_reason'] as $reason => $amount ) {
			$lines[] = sprintf( '%s: %s LKR', $reason, number_format( round( $amount ), 0 ) );
		}

		return array(
			array(
				'heading' => 'Waste by reason (as logged)',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-06 — supplier price watch (M-19 changes + effective dates).
	 *
	 * @return array
	 */
	private static function build_r_06() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$impact                      = WP_MCP_AI_Fnb_Metrics::m19_price_change_impact( $month );
		$lines                       = array( 'Price-change impact this month: ' . number_format( round( $impact['value'] ), 0 ) . ' LKR' );
		foreach ( $impact['changes'] as $change ) {
			$lines[] = sprintf(
				'%s: %s → %s LKR/unit effective %s; qty bought %s; impact %s LKR',
				$change['ingredient_id'],
				number_format( $change['old_unit_price'], 2 ),
				number_format( $change['new_unit_price'], 2 ),
				$change['effective_from'],
				$change['qty_bought'],
				number_format( round( $change['impact_lkr'] ), 0 )
			);
		}

		return array(
			array(
				'heading' => 'Price changes vs last month',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-07 — menu item margin report with engineering quadrants.
	 *
	 * @return array
	 */
	private static function build_r_07() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$engineering                 = WP_MCP_AI_Fnb_Metrics::menu_engineering( $month );
		$lines                       = array();
		foreach ( $engineering['rows'] as $item ) {
			$lines[] = sprintf(
				'%s %s — margin %s LKR (cost %s%%), share %s%% — %s: %s',
				$item['item_id'],
				$item['item'],
				number_format( $item['margin_lkr'], 0 ),
				round( $item['cost_pct'] * 100, 1 ),
				round( $item['popularity_share'] * 100, 2 ),
				$item['quadrant'],
				$item['action']
			);
		}

		return array(
			array(
				'heading' => 'Dish margins and menu-engineering quadrant',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-08 — monthly cost review (actual vs budget vs last month, volume/per-cover).
	 *
	 * @return array
	 */
	private static function build_r_08() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$prev_month                  = isset( $args['prev_month'] ) ? sanitize_text_field( (string) $args['prev_month'] ) : self::prev_month( $month );

		$opex        = WP_MCP_AI_Fnb_Metrics::total_operating_cost( $month );
		$prev        = WP_MCP_AI_Fnb_Metrics::total_operating_cost( $prev_month );
		$covers      = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
		$prev_covers = WP_MCP_AI_Fnb_Metrics::m02_covers( $prev_month );

		$volume    = WP_MCP_AI_Fnb_Metrics::m07_volume_effect( $prev['total'], $prev_covers['value'], $covers['value'] );
		$per_cover = WP_MCP_AI_Fnb_Metrics::m08_per_cover_effect( $opex['total'], $prev['total'], $volume );

		$lines = array(
			sprintf( 'Total operating expenses: %s LKR (prev month %s LKR, change %s LKR)', number_format( round( $opex['total'] ), 0 ), number_format( round( $prev['total'] ), 0 ), number_format( round( $opex['total'] - $prev['total'] ), 0 ) ),
			sprintf( 'Volume effect: %s LKR (covers %s → %s)', number_format( round( $volume ), 0 ), round( $prev_covers['value'] ), round( $covers['value'] ) ),
			sprintf( 'Per-cover effect: %s LKR', number_format( round( $per_cover ), 0 ) ),
		);
		foreach ( $opex['lines'] as $line => $amount ) {
			$lines[] = sprintf( '%s: %s LKR', $line, number_format( round( $amount ), 0 ) );
		}

		$budget       = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month );
		$budget_lines = array();
		if ( isset( $budget['rows'] ) && is_array( $budget['rows'] ) ) {
			foreach ( $budget['rows'] as $row ) {
				if ( null === $row['variance'] ) {
					continue;
				}
				$budget_lines[] = sprintf( '%s: actual %s vs budget %s (%s LKR)', $row['line'], null === $row['actual'] ? 'n/a' : number_format( round( $row['actual'] ), 0 ), number_format( round( $row['budget'] ), 0 ), number_format( round( $row['variance'] ), 0 ) );
			}
		}

		return array(
			array(
				'heading' => 'Cost change (volume vs per-cover)',
				'lines'   => $lines,
			),
			array(
				'heading' => 'Actual vs budget by line',
				'lines'   => $budget_lines,
			),
		);
	}

	/**
	 * R-09 — labour report.
	 *
	 * @return array
	 */
	private static function build_r_09() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$payroll                     = WP_MCP_AI_Fnb_Metrics::payroll_total( $month );
		$m09                         = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( $month );
		$m10                         = WP_MCP_AI_Fnb_Metrics::m10_labour_pct( $month );

		$lines = array(
			sprintf( 'Total payroll: %s LKR', number_format( round( $payroll['value'] ), 0 ) ),
			sprintf( 'Overtime hours: %s (per 100 covers: %s)', $payroll['overtime_hours'], $m09['value'] ),
			sprintf( 'Casual shifts: %s', $payroll['casual_shifts'] ),
			sprintf( 'Labour cost %%: %s%%', round( $m10['value'] * 100, 2 ) ),
		);
		foreach ( $payroll['departments'] as $dept => $cost ) {
			$lines[] = sprintf( '%s: %s LKR', $dept, number_format( round( $cost ), 0 ) );
		}

		return array(
			array(
				'heading' => 'Payroll, overtime and casuals by department',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-10 — utilities report.
	 *
	 * @return array
	 */
	private static function build_r_10() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$utils                       = WP_MCP_AI_Fnb_Metrics::utilities_totals( $month );
		$m11                         = WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( $month );

		$lines = array(
			sprintf( 'Electricity: %s kWh (%s per cover) — %s LKR', $utils['kwh'], $m11['value'], number_format( round( $utils['Electricity'] ), 0 ) ),
			sprintf( 'Water: %s m³ — %s LKR', $utils['water_m3'], number_format( round( $utils['Water'] ), 0 ) ),
			sprintf( 'LPG: %s cylinders — %s LKR', $utils['lpg_units'], number_format( round( $utils['LPG'] ), 0 ) ),
		);

		return array(
			array(
				'heading' => 'kWh, water and LPG per cover',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * R-11 — payables report (unpaid invoices, duplicates, unusual amounts).
	 *
	 * @return array
	 */
	private static function build_r_11() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$ledger                      = WP_MCP_AI_Fnb_Data_Source::get_table( '14.0' );
		if ( is_wp_error( $ledger ) ) {
			return array(
				array(
					'heading' => 'Payables',
					'lines'   => array( 'Expense ledger unavailable.' ),
				),
			);
		}

		$headers    = $ledger['header_index'];
		$unpaid     = array();
		$seen       = array();
		$duplicates = array();
		foreach ( $ledger['rows'] as $row ) {
			$vendor  = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'vendor' );
			$invoice = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'vendor_invoice' );
			$amount  = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'amount_lkr' ) );
			$status  = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'status' );

			$dup_key = strtolower( $vendor . '|' . $invoice . '|' . round( $amount ) );
			if ( isset( $seen[ $dup_key ] ) ) {
				$duplicates[] = sprintf( 'Possible duplicate: %s invoice %s (%s LKR) — flag for checking', $vendor, $invoice, number_format( round( $amount ), 0 ) );
			}
			$seen[ $dup_key ] = true;

			if ( 'Paid' !== $status ) {
				$unpaid[] = sprintf( '%s — %s — %s LKR — due %s — %s', $vendor, $invoice, number_format( round( $amount ), 0 ), WP_MCP_AI_Fnb_Data_Source::date_only( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'due_date' ) ), $status );
			}
		}

		return array(
			array(
				'heading' => 'Unpaid invoices',
				'lines'   => $unpaid ? $unpaid : array( 'No unpaid invoices.' ),
			),
			array(
				'heading' => 'Possible duplicates (flag, do not conclude)',
				'lines'   => $duplicates ? $duplicates : array( 'None flagged.' ),
			),
		);
	}

	/**
	 * R-12 — investor monthly pack (declarative, outcome-first).
	 *
	 * @return array
	 */
	private static function build_r_12() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$food_rev                    = WP_MCP_AI_Fnb_Metrics::m01_revenue( $month, 'Food' );
		$bev_rev                     = WP_MCP_AI_Fnb_Metrics::m01_revenue( $month, 'Beverage' );
		$covers                      = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
		$m03                         = WP_MCP_AI_Fnb_Metrics::m03_avg_spend( $month );
		$m04                         = WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( $month );
		$m05                         = WP_MCP_AI_Fnb_Metrics::m05_beverage_cost_pct( $month );
		$opex                        = WP_MCP_AI_Fnb_Metrics::total_operating_cost( $month );
		$revenue                     = $food_rev['value'] + $bev_rev['value'];

		$lines = array(
			sprintf( 'Revenue for %s was %s LKR across %s covers (average spend %s LKR per cover).', $month, number_format( round( $revenue ), 0 ), round( $covers['value'] ), number_format( $m03['value'], 0 ) ),
			sprintf( 'Food revenue %s LKR; beverage revenue %s LKR.', number_format( round( $food_rev['value'] ), 0 ), number_format( round( $bev_rev['value'] ), 0 ) ),
			sprintf( 'Food cost was %s%% of food revenue and beverage cost %s%% of beverage revenue, valued on stock used.', round( $m04['value'] * 100, 2 ), round( $m05['value'] * 100, 2 ) ),
			sprintf( 'Total operating expenses were %s LKR (%s%% of revenue).', number_format( round( $opex['total'] ), 0 ), $revenue > 0 ? round( $opex['total'] / $revenue * 100, 2 ) : 0 ),
		);

		$budget       = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month );
		$budget_lines = array();
		if ( isset( $budget['rows'] ) && is_array( $budget['rows'] ) ) {
			foreach ( $budget['rows'] as $row ) {
				if ( null !== $row['variance'] && $row['variance'] > 0 ) {
					$budget_lines[] = sprintf( '%s exceeded budget by %s LKR.', $row['line'], number_format( round( $row['variance'] ), 0 ) );
				}
			}
		}

		return array(
			array(
				'heading' => 'Headline KPIs',
				'lines'   => $lines,
			),
			array(
				'heading' => 'Budget variance',
				'lines'   => $budget_lines ? $budget_lines : array( 'All lines at or under budget.' ),
			),
			array(
				'heading' => 'Key issues and actions',
				'lines'   => array( 'See R-02 needs-attention brief.' ),
			),
		);
	}

	/**
	 * R-13 — weekly content brief (A4).
	 *
	 * @return array
	 */
	private static function build_r_13() {
		list( $month, $date, $args ) = array_pad( func_get_args(), 3, array() );
		$lines                       = array();

		// Quiet days: lowest expected covers over the next 7 days.
		$start    = ( '' !== $date ) ? $date : WP_MCP_AI_Fnb_Settings::get( 'as_of_date', gmdate( 'Y-m-d' ) );
		$forecast = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$day              = gmdate( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );
			$forecast[ $day ] = WP_MCP_AI_Fnb_Metrics::m12_expected_covers( $day )['value'];
		}
		asort( $forecast );
		$quiet   = array_slice( array_keys( $forecast ), 0, 2 );
		$lines[] = 'Quiet days this week: ' . implode( ', ', $quiet );

		// Overstocked items: lowest days-of-cover flags.
		$ingredients = WP_MCP_AI_Fnb_Data_Source::get_table( '3.0' );
		if ( ! is_wp_error( $ingredients ) ) {
			$over = array();
			foreach ( $ingredients['rows'] as $row ) {
				$ingredient = (string) WP_MCP_AI_Fnb_Data_Source::cell( '3.0', $ingredients['header_index'], $row, 'ingredient_id' );
				$days       = WP_MCP_AI_Fnb_Metrics::m14_days_of_cover( $ingredient, $start );
				if ( $days['value'] > 14 ) {
					$over[ $ingredient ] = $days['value'];
				}
			}
			arsort( $over );
			$over    = array_slice( $over, 0, 3, true );
			$lines[] = 'Overstocked items to promote: ' . ( $over ? implode(
				', ',
				array_map(
					static function ( $k, $v ) {
						return $k . ' (' . round( $v ) . ' days)';
					},
					array_keys( $over ),
					$over
				)
			) : 'none' );
		}

		// Deity counts from the asset log.
		$assets  = WP_MCP_AI_Fnb_Data_Source::get_table( '17.0' );
		$deities = array();
		if ( ! is_wp_error( $assets ) ) {
			foreach ( $assets['rows'] as $row ) {
				$deity = (string) WP_MCP_AI_Fnb_Data_Source::cell( '17.0', $assets['header_index'], $row, 'deity' );
				if ( '' === $deity ) {
					continue;
				}
				$deities[ $deity ] = isset( $deities[ $deity ] ) ? $deities[ $deity ] + 1 : 1;
			}
		}
		$deity_lines = array();
		foreach ( $deities as $deity => $count ) {
			$deity_lines[] = $deity . ': ' . $count . ' posts';
		}
		$lines[] = 'Deity usage: ' . ( $deity_lines ? implode( '; ', $deity_lines ) : 'none logged' );

		return array(
			array(
				'heading' => 'Content ideas linked to bookings and stock',
				'lines'   => $lines,
			),
		);
	}

	/**
	 * Format an item ranking for report lines.
	 *
	 * @param array $ranking item_id => revenue.
	 * @return array<int,string>
	 */
	private static function format_ranking( $ranking ) {
		$lines = array();
		foreach ( $ranking as $item_id => $revenue ) {
			$lines[] = sprintf( '%s: %s LKR', $item_id, number_format( round( $revenue ), 0 ) );
		}

		return $lines ? $lines : array( 'No sales lines.' );
	}

	/**
	 * Previous month for a Y-m value.
	 *
	 * @param string $month Y-m.
	 * @return string
	 */
	private static function prev_month( $month ) {
		return gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) );
	}
}
