<?php
/**
 * Cloudways App Varnish Settings Update Tool
 *
 * Update Varnish configuration (TTL, cacheable paths, exclusions) for an application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_varnish_settings_update';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Update App Varnish Settings', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Update Varnish configuration (TTL, cacheable paths, exclusions) for an application.', 'mcp-ai-wpoos-pro' );
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
					'ttl'       => array(
						'type'        => 'integer',
						'description' => __( 'Cache TTL in seconds (optional).', 'mcp-ai-wpoos-pro' ),
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
			$server_id = $this->sanitize_server_id( $arguments );
			$app_id    = $this->sanitize_app_id( $arguments );
			$ttl       = isset( $arguments['ttl'] ) ? absint( $arguments['ttl'] ) : 0;

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
			if ( $ttl > 0 ) {
				$body['ttl'] = $ttl;
			}

			if ( empty( $body ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_no_settings',
					__( 'At least one Varnish setting must be provided (ttl).', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/varnish/settings';
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %d: app ID */
					__( 'Varnish settings updated for app %d.', 'mcp-ai-wpoos-pro' ),
					$app_id
				),
				$result
			);
		}
	}
}
