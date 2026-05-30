<?php
/**
 * Cloudways Create App Tool
 *
 * Create a new application (WordPress, Laravel, Magento, etc.) on a server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_Create' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_Create extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_create';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Create App', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Create a new application (WordPress, Laravel, Magento, etc.) on a server.', 'mcp-ai-wpoos-pro' );
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
					'app'       => array(
						'type'        => 'string',
						'description' => __( 'The application type.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'wordpress', 'woocommerce', 'laravel', 'magento', 'php' ),
					),
					'label'     => array(
						'type'        => 'string',
						'description' => __( 'The application label.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app', 'label' ),
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
			$app       = isset( $arguments['app'] ) ? sanitize_text_field( $arguments['app'] ) : '';
			$label     = $this->sanitize_label( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
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

			$path   = '/app';
			$body   = array(
				'server_id' => $server_id,
				'app'       => $app,
				'label'     => $label,
			);
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Application creation initiated.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
