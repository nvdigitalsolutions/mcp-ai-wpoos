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
 * Cloudways Git History Get Tool
 *
 * Retrieve recent Git deployment history for an application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Git_History_Get' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Git_History_Get extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_git_history_get';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Git Deployment History', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Retrieve recent Git deployment history for an application.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'nvoos-content-graph-pro' ),
					),
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'nvoos-content-graph-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app_id' ),
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
			$app_id    = $this->sanitize_app_id( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'nvoos-content-graph-pro' )
				);
			}

			if ( 0 === $app_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_app_id',
					__( 'A valid app ID is required.', 'nvoos-content-graph-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/git/history';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['history'] ) || ! is_array( $result['history'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
				);
			}

			$history = array();
			foreach ( $result['history'] as $entry ) {
				$history[] = array(
					'id'        => isset( $entry['id'] ) ? sanitize_text_field( $entry['id'] ) : '',
					'commit'    => isset( $entry['commit'] ) ? sanitize_text_field( $entry['commit'] ) : '',
					'message'   => isset( $entry['message'] ) ? sanitize_text_field( $entry['message'] ) : '',
					'timestamp' => isset( $entry['timestamp'] ) ? sanitize_text_field( $entry['timestamp'] ) : '',
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of history entries */
					_n( 'Found %d deployment history entry.', 'Found %d deployment history entries.', count( $history ), 'nvoos-content-graph-pro' ),
					count( $history )
				),
				array( 'history' => $history )
			);
		}
	}
}
