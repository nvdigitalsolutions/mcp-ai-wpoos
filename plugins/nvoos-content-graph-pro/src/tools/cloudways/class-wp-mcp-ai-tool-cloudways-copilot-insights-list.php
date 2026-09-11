<?php
/**
 * Cloudways tool batch (ecosystem port - Wave F2, cloudways toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cloudways/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no
 * path constants - the shared base class resolves via the entry's spl autoloader).
 *
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

declare(strict_types=1);


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
			return __( 'List Copilot Insights', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Retrieve AI-driven insights, alerts, and recommendations for your infrastructure.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The server ID (optional).', 'nvoos-content-graph-pro' ),
					),
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID (optional).', 'nvoos-content-graph-pro' ),
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
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
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
					_n( 'Found %d insight.', 'Found %d insights.', count( $insights ), 'nvoos-content-graph-pro' ),
					count( $insights )
				),
				array( 'insights' => $insights )
			);
		}
	}
}
