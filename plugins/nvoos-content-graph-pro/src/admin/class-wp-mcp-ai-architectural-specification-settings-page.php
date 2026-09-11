<?php
/**
 * Architectural_Specification_Settings_Page (ecosystem port - Wave F2, architectural-design admin slice).
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
 * Architectural Specification Settings Page
 */
class WP_MCP_AI_Architectural_Specification_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_architectural_specification_settings';
		$this->post_type   = 'mcp_ai_arch_spec';
		$this->page_title  = __( 'Specification Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'architectural-specification-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Specification AI features and Research & Add functionality.', 'nvoos-content-graph-pro' ) . '</p>';
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
				<h2><?php esc_html_e( 'About Specification Settings', 'nvoos-content-graph-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating construction specifications.', 'nvoos-content-graph-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'CSI MasterFormat Organization', 'nvoos-content-graph-pro' ); ?></h3>
				<p><?php esc_html_e( 'Specifications are organized using CSI MasterFormat divisions, the industry standard for construction specifications in North America:', 'nvoos-content-graph-pro' ); ?></p>
				<ul style="list-style: disc; margin-left: 20px; columns: 2; column-gap: 20px;">
					<li><strong>00</strong> - Procurement and Contracting</li>
					<li><strong>01</strong> - General Requirements</li>
					<li><strong>02</strong> - Existing Conditions</li>
					<li><strong>03</strong> - Concrete</li>
					<li><strong>04</strong> - Masonry</li>
					<li><strong>05</strong> - Metals</li>
					<li><strong>06</strong> - Wood, Plastics, Composites</li>
					<li><strong>07</strong> - Thermal & Moisture Protection</li>
					<li><strong>08</strong> - Openings</li>
					<li><strong>09</strong> - Finishes</li>
					<li><strong>10</strong> - Specialties</li>
					<li><strong>11</strong> - Equipment</li>
					<li><strong>12</strong> - Furnishings</li>
					<li><strong>13</strong> - Special Construction</li>
					<li><strong>14</strong> - Conveying Equipment</li>
					<li><strong>21</strong> - Fire Suppression</li>
					<li><strong>22</strong> - Plumbing</li>
					<li><strong>23</strong> - HVAC</li>
					<li><strong>26</strong> - Electrical</li>
					<li><strong>27</strong> - Communications</li>
				</ul>

				<h3><?php esc_html_e( 'Three-Part Specification Format', 'nvoos-content-graph-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong><?php esc_html_e( 'Part 1 - General:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Summary, references, submittals, quality assurance, delivery and storage', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Part 2 - Products:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Materials, manufacturers, fabrication, finishes, accessories', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Part 3 - Execution:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Preparation, installation, field quality control, cleaning, protection', 'nvoos-content-graph-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Specification_Settings_Page();
