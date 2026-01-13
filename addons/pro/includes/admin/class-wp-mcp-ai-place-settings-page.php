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
}

// Initialize.
new WP_MCP_AI_Place_Settings_Page();
