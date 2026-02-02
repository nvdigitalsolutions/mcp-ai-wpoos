<?php
/**
 * Media Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Media Toolkit Settings Page Class
 */
class WP_MCP_AI_Media_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'media';
		$this->toolkit_name     = __( 'Media Settings', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_media_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-media-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-admin-media';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Media Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Advanced media management with AI-powered design generation, template library, and collection management.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Design Generation: Create graphics and designs using AI-powered tools', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Template Library: Access and manage design templates for various use cases', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Media Collections: Organize media files into collections for better management', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk Operations: Perform batch operations on multiple media files', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Smart Tagging: Automatically tag media with AI-powered content recognition', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Remote Media Sync: Synchronize media across multiple WordPress sites', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Media Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable AI Design Generation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_ai_design" value="1" />
							<?php esc_html_e( 'Allow AI-powered design generation for media', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Template Category', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_template_category" class="regular-text">
							<option value="social_media"><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="blog_graphics"><?php esc_html_e( 'Blog Graphics', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="marketing"><?php esc_html_e( 'Marketing Materials', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="presentations"><?php esc_html_e( 'Presentations', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default category when browsing templates', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Smart Tagging', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_smart_tagging" value="1" />
							<?php esc_html_e( 'Automatically tag uploaded media using AI', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Remote Media Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_remote_sync" value="1" />
							<?php esc_html_e( 'Synchronize media with remote WordPress sites (requires Remote Sites toolkit)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Collection Size', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="max_collection_size" value="100" min="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum number of items per media collection', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'generate_design_ai'      => __( 'Generate Design (AI)', 'mcp-ai-wpoos-pro' ),
			'browse_template_library' => __( 'Browse Template Library', 'mcp-ai-wpoos-pro' ),
			'apply_template'          => __( 'Apply Template', 'mcp-ai-wpoos-pro' ),
			'create_media_collection' => __( 'Create Media Collection', 'mcp-ai-wpoos-pro' ),
			'add_to_collection'       => __( 'Add to Collection', 'mcp-ai-wpoos-pro' ),
			'bulk_tag_media'          => __( 'Bulk Tag Media', 'mcp-ai-wpoos-pro' ),
			'bulk_resize_media'       => __( 'Bulk Resize Media', 'mcp-ai-wpoos-pro' ),
			'bulk_compress_media'     => __( 'Bulk Compress Media', 'mcp-ai-wpoos-pro' ),
			'sync_media_to_remote'    => __( 'Sync Media to Remote', 'mcp-ai-wpoos-pro' ),
			'import_media_from_url'   => __( 'Import Media from URL', 'mcp-ai-wpoos-pro' ),
			'generate_media_report'   => __( 'Generate Media Report', 'mcp-ai-wpoos-pro' ),
			'find_unused_media'       => __( 'Find Unused Media', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Media_Toolkit_Settings_Page();
}
