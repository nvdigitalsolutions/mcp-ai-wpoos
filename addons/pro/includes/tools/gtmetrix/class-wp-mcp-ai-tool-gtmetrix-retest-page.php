<?php
/**
 * GTmetrix tool: retest page.
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
 * Retest a GTmetrix page with its saved analysis options (credit-consuming).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Retest_Page extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_retest_page';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Retest Page', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retest a GTmetrix page reusing its saved analysis options. Consumes API credits and respects the site daily credit budget.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Regression checks after a deploy or optimization change on a known page.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Testing a new URL with different options — use gtmetrix_start_test.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_compare_reports', 'gtmetrix_start_test' ),
			'notes'           => __( 'Consumes GTmetrix API credits.', 'mcp-ai-wpoos-pro' ),
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
			'type'       => 'object',
			'properties' => array(
				'page_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix page ID to retest.', 'mcp-ai-wpoos-pro' ),
				),
				'url'     => array(
					'type'        => 'string',
					'description' => __( 'Alternative: retest the page mapped to this URL.', 'mcp-ai-wpoos-pro' ),
				),
			),
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

		$page_id = $this->arg_page_id( $arguments );
		$url     = $this->arg_url( $arguments );

		if ( '' === $page_id && '' !== $url && class_exists( 'WP_MCP_AI_GTmetrix_Snapshot_Store' ) ) {
			$page_id = WP_MCP_AI_GTmetrix_Snapshot_Store::page_id_for_url( $url );
		}

		if ( '' === $page_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_page', __( 'A page ID (or a URL with a known page mapping) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' !== $url && ! WP_MCP_AI_GTmetrix_Settings::is_url_allowed( $url ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_host_denied', __( 'That host is not in the GTmetrix test-target allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$cost   = WP_MCP_AI_GTmetrix_Scheduler::cost_of( array( 'report' => 'lighthouse' ) );
		$budget = WP_MCP_AI_GTmetrix_Scheduler::preflight_budget( $cost );
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		$result = $this->client()->retest_page( $page_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_AI_GTmetrix_Scheduler::record_charge( $cost );

		$attrs   = isset( $result['attributes'] ) ? $result['attributes'] : $result;
		$test_id = isset( $result['id'] ) ? $result['id'] : ( isset( $attrs['id'] ) ? $attrs['id'] : '' );
		WP_MCP_AI_GTmetrix_Poller::schedule_poll( $test_id );

		return array(
			'success'      => true,
			'page_id'      => $page_id,
			'test_id'      => $test_id,
			'state'        => isset( $attrs['state'] ) ? $attrs['state'] : 'queued',
			'credits_used' => $cost,
			'next'         => __( 'Poll gtmetrix_get_test_status, then compare with gtmetrix_compare_reports.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
