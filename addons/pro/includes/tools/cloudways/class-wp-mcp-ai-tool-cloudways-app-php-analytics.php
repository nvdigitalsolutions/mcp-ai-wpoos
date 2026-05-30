<?php
/**
 * Cloudways App PHP Analytics Tool
 *
 * Analyze PHP performance and slow pages for an application.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_php_analytics';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'App PHP Analytics', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Analyze PHP performance and slow pages for an application.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app_id' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$server_id = $this->sanitize_server_id( $arguments );
			$app_id    = $this->sanitize_app_id( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 === $app_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_app_id',
					__( 'A valid app ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/analytics/php';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['php_analytics'] ) || ! is_array( $result['php_analytics'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$php_analytics = $result['php_analytics'];

			$data = array(
				'response_time' => isset( $php_analytics['response_time'] ) ? sanitize_text_field( $php_analytics['response_time'] ) : '',
				'slow_pages'    => isset( $php_analytics['slow_pages'] ) ? absint( $php_analytics['slow_pages'] ) : 0,
			);

			return $this->success(
				sprintf(
					/* translators: %d: app ID */
					__( 'PHP analytics retrieved for app %d.', 'mcp-ai-wpoos-pro' ),
					$app_id
				),
				array( 'php_analytics' => $data )
			);
		}
	}
}
