<?php
/**
 * Country Configuration Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Country Configuration Page class.
 */
class WP_MCP_AI_Reg_Country_Config_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 24 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Country Requirements', 'mcp-ai-wpoos-pro' ),
			__( 'Country Requirements', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-reg-country-config',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the country config page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Country Requirements Configuration', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Configure regulatory requirements for different countries and authorities.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}
}

WP_MCP_AI_Reg_Country_Config_Page::init();
