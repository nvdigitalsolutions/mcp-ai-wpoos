<?php
/**
 * Base class for Profession metaboxes.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all profession metaboxes.
 *
 * Provides common functionality for metabox rendering, saving, and validation.
 */
abstract class WP_MCP_AI_Profession_Metabox_Base {

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
		return 'high';
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
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public function can_view( $post ) {
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Check if current user has permission to save this metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_save( $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}
}
