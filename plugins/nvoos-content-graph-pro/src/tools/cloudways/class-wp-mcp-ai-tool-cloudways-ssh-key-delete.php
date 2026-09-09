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

declare(strict_types=1);


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
			return __( 'Delete SSH Key', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Delete a previously added SSH key by its ID.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'ssh_key_id' => array(
						'type'        => 'integer',
						'description' => __( 'The SSH key ID to delete.', 'nvoos-content-graph-pro' ),
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
					__( 'A valid SSH key ID is required.', 'nvoos-content-graph-pro' )
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
					__( 'SSH key %d deleted successfully.', 'nvoos-content-graph-pro' ),
					$ssh_key_id
				),
				$result
			);
		}
	}
}
