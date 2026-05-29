<?php
/**
 * Cloudways Clone App to Server Tool
 *
 * Clone an application to a different server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_clone_to_server';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Clone App to Server', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Clone an application to a different server.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id'        => array(
						'type'        => 'integer',
						'description' => __( 'The source server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'           => array(
						'type'        => 'integer',
						'description' => __( 'The source application ID.', 'mcp-ai-wpoos-pro' ),
					),
					'target_server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The target server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'label'            => array(
						'type'        => 'string',
						'description' => __( 'Label for the cloned app on the target server.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'server_id', 'app_id', 'target_server_id', 'label' ),
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
			$server_id        = $this->sanitize_server_id( $arguments );
			$app_id           = $this->sanitize_app_id( $arguments );
			$target_server_id = isset( $arguments['target_server_id'] ) ? absint( $arguments['target_server_id'] ) : 0;
			$label            = $this->sanitize_label( $arguments );

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

			if ( 0 === $target_server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_target_server_id',
					__( 'A valid target server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A valid label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/clone-to-server';
			$body   = array(
				'target_server_id' => $target_server_id,
				'label'            => $label,
			);
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Application clone initiated.', 'mcp-ai-wpoos-pro' ),
				$result
			);
		}
	}
}
