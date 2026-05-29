<?php
/**
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
			return __( 'Restart Service', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Restart a specific service (nginx, mysql, php-fpm) on a server.', 'mcp-ai-wpoos-pro' );
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
					'service'   => array(
						'type'        => 'string',
						'description' => __( 'The service to restart.', 'mcp-ai-wpoos-pro' ),
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
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$valid_services = array( 'nginx', 'mysql', 'php-fpm', 'varnish', 'redis' );
			if ( '' === $service || ! in_array( $service, $valid_services, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_service',
					__( 'A valid service name is required (nginx, mysql, php-fpm, varnish, redis).', 'mcp-ai-wpoos-pro' )
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
					__( 'Service %1$s restarted on server %2$d.', 'mcp-ai-wpoos-pro' ),
					esc_html( $service ),
					$server_id
				),
				$result
			);
		}
	}
}
