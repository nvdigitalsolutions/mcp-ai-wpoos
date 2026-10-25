<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * FlowHub Toolkit Settings Section (Proposal 089 Phase 2b)
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

if ( ! class_exists( 'WP_MCP_AI_Section_FlowHub_Toolkit' ) ) {
	/**
	 * FlowHub toolkit settings section.
	 */
	class WP_MCP_AI_Section_FlowHub_Toolkit extends WP_MCP_AI_Commerce_Toolkit_Settings_Section {

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'toolkit_flowhub';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'flowhub';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'FlowHub POS inventory synchronisation with WooCommerce. Compliance traceability (Metrc/BioTrack) stays inside FlowHub — the console is read-mostly.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Toolkit slug for advisory field keys.
		 *
		 * @return string
		 */
		protected function get_toolkit_slug() {
			return 'flowhub';
		}
	}
}
