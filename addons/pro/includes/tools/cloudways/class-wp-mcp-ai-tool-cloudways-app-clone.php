<?php
/**
 * Cloudways Clone App Tool
 *
 * Clone an application on the same server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_App_Clone' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_App_Clone extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_app_clone';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Clone App', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Clone an application on the same server.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Duplicating an application onto the same server, for example to stage a copy for testing.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Cloning across servers; use cloudways_app_clone_to_server when the target is a different server.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_app_clone_to_server', 'cloudways_list_apps', 'cloudways_get_operation_status' ),
				'notes'           => __( 'Requires server_id and app_id; discover both with cloudways_list_servers and cloudways_list_apps first.', 'mcp-ai-wpoos-pro' ),
			);
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
						'description' => __( 'Label for the new cloned app.', 'mcp-ai-wpoos-pro' ),
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
			$label     = $this->sanitize_label( $arguments );

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
					__( 'A valid label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/clone';
			$body   = array( 'label' => $label );
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
