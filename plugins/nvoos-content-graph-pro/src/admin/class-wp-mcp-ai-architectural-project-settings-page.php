<?php
/**
 * Architectural_Project_Settings_Page (ecosystem port - Wave F2, architectural-design admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root for the base-class requires (the
 * `__DIR__` trait requires stay portable).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Design Project Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'architectural-project-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Design Project AI features and Research & Add functionality.', 'nvoos-content-graph-pro' ) . '</p>';
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
				__( 'Settings saved successfully.', 'nvoos-content-graph-pro' ),
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
				submit_button( __( 'Save Settings', 'nvoos-content-graph-pro' ) );
				?>
			</form>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'About Design Project Settings', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating design projects.', 'nvoos-content-graph-pro' ); ?></p>
				<p><?php esc_html_e( 'The assistant you select will be used in the research chat interface. The assistant\'s own provider and model configuration will be used for generating architectural content.', 'nvoos-content-graph-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'Industry Standards', 'nvoos-content-graph-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong><?php esc_html_e( 'Project Types:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Residential, Commercial, Industrial, Institutional, Mixed-Use', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Design Phases:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Concept, Schematic, Design Development, Construction Documents, Bidding, Execution', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'AI Tools Available:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( '16 professional tools for floor plans, 3D modeling, code compliance, cost estimation', 'nvoos-content-graph-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Project_Settings_Page();
