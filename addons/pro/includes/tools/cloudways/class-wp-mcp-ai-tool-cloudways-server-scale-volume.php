<?php
/**
 * Cloudways Scale Server Volume Tool
 *
 * Change the data volume size on a server (Amazon and GCE only).
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_scale_volume';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Scale Server Volume', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Change the data volume size on a server (Amazon and GCE only).', 'mcp-ai-wpoos-pro' );
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
					'size_gb'   => array(
						'type'        => 'integer',
						'description' => __( 'The desired volume size in GB.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'size_gb' ),
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
			$size_gb   = isset( $arguments['size_gb'] ) ? absint( $arguments['size_gb'] ) : 0;

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 === $size_gb ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_size_gb',
					__( 'A valid volume size in GB is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/volume/scale';
			$body   = array( 'size_gb' => $size_gb );
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				/* translators: %d: size in GB */
				sprintf( __( 'Volume scaling initiated.', 'mcp-ai-wpoos-pro' ), $size_gb ),
				$result
			);
		}
	}
}
