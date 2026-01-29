<?php
/**
 * Architectural Project Research & Add Page
 *
 * Provides AI-assisted design project creation interface for Architectural Design Toolkit.
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
 * Architectural Project Research & Add Page
 */
class WP_MCP_AI_Architectural_Project_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type      = 'mcp_ai_arch_proj';
		$this->page_title     = __( 'Research & Add Design Projects', 'mcp-ai-wpoos-pro' );
		$this->menu_title     = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug      = 'architectural-project-research';
		$this->settings_key   = 'wp_mcp_ai_architectural_project_settings';
		$this->capability     = 'edit_posts';
		$this->research_title = __( 'Architectural Design Project Research', 'mcp-ai-wpoos-pro' );

		parent::__construct( 'architectural_design' );
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'projects' => __( 'Design Projects', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get research instructions.
	 *
	 * @return string
	 */
	protected function get_research_instructions() {
		return __(
			'Use AI assistance to research and plan architectural design projects. The AI can help you generate floor plans, analyze site conditions, research building codes, and create comprehensive project documentation following AIA and industry standards.',
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
			__( 'Create a new residential project with 3 bedrooms, 2 bathrooms, 2000 sq ft', 'mcp-ai-wpoos-pro' ),
			__( 'Generate a floor plan for a commercial office space', 'mcp-ai-wpoos-pro' ),
			__( 'Analyze building code requirements for a mixed-use development', 'mcp-ai-wpoos-pro' ),
			__( 'Create project documentation for a sustainable residential design', 'mcp-ai-wpoos-pro' ),
			__( 'Research zoning requirements for an urban infill project', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get available tools for this research page.
	 *
	 * @return array
	 */
	protected function get_available_tools() {
		return array(
			'generate_floor_plan',
			'optimize_space_layout',
			'create_floor_plan_variations',
			'generate_3d_model',
			'check_building_code_compliance',
			'analyze_structural_feasibility',
			'calculate_sustainability_metrics',
			'estimate_construction_cost',
			'generate_construction_timeline',
		);
	}

	/**
	 * Render additional page content.
	 */
	protected function render_additional_content() {
		?>
		<div class="architectural-research-tips" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Architectural Design Best Practices', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Define project type (Residential, Commercial, Industrial, Institutional, Mixed-Use)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Specify square footage, number of rooms, and functional requirements', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Include site constraints, zoning requirements, and building codes', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Consider sustainability goals (LEED, Energy Star, Passive House)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Document budget constraints and timeline expectations', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<div class="architectural-standards-info" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Industry Standards Reference', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p style="margin: 8px 0;">
				<?php
				echo wp_kses_post(
					__( '<strong>AIA Standards:</strong> Floor plans, elevations, sections, and details follow American Institute of Architects conventions.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p style="margin: 8px 0;">
				<?php
				echo wp_kses_post(
					__( '<strong>Building Codes:</strong> IBC (International Building Code) and IRC (International Residential Code) compliance checks.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p style="margin: 8px 0;">
				<?php
				echo wp_kses_post(
					__( '<strong>CSI MasterFormat:</strong> Specifications organized by standard construction divisions.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
