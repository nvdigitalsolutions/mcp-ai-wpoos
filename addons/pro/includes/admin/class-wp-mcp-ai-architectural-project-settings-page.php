<?php
/**
 * Architectural Project Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Architectural Design Project functionality.
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
 * Architectural Project Settings Page
 */
class WP_MCP_AI_Architectural_Project_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_architectural_project_settings';
		$this->post_type   = 'mcp_ai_arch_proj';
		$this->page_title  = __( 'Design Project Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'architectural-project-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Design Project AI features and Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
				<h2><?php esc_html_e( 'About Design Project Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating design projects.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p><?php esc_html_e( 'The assistant you select will be used in the research chat interface. The assistant\'s own provider and model configuration will be used for generating architectural content.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'Industry Standards', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong><?php esc_html_e( 'Project Types:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Residential, Commercial, Industrial, Institutional, Mixed-Use', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Design Phases:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Concept, Schematic, Design Development, Construction Documents, Bidding, Execution', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'AI Tools Available:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( '16 professional tools for floor plans, 3D modeling, code compliance, cost estimation', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Project_Settings_Page();
