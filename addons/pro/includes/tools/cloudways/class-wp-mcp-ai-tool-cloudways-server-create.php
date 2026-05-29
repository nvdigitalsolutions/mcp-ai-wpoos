<?php
/**
 * Cloudways Create Server Tool
 *
 * Create a new server on DigitalOcean, AWS, GCE, Vultr, or Linode
 * with an initial application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Server_Create' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Server_Create extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_server_create';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Create Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Create a new server on DigitalOcean, AWS, GCE, Vultr, or Linode with an initial application.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'cloud'  => array(
						'type'        => 'string',
						'description' => __( 'The cloud provider.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'digitalocean', 'aws', 'gce', 'vultr', 'linode' ),
					),
					'size'   => array(
						'type'        => 'string',
						'description' => __( 'The server size (e.g. "2GB").', 'mcp-ai-wpoos-pro' ),
					),
					'region' => array(
						'type'        => 'string',
						'description' => __( 'The deployment region.', 'mcp-ai-wpoos-pro' ),
					),
					'app'    => array(
						'type'        => 'string',
						'description' => __( 'The initial application type.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'wordpress', 'woocommerce', 'laravel', 'magento', 'php' ),
					),
					'label'  => array(
						'type'        => 'string',
						'description' => __( 'The server label.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'cloud', 'size', 'region', 'app', 'label' ),
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
			$cloud  = isset( $arguments['cloud'] ) ? sanitize_text_field( $arguments['cloud'] ) : '';
			$size   = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '';
			$region = isset( $arguments['region'] ) ? sanitize_text_field( $arguments['region'] ) : '';
			$app    = isset( $arguments['app'] ) ? sanitize_text_field( $arguments['app'] ) : '';
			$label  = $this->sanitize_label( $arguments );

			$valid_clouds = array( 'digitalocean', 'aws', 'gce', 'vultr', 'linode' );
			if ( '' === $cloud || ! in_array( $cloud, $valid_clouds, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_cloud',
					__( 'A valid cloud provider is required (digitalocean, aws, gce, vultr, linode).', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $size ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_size',
					__( 'A valid server size is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $region ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_region',
					__( 'A valid region is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$valid_apps = array( 'wordpress', 'woocommerce', 'laravel', 'magento', 'php' );
			if ( '' === $app || ! in_array( $app, $valid_apps, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_app',
					__( 'A valid application type is required (WordPress, woocommerce, laravel, magento, php).', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A valid label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/server';
			$body   = array(
				'cloud'  => $cloud,
				'size'   => $size,
				'region' => $region,
				'app'    => $app,
				'label'  => $label,
			);
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Server creation initiated.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
