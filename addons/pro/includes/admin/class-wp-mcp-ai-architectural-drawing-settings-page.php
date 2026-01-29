<?php
/**
 * Architectural Drawing Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Architectural Drawing functionality.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Architectural Drawing Settings Page
 */
class WP_MCP_AI_Architectural_Drawing_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_architectural_drawing_settings';
		$this->post_type   = 'mcp_ai_arch_draw';
		$this->page_title  = __( 'Drawing Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'architectural-drawing-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Drawing AI features and Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check for settings update.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core handles nonce verification for settings pages.
			add_settings_error(
				$this->option_name . '_messages',
				$this->option_name . '_message',
				__( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ),
				'success'
			);
		}

		settings_errors( $this->option_name . '_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name . '_group' );
				do_settings_sections( $this->option_name );
				submit_button( __( 'Save Settings', 'mcp-ai-wpoos-pro' ) );
				?>
			</form>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'About Drawing Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating architectural drawings.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'AIA/NCS Standard Drawing Types', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong>A-FLOR:</strong> <?php esc_html_e( 'Floor Plans - Horizontal layouts showing rooms, walls, doors, windows', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>A-ELEV:</strong> <?php esc_html_e( 'Elevations - Vertical facades showing exterior appearance', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>A-SECT:</strong> <?php esc_html_e( 'Sections - Vertical cut-throughs revealing internal structure', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>A-DETL:</strong> <?php esc_html_e( 'Details - Enlarged views of construction assemblies', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>A-RCPN:</strong> <?php esc_html_e( 'Reflected Ceiling Plans - Overhead views showing ceiling and lighting', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>A-SITE:</strong> <?php esc_html_e( 'Site Plans - Building footprint and site context', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Drawing Management Best Practices', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'Assign unique drawing numbers (e.g., A-101, A-102)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Include scale notation (1/4" = 1\'-0", 1:100)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Track revisions with revision numbers and dates', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Link drawings to their parent project', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Drawing_Settings_Page();
