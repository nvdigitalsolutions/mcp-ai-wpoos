<?php
/**
 * Cloudways App CORS Headers Update Tool
 *
 * Update CORS (cross-origin resource sharing) headers for an application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_cors_headers_update';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Update CORS Headers', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Update CORS (cross-origin resource sharing) headers for an application.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'          => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'mcp-ai-wpoos-pro' ),
					),
					'allowed_origins' => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated list of allowed origins, or "*".', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
					'allowed_methods' => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated list of allowed HTTP methods (optional).', 'mcp-ai-wpoos-pro' ),
					),
					'allowed_headers' => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated list of allowed headers (optional).', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app_id', 'allowed_origins' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$server_id       = $this->sanitize_server_id( $arguments );
			$app_id          = $this->sanitize_app_id( $arguments );
			$allowed_origins = isset( $arguments['allowed_origins'] ) ? sanitize_text_field( $arguments['allowed_origins'] ) : '';
			$allowed_methods = isset( $arguments['allowed_methods'] ) ? sanitize_text_field( $arguments['allowed_methods'] ) : '';
			$allowed_headers = isset( $arguments['allowed_headers'] ) ? sanitize_text_field( $arguments['allowed_headers'] ) : '';

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

			if ( '' === $allowed_origins ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_origins',
					__( 'Allowed origins are required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$body = array( 'allowed_origins' => $allowed_origins );
			if ( '' !== $allowed_methods ) {
				$body['allowed_methods'] = $allowed_methods;
			}
			if ( '' !== $allowed_headers ) {
				$body['allowed_headers'] = $allowed_headers;
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/cors';
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %d: app ID */
					__( 'CORS headers updated for app %d.', 'mcp-ai-wpoos-pro' ),
					$app_id
				),
				$result
			);
		}
	}
}
