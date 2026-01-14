<?php
/**
 * Product Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for WooCommerce Product Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Product Settings Page
 */
class WP_MCP_AI_Product_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_product_settings';
		$this->post_type   = 'product';
		$this->page_title  = __( 'Product Research Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Research Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'product-research-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			$this->option_name . '_group',
			$this->option_name,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			$this->option_name . '_section',
			__( 'Research & Add Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'assistant_id',
			__( 'Assistant', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_assistant_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for product research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create WooCommerce products using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
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
		$sanitized = array();

		if ( isset( $input['assistant_id'] ) ) {
			$sanitized['assistant_id'] = absint( $input['assistant_id'] );
		}

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Product_Settings_Page();
