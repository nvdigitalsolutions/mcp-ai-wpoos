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

class WP_MCP_AI_Law_Firm_Calculator {

	// Deadline calculations
	public static function add_business_days( string $start_date, int $days, string $jurisdiction = 'federal' ): string {
		$date = new DateTime( $start_date );
		$holidays = self::get_federal_holidays( (int) $date->format( 'Y' ) );
		$added = 0;
		while ( $added < $days ) {
			$date->modify( '+1 day' );
			$dow = (int) $date->format( 'N' );
			if ( $dow <= 5 && ! in_array( $date->format( 'Y-m-d' ), $holidays, true ) ) {
				$added++;
			}
		}
		return $date->format( 'Y-m-d' );
	}

	public static function calculate_filing_deadline( string $event_date, int $days, string $rule_type = 'frcp' ): string {
		if ( 'calendar' === $rule_type ) {
			$date = new DateTime( $event_date );
			$date->modify( "+{$days} days" );
			return $date->format( 'Y-m-d' );
		}
		// FRCP Rule 6: exclude event day, count business days, extend if last day is weekend/holiday
		return self::add_business_days( $event_date, $days );
	}

	public static function get_federal_holidays( int $year ): array {
		$holidays = array();
		$holidays[] = "{$year}-01-01"; // New Year's Day
		// MLK Day: 3rd Monday of January
		$holidays[] = date( 'Y-m-d', strtotime( "third monday of january {$year}" ) );
		// Presidents' Day: 3rd Monday of February
		$holidays[] = date( 'Y-m-d', strtotime( "third monday of february {$year}" ) );
		// Memorial Day: Last Monday of May
		$holidays[] = date( 'Y-m-d', strtotime( "last monday of may {$year}" ) );
		// Juneteenth
		$holidays[] = "{$year}-06-19";
		// Independence Day
		$holidays[] = "{$year}-07-04";
		// Labor Day: 1st Monday of September
		$holidays[] = date( 'Y-m-d', strtotime( "first monday of september {$year}" ) );
		// Columbus Day: 2nd Monday of October
		$holidays[] = date( 'Y-m-d', strtotime( "second monday of october {$year}" ) );
		// Veterans Day
		$holidays[] = "{$year}-11-11";
		// Thanksgiving: 4th Thursday of November
		$holidays[] = date( 'Y-m-d', strtotime( "fourth thursday of november {$year}" ) );
		// Christmas
		$holidays[] = "{$year}-12-25";
		return $holidays;
	}

	public static function is_business_day( string $date ): bool {
		$dt = new DateTime( $date );
		$dow = (int) $dt->format( 'N' );
		if ( $dow > 5 ) {
			return false;
		}
		$holidays = self::get_federal_holidays( (int) $dt->format( 'Y' ) );
		return ! in_array( $date, $holidays, true );
	}

