<?php
/**
 * Cloudways Restart Server Tool
 *
 * Restart a Cloudways server to apply configuration changes.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Restart' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Restart extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_restart';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Restart Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Restart a Cloudways server to apply configuration changes.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Rebooting a server to apply configuration changes or recover from an unresponsive state.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Restarting a single service; use cloudways_restart_service for a narrower impact.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_restart_service', 'cloudways_service_status', 'cloudways_server_stop', 'cloudways_server_start' ),
				'notes'           => __( 'All apps on the server experience downtime until the reboot completes.', 'mcp-ai-wpoos-pro' ),
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

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/restart';
			$result = $this->client()->post( $path, array() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Server restart initiated.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
