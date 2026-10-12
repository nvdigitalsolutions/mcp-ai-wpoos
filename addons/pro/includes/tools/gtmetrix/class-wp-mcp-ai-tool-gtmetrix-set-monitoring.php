<?php
/**
 * GTmetrix tool: set page monitoring.
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

/**
 * Enable, disable or change GTmetrix monitoring for a page.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Set_Monitoring extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_set_monitoring';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Set Monitoring', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enable, disable or change GTmetrix monitoring frequency for a page (no, hourly, daily, weekly, monthly). Hourly monitoring and some locations depend on the GTmetrix plan.', 'mcp-ai-wpoos-pro' );
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
				'page_id'   => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix page ID.', 'mcp-ai-wpoos-pro' ),
				),
				'frequency' => array(
					'type'        => 'string',
					'enum'        => array( 'no', 'none', 'hourly', 'daily', 'weekly', 'monthly' ),
					'description' => __( 'Monitoring frequency; "no" or "none" disables monitoring.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'page_id', 'frequency' ),
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
		if ( '' === $page_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_page', __( 'A page ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$frequency = isset( $arguments['frequency'] ) ? sanitize_key( $arguments['frequency'] ) : '';
		$allowed   = array( 'no', 'none', 'hourly', 'daily', 'weekly', 'monthly' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_frequency', __( 'Invalid monitoring frequency.', 'mcp-ai-wpoos-pro' ) );
		}

		$page = $this->client()->set_monitoring( $page_id, $frequency );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$attrs = isset( $page['attributes'] ) ? $page['attributes'] : array();

		return array(
			'success'   => true,
			'page_id'   => isset( $page['id'] ) ? $page['id'] : $page_id,
			'frequency' => $frequency,
			'monitored' => isset( $attrs['monitored'] ) ? $attrs['monitored'] : $frequency,
		);
	}
}
