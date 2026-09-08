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
 * Cloudways Restart Service Tool
 *
 * Restart a specific service (nginx, mysql, php-fpm) on a server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Restart_Service' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Restart_Service extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_restart_service';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Restart Service', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Restart a specific service (nginx, mysql, php-fpm) on a server.', 'nvoos-content-graph-pro' );
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
					'service'   => array(
						'type'        => 'string',
						'description' => __( 'The service to restart.', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'nginx', 'mysql', 'php-fpm', 'varnish', 'redis' ),
					),
				),
				'required'   => array( 'server_id', 'service' ),
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
			$service   = isset( $arguments['service'] ) ? sanitize_text_field( $arguments['service'] ) : '';

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'nvoos-content-graph-pro' )
				);
			}

			$valid_services = array( 'nginx', 'mysql', 'php-fpm', 'varnish', 'redis' );
			if ( '' === $service || ! in_array( $service, $valid_services, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_service',
					__( 'A valid service name is required (nginx, mysql, php-fpm, varnish, redis).', 'nvoos-content-graph-pro' )
				);
			}

			$path   = '/service/' . $server_id . '/restart';
			$body   = array( 'service' => $service );
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: 1: service name, 2: server ID */
					__( 'Service %1$s restarted on server %2$d.', 'nvoos-content-graph-pro' ),
					esc_html( $service ),
					$server_id
				),
				$result
			);
		}
	}
}
