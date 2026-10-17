<?php
/**
 * GTmetrix Toolkit — Scheduler.
 *
 * Core-page registry sweeps: enqueue metrics-only (or full Lighthouse) tests
 * for the registered core pages, respecting the daily credit budget. Backs
 * the weekly sweep and monthly review schedule presets.
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
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-poller.php';

/**
 * GTmetrix scheduled sweep helpers.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Scheduler {

	/**
	 * Report-type cost in credits (proposal §3.4).
	 *
	 * @var array<string, float>
	 */
	const COSTS = array(
		'lighthouse'        => 1.0,
		'legacy'            => 0.7,
		'lighthouse,legacy' => 1.1,
		'none'              => 0.6,
	);

	/**
	 * Estimate the credit cost of a test configuration.
	 *
	 * @param array $params Test parameters.
	 * @return float
	 */
	public static function cost_of( $params ) {
		$report    = isset( $params['report'] ) ? $params['report'] : 'lighthouse';
		$cost      = isset( self::COSTS[ $report ] ) ? self::COSTS[ $report ] : 1.0;
		$retention = isset( $params['retention'] ) ? absint( $params['retention'] ) : 1;
		if ( 6 === $retention ) {
			$cost += 0.4;
		} elseif ( 12 === $retention ) {
			$cost += 0.9;
		} elseif ( 24 === $retention ) {
			$cost += 1.8;
		}
		if ( ! empty( $params['video'] ) ) {
			$cost += 0.9;
		}

		return $cost;
	}

	/**
	 * Preflight the credit budget against the account status.
	 *
	 * @param float $cost Planned cost.
	 * @return true|WP_Error
	 */
	public static function preflight_budget( $cost ) {
		$client = new WP_MCP_AI_GTmetrix_Client();
		$status = $client->get_status();
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$attrs   = isset( $status['attributes'] ) ? $status['attributes'] : array();
		$credits = isset( $attrs['api_credits'] ) ? (float) $attrs['api_credits'] : 0.0;
		if ( $credits < $cost ) {
			return new WP_Error(
				'wp_mcp_ai_gtmetrix_insufficient_credits',
				sprintf(
					/* translators: 1: required credits, 2: available credits */
					__( 'This test needs %1$s credits but the GTmetrix account only has %2$s.', 'mcp-ai-wpoos-pro' ),
					number_format_i18n( $cost, 1 ),
					number_format_i18n( $credits, 1 )
				)
			);
		}

		// Site-level daily budget.
		$used   = (float) get_transient( 'gtmetrix_credits_used_today' );
		$budget = WP_MCP_AI_GTmetrix_Settings::credit_budget_daily();
		if ( ( $used + $cost ) > $budget ) {
			return new WP_Error(
				'wp_mcp_ai_gtmetrix_budget_exhausted',
				sprintf(
					/* translators: 1: daily budget */
					__( 'The site daily credit budget (%1$s credits) would be exceeded. Increase it in the GTmetrix toolkit settings.', 'mcp-ai-wpoos-pro' ),
					number_format_i18n( $budget, 1 )
				)
			);
		}

		return true;
	}

	/**
	 * Record a credit charge against the daily budget transient.
	 *
	 * @param float $cost Credits charged.
	 * @return void
	 */
	public static function record_charge( $cost ) {
		$key  = 'gtmetrix_credits_used_today';
		$used = (float) get_transient( $key );
		set_transient( $key, $used + (float) $cost, DAY_IN_SECONDS );
	}

	/**
	 * Enqueue a sweep over the core pages.
	 *
	 * @param string $report_type lighthouse (default) or none (metrics-only).
	 * @return array{started: array, skipped: array, errors: array}
	 */
	public static function enqueue_sweep( $report_type = 'none' ) {
		$client  = new WP_MCP_AI_GTmetrix_Client();
		$started = array();
		$skipped = array();
		$errors  = array();

		foreach ( WP_MCP_AI_GTmetrix_Settings::core_pages() as $page ) {
			$url = isset( $page['url'] ) ? $page['url'] : '';
			if ( '' === $url || ! WP_MCP_AI_GTmetrix_Settings::is_url_allowed( $url ) ) {
				$skipped[] = array(
					'url'    => $url,
					'reason' => 'host-not-allowed',
				);
				continue;
			}

			$params   = array(
				'url'    => $url,
				'report' => $report_type,
			);
			$location = WP_MCP_AI_GTmetrix_Settings::get( 'default_location', '' );
			if ( '' !== $location ) {
				$params['location'] = $location;
			}
			$browser = WP_MCP_AI_GTmetrix_Settings::get( 'default_browser', '' );
			if ( '' !== $browser ) {
				$params['browser'] = $browser;
			}

			$budget = self::preflight_budget( self::cost_of( $params ) );
			if ( is_wp_error( $budget ) ) {
				$errors[] = array(
					'url'   => $url,
					'error' => $budget->get_error_message(),
				);
				continue;
			}

			$result = $client->start_test( $params );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'url'   => $url,
					'error' => $result->get_error_message(),
				);
				continue;
			}

			self::record_charge( self::cost_of( $params ) );
			$attrs   = isset( $result['attributes'] ) ? $result['attributes'] : $result;
			$test_id = isset( $result['id'] ) ? $result['id'] : ( isset( $attrs['id'] ) ? $attrs['id'] : '' );
			WP_MCP_AI_GTmetrix_Poller::schedule_poll( $test_id );
			$started[] = array(
				'url'     => $url,
				'test_id' => $test_id,
			);
		}

		return array(
			'started' => $started,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Full-Lighthouse monthly review sweep.
	 *
	 * @return array{started: array, skipped: array, errors: array}
	 */
	public static function monthly_review() {
		return self::enqueue_sweep( 'lighthouse' );
	}
}
