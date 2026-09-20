<?php
/**
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Addon_Activate' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Addon_Activate extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_addon_activate';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Activate Add-on', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Activate an add-on on your Cloudways account.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Enabling a Cloudways add-on when you already know its add-on identifier.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Discovering which add-ons exist or their cost; use cloudways_addon_list first.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_addon_list', 'cloudways_copilot_insights_list' ),
				'notes'           => __( 'The addon argument is the Cloudways identifier; list valid values with cloudways_addon_list.', 'mcp-ai-wpoos-pro' ),
			);
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'addon' => array(
						'type'        => 'string',
						'description' => __( 'The add-on identifier to activate.', 'mcp-ai-wpoos-pro' ),
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
					__( 'An add-on identifier is required.', 'mcp-ai-wpoos-pro' )
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
					__( 'Add-on "%s" activated successfully.', 'mcp-ai-wpoos-pro' ),
					esc_html( $addon )
				),
				$result
			);
		}
	}
}
