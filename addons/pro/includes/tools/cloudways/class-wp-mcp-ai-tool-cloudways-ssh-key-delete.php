<?php
/**
 * Cloudways SSH Key Delete Tool
 *
 * Delete a previously added SSH key by its ID.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_ssh_key_delete';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Delete SSH Key', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Delete a previously added SSH key by its ID.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'ssh_key_id' => array(
						'type'        => 'integer',
						'description' => __( 'The SSH key ID to delete.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'ssh_key_id' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'non-reversible' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$ssh_key_id = isset( $arguments['ssh_key_id'] ) ? absint( $arguments['ssh_key_id'] ) : 0;

			if ( 0 === $ssh_key_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_ssh_key_id',
					__( 'A valid SSH key ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/ssh-key/' . $ssh_key_id;
			$result = $this->client()->delete( $path, array() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %d: SSH key ID */
					__( 'SSH key %d deleted successfully.', 'mcp-ai-wpoos-pro' ),
					$ssh_key_id
				),
				$result
			);
		}
	}
}
