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

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Get_Operation_Status extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_get_operation_status';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Get Operation Status', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Check the status of an asynchronous operation (server creation, backup, scaling, etc.).', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'operation_id' => array(
						'type'        => 'string',
						'description' => __( 'The operation ID to check.', 'nvoos-content-graph-pro' ),
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
					__( 'A valid operation ID is required.', 'nvoos-content-graph-pro' )
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
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
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
					__( 'Operation status for %s.', 'nvoos-content-graph-pro' ),
					$operation_id
				),
				array( 'operation' => $data )
			);
		}
	}
}
