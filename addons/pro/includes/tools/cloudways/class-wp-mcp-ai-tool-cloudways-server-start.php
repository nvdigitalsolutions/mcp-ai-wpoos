<?php
/**
 * Cloudways Start Server Tool
 *
 * Start a stopped Cloudways server.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.16
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Start' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Start extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_start';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Start Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Start a stopped Cloudways server.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Bringing a stopped server back online to restore all of its hosted apps.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Rebooting a running server or fixing one hung service; use cloudways_server_restart or cloudways_restart_service.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_server_stop', 'cloudways_server_restart', 'cloudways_get_server' ),
			);
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
			$server_id = $this->sanitize_server_id( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/start';
			$result = $this->client()->post( $path, array() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				/* translators: %d: server ID */
				sprintf( __( 'Server started successfully.', 'mcp-ai-wpoos-pro' ), $server_id ),
				$result
			);
		}
	}
}
