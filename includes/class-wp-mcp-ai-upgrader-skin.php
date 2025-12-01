<?php
/**
 * Silent upgrader skin for plugin and theme installations.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';

/**
 * Silent Upgrader Skin
 *
 * Provides a silent upgrader skin that suppresses output during
 * plugin and theme installations performed by AI tools.
 */
class WP_MCP_AI_Upgrader_Skin extends WP_Upgrader_Skin {
	/**
	 * Override feedback to suppress output.
	 *
	 * @param string $string Feedback message.
	 * @param mixed  ...$args Optional arguments.
	 */
	public function feedback( $string, ...$args ) {
		// Suppress output.
	}

	/**
	 * Override header to suppress output.
	 */
	public function header() {
		// Suppress output.
	}

	/**
	 * Override footer to suppress output.
	 */
	public function footer() {
		// Suppress output.
	}

	/**
	 * Override error to suppress output.
	 *
	 * @param string|WP_Error $errors Error message or WP_Error object.
	 */
	public function error( $errors ) {
		// Suppress output.
	}
}
