<?php
/**
 * Document Management Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Management Page class.
 */
class WP_MCP_AI_Reg_Document_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 23 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Document Management', 'mcp-ai-wpoos-pro' ),
			__( 'Documents', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			'wp-mcp-ai-reg-documents',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the document page.
	 */
	public static function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Document Management', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Manage documents, track expiry dates, and monitor compliance.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>
		<?php
	}
}

WP_MCP_AI_Reg_Document_Page::init();
