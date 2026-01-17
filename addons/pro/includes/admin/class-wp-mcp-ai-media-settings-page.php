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

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Media_Settings_Page();
