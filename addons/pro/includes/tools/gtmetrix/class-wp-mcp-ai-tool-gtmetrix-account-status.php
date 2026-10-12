<?php
/**
 * GTmetrix tool: account status.
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
 * Read GTmetrix account status: credits, refill, plan and feature flags.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Account_Status extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_account_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Account Status', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the GTmetrix account status: API credit balance, next refill, plan type and PRO feature flags. Use before starting tests to confirm credit availability.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
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

		$status = $this->client()->get_status();
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$attrs = isset( $status['attributes'] ) ? $status['attributes'] : array();

		return array(
			'success'              => true,
			'api_credits'          => isset( $attrs['api_credits'] ) ? $attrs['api_credits'] : null,
			'api_refill'           => isset( $attrs['api_refill'] ) ? $attrs['api_refill'] : null,
			'api_refill_amount'    => isset( $attrs['api_refill_amount'] ) ? $attrs['api_refill_amount'] : null,
			'account_type'         => isset( $attrs['account_type'] ) ? $attrs['account_type'] : '',
			'pro_analysis_options' => ! empty( $attrs['account_pro_analysis_options_access'] ),
			'pro_locations'        => ! empty( $attrs['account_pro_locations_access'] ),
			'whitelabel_pdf'       => ! empty( $attrs['account_whitelabel_pdf_access'] ),
		);
	}
}
