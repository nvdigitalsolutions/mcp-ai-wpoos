<?php
/**
 * Cloudways List Servers Tool
 *
 * Lists all Cloudways servers with status, cloud provider, region, IP,
 * and application count.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_List_Servers' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_List_Servers extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_list_servers';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List Cloudways Servers', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all Cloudways servers with status, cloud provider, region, IP, and application count.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would encode
				// as `[]`, which strict providers (DeepSeek) reject.
				'properties' => new stdClass(),
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
			$result = $this->client()->get( '/server' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['servers'] ) || ! is_array( $result['servers'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$servers = array();
			foreach ( $result['servers'] as $server ) {
				$servers[] = array(
					'id'            => absint( $server['id'] ),
					'label'         => sanitize_text_field( $server['label'] ),
					'status'        => sanitize_text_field( $server['status'] ),
					'cloud'         => sanitize_text_field( $server['cloud'] ),
					'region'        => sanitize_text_field( $server['region'] ),
					'public_ip'     => sanitize_text_field( $server['public_ip'] ),
					'app_count'     => absint( $server['app_count'] ),
					'ram'           => sanitize_text_field( $server['ram'] ),
					'instance_type' => sanitize_text_field( $server['instance_type'] ),
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of servers */
					_n( 'Found %d server.', 'Found %d servers.', count( $servers ), 'mcp-ai-wpoos-pro' ),
					count( $servers )
				),
				array( 'servers' => $servers )
			);
		}
	}
}
