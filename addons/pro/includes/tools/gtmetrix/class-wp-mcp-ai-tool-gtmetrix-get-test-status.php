<?php
/**
 * GTmetrix tool: get test status.
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
 * Poll a GTmetrix test's state (queued, started, error, completed).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Test_Status extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_test_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Test Status', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Check the state of a GTmetrix test (queued, started, error or completed). Completed tests include the report ID.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix test ID returned by gtmetrix_start_test.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'test_id' ),
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

		$test_id = isset( $arguments['test_id'] ) ? sanitize_text_field( (string) $arguments['test_id'] ) : '';
		if ( '' === $test_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_test', __( 'A test ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$test = $this->client()->get_test( $test_id );
		if ( is_wp_error( $test ) ) {
			return $test;
		}

		$attrs = isset( $test['attributes'] ) ? $test['attributes'] : array();

		$response = array(
			'success' => true,
			'test_id' => isset( $test['id'] ) ? $test['id'] : $test_id,
			'state'   => isset( $attrs['state'] ) ? $attrs['state'] : 'unknown',
			'url'     => isset( $attrs['url'] ) ? $attrs['url'] : '',
		);

		if ( 'error' === $response['state'] ) {
			$response['error'] = isset( $attrs['error'] ) ? $attrs['error'] : __( 'Unknown test error.', 'mcp-ai-wpoos-pro' );
		}

		if ( 'completed' === $response['state'] ) {
			$response['report_id'] = isset( $attrs['report'] ) ? $attrs['report'] : '';
		}

		return $response;
	}
}
