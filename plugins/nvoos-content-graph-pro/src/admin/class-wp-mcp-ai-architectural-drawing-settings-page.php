<?php
/**
 * Architectural_Drawing_Settings_Page (ecosystem port - Wave F2, architectural-design admin slice).
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
 * Architectural Drawing Settings Page
 */
class WP_MCP_AI_Architectural_Drawing_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_architectural_drawing_settings';
		$this->post_type   = 'mcp_ai_arch_draw';
		$this->page_title  = __( 'Drawing Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'architectural-drawing-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Drawing AI features and Research & Add functionality.', 'nvoos-content-graph-pro' ) . '</p>';
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
				<h2><?php esc_html_e( 'About Drawing Settings', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating architectural drawings.', 'nvoos-content-graph-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'AIA/NCS Standard Drawing Types', 'nvoos-content-graph-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong>A-FLOR:</strong> <?php esc_html_e( 'Floor Plans - Horizontal layouts showing rooms, walls, doors, windows', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong>A-ELEV:</strong> <?php esc_html_e( 'Elevations - Vertical facades showing exterior appearance', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong>A-SECT:</strong> <?php esc_html_e( 'Sections - Vertical cut-throughs revealing internal structure', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong>A-DETL:</strong> <?php esc_html_e( 'Details - Enlarged views of construction assemblies', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong>A-RCPN:</strong> <?php esc_html_e( 'Reflected Ceiling Plans - Overhead views showing ceiling and lighting', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong>A-SITE:</strong> <?php esc_html_e( 'Site Plans - Building footprint and site context', 'nvoos-content-graph-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Drawing Management Best Practices', 'nvoos-content-graph-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'Assign unique drawing numbers (e.g., A-101, A-102)', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( 'Include scale notation (1/4" = 1\'-0", 1:100)', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( 'Track revisions with revision numbers and dates', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( 'Link drawings to their parent project', 'nvoos-content-graph-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Drawing_Settings_Page();
