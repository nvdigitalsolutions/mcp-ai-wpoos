<?php
/**
 * Cloudways Get Operation Status Tool
 *
 * Check the status of an asynchronous operation (server creation, backup,
 * scaling, etc.).
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Get_Operation_Status extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_get_operation_status';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Operation Status', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Check the status of an asynchronous operation (server creation, backup, scaling, etc.).', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Polling the progress or final outcome of an asynchronous Cloudways operation using its operation_id.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Starting new work or looking up servers; run the provisioning, backup, or scaling tool and capture its operation_id first.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_server_create', 'cloudways_server_clone', 'cloudways_create_app_backup' ),
				'notes'           => __( 'Most write tools return an operation_id in their response; poll until status reports completion.', 'mcp-ai-wpoos-pro' ),
			);
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'operation_id' => array(
						'type'        => 'string',
						'description' => __( 'The operation ID to check.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'operation_id' ),
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
			$operation_id = isset( $arguments['operation_id'] ) ? sanitize_text_field( $arguments['operation_id'] ) : '';

			if ( '' === $operation_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_operation_id',
					__( 'A valid operation ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/operation/' . $operation_id;
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['operation'] ) || ! is_array( $result['operation'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$operation = $result['operation'];

			$data = array(
				'id'       => sanitize_text_field( $operation['id'] ),
				'status'   => sanitize_text_field( $operation['status'] ),
				'type'     => isset( $operation['type'] ) ? sanitize_text_field( $operation['type'] ) : '',
				'progress' => isset( $operation['progress'] ) ? absint( $operation['progress'] ) : 0,
			);

			return $this->success(
				sprintf(
					/* translators: %s: operation ID */
					__( 'Operation status for %s.', 'mcp-ai-wpoos-pro' ),
					$operation_id
				),
				array( 'operation' => $data )
			);
		}
	}
}
