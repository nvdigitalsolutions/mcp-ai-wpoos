<?php
/**
 * Cloudways Clone Server Tool
 *
 * Clone an existing server to a new server with the same applications
 * and optionally settings, domains, cron jobs, and SSL certificates.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Clone' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Clone extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_clone';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Clone Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Clone an existing server to a new server with the same applications and optionally settings, domains, cron jobs, and SSL certificates.', 'mcp-ai-wpoos-pro' );
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
					'label'     => array(
						'type'        => 'string',
						'description' => __( 'Label for the new cloned server.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'label' ),
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
			$label     = $this->sanitize_label( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A valid label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server/' . $server_id . '/clone';
			$body   = array( 'label' => $label );
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Server clone initiated.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
