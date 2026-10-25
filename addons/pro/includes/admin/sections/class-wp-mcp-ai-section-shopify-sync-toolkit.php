<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Shopify Sync Toolkit Settings Section (Proposal 089 Phase 2b)
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Shopify_Sync_Toolkit' ) ) {
	/**
	 * Shopify Sync toolkit settings section.
	 */
	class WP_MCP_AI_Section_Shopify_Sync_Toolkit extends WP_MCP_AI_Commerce_Toolkit_Settings_Section {

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'toolkit_shopify_sync';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Shopify Sync Toolkit', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'shopify_sync';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Background Shopify↔WooCommerce inventory synchronisation with CCT caching and webhook intake.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Toolkit slug for advisory field keys.
		 *
		 * @return string
		 */
		protected function get_toolkit_slug() {
			return 'shopify_sync';
		}
	}
}
