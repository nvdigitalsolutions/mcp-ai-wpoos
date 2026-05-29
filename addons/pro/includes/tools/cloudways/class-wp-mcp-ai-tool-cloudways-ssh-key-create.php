<?php
/**
 * Cloudways SSH Key Create Tool
 *
 * Add an SSH public key to a server, application, or system user.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_SSH_Key_Create extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_ssh_key_create';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Create SSH Key', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Add an SSH public key to a server, application, or system user.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'     => array(
						'type'        => 'integer',
						'description' => __( 'The application ID (optional, default: 0).', 'mcp-ai-wpoos-pro' ),
					),
					'label'      => array(
						'type'        => 'string',
						'description' => __( 'A label to identify this SSH key.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
					'public_key' => array(
						'type'        => 'string',
						'description' => __( 'The SSH public key content.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
				),
				'required'   => array( 'server_id', 'label', 'public_key' ),
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
			$server_id  = $this->sanitize_server_id( $arguments );
			$app_id     = $this->sanitize_app_id( $arguments );
			$label      = $this->sanitize_label( $arguments, 'label' );
			$public_key = isset( $arguments['public_key'] ) ? sanitize_text_field( $arguments['public_key'] ) : '';

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A label is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $public_key ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_public_key',
					__( 'An SSH public key is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$body   = array(
				'server_id'  => $server_id,
				'app_id'     => $app_id,
				'label'      => $label,
				'public_key' => $public_key,
			);
			$result = $this->client()->post( '/ssh-key', $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %s: SSH key label */
					__( 'SSH key "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
					esc_html( $label )
				),
				$result
			);
		}
	}
}
