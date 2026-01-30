<?php
/**
 * Registration Dashboard Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration Dashboard Page class.
 */
class WP_MCP_AI_Registration_Dashboard_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 22 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai',
			__( 'Registration Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Reg Dashboard', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			'wp-mcp-ai-registration-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Registration Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Track registration status across all countries and products.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}
}

WP_MCP_AI_Registration_Dashboard_Page::init();
