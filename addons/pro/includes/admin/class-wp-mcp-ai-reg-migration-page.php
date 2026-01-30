<?php
/**
 * Excel Migration Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excel Migration Page class.
 */
class WP_MCP_AI_Reg_Migration_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Import from Excel', 'mcp-ai-wpoos-pro' ),
			__( 'Import Excel', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-reg-migration',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the migration page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import from Excel', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Import existing registration data from Excel files into the system.', 'mcp-ai-wpoos-pro' ); ?></p>
			<div class="notice notice-info">
				<p><?php echo esc_html__( 'This tool helps you migrate data from your current Excel tracker to the WordPress-based system.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
		</div>
		<?php
	}
}

WP_MCP_AI_Reg_Migration_Page::init();
