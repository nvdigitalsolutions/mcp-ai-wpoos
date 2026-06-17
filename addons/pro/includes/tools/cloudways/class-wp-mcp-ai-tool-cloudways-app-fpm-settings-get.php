<?php
/**
 * Cloudways App FPM Settings Get Tool
 *
 * Retrieve current PHP-FPM configuration for an application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_fpm_settings_get';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get App FPM Settings', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Retrieve current PHP-FPM configuration for an application.', 'mcp-ai-wpoos-pro' );
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

			$path   = '/app/' . $server_id . '/' . $app_id . '/fpm/settings';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['fpm_settings'] ) || ! is_array( $result['fpm_settings'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$fs           = $result['fpm_settings'];
			$fpm_settings = array(
				'max_children'    => isset( $fs['max_children'] ) ? absint( $fs['max_children'] ) : 0,
				'max_requests'    => isset( $fs['max_requests'] ) ? absint( $fs['max_requests'] ) : 0,
				'memory_limit'    => isset( $fs['memory_limit'] ) ? sanitize_text_field( $fs['memory_limit'] ) : '',
				'process_manager' => isset( $fs['process_manager'] ) ? sanitize_text_field( $fs['process_manager'] ) : '',
			);

			return $this->success(
				__( 'PHP-FPM settings retrieved.', 'mcp-ai-wpoos-pro' ),
				array( 'fpm_settings' => $fpm_settings )
			);
		}
	}
}
