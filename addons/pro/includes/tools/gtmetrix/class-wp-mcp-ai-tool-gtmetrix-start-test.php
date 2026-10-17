<?php
/**
 * GTmetrix tool: start a test.
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

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-scheduler.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-poller.php';

/**
 * Start a GTmetrix test (credit-consuming, budget-gated, host-allowlisted).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Start_Test extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_start_test';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Start Test', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Start a GTmetrix performance test for a URL. Consumes API credits (Lighthouse 1.0, metrics-only 0.6). The test is polled automatically; use gtmetrix_get_test_status to check progress.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Running an on-demand performance test for a site page.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading results — use gtmetrix_get_report / gtmetrix_analyze_report.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_get_test_status', 'gtmetrix_get_report', 'gtmetrix_analyze_report' ),
			'notes'           => __( 'Consumes GTmetrix API credits; the site daily credit budget applies. Only allowed hosts can be tested.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'external-api', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'             => array(
					'type'        => 'string',
					'description' => __( 'URL of the page to test (must be on an allowed host).', 'mcp-ai-wpoos-pro' ),
				),
				'location'        => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix location ID (default: account default).', 'mcp-ai-wpoos-pro' ),
				),
				'browser'         => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix browser ID (default: account default).', 'mcp-ai-wpoos-pro' ),
				),
				'report'          => array(
					'type'        => 'string',
					'enum'        => array( 'lighthouse', 'legacy', 'lighthouse,legacy', 'none' ),
					'description' => __( 'Report type: lighthouse (default), legacy (PageSpeed/YSlow), both, or none for metrics-only (cheapest).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'lighthouse',
				),
				'retention'       => array(
					'type'        => 'integer',
					'enum'        => array( 1, 6, 12, 24 ),
					'description' => __( 'Report retention in months (longer costs extra credits).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'simulate_device' => array(
					'type'        => 'string',
					'description' => __( 'Simulated device ID (PRO plans only).', 'mcp-ai-wpoos-pro' ),
				),
				'throttle'        => array(
					'type'        => 'string',
					'description' => __( 'Connection throttle as down/up/latency Kbps/ms, e.g. 9000/5000/125.', 'mcp-ai-wpoos-pro' ),
				),
				'adblock'         => array(
					'type'        => 'boolean',
					'description' => __( 'Run the test with an ad blocker enabled.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'video'           => array(
					'type'        => 'boolean',
					'description' => __( 'Record a load video (extra credits).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard_key();
		if ( $error ) {
			return $error;
		}

		$url = $this->arg_url( $arguments );
		if ( '' === $url ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_url', __( 'A valid http(s) URL is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! WP_MCP_AI_GTmetrix_Settings::is_url_allowed( $url ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_host_denied', __( 'That host is not in the GTmetrix test-target allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$params = array(
			'url'       => $url,
			'report'    => isset( $arguments['report'] ) ? sanitize_key( $arguments['report'] ) : 'lighthouse',
			'retention' => isset( $arguments['retention'] ) ? absint( $arguments['retention'] ) : 1,
		);
		if ( isset( $arguments['location'] ) && '' !== sanitize_key( $arguments['location'] ) ) {
			$params['location'] = sanitize_key( $arguments['location'] );
		}
		if ( isset( $arguments['browser'] ) && '' !== sanitize_key( $arguments['browser'] ) ) {
			$params['browser'] = sanitize_key( $arguments['browser'] );
		}
		if ( isset( $arguments['simulate_device'] ) && '' !== sanitize_key( $arguments['simulate_device'] ) ) {
			$params['simulate_device'] = sanitize_key( $arguments['simulate_device'] );
		}
		if ( isset( $arguments['throttle'] ) ) {
			$throttle = sanitize_text_field( $arguments['throttle'] );
			if ( preg_match( '/^\d+\/\d+\/\d+$/', $throttle ) ) {
				$params['throttle'] = $throttle;
			}
		}
		if ( ! empty( $arguments['adblock'] ) ) {
			$params['adblock'] = 1;
		}
		if ( ! empty( $arguments['video'] ) ) {
			$params['video'] = 1;
		}

		$cost   = WP_MCP_AI_GTmetrix_Scheduler::cost_of( $params );
		$budget = WP_MCP_AI_GTmetrix_Scheduler::preflight_budget( $cost );
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		$result = $this->client()->start_test( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_AI_GTmetrix_Scheduler::record_charge( $cost );

		$attrs   = isset( $result['attributes'] ) ? $result['attributes'] : $result;
		$test_id = isset( $result['id'] ) ? $result['id'] : ( isset( $attrs['id'] ) ? $attrs['id'] : '' );
		WP_MCP_AI_GTmetrix_Poller::schedule_poll( $test_id );

		$meta = isset( $result['_meta'] ) ? $result['_meta'] : array();

		return array(
			'success'      => true,
			'test_id'      => $test_id,
			'state'        => isset( $attrs['state'] ) ? $attrs['state'] : 'queued',
			'url'          => $url,
			'credits_used' => isset( $meta['credits_used'] ) ? $meta['credits_used'] : $cost,
			'credits_left' => isset( $meta['credits_left'] ) ? $meta['credits_left'] : null,
			'next'         => __( 'Poll gtmetrix_get_test_status in a few seconds; the toolkit also snapshots the report automatically.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
