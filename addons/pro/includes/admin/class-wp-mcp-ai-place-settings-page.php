<?php
/**
 * Place Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Place Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Place Settings Page
 */
class WP_MCP_AI_Place_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_place_settings';
		$this->post_type   = 'mcp_ai_place';
		$this->page_title  = __( 'Place Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'place-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.2.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Place Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Location and place management system with AI-powered research, geocoding, and structured data for businesses, landmarks, and points of interest.', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Place Management: Create and manage locations with full address details', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'AI Research: Discover and research places automatically', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Geocoding: Convert addresses to coordinates and reverse geocoding', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Search & Save: Search for places and save them to your database', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Structured Data: Store addresses, phone numbers, opening hours, ratings', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Categories: Organize places by type (restaurants, hotels, landmarks, etc.)', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Business directories and local search websites', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Travel guides and tourism platforms', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Real estate and property listings', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Event venues and location databases', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 1.2.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			'create_place'           => __( 'Create Place', 'mcp-ai-wpoos-pro' ),
			'list_places'            => __( 'List Places', 'mcp-ai-wpoos-pro' ),
			'get_place'              => __( 'Get Place', 'mcp-ai-wpoos-pro' ),
			'update_place'           => __( 'Update Place', 'mcp-ai-wpoos-pro' ),
			'delete_place'           => __( 'Delete Place', 'mcp-ai-wpoos-pro' ),
			'research_place'         => __( 'Research Place', 'mcp-ai-wpoos-pro' ),
			'search_and_save_places' => __( 'Search and Save Places', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add place-specific settings.
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
			<?php esc_html_e( 'Enable the Research & Add page for place research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create places using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
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

		// Add place-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Place_Settings_Page();
