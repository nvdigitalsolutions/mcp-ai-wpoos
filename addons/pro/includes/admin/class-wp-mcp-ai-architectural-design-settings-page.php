<?php
/**
 * Architectural Design Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Architectural Design Toolkit Settings Page Class
 */
class WP_MCP_AI_Architectural_Design_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'architectural_design';
		$this->toolkit_name     = __( 'Architectural Design Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_architectural_design_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-architectural-design-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-admin-multisite';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Architectural Design Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered architectural design toolkit with 16 professional tools for floor plan generation, 3D modeling, blueprint creation, code compliance, sustainability analysis, and cost estimation.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Floor Plan Generation: Create floor plans from natural language descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Space Optimization: AI-powered layout optimization for functionality and flow', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '3D Visualization: Generate 3D models and photorealistic renderings', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Blueprint Automation: Create professional construction drawing sets', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Code Compliance: Automated building code and zoning validation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Sustainability Analysis: LEED scoring and energy efficiency calculations', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Cost Estimation: AI-powered material takeoffs and cost projections', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Multi-format Export: PDF blueprints, DWG, IFC, and 3D model formats', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Tool Categories', 'mcp-ai-wpoos-pro' ); ?></h3>
			<div class="tool-categories">
				<div class="tool-category">
					<h4><?php esc_html_e( 'Floor Planning & Space Design (4 tools)', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'AI-powered floor plan generation, layout optimization, and sketch conversion', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div class="tool-category">
					<h4><?php esc_html_e( '3D Modeling & Visualization (3 tools)', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( '3D model generation, photorealistic rendering, and virtual tours', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div class="tool-category">
					<h4><?php esc_html_e( 'Documentation & Blueprints (3 tools)', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Construction drawings, detail sheets, and multi-format export', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div class="tool-category">
					<h4><?php esc_html_e( 'Analysis & Compliance (3 tools)', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Building code validation, structural analysis, and sustainability metrics', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div class="tool-category">
					<h4><?php esc_html_e( 'Estimation & Scheduling (3 tools)', 'mcp-ai-wpoos-pro' ); ?></h4>
					<p><?php esc_html_e( 'Material scheduling, cost estimation, and project timelines', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Architects:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Rapid concept generation and client presentations', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Designers:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Space planning and interior layouts', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Contractors:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Material takeoffs and cost estimation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Developers:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Feasibility studies and code compliance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Students:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Learning tool and portfolio projects', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Architectural Design Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Unit System', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_unit_system" class="regular-text">
							<option value="imperial"><?php esc_html_e( 'Imperial (feet, inches)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="metric"><?php esc_html_e( 'Metric (meters, centimeters)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default measurement system for floor plans and dimensions', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Building Code Standard', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="building_code_standard" class="regular-text">
							<option value="ibc"><?php esc_html_e( 'International Building Code (IBC)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="irc"><?php esc_html_e( 'International Residential Code (IRC)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="local"><?php esc_html_e( 'Local/Custom', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Primary building code for compliance validation', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Export Format', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_export_format" class="regular-text">
							<option value="pdf"><?php esc_html_e( 'PDF (Universal)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="dwg"><?php esc_html_e( 'DWG (AutoCAD)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="ifc"><?php esc_html_e( 'IFC (BIM Standard)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="svg"><?php esc_html_e( 'SVG (Vector)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format for exporting architectural documents', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rendering Quality', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="rendering_quality" class="regular-text">
							<option value="draft"><?php esc_html_e( 'Draft (Fast)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="standard"><?php esc_html_e( 'Standard (Balanced)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="high"><?php esc_html_e( 'High (Photorealistic)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default quality for 3D renderings and visualizations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable GPU Rendering', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_gpu_rendering" value="1" />
							<?php esc_html_e( 'Use GPU acceleration for faster rendering (requires remote sites)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sustainability Framework', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="sustainability_framework" class="regular-text">
							<option value="leed"><?php esc_html_e( 'LEED (Leadership in Energy and Environmental Design)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="breeam"><?php esc_html_e( 'BREEAM (Building Research Establishment Environmental Assessment Method)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="energy_star"><?php esc_html_e( 'Energy Star', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="passive_house"><?php esc_html_e( 'Passive House', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Framework for sustainability analysis and scoring', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'generate_floor_plan'              => __( 'Generate Floor Plan', 'mcp-ai-wpoos-pro' ),
			'optimize_space_layout'            => __( 'Optimize Space Layout', 'mcp-ai-wpoos-pro' ),
			'create_floor_plan_variations'     => __( 'Create Floor Plan Variations', 'mcp-ai-wpoos-pro' ),
			'convert_sketch_to_floor_plan'     => __( 'Convert Sketch to Floor Plan', 'mcp-ai-wpoos-pro' ),
			'generate_3d_model'                => __( 'Generate 3D Model', 'mcp-ai-wpoos-pro' ),
			'render_architectural_view'        => __( 'Render Architectural View', 'mcp-ai-wpoos-pro' ),
			'create_walkthrough_animation'     => __( 'Create Walkthrough Animation', 'mcp-ai-wpoos-pro' ),
			'generate_construction_drawings'   => __( 'Generate Construction Drawings', 'mcp-ai-wpoos-pro' ),
			'generate_detail_drawings'         => __( 'Generate Detail Drawings', 'mcp-ai-wpoos-pro' ),
			'export_architectural_documents'   => __( 'Export Architectural Documents', 'mcp-ai-wpoos-pro' ),
			'check_building_code_compliance'   => __( 'Check Building Code Compliance', 'mcp-ai-wpoos-pro' ),
			'analyze_structural_feasibility'   => __( 'Analyze Structural Feasibility', 'mcp-ai-wpoos-pro' ),
			'calculate_sustainability_metrics' => __( 'Calculate Sustainability Metrics', 'mcp-ai-wpoos-pro' ),
			'generate_material_schedule'       => __( 'Generate Material Schedule', 'mcp-ai-wpoos-pro' ),
			'estimate_construction_cost'       => __( 'Estimate Construction Cost', 'mcp-ai-wpoos-pro' ),
			'generate_construction_timeline'   => __( 'Generate Construction Timeline', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Architectural_Design_Settings_Page();
}
