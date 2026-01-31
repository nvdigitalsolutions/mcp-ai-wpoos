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
