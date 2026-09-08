<?php
/**
 * Calendar Booking data layer (ecosystem port — Wave F2, calendar-booking data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/metaboxes/class-wp-mcp-ai-staff-metabox-base.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the metabox requires resolve from the
 * addon's `src/metaboxes/` copies (same batch).
 *
 * @package NvoosContentGraphPro
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
			$message = __( 'You do not have permission to access this section.', 'nvoos-content-graph-pro' );
		}
		echo '<p>' . esc_html( $message ) . '</p>';
	}
}
