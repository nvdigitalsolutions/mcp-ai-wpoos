<?php
/**
 * Product Research Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Research Page class.
 */
class WP_MCP_AI_Reg_Product_Research_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 21 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Product Research', 'mcp-ai-wpoos-pro' ),
			__( 'Product Research', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			'wp-mcp-ai-reg-product-research',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Product Research', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Research and add products for regulatory registration.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}
}

WP_MCP_AI_Reg_Product_Research_Page::init();
