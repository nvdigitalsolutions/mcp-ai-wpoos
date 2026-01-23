<?php
/**
 * Member Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Health & Wellness Member (Family & Pets) Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Member Settings Page
 */
class WP_MCP_AI_Member_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_member_settings';
		$this->post_type   = 'mcp_ai_member';
		$this->page_title  = __( 'Member Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'member-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add member-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_pet_members',
			__( 'Enable Pet Members', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_pet_members_field' ),
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
			<?php esc_html_e( 'Enable the Research & Add page for member management', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create family members and pets using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable pet members field.
	 */
	public function render_enable_pet_members_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_pet_members'] ) ? (bool) $options['enable_pet_members'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_pet_members]"
				id="enable_pet_members"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable pet member management', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can manage pet health records in addition to human family members.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		?>
		<p>
			<?php
			esc_html_e(
				'Configure AI assistance settings for Health & Wellness member management. Select an AI assistant to help with creating and managing family member and pet health records.',
				'mcp-ai-wpoos-pro'
			);
			?>
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

		// Add member-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		if ( isset( $input['enable_pet_members'] ) ) {
			$sanitized['enable_pet_members'] = (bool) $input['enable_pet_members'];
		} else {
			$sanitized['enable_pet_members'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Member_Settings_Page();
