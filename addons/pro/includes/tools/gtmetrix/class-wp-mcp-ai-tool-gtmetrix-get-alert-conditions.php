<?php
/**
 * GTmetrix tool: get alert conditions.
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
 * Read a page's performance-alert condition configuration.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Alert_Conditions extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_alert_conditions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Alert Conditions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the performance-alert conditions configured for a GTmetrix page: which metrics, thresholds and recipients trigger alerts.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'GTmetrix page ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'page_id' ),
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

		$conditions = $this->client()->get_alert_conditions( $page_id );
		if ( is_wp_error( $conditions ) ) {
			return $conditions;
		}

		$attrs = isset( $conditions['attributes'] ) ? $conditions['attributes'] : array();

		return array(
			'success'      => true,
			'page_id'      => isset( $conditions['id'] ) ? $conditions['id'] : $page_id,
			'create_video' => ! empty( $attrs['create_video'] ),
			'conditions'   => isset( $attrs['conditions'] ) ? $attrs['conditions'] : array(),
			'send_to'      => isset( $attrs['send_to'] ) ? $attrs['send_to'] : array(),
		);
	}
}
