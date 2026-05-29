<?php
/**
 * Cloudways Scale Server Tool
 *
 * Upgrade or downgrade server size (CPU/RAM).
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Scale' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Scale extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_scale';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Scale Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Upgrade or downgrade server size (CPU/RAM).', 'mcp-ai-wpoos-pro' );
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
					'size'      => array(
						'type'        => 'string',
						'description' => __( 'The desired server size (e.g. "2GB").', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'size' ),
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
			$size      = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '';

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $size ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_size',
					__( 'A valid server size is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/scale';
			$body   = array( 'size' => $size );
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				/* translators: %s: server size */
				sprintf( __( 'Server scaling initiated.', 'mcp-ai-wpoos-pro' ), esc_html( $size ) ),
				$result
			);
		}
	}
}
