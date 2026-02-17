<?php
/**
 * Base class for Staff metaboxes.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all staff metaboxes.
 *
 * Provides common functionality for metabox rendering, saving, and validation.
 *
 * @since 2.6.0
 */
abstract class WP_MCP_AI_Staff_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Get the metabox context (normal, side, advanced).
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get the metabox priority (high, core, default, low).
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'default';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	abstract public function render( $post );

	/**
	 * Save metabox data.
	 *
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
	 * @return bool
	 */
	protected function can_view() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if current user has permission to edit this metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function can_edit( $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Render a permission denied message.
	 *
	 * @param string $message Optional custom message.
	 * @return void
	 */
	protected function render_permission_denied( $message = '' ) {
		if ( empty( $message ) ) {
			$message = __( 'You do not have permission to access this section.', 'mcp-ai-wpoos-pro' );
		}
		echo '<p>' . esc_html( $message ) . '</p>';
	}
}
