<?php
/**
 * ECA Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for ECA Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * ECA Settings Page
 */
class WP_MCP_AI_ECA_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_eca_settings';
		$this->post_type   = 'mcp_ai_eca';
		$this->page_title  = __( 'ECA Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'eca-settings';

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
		<h2><?php esc_html_e( 'Extra-Curricular Activities (ECA) Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Comprehensive toolkit for managing school extra-curricular activities including creation, enrollment, research, and iSAMS integration.', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Activity Management: Create, update, and track extra-curricular activities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Student Enrollment: Manage student enrollments and participation tracking', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'AI Research: Discover new activity ideas and program structures', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'iSAMS Integration: Sync activities from iSAMS school management system', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Activity Analytics: Track participation rates and activity popularity', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Schools managing clubs, sports teams, and after-school programs', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Enrichment program coordinators planning new activities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Educational institutions syncing with iSAMS', 'mcp-ai-wpoos-pro' ); ?></li>
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
			'create_eca'           => __( 'Create ECA', 'mcp-ai-wpoos-pro' ),
			'list_ecas'            => __( 'List ECAs', 'mcp-ai-wpoos-pro' ),
			'get_eca'              => __( 'Get ECA', 'mcp-ai-wpoos-pro' ),
			'update_eca'           => __( 'Update ECA', 'mcp-ai-wpoos-pro' ),
			'delete_eca'           => __( 'Delete ECA', 'mcp-ai-wpoos-pro' ),
			'enroll_student_eca'   => __( 'Enroll Student in ECA', 'mcp-ai-wpoos-pro' ),
			'research_eca'         => __( 'Research ECA', 'mcp-ai-wpoos-pro' ),
			'sync_ecas_from_isams' => __( 'Sync ECAs from iSAMS', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add ECA-specific settings.
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
			<?php esc_html_e( 'Enable the Research & Add page for ECA research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create extra-curricular activities using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
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

		// Add ECA-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_ECA_Settings_Page();
