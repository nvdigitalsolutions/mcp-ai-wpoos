<?php
/**
 * Cloudways Get Server Tool
 *
 * Get detailed information about a specific server including configuration
 * and hosted apps.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Get_Server' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Get_Server extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_get_server';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Cloudways Server Details', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Get detailed information about a specific server including configuration and hosted apps.', 'mcp-ai-wpoos-pro' );
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

			$path   = '/server/' . $server_id;
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['server'] ) || ! is_array( $result['server'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$server = $result['server'];

			$data = array(
				'id'            => absint( $server['id'] ),
				'label'         => sanitize_text_field( $server['label'] ),
				'status'        => sanitize_text_field( $server['status'] ),
				'cloud'         => sanitize_text_field( $server['cloud'] ),
				'region'        => sanitize_text_field( $server['region'] ),
				'public_ip'     => sanitize_text_field( $server['public_ip'] ),
				'ram'           => sanitize_text_field( $server['ram'] ),
				'instance_type' => sanitize_text_field( $server['instance_type'] ),
			);

			return $this->success(
				sprintf(
					/* translators: %s: server label */
					__( 'Server details for %s.', 'mcp-ai-wpoos-pro' ),
					$data['label']
				),
				array( 'server' => $data )
			);
		}
	}
}
