<?php
/**
 * Architectural Specification Research & Add Page
 *
 * Provides AI-assisted specification writing interface following CSI MasterFormat.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Architectural Specification Research & Add Page
 */
class WP_MCP_AI_Architectural_Specification_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type      = 'mcp_ai_arch_spec';
		$this->page_title     = __( 'Research & Add Specifications', 'mcp-ai-wpoos-pro' );
		$this->menu_title     = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug      = 'architectural-specification-research';
		$this->settings_key   = 'wp_mcp_ai_architectural_specification_settings';
		$this->capability     = 'edit_posts';
		$this->research_title = __( 'Architectural Specification Research', 'mcp-ai-wpoos-pro' );

		parent::__construct( 'architectural_design' );
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'specifications' => __( 'Specifications', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get research instructions.
	 *
	 * @return string
	 */
	protected function get_research_instructions() {
		return __(
			'Use AI assistance to research and write construction specifications following CSI MasterFormat standards. Create detailed technical specifications, material requirements, and installation procedures organized by division.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get research prompt suggestions.
	 *
	 * @return array
	 */
	protected function get_research_prompt_suggestions() {
		return array(
			__( 'Write a specification for Division 03 - Concrete work', 'mcp-ai-wpoos-pro' ),
			__( 'Create specifications for Division 09 - Interior finishes', 'mcp-ai-wpoos-pro' ),
			__( 'Generate Division 07 specifications for roofing and waterproofing', 'mcp-ai-wpoos-pro' ),
			__( 'Research Division 26 electrical specifications and requirements', 'mcp-ai-wpoos-pro' ),
			__( 'Create a material schedule for Division 08 - Doors and windows', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get available tools for this research page.
	 *
	 * @return array
	 */
	protected function get_available_tools() {
		return array(
			'generate_material_schedule',
			'estimate_construction_cost',
			'check_building_code_compliance',
			'analyze_structural_feasibility',
			'calculate_sustainability_metrics',
		);
	}

	/**
	 * Render additional page content.
	 */
	protected function render_additional_content() {
		?>
		<div class="csi-divisions-reference" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'CSI MasterFormat Divisions', 'mcp-ai-wpoos-pro' ); ?></h4>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 8px; font-size: 13px;">
				<div><strong>00</strong> - Procurement and Contracting</div>
				<div><strong>01</strong> - General Requirements</div>
				<div><strong>02</strong> - Existing Conditions</div>
				<div><strong>03</strong> - Concrete</div>
				<div><strong>04</strong> - Masonry</div>
				<div><strong>05</strong> - Metals</div>
				<div><strong>06</strong> - Wood, Plastics, Composites</div>
				<div><strong>07</strong> - Thermal & Moisture Protection</div>
				<div><strong>08</strong> - Openings</div>
				<div><strong>09</strong> - Finishes</div>
				<div><strong>10</strong> - Specialties</div>
				<div><strong>11</strong> - Equipment</div>
				<div><strong>12</strong> - Furnishings</div>
				<div><strong>13</strong> - Special Construction</div>
				<div><strong>14</strong> - Conveying Equipment</div>
				<div><strong>21</strong> - Fire Suppression</div>
				<div><strong>22</strong> - Plumbing</div>
				<div><strong>23</strong> - HVAC</div>
				<div><strong>26</strong> - Electrical</div>
				<div><strong>27</strong> - Communications</div>
				<div><strong>28</strong> - Electronic Safety & Security</div>
				<div><strong>31</strong> - Earthwork</div>
				<div><strong>32</strong> - Exterior Improvements</div>
				<div><strong>33</strong> - Utilities</div>
			</div>
		</div>

		<div class="specification-best-practices" style="background: #e7f3e7; border-left: 4px solid #28a745; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Specification Writing Best Practices', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Organize by CSI MasterFormat division numbers', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Include Part 1 (General), Part 2 (Products), Part 3 (Execution)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Specify manufacturer names, model numbers, and acceptable alternatives', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Reference industry standards (ASTM, ANSI, UL, etc.)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Coordinate specifications with drawings using keynotes', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Include quality assurance requirements and testing procedures', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<div class="specification-structure" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Three-Part Specification Structure', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p style="margin: 8px 0;">
				<strong><?php esc_html_e( 'Part 1 - General:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Summary, references, submittals, quality assurance, delivery and storage', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p style="margin: 8px 0;">
				<strong><?php esc_html_e( 'Part 2 - Products:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Materials, manufacturers, fabrication, finishes, accessories', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p style="margin: 8px 0;">
				<strong><?php esc_html_e( 'Part 3 - Execution:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Preparation, installation, field quality control, cleaning, protection', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}
}
