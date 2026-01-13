<?php
/**
 * Project Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Project Management functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Project Settings Page
 */
class WP_MCP_AI_Project_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_project_settings';
		$this->post_type   = 'mcp_ai_project';
		$this->page_title  = __( 'Project Management Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'project-settings';

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
			__( 'AI Assistant Configuration', 'mcp-ai-wpoos-pro' ),
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
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Project Management AI features.', 'mcp-ai-wpoos-pro' ) . '</p>';
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

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Project_Settings_Page();
