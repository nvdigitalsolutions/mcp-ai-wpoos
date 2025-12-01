<?php
/**
 * Base class for Assistant metaboxes.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all assistant metaboxes.
 *
 * Provides common functionality for metabox rendering, saving, and validation.
 *
 * @since 1.0.0
 */
abstract class WP_MCP_AI_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Get the metabox context (normal, side, advanced).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get the metabox priority (high, core, default, low).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_priority() {
		return 'default';
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	abstract public function render( $post );

	/**
	 * Save metabox data.
	 *
	 * @since 1.0.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Override in child classes if needed.
	}

	/**
	 * Check if current user has permission to view this metabox.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function can_view() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Render a permission denied message.
	 *
	 * @since 1.0.0
	 * @param string $message Optional custom message.
	 * @return void
	 */
	protected function render_permission_denied( $message = '' ) {
		if ( empty( $message ) ) {
			$message = __( 'You do not have permission to access this section.', 'wp-mcp-ai' );
		}
		echo '<p>' . esc_html( $message ) . '</p>';
	}
}
