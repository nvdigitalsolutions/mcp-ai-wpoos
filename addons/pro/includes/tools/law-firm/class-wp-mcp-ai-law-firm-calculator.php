<?php
/**
 * Law Firm Toolkit Shared Calculator Engine
 *
 * Core calculation methods used across all Law Firm toolkit modules.
 * Implements industry-standard formulas for legal deadlines, fees,
 * financial math, trust accounting, and billing.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Law_Firm_Calculator tool.
 */
class WP_MCP_AI_Law_Firm_Calculator {

	// Deadline calculations.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Add_business_days.
	 *
	 * @param string $start_date Parameter.
	 * @param int    $days Parameter.
	 * @param string $jurisdiction Parameter.
	 * @return array|WP_Error Result.
	 *
	 * @param int    $start_date Parameter.
	 * @param int    $jurisdiction Parameter.
	 */
	public static function add_business_days( string $start_date, int $days, string $jurisdiction = 'federal' ): string {
		$date     = new DateTime( $start_date );
		$holidays = self::get_federal_holidays( (int) $date->format( 'Y' ) );
		$added    = 0;
		while ( $added < $days ) {
			$date->modify( '+1 day' );
			$dow = (int) $date->format( 'N' );
			if ( $dow <= 5 && ! in_array( $date->format( 'Y-m-d' ), $holidays, true ) ) {
				++$added;
			}
		}
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Calculate_filing_deadline.
	 *
	 * @param string $event_date Parameter.
	 * @param int    $days Parameter.
	 * @param string $rule_type Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_filing_deadline( string $event_date, int $days, string $rule_type = 'frcp' ): string {
		if ( 'calendar' === $rule_type ) {
			$date = new DateTime( $event_date );
			$date->modify( "+{$days} days" );
			return $date->format( 'Y-m-d' );
		}
		// FRCP Rule 6: exclude event day, count business days, extend if last day is weekend/holiday.
		return self::add_business_days( $event_date, $days );
	}

	/**
	 * Get_federal_holidays.
	 *
	 * @param int $year Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function get_federal_holidays( int $year ): array {
		$holidays   = array();
		$holidays[] = "{$year}-01-01"; // New Year's Day
		// MLK Day: 3rd Monday of January.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "third monday of january {$year}" ) );
		// Presidents' Day: 3rd Monday of February.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "third monday of february {$year}" ) );
		// Memorial Day: Last Monday of May.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "last monday of may {$year}" ) );
		// Juneteenth.
		$holidays[] = "{$year}-06-19";
		// Independence Day.
		$holidays[] = "{$year}-07-04";
		// Labor Day: 1st Monday of September.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "first monday of september {$year}" ) );
		// Columbus Day: 2nd Monday of October.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "second monday of october {$year}" ) );
		// Veterans Day.
		$holidays[] = "{$year}-11-11";
		// Thanksgiving: 4th Thursday of November.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$holidays[] = date( 'Y-m-d', strtotime( "fourth thursday of november {$year}" ) );
		// Christmas.
		$holidays[] = "{$year}-12-25";
		return $holidays;
	}

	/**
	 * Is_business_day.
	 *
	 * @param string $date Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function is_business_day( string $date ): bool {
		$dt  = new DateTime( $date );
		$dow = (int) $dt->format( 'N' );
		if ( $dow > 5 ) {
			return false;
		}
		$holidays = self::get_federal_holidays( (int) $dt->format( 'Y' ) );
		return ! in_array( $date, $holidays, true );
	}

	/**
	 * Calculate_statute_of_limitations.
	 *
	 * @param string $incident_date Parameter.
	 * @param int    $years Parameter.
	 * @param int    $tolling_days Parameter.
	 * @param string $jurisdiction Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_statute_of_limitations( string $incident_date, int $years, int $tolling_days = 0, string $jurisdiction = 'federal' ): array {
		$incident   = new DateTime( $incident_date );
		$expiration = clone $incident;
		$expiration->modify( "+{$years} years" );
		if ( $tolling_days > 0 ) {
			$expiration->modify( "+{$tolling_days} days" );
		}
		$now            = new DateTime();
		$interval       = $now->diff( $expiration );
		$days_remaining = $interval->invert ? -$interval->days : $interval->days;
		$warning        = 'green';
		if ( $days_remaining <= 0 ) {
			$warning = 'expired';
		} elseif ( $days_remaining <= 30 ) {
			$warning = 'red';
		} elseif ( $days_remaining <= 90 ) {
			$warning = 'yellow';
		}
		return array(
			'expiration_date' => $expiration->format( 'Y-m-d' ),
			'days_remaining'  => $days_remaining,
			'is_expired'      => $days_remaining <= 0,
			'warning_level'   => $warning,
		);
	}

	// Fee calculations.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Calculate_hourly_fee.
	 *
	 * @param float $hours Parameter.
	 * @param float $rate Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_hourly_fee( float $hours, float $rate ): float {
		return round( $hours * $rate, 2 );
	}

	/**
	 * Calculate_contingency_fee.
	 *
	 * @param float  $recovery Parameter.
	 * @param float  $pct Parameter.
	 * @param string $stage Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_contingency_fee( float $recovery, float $pct, string $stage = 'pre_filing' ): array {
		$defaults = array(
			'pre_filing'  => 0.3333,
			'post_filing' => 0.40,
			'post_trial'  => 0.45,
		);
		$rate     = $pct > 0 ? $pct : ( $defaults[ $stage ] ?? 0.3333 );
		$fee      = round( $recovery * $rate, 2 );
		return array(
			'fee_amount'   => $fee,
			'client_share' => round( $recovery - $fee, 2 ),
			'rate'         => $rate,
			'stage'        => $stage,
		);
	}

	/**
	 * Calculate_blended_rate.
	 *
	 * @param array $attorneys Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_blended_rate( array $attorneys ): float {
		$total_hours = 0.0;
		$total_fees  = 0.0;
		foreach ( $attorneys as $a ) {
			$h            = (float) ( $a['hours'] ?? 0 );
			$r            = (float) ( $a['rate'] ?? 0 );
			$total_hours += $h;
			$total_fees  += $h * $r;
		}
		return $total_hours > 0 ? round( $total_fees / $total_hours, 2 ) : 0.0;
	}

	/**
	 * Calculate_lodestar.
	 *
	 * @param float $hours Parameter.
	 * @param float $rate Parameter.
	 * @param float $multiplier Parameter.
	 * @return array|WP_Error Result.
	 *
	 * @param array $rate Parameter.
	 */
	public static function calculate_lodestar( float $hours, float $rate, float $multiplier = 1.0 ): float {
		return round( $hours * $rate * $multiplier, 2 );
	}

	// Financial math.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Calculate_present_value.
	 *
	 * @param float $fv Parameter.
	 * @param float $rate Parameter.
	 * @param int   $years Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_present_value( float $fv, float $rate, int $years ): float {
		if ( $rate <= 0 || $years <= 0 ) {
			return round( $fv, 2 );
		}
		return round( $fv / pow( 1 + $rate, $years ), 2 );
	}

	/**
	 * Calculate_prejudgment_interest.
	 *
	 * @param float  $principal Parameter.
	 * @param float  $annual_rate Parameter.
	 * @param string $start Parameter.
	 * @param string $end Parameter.
	 * @param string $method Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_prejudgment_interest( float $principal, float $annual_rate, string $start, string $end, string $method = 'simple' ): array {
		$s          = new DateTime( $start );
		$e          = new DateTime( $end );
		$days       = max( 0, (int) $s->diff( $e )->days );
		$daily_rate = $annual_rate / 365;
		if ( 'compound' === $method ) {
			$interest = $principal * ( pow( 1 + $daily_rate, $days ) - 1 );
		} else {
			$interest = $principal * $daily_rate * $days;
		}
		return array(
			'interest_amount' => round( $interest, 2 ),
			'total'           => round( $principal + $interest, 2 ),
			'days'            => $days,
			'daily_rate'      => round( $daily_rate, 6 ),
		);
	}

	/**
	 * Calculate_structured_settlement_npv.
	 *
	 * @param array $payments Parameter.
	 * @param float $discount_rate Parameter.
	 * @return array|WP_Error Result.
	 *
	 * @param array $discount_rate Parameter.
	 */
	public static function calculate_structured_settlement_npv( array $payments, float $discount_rate ): float {
		$npv = 0.0;
		foreach ( $payments as $p ) {
			$amount = (float) ( $p['amount'] ?? 0 );
			$years  = (float) ( $p['years_from_now'] ?? 0 );
			$npv   += $amount / pow( 1 + $discount_rate, $years );
		}
		return round( $npv, 2 );
	}

	/**
	 * Calculate_damages.
	 *
	 * @param float $annual_wages Parameter.
	 * @param int   $years_remaining Parameter.
	 * @param float $discount_rate Parameter.
	 * @param float $growth_rate Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_damages( float $annual_wages, int $years_remaining, float $discount_rate, float $growth_rate = 0.03 ): array {
		$total    = 0.0;
		$schedule = array();
		for ( $y = 1; $y <= $years_remaining; $y++ ) {
			$wage       = $annual_wages * pow( 1 + $growth_rate, $y - 1 );
			$pv         = $wage / pow( 1 + $discount_rate, $y );
			$total     += $pv;
			$schedule[] = array(
				'year'          => $y,
				'wage'          => round( $wage, 2 ),
				'present_value' => round( $pv, 2 ),
			);
		}
		return array(
			'total_present_value' => round( $total, 2 ),
			'undiscounted_total'  => round( $annual_wages * $years_remaining, 2 ),
			'schedule'            => $schedule,
		);
	}

	// Trust accounting.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Calculate_trust_balance.
	 *
	 * @param array $transactions Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_trust_balance( array $transactions ): array {
		$balance       = 0.0;
		$deposits      = 0.0;
		$disbursements = 0.0;
		foreach ( $transactions as $t ) {
			$amt = (float) ( $t['amount'] ?? 0 );
			if ( 'deposit' === ( $t['type'] ?? '' ) ) {
				$balance  += $amt;
				$deposits += $amt;
			} else {
				$balance       -= $amt;
				$disbursements += $amt;
			}
		}
		return array(
			'balance'             => round( $balance, 2 ),
			'total_deposits'      => round( $deposits, 2 ),
			'total_disbursements' => round( $disbursements, 2 ),
			'transaction_count'   => count( $transactions ),
		);
	}

	/**
	 * Three_way_reconciliation.
	 *
	 * @param float $bank Parameter.
	 * @param float $book Parameter.
	 * @param float $client_total Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function three_way_reconciliation( float $bank, float $book, float $client_total ): array {
		$reconciled = abs( $bank - $book ) < 0.01 && abs( $book - $client_total ) < 0.01;
		return array(
			'is_reconciled' => $reconciled,
			'bank_balance'  => round( $bank, 2 ),
			'book_balance'  => round( $book, 2 ),
			'client_total'  => round( $client_total, 2 ),
			'discrepancy'   => round( $bank - $client_total, 2 ),
		);
	}

	/**
	 * Calculate_iolta_interest.
	 *
	 * @param float $balance Parameter.
	 * @param float $annual_rate Parameter.
	 * @param int   $days Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function calculate_iolta_interest( float $balance, float $annual_rate, int $days ): float {
		return round( $balance * ( $annual_rate / 365 ) * $days, 2 );
	}

	// Billing math.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Validate_utbms_code.
	 *
	 * @param string $code Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function validate_utbms_code( string $code ): array {
		$prefix     = substr( $code, 0, 1 );
		$categories = array(
			'L' => 'Litigation',
			'A' => 'Counseling/Advisory',
			'P' => 'Project',
			'E' => 'Bankruptcy',
			'C' => 'Case Assessment',
		);
		$is_valid   = isset( $categories[ $prefix ] ) && preg_match( '/^[LAPEC]\d{3}$/', $code );
		return array(
			'is_valid' => $is_valid,
			'category' => $categories[ $prefix ] ?? 'Unknown',
			'code'     => $code,
		);
	}

	/**
	 * Detect_block_billing.
	 *
	 * @param string $description Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function detect_block_billing( string $description ): array {
		$semicolons = substr_count( $description, ';' );
		$periods    = substr_count( $description, '.' );
		$entries    = max( $semicolons, $periods - 1 );
		$is_block   = $entries >= 3;
		return array(
			'is_block_billed' => $is_block,
			'entry_count'     => $entries + 1,
			'suggestion'      => $is_block ? 'Split into separate time entries for each distinct task' : 'Entry appears properly formatted',
		);
	}

	/**
	 * Format_ledes_line.
	 *
	 * @param array $entry Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function format_ledes_line( array $entry ): string {
		return implode(
			'|',
			array(
				$entry['invoice_date'] ?? '',
				$entry['invoice_number'] ?? '',
				$entry['client_id'] ?? '',
				$entry['matter_id'] ?? '',
				$entry['timekeeper_id'] ?? '',
				$entry['task_code'] ?? '',
				$entry['activity_code'] ?? '',
				$entry['expense_code'] ?? '',
				$entry['hours'] ?? '0',
				$entry['rate'] ?? '0',
				$entry['amount'] ?? '0',
				$entry['description'] ?? '',
			)
		) . '[]';
	}

	/**
	 * Format_time_increment.
	 *
	 * @param float $hours Parameter.
	 * @param float $increment Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function format_time_increment( float $hours, float $increment = 0.1 ): float {
		return ceil( $hours / $increment ) * $increment;
	}

	// Formatting helpers.
	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Format_currency.
	 *
	 * @param float $amount Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function format_currency( float $amount ): string {
		return '$' . number_format( $amount, 2 );
	}

	/**
	 * Format_percentage.
	 *
	 * @param float $decimal Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function format_percentage( float $decimal ): string {
		return number_format( $decimal * 100, 2 ) . '%';
	}

	/**
	 * Format_hours.
	 *
	 * @param float $hours Parameter.
	 * @return array|WP_Error Result.
	 */
	public static function format_hours( float $hours ): string {
		return number_format( $hours, 1 ) . ' hrs';
	}
}
