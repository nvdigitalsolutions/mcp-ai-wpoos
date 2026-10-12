<?php
/**
 * GTmetrix Toolkit — Test Poller.
 *
 * Action Scheduler-backed lifecycle for GTmetrix tests: schedule a poll,
 * advance queued → started → completed (snapshot + report-ready action) or
 * error (error action), with a single-flight transient lock per test and a
 * pure advance() state machine for tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-client.php';

/**
 * GTmetrix test poller.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Poller {

	const AS_HOOK = 'wp_mcp_ai_gtmetrix_poll';

	/**
	 * Schedule the first poll for a test.
	 *
	 * @param string $test_id GTmetrix test ID.
	 * @return int|bool Action ID or false.
	 */
	public static function schedule_poll( $test_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$interval = absint( WP_MCP_AI_GTmetrix_Settings::get( 'poll_interval_s', 3 ) );

		return as_schedule_single_action( time() + $interval, self::AS_HOOK, array( 'test_id' => $test_id ) );
	}

	/**
	 * Poll handler invoked by Action Scheduler.
	 *
	 * @param string $test_id GTmetrix test ID.
	 * @return void
	 */
	public static function poll( $test_id ) {
		$test_id = sanitize_text_field( $test_id );
		if ( '' === $test_id ) {
			return;
		}

		$lock_key = 'gtmetrix_poll_' . md5( $test_id );
		if ( false !== get_transient( $lock_key ) ) {
			return; // Another poll in flight.
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$client = new WP_MCP_AI_GTmetrix_Client();
		$test   = $client->get_test( $test_id );

		// Release the lock before branching.
		delete_transient( $lock_key );

		if ( is_wp_error( $test ) ) {
			do_action( 'wp_mcp_ai_gtmetrix_test_error', $test_id, $test->get_error_message() );

			return;
		}

		$attrs   = isset( $test['attributes'] ) ? $test['attributes'] : array();
		$state   = isset( $attrs['state'] ) ? $attrs['state'] : '';
		$attempt = self::attempt_counter( $test_id );

		$next = self::advance( $state, $attempt );
		switch ( $next ) {
			case 'reschedule':
				++$attempt;
				self::attempt_counter( $test_id, $attempt );
				self::schedule_poll( $test_id );
				break;

			case 'complete':
				$report_id = isset( $attrs['report'] ) ? $attrs['report'] : '';
				if ( '' !== $report_id ) {
					$report = $client->get_report( $report_id );
					if ( ! is_wp_error( $report ) ) {
						self::snapshot( $report );
						do_action( 'wp_mcp_ai_gtmetrix_report_ready', $report_id, $report );
					}
				}
				break;

			case 'error':
				do_action(
					'wp_mcp_ai_gtmetrix_test_error',
					$test_id,
					isset( $attrs['error'] ) ? $attrs['error'] : __( 'Unknown test error.', 'mcp-ai-wpoos-pro' )
				);
				break;

			default:
				break;
		}
	}

	/**
	 * Pure state machine: given the API state and attempt counter, decide the
	 * next step. Testable without the queue or network.
	 *
	 * @param string $state   GTmetrix test state.
	 * @param int    $attempt Poll attempt number (1-based).
	 * @return string reschedule|complete|error|stop
	 */
	public static function advance( $state, $attempt ) {
		$max = absint( WP_MCP_AI_GTmetrix_Settings::get( 'poll_max_attempts', 20 ) );

		if ( 'completed' === $state ) {
			return 'complete';
		}
		if ( 'error' === $state ) {
			return 'error';
		}
		if ( 'queued' === $state || 'started' === $state ) {
			return $attempt < $max ? 'reschedule' : 'stop';
		}

		return 'stop';
	}

	/**
	 * Persist a completed report into the snapshot store.
	 *
	 * @param array $report Report data (API resource or attributes).
	 * @return int|WP_Error
	 */
	public static function snapshot( $report ) {
		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Snapshot_Store' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-snapshot-store.php';
		}

		$store     = new WP_MCP_AI_GTmetrix_Snapshot_Store();
		$attrs     = isset( $report['attributes'] ) && is_array( $report['attributes'] ) ? $report['attributes'] : $report;
		$report_id = isset( $report['id'] ) ? $report['id'] : ( isset( $attrs['id'] ) ? $attrs['id'] : '' );
		if ( '' === $report_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'Report has no ID.', 'mcp-ai-wpoos-pro' ) );
		}
		$attrs['id'] = $report_id;

		return $store->save_report( $attrs );
	}

	/**
	 * Read/increment the per-test poll attempt counter.
	 *
	 * @param string   $test_id Test ID.
	 * @param int|null $set     New value (null = read only).
	 * @return int
	 */
	private static function attempt_counter( $test_id, $set = null ) {
		$key     = 'gtmetrix_attempt_' . md5( $test_id );
		$current = absint( get_transient( $key ) );

		if ( null !== $set ) {
			set_transient( $key, absint( $set ), HOUR_IN_SECONDS );

			return absint( $set );
		}

		return $current;
	}

	/**
	 * Count tests currently queued or started (concurrency guard input).
	 *
	 * @return int|WP_Error
	 */
	public static function pending_tests() {
		$client = new WP_MCP_AI_GTmetrix_Client();
		$tests  = $client->list_tests(
			array(
				'filter[state]' => 'queued,started',
			)
		);
		if ( is_wp_error( $tests ) ) {
			return $tests;
		}

		if ( isset( $tests[0]['attributes'] ) ) {
			return count( $tests );
		}

		return 0;
	}

	/**
	 * Plan concurrency cap (2 Basic, 8 PRO) from the account status.
	 *
	 * @param string $account_type GTmetrix account type.
	 * @return int
	 */
	public static function concurrency_cap( $account_type ) {
		return 'PRO' === strtoupper( (string) $account_type ) ? 8 : 2;
	}
}
