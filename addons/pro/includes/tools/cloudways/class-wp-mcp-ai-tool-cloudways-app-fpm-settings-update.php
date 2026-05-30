<?php
/**
 * Cloudways App FPM Settings Update Tool
 *
 * Configure PHP-FPM settings (workers, max children, request memory).
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_fpm_settings_update';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Update App FPM Settings', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Configure PHP-FPM settings (workers, max children, request memory).', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'mcp-ai-wpoos-pro' ),
					),
					'max_children' => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of child processes (optional).', 'mcp-ai-wpoos-pro' ),
					),
					'max_requests' => array(
						'type'        => 'integer',
						'description' => __( 'Maximum number of requests per child process (optional).', 'mcp-ai-wpoos-pro' ),
					),
					'memory_limit' => array(
						'type'        => 'string',
						'description' => __( 'PHP memory limit (optional, e.g. 128M, 256M).', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app_id' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible', 'performance-impact' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$server_id    = $this->sanitize_server_id( $arguments );
			$app_id       = $this->sanitize_app_id( $arguments );
			$max_children = isset( $arguments['max_children'] ) ? absint( $arguments['max_children'] ) : 0;
			$max_requests = isset( $arguments['max_requests'] ) ? absint( $arguments['max_requests'] ) : 0;
			$memory_limit = isset( $arguments['memory_limit'] ) ? sanitize_text_field( $arguments['memory_limit'] ) : '';

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

			$body = array();
			if ( $max_children > 0 ) {
				$body['max_children'] = $max_children;
			}
			if ( $max_requests > 0 ) {
				$body['max_requests'] = $max_requests;
			}
			if ( '' !== $memory_limit ) {
				$body['memory_limit'] = $memory_limit;
			}

			if ( empty( $body ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_no_settings',
					__( 'At least one setting must be provided (max_children, max_requests, or memory_limit).', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/fpm/settings';
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %d: app ID */
					__( 'PHP-FPM settings updated for app %d.', 'mcp-ai-wpoos-pro' ),
					$app_id
				),
				$result
			);
		}
	}
}
