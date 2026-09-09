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
 * Cloudways Addon List Tool
 *
 * List all available Cloudways add-ons with status and pricing.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Addon_List' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Addon_List extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_addon_list';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List Add-ons', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all available Cloudways add-ons with status and pricing.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would encode
				// as `[]`, which strict providers (DeepSeek) reject.
				'properties' => new stdClass(),
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
			$result = $this->client()->get( '/addon' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['addons'] ) || ! is_array( $result['addons'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
				);
			}

			$addons = array();
			foreach ( $result['addons'] as $addon ) {
				$addons[] = array(
					'id'          => isset( $addon['id'] ) ? sanitize_text_field( $addon['id'] ) : '',
					'name'        => isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '',
					'description' => isset( $addon['description'] ) ? sanitize_text_field( $addon['description'] ) : '',
					'status'      => isset( $addon['status'] ) ? sanitize_text_field( $addon['status'] ) : '',
					'price'       => isset( $addon['price'] ) ? sanitize_text_field( $addon['price'] ) : '',
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of add-ons */
					_n( 'Found %d add-on.', 'Found %d add-ons.', count( $addons ), 'nvoos-content-graph-pro' ),
					count( $addons )
				),
				array( 'addons' => $addons )
			);
		}
	}
}
