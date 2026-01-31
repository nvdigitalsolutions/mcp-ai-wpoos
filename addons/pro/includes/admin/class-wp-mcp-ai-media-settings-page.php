<?php
/**
 * Media Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Media Design & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Media Settings Page
 */
class WP_MCP_AI_Media_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_media_settings';
		$this->post_type   = 'mcp_ai_media_tpl';
		$this->page_title  = __( 'Media Toolkit Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'media-toolkit-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Add settings submenu page.
	 */
	public function add_settings_page() {
		// Media templates are under upload.php (Media menu).
		add_submenu_page(
			'upload.php',
			$this->page_title,
			$this->menu_title,
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add media-specific settings.
		add_settings_field(
			'enable_design_page',
			__( 'Enable Design & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_design_page_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_ai_design',
			__( 'Enable AI Design Generation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_ai_design_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_template_category',
			__( 'Default Template Category', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_template_category_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_smart_tagging',
			__( 'Enable Smart Tagging', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_smart_tagging_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'max_collection_size',
			__( 'Max Collection Size', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_max_collection_size_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable design page field.
	 */
	public function render_enable_design_page_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_design_page'] ) ? (bool) $options['enable_design_page'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_design_page]"
				id="enable_design_page"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Design & Add page for media template design', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Design & Add page to create media templates using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable AI design field.
	 */
	public function render_enable_ai_design_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_ai_design'] ) ? (bool) $options['enable_ai_design'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_ai_design]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Allow AI-powered design generation for media', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render default template category field.
	 */
	public function render_default_template_category_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_template_category'] ) ? $options['default_template_category'] : 'social_media';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_template_category]" class="regular-text">
			<option value="social_media" <?php selected( $value, 'social_media' ); ?>><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="blog_graphics" <?php selected( $value, 'blog_graphics' ); ?>><?php esc_html_e( 'Blog Graphics', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="marketing" <?php selected( $value, 'marketing' ); ?>><?php esc_html_e( 'Marketing Materials', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="presentations" <?php selected( $value, 'presentations' ); ?>><?php esc_html_e( 'Presentations', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default category when browsing templates', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable smart tagging field.
	 */
	public function render_enable_smart_tagging_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_smart_tagging'] ) ? (bool) $options['enable_smart_tagging'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_smart_tagging]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Automatically tag uploaded media using AI', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render max collection size field.
	 */
	public function render_max_collection_size_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['max_collection_size'] ) ? absint( $options['max_collection_size'] ) : 100;

		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_collection_size]" value="<?php echo esc_attr( $value ); ?>" min="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum number of items per media collection', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Media Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Advanced media management with AI-powered design generation, template library, and collection management.', 'mcp-ai-wpoos-pro' ); ?></p>

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
	 * Get tools list.
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

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add media-specific sanitization.
		if ( isset( $input['enable_design_page'] ) ) {
			$sanitized['enable_design_page'] = (bool) $input['enable_design_page'];
		} else {
			$sanitized['enable_design_page'] = false;
		}

		if ( isset( $input['enable_ai_design'] ) ) {
			$sanitized['enable_ai_design'] = (bool) $input['enable_ai_design'];
		} else {
			$sanitized['enable_ai_design'] = false;
		}

		if ( isset( $input['default_template_category'] ) ) {
			$sanitized['default_template_category'] = sanitize_text_field( $input['default_template_category'] );
		}

		if ( isset( $input['enable_smart_tagging'] ) ) {
			$sanitized['enable_smart_tagging'] = (bool) $input['enable_smart_tagging'];
		} else {
			$sanitized['enable_smart_tagging'] = false;
		}

		if ( isset( $input['max_collection_size'] ) ) {
			$sanitized['max_collection_size'] = absint( $input['max_collection_size'] );
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Media_Settings_Page();
