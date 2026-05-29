<?php
/**
 * Cloudways SSH Key List Tool
 *
 * List all SSH keys for a server.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_SSH_Key_List' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_SSH_Key_List extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_ssh_key_list';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List SSH Keys', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all SSH keys for a server.', 'mcp-ai-wpoos-pro' );
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
				),
				'required'   => array( 'server_id' ),
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
			$server_id = $this->sanitize_server_id( $arguments );

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/ssh-key?server_id=' . $server_id;
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['ssh_keys'] ) || ! is_array( $result['ssh_keys'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$ssh_keys = array();
			foreach ( $result['ssh_keys'] as $key ) {
				$ssh_keys[] = array(
					'id'       => isset( $key['id'] ) ? absint( $key['id'] ) : 0,
					'label'    => isset( $key['label'] ) ? sanitize_text_field( $key['label'] ) : '',
					'key_type' => isset( $key['key_type'] ) ? sanitize_text_field( $key['key_type'] ) : '',
					'user'     => isset( $key['user'] ) ? sanitize_text_field( $key['user'] ) : '',
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of SSH keys */
					_n( 'Found %d SSH key.', 'Found %d SSH keys.', count( $ssh_keys ), 'mcp-ai-wpoos-pro' ),
					count( $ssh_keys )
				),
				array( 'ssh_keys' => $ssh_keys )
			);
		}
	}
}
