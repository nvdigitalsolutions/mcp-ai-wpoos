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

declare(strict_types=1);


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
			return __( 'Create SSH Key', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Add an SSH public key to a server, application, or system user.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'nvoos-content-graph-pro' ),
					),
					'app_id'     => array(
						'type'        => 'integer',
						'description' => __( 'The application ID (optional, default: 0).', 'nvoos-content-graph-pro' ),
					),
					'label'      => array(
						'type'        => 'string',
						'description' => __( 'A label to identify this SSH key.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
					'public_key' => array(
						'type'        => 'string',
						'description' => __( 'The SSH public key content.', 'nvoos-content-graph-pro' ),
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
					__( 'A valid server ID is required.', 'nvoos-content-graph-pro' )
				);
			}

			if ( '' === $label ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_label',
					__( 'A label is required.', 'nvoos-content-graph-pro' )
				);
			}

			if ( '' === $public_key ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_public_key',
					__( 'An SSH public key is required.', 'nvoos-content-graph-pro' )
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
					__( 'SSH key "%s" created successfully.', 'nvoos-content-graph-pro' ),
					esc_html( $label )
				),
				$result
			);
		}
	}
}
