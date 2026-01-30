<?php
/**
 * Product Settings Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Settings Page class.
 */
class WP_MCP_AI_Reg_Product_Settings_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai',
			__( 'Product Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Product Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-reg-product-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Product Settings', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Configure product master data settings for regulatory registration.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}
}

WP_MCP_AI_Reg_Product_Settings_Page::init();
