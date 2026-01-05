<?php
/**
 * Base class for Assistant metaboxes.
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
	 * Get documentation URL for this metabox.
	 *
	 * Override this method in child classes to provide metabox-specific documentation links.
	 *
	 * @since 1.0.0
	 * @return string Documentation URL or empty string if no documentation available.
	 */
	public function get_documentation_url() {
		return '';
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

	/**
	 * Render documentation link for this metabox.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function render_documentation_link() {
		$documentation_url = $this->get_documentation_url();
		if ( empty( $documentation_url ) ) {
			return;
		}
		?>
		<p class="metabox-documentation" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dcdcde;">
			<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
			<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'View Documentation', 'wp-mcp-ai' ); ?>
				<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
			</a>
		</p>
		<?php
	}
}
