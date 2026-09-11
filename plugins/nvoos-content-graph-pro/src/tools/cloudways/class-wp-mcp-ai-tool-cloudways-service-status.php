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
 * Cloudways Service Status Tool
 *
 * Check the status of all services (nginx, mysql, php-fpm, varnish, redis)
 * on a server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Service_Status' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Service_Status extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_service_status';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Check Service Status', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Check the status of all services (nginx, mysql, php-fpm, varnish, redis) on a server.', 'nvoos-content-graph-pro' );
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
					__( 'A valid server ID is required.', 'nvoos-content-graph-pro' )
				);
			}

			$path   = '/service/' . $server_id;
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['services'] ) || ! is_array( $result['services'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
				);
			}

			$services = array();
			foreach ( $result['services'] as $service ) {
				$services[] = array(
					'name'   => sanitize_text_field( $service['name'] ),
					'status' => sanitize_text_field( $service['status'] ),
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: server ID */
					__( 'Service status retrieved for server %d.', 'nvoos-content-graph-pro' ),
					$server_id
				),
				array( 'services' => $services )
			);
		}
	}
}
