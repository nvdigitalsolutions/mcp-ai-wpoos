<?php
/**
 * Architectural Drawing Research & Add Page
 *
 * Provides AI-assisted architectural drawing creation interface.
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
 * Architectural Drawing Research & Add Page
 */
class WP_MCP_AI_Architectural_Drawing_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type      = 'mcp_ai_arch_draw';
		$this->page_title     = __( 'Research & Add Drawings', 'mcp-ai-wpoos-pro' );
		$this->menu_title     = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug      = 'architectural-drawing-research';
		$this->settings_key   = 'wp_mcp_ai_architectural_drawing_settings';
		$this->capability     = 'edit_posts';
		$this->research_title = __( 'Architectural Drawing Research', 'mcp-ai-wpoos-pro' );

		parent::__construct( 'architectural_design' );
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'drawings' => __( 'Drawings', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get research instructions.
	 *
	 * @return string
	 */
	protected function get_research_instructions() {
		return __(
			'Use AI assistance to generate professional architectural drawings following AIA/NCS standards. Create floor plans, elevations, sections, details, and construction documents with proper layer naming and conventions.',
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
			__( 'Generate a floor plan for Project #123', 'mcp-ai-wpoos-pro' ),
			__( 'Create elevation drawings showing all four facades', 'mcp-ai-wpoos-pro' ),
			__( 'Generate building sections with dimensions and annotations', 'mcp-ai-wpoos-pro' ),
			__( 'Create detail drawings for window and door assemblies', 'mcp-ai-wpoos-pro' ),
			__( 'Generate a reflected ceiling plan with lighting layout', 'mcp-ai-wpoos-pro' ),
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
			'create_floor_plan_variations',
			'convert_sketch_to_floor_plan',
			'generate_3d_model',
			'render_architectural_view',
			'create_walkthrough_animation',
			'generate_construction_drawings',
			'generate_detail_drawings',
			'export_architectural_documents',
		);
	}

	/**
	 * Render additional page content.
	 */
	protected function render_additional_content() {
		?>
		<div class="drawing-types-reference" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'AIA/NCS Standard Drawing Types', 'mcp-ai-wpoos-pro' ); ?></h4>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px;">
				<div>
					<strong><?php esc_html_e( 'Floor Plans (A-FLOR):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Horizontal layouts showing rooms, walls, doors, windows', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div>
					<strong><?php esc_html_e( 'Elevations (A-ELEV):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Vertical facades showing exterior appearance', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div>
					<strong><?php esc_html_e( 'Sections (A-SECT):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Vertical cut-throughs revealing internal structure', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div>
					<strong><?php esc_html_e( 'Details (A-DETL):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Enlarged views of construction assemblies', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div>
					<strong><?php esc_html_e( 'Ceiling Plans (A-RCPN):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Overhead views showing ceiling and lighting', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div>
					<strong><?php esc_html_e( 'Site Plans (A-SITE):', 'mcp-ai-wpoos-pro' ); ?></strong>
					<p style="margin: 4px 0 0; font-size: 13px;"><?php esc_html_e( 'Building footprint and site context', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			</div>
		</div>

		<div class="drawing-best-practices" style="background: #e7f3e7; border-left: 4px solid #28a745; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Drawing Best Practices', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Assign unique drawing numbers (e.g., A-101, A-102)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Include scale notation (1/4" = 1\'-0", 1:100)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Track revisions with revision numbers and dates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Link drawings to their parent project', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Coordinate with specifications using CSI divisions', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
