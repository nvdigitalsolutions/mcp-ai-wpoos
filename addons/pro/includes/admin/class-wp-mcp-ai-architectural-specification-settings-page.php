<?php
/**
 * Architectural Specification Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Architectural Specification functionality.
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
 * Architectural Specification Settings Page
 */
class WP_MCP_AI_Architectural_Specification_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_architectural_specification_settings';
		$this->post_type   = 'mcp_ai_arch_spec';
		$this->page_title  = __( 'Specification Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'architectural-specification-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Architectural Specification AI features and Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
				<h2><?php esc_html_e( 'About Specification Settings', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'These settings control which AI assistant is used for the Research & Add functionality when creating construction specifications.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<h3><?php esc_html_e( 'CSI MasterFormat Organization', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'Specifications are organized using CSI MasterFormat divisions, the industry standard for construction specifications in North America:', 'mcp-ai-wpoos-pro' ); ?></p>
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

				<h3><?php esc_html_e( 'Three-Part Specification Format', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><strong><?php esc_html_e( 'Part 1 - General:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Summary, references, submittals, quality assurance, delivery and storage', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Part 2 - Products:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Materials, manufacturers, fabrication, finishes, accessories', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Part 3 - Execution:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Preparation, installation, field quality control, cleaning, protection', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

// Initialize settings page.
new WP_MCP_AI_Architectural_Specification_Settings_Page();
