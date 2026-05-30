<?php
/**
 * Cloudways Copilot Insights List Tool
 *
 * Retrieve AI-driven insights, alerts, and recommendations for your infrastructure.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_copilot_insights_list';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List Copilot Insights', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Retrieve AI-driven insights, alerts, and recommendations for your infrastructure.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The server ID (optional).', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID (optional).', 'mcp-ai-wpoos-pro' ),
					),
				),
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
			$app_id    = $this->sanitize_app_id( $arguments );

			$path   = '/copilot/insights';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['insights'] ) || ! is_array( $result['insights'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$insights = array();
			foreach ( $result['insights'] as $insight ) {
				$insights[] = array(
					'id'          => isset( $insight['id'] ) ? sanitize_text_field( $insight['id'] ) : '',
					'title'       => isset( $insight['title'] ) ? sanitize_text_field( $insight['title'] ) : '',
					'description' => isset( $insight['description'] ) ? sanitize_text_field( $insight['description'] ) : '',
					'severity'    => isset( $insight['severity'] ) ? sanitize_text_field( $insight['severity'] ) : '',
					'category'    => isset( $insight['category'] ) ? sanitize_text_field( $insight['category'] ) : '',
					'timestamp'   => isset( $insight['timestamp'] ) ? sanitize_text_field( $insight['timestamp'] ) : '',
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of insights */
					_n( 'Found %d insight.', 'Found %d insights.', count( $insights ), 'mcp-ai-wpoos-pro' ),
					count( $insights )
				),
				array( 'insights' => $insights )
			);
		}
	}
}
