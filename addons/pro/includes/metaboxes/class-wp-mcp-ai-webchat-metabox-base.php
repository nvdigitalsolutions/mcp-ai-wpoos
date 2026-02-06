<?php
/**
 * Base class for WebChat metaboxes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all webchat metaboxes.
 *
 * Provides common functionality for metabox rendering, saving, and validation.
 */
abstract class WP_MCP_AI_WebChat_Metabox_Base {

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

	/**
	 * Get documentation URL for this metabox.
	 *
	 * Override this method in child classes to provide metabox-specific documentation links.
	 *
	 * @return string Documentation URL or empty string if no documentation available.
	 */
	public function get_documentation_url() {
		return '';
	}

	/**
	 * Render documentation link for this metabox.
	 *
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
				<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos-pro' ); ?>
				<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
			</a>
		</p>
		<?php
	}
}
