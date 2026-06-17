<?php
/**
 * Cloudways Get App Tool
 *
 * Get detailed information about a specific application including
 * configuration.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Get_App' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Get_App extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_get_app';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Cloudways Application Details', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Get detailed information about a specific application including configuration.', 'mcp-ai-wpoos-pro' );
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

			$path   = '/app';
			$query  = array(
				'server_id' => $server_id,
				'app_id'    => $app_id,
			);
			$result = $this->client()->get( $path, $query );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['app'] ) || ! is_array( $result['app'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$app = $result['app'];

			$data = array(
				'id'       => absint( $app['id'] ),
				'label'    => sanitize_text_field( $app['label'] ),
				'app_type' => sanitize_text_field( $app['app_type'] ),
				'status'   => sanitize_text_field( $app['status'] ),
			);

			return $this->success(
				sprintf(
					/* translators: %s: app label */
					__( 'App details for %s.', 'mcp-ai-wpoos-pro' ),
					$data['label']
				),
				array( 'app' => $data )
			);
		}
	}
}
