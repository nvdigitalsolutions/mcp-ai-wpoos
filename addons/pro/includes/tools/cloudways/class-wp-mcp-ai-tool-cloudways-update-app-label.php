<?php
/**
 * Cloudways Update App Label Tool
 *
 * Rename an application.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Update_App_Label' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Update_App_Label extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_update_app_label';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Update App Label', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Rename an application.', 'mcp-ai-wpoos-pro' );
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
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'mcp-ai-wpoos-pro' ),
					),
					'label'     => array(
						'type'        => 'string',
						'description' => __( 'The new app label.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
				),
				'required'   => array( 'server_id', 'app_id', 'label' ),
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
			$label     = $this->sanitize_label( $arguments, 'label' );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 === $app_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_app_id',
					__( 'A valid app ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/label';
			$body   = array( 'label' => $label );
			$result = $this->client()->put( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: 1: new label, 2: app ID */
					__( 'App renamed to "%1$s" (ID: %2$d).', 'mcp-ai-wpoos-pro' ),
					esc_html( $label ),
					$app_id
				),
				$result
			);
		}
	}
}
