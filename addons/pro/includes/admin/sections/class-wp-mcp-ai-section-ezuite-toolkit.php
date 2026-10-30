<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * EZuite Toolkit Settings Section (Proposal 089 Phase 2b)
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

if ( ! class_exists( 'WP_MCP_AI_Section_Ezuite_Toolkit' ) ) {
	/**
	 * EZuite toolkit settings section.
	 */
	class WP_MCP_AI_Section_Ezuite_Toolkit extends WP_MCP_AI_Commerce_Toolkit_Settings_Section {

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'toolkit_ezuite';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'EZuite ERP Toolkit', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'ezuite';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'EZuite ERP inventory synchronisation with WooCommerce, including CCT caching and low-stock alerts.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Toolkit slug for advisory field keys.
		 *
		 * @return string
		 */
		protected function get_toolkit_slug() {
			return 'ezuite';
		}
	}
}