	public static function calculate_statute_of_limitations( string $incident_date, int $years, int $tolling_days = 0, string $jurisdiction = 'federal' ): array {
		$incident = new DateTime( $incident_date );
		$expiration = clone $incident;
		$expiration->modify( "+{$years} years" );
		if ( $tolling_days > 0 ) {
			$expiration->modify( "+{$tolling_days} days" );
		}
		$now = new DateTime();
		$interval = $now->diff( $expiration );
		$days_remaining = $interval->invert ? -$interval->days : $interval->days;
		$warning = 'green';
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

	// Fee calculations
	public static function calculate_hourly_fee( float $hours, float $rate ): float {
		return round( $hours * $rate, 2 );
	}

	public static function calculate_contingency_fee( float $recovery, float $pct, string $stage = 'pre_filing' ): array {
		$defaults = array( 'pre_filing' => 0.3333, 'post_filing' => 0.40, 'post_trial' => 0.45 );
		$rate = $pct > 0 ? $pct : ( $defaults[ $stage ] ?? 0.3333 );
		$fee = round( $recovery * $rate, 2 );
		return array( 'fee_amount' => $fee, 'client_share' => round( $recovery - $fee, 2 ), 'rate' => $rate, 'stage' => $stage );
	}

	public static function calculate_blended_rate( array $attorneys ): float {
		$total_hours = 0.0;
		$total_fees = 0.0;
		foreach ( $attorneys as $a ) {
			$h = (float) ( $a['hours'] ?? 0 );
			$r = (float) ( $a['rate'] ?? 0 );
			$total_hours += $h;
			$total_fees += $h * $r;
		}
		return $total_hours > 0 ? round( $total_fees / $total_hours, 2 ) : 0.0;
	}

	public static function calculate_lodestar( float $hours, float $rate, float $multiplier = 1.0 ): float {
		return round( $hours * $rate * $multiplier, 2 );
	}

	// Financial math
	public static function calculate_present_value( float $fv, float $rate, int $years ): float {
		if ( $rate <= 0 || $years <= 0 ) {
			return round( $fv, 2 );
		}
		return round( $fv / pow( 1 + $rate, $years ), 2 );
	}

	public static function calculate_prejudgment_interest( float $principal, float $annual_rate, string $start, string $end, string $method = 'simple' ): array {
		$s = new DateTime( $start );
		$e = new DateTime( $end );
		$days = max( 0, (int) $s->diff( $e )->days );
		$daily_rate = $annual_rate / 365;
		if ( 'compound' === $method ) {
			$interest = $principal * ( pow( 1 + $daily_rate, $days ) - 1 );
		} else {
			$interest = $principal * $daily_rate * $days;
		}
		return array( 'interest_amount' => round( $interest, 2 ), 'total' => round( $principal + $interest, 2 ), 'days' => $days, 'daily_rate' => round( $daily_rate, 6 ) );
	}

	public static function calculate_structured_settlement_npv( array $payments, float $discount_rate ): float {
		$npv = 0.0;
		foreach ( $payments as $p ) {
			$amount = (float) ( $p['amount'] ?? 0 );
			$years = (float) ( $p['years_from_now'] ?? 0 );
			$npv += $amount / pow( 1 + $discount_rate, $years );
		}
		return round( $npv, 2 );
	}

	public static function calculate_damages( float $annual_wages, int $years_remaining, float $discount_rate, float $growth_rate = 0.03 ): array {
		$total = 0.0;
		$schedule = array();
		for ( $y = 1; $y <= $years_remaining; $y++ ) {
			$wage = $annual_wages * pow( 1 + $growth_rate, $y - 1 );
			$pv = $wage / pow( 1 + $discount_rate, $y );
			$total += $pv;
			$schedule[] = array( 'year' => $y, 'wage' => round( $wage, 2 ), 'present_value' => round( $pv, 2 ) );
		}
		return array( 'total_present_value' => round( $total, 2 ), 'undiscounted_total' => round( $annual_wages * $years_remaining, 2 ), 'schedule' => $schedule );
	}

	// Trust accounting
	public static function calculate_trust_balance( array $transactions ): array {
		$balance = 0.0;
		$deposits = 0.0;
		$disbursements = 0.0;
		foreach ( $transactions as $t ) {
			$amt = (float) ( $t['amount'] ?? 0 );
			if ( 'deposit' === ( $t['type'] ?? '' ) ) {
				$balance += $amt;
				$deposits += $amt;
			} else {
				$balance -= $amt;
				$disbursements += $amt;
			}
		}
		return array( 'balance' => round( $balance, 2 ), 'total_deposits' => round( $deposits, 2 ), 'total_disbursements' => round( $disbursements, 2 ), 'transaction_count' => count( $transactions ) );
	}

	public static function three_way_reconciliation( float $bank, float $book, float $client_total ): array {
		$reconciled = abs( $bank - $book ) < 0.01 && abs( $book - $client_total ) < 0.01;
		return array(
			'is_reconciled'   => $reconciled,
			'bank_balance'    => round( $bank, 2 ),
			'book_balance'    => round( $book, 2 ),
			'client_total'    => round( $client_total, 2 ),
			'discrepancy'     => round( $bank - $client_total, 2 ),
		);
	}

	public static function calculate_iolta_interest( float $balance, float $annual_rate, int $days ): float {
		return round( $balance * ( $annual_rate / 365 ) * $days, 2 );
	}

	// Billing math
	public static function validate_utbms_code( string $code ): array {
		$prefix = substr( $code, 0, 1 );
		$categories = array( 'L' => 'Litigation', 'A' => 'Counseling/Advisory', 'P' => 'Project', 'E' => 'Bankruptcy', 'C' => 'Case Assessment' );
		$is_valid = isset( $categories[ $prefix ] ) && preg_match( '/^[LAPEC]\d{3}$/', $code );
		return array( 'is_valid' => $is_valid, 'category' => $categories[ $prefix ] ?? 'Unknown', 'code' => $code );
	}

	public static function detect_block_billing( string $description ): array {
		$semicolons = substr_count( $description, ';' );
		$periods = substr_count( $description, '.' );
		$entries = max( $semicolons, $periods - 1 );
		$is_block = $entries >= 3;
		return array( 'is_block_billed' => $is_block, 'entry_count' => $entries + 1, 'suggestion' => $is_block ? 'Split into separate time entries for each distinct task' : 'Entry appears properly formatted' );
	}

	public static function format_ledes_line( array $entry ): string {
		return implode( '|', array(
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
		) ) . '[]';
	}

	public static function format_time_increment( float $hours, float $increment = 0.1 ): float {
		return ceil( $hours / $increment ) * $increment;
	}

	// Formatting helpers
	public static function format_currency( float $amount ): string {
		return '$' . number_format( $amount, 2 );
	}

	public static function format_percentage( float $decimal ): string {
		return number_format( $decimal * 100, 2 ) . '%';
	}

	public static function format_hours( float $hours ): string {
		return number_format( $hours, 1 ) . ' hrs';
	}
}
