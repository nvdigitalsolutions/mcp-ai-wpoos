<?php
/**
 * Coordinates registration of the chat shortcodes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Shortcodes' ) ) {
	/**
	 * Instantiate the shortcode handlers and coordinate shared assets.
	 */
	class WP_MCP_AI_Shortcodes {
		/**
		 * Primary chat shortcode handler.
		 *
		 * @var WP_MCP_AI_Shortcode
		 */
		protected $chat_shortcode;

		/**
		 * Boot the shortcode handlers.
		 */
		public function __construct() {
			$this->chat_shortcode = new WP_MCP_AI_Shortcode();

			// Maintain backwards compatibility with the historic globals.
			$GLOBALS['wp_mcp_ai_shortcode'] = $this->chat_shortcode;
		}

		/**
		 * Retrieve the classic chat shortcode handler.
		 *
		 * @return WP_MCP_AI_Shortcode
		 */
		public function get_chat_shortcode() {
			return $this->chat_shortcode;
		}
	}
}
