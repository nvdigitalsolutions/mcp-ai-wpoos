<?php
/**
 * Cloudways Server Monitor Summary Tool
 *
 * View server bandwidth and disk usage metrics.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_monitor_summary';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Server Monitoring Summary', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'View server bandwidth and disk usage metrics.', 'mcp-ai-wpoos-pro' );
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

			$path   = '/server/' . $server_id . '/monitor/summary';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['summary'] ) || ! is_array( $result['summary'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$summary = $result['summary'];

			$data = array(
				'bandwidth' => isset( $summary['bandwidth'] ) ? sanitize_text_field( $summary['bandwidth'] ) : '',
				'disk'      => isset( $summary['disk'] ) ? sanitize_text_field( $summary['disk'] ) : '',
			);

			return $this->success(
				sprintf(
					/* translators: %d: server ID */
					__( 'Monitoring summary retrieved for server %d.', 'mcp-ai-wpoos-pro' ),
					$server_id
				),
				array( 'summary' => $data )
			);
		}
	}
}
