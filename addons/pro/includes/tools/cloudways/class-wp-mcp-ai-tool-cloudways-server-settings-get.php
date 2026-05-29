<?php
/**
 * Cloudways Server Settings Get Tool
 *
 * View server and PHP/MySQL configuration.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Settings_Get extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_settings_get';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Server Settings', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'View server and PHP/MySQL configuration.', 'mcp-ai-wpoos-pro' );
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
				),
				'required'   => array( 'server_id' ),
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

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/settings';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['settings'] ) || ! is_array( $result['settings'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$settings = $result['settings'];

			$data = array(
				'php_version'   => isset( $settings['php_version'] ) ? sanitize_text_field( $settings['php_version'] ) : '',
				'mysql_version' => isset( $settings['mysql_version'] ) ? sanitize_text_field( $settings['mysql_version'] ) : '',
				'nginx_version' => isset( $settings['nginx_version'] ) ? sanitize_text_field( $settings['nginx_version'] ) : '',
			);

			return $this->success(
				sprintf(
					/* translators: %d: server ID */
					__( 'Settings retrieved for server %d.', 'mcp-ai-wpoos-pro' ),
					$server_id
				),
				array( 'settings' => $data )
			);
		}
	}
}
