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
	}

	/**
	 * Initialize the settings page.
	 */
	public static function init() {
		$instance = new self();
		$instance->init();
	}
}

// Initialize.
WP_MCP_AI_ECA_Settings_Page::init();
