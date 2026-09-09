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
 * Cloudways Addon Activate Tool
 *
 * Activate an add-on on your Cloudways account.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Addon_Activate' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Addon_Activate extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_addon_activate';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Activate Add-on', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Activate an add-on on your Cloudways account.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'addon' => array(
						'type'        => 'string',
						'description' => __( 'The add-on identifier to activate.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
				),
				'required'   => array( 'addon' ),
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
			$addon = isset( $arguments['addon'] ) ? sanitize_text_field( $arguments['addon'] ) : '';

			if ( '' === $addon ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_addon',
					__( 'An add-on identifier is required.', 'nvoos-content-graph-pro' )
				);
			}

			$body   = array( 'addon' => $addon );
			$result = $this->client()->post( '/addon/activate', $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: %s: add-on identifier */
					__( 'Add-on "%s" activated successfully.', 'nvoos-content-graph-pro' ),
					esc_html( $addon )
				),
				$result
			);
		}
	}
}
