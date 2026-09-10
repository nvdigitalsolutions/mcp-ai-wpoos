<?php
/**
 * metaboxes/class-wp-mcp-ai-eca-metabox-base.php (ecosystem port — Wave F5, eca-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/metaboxes/class-wp-mcp-ai-eca-metabox-base.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the byte-identical `defined( 'WP_MCP_AI_PRO_VERSION' )`
 * pro-active checks stay as-is — defined-const checks, standalone-safe).
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
 * Abstract base class for all ECA metaboxes.
 *
 * Provides common functionality for metabox rendering, saving, and validation.
 */
abstract class WP_MCP_AI_ECA_Metabox_Base {

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
			$message = __( 'You do not have permission to access this section.', 'nvoos-content-graph-pro' );
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
				<?php esc_html_e( 'View Documentation', 'nvoos-content-graph-pro' ); ?>
				<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
			</a>
		</p>
		<?php
	}
}
