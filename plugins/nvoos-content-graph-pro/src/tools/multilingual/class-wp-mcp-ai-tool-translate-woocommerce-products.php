<?php
/**
 * Multilingual tool batch (ecosystem port - Wave F2, multilingual toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/multilingual/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated tool batch - the D8-compat interface copies resolve via the entry's spl autoloader;
 * the import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root).
 *
 * Translate WooCommerce Products Tool
 *
 * Translate product catalogs including titles, descriptions, attributes, and categories for multiple languages.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Translate_WooCommerce_Products tool.
 */
class WP_MCP_AI_Tool_Translate_WooCommerce_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_multilingual_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return __( 'Multi-language Content toolkit is not enabled.', 'nvoos-content-graph-pro' );
		}
		return __( 'Translate WooCommerce Products tool is not available.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'translate_woocommerce_products';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Translate WooCommerce Products', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Translate product catalogs including titles, descriptions, attributes, and categories for multiple languages.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_ids'        => array(
					'type'        => 'array',
					'description' => 'Product IDs to translate',
				),
				'target_language'    => array(
					'type'        => 'string',
					'description' => 'Target language code',
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => 'Translate product variations',
					'default'     => true,
				),
				'include_categories' => array(
					'type'        => 'boolean',
					'description' => 'Translate categories',
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'content'     => true,
			'translation' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// TODO: Implement translate_woocommerce_products logic.

		return array(
			'success' => true,
			'message' => __( 'Translate WooCommerce Products executed successfully.', 'nvoos-content-graph-pro' ),
		);
	}
}
