<?php
/**
 * Cloudways Stop Server Tool
 *
 * Stop a running Cloudways server. All hosted apps will be unavailable.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Stop' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Stop extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_stop';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Stop Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Stop a running Cloudways server.', 'mcp-ai-wpoos-pro' );
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
					'confirm'   => array(
						'type'        => 'boolean',
						'description' => __( 'Set to true to confirm this action.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'confirm' ),
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

			if ( ! $this->sanitize_confirm( $arguments ) ) {
				return new WP_Error(
					'cloudways_confirm_required',
					__( 'You must set confirm to true to perform this destructive action.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/stop';
			$result = $this->client()->post( $path, array() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Server stopped successfully. Note: all hosted apps will be unavailable.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
