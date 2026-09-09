<?php
/**
 * Architectural_Design_Settings_Page (ecosystem port - Wave F2, architectural-design admin slice).
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

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Architectural Design Toolkit Settings Page Class
 */
class WP_MCP_AI_Architectural_Design_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'architectural_design';
		$this->toolkit_name     = __( 'Architectural Design Toolkit', 'nvoos-content-graph-pro' );
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
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Architectural Design Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>

				<div class="toolkit-description">
					<p>
						<?php
						esc_html_e(
							'AI-assisted architectural design with 39 production tools spanning floor planning, 3D visualisation, construction documentation, regional code compliance, structural & sustainability analysis, certification scoring (EDGE / LEED v4 BD+C), cost engineering (BoQ in POMI / SMM7 / NRM2 / CSI MasterFormat), BIM interoperability (IFC 4.3 / gbXML 6.01 / DWG), project delivery (BEP / RFI / submittal logs), and an embedding-based precedent library.',
							'nvoos-content-graph-pro'
						);
						?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Regional coverage:', 'nvoos-content-graph-pro' ); ?></strong>
						<?php esc_html_e( 'Sri Lanka (primary — UDA + 2025 Gazette 2430/13), Jamaica (JNBC 2018 + ASCE 7 hurricane wind), and the United States (IBC / IRC 2024, IECC 2024, ASCE 7-22, ASHRAE 90.1-2022).', 'nvoos-content-graph-pro' ); ?>
					</p>
					<p class="description">
						<em>
							<?php esc_html_e( 'Analytical and advisory output only. Engage a registered architect, chartered structural engineer, MEP engineer and quantity surveyor before any submission to a planning authority or construction contract.', 'nvoos-content-graph-pro' ); ?>
						</em>
					</p>
				</div>

				<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'AI Floor Plan Generation:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Create, optimise and vary floor plans from natural-language briefs or sketches.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( '3D Visualisation:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Generate 3D models, photorealistic renderings and walkthrough animations.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Regional Code Compliance:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Wind & seismic load calculations, setback / FAR validation, and dedicated UDA, JNBC and IBC/IRC compliance dispatch.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Sustainability Scoring:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'IFC EDGE (LK/JM/US baselines) and full LEED v4 BD+C credit catalogue with prerequisites and certification thresholds.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Climate & Comfort Analysis:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Natural ventilation, daylight & solar-gain analysis, and thermal-comfort simulation tuned for tropical, hurricane-prone and temperate climates.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Cost Engineering:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Material schedules, parametric estimates, BoQ generation and a curated value-engineering option library.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'BIM Interoperability:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Import DWG / IFC payloads and export structurally valid IFC 4.3 STEP and gbXML 6.01 files for downstream tooling.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Project Delivery:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'BIM Execution Plan generation (AIA E202 / E203 / ISO 19650-2) plus RFI and submittal logs stored against project posts.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Precedent Library:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Curate built case studies and search them with embedding-based semantic similarity (with a deterministic keyword fallback).', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'CPT-Backed Entities:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Projects, drawings, specifications and precedents are stored as mcp_ai_arch_* custom post types so every tool reads from a single source of truth.', 'nvoos-content-graph-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Tool Modules (39 tools across 10 modules)', 'nvoos-content-graph-pro' ); ?></h3>
				<div class="tool-categories">
					<div class="tool-category">
						<h4><?php esc_html_e( 'Floor Planning & Space Design (4 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'AI-powered floor-plan generation, layout optimisation, design variations, and sketch-to-plan conversion.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( '3D Modelling & Visualisation (3 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( '3D model generation, photorealistic rendering, and walkthrough animation.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Documentation & Blueprints (3 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Construction-drawing sets, detail drawings, and multi-format document export.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Analysis & Compliance (5 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Building-code compliance, structural feasibility, sustainability metrics, natural ventilation, daylight & solar gain.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Estimation & Scheduling (5 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Material schedules, construction cost estimates, project timelines, BoQ generation, and value-engineering options.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Regional Compliance (7 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Wind & seismic loads, setback / FAR validation, UDA (LK), JNBC hurricane (JM), US IBC/IRC dispatch, and combined compliance dossiers.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Sustainability (3 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Thermal-comfort simulation, IFC EDGE certification scoring, and full LEED v4 BD+C scoring.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Interoperability — IFC / gbXML / DWG (4 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Import DWG and IFC payloads, export structurally valid IFC 4.3 STEP and gbXML 6.01 for IfcOpenShell, EnergyPlus and OpenStudio.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Project Delivery — BEP / RFI / Submittal (3 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'BIM Execution Plans (AIA E202 / E203 / ISO 19650-2), RFI logs and submittal logs scoped to each project post.', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="tool-category">
						<h4><?php esc_html_e( 'Precedent Library — Semantic Search (2 tools)', 'nvoos-content-graph-pro' ); ?></h4>
						<p><?php esc_html_e( 'Manage architectural precedents and search them with OpenAI embeddings (deterministic keyword fallback when offline).', 'nvoos-content-graph-pro' ); ?></p>
					</div>
				</div>

				<h3><?php esc_html_e( 'Industry-Standards Alignment', 'nvoos-content-graph-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Sri Lanka:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'UDA Planning & Building Regulations (incl. Gazette 2430/13 effective 1 April 2025), SLS 947:2009 ventilation, BS 6399-2 / IS 875-3 wind, IS 1893 seismic, NBRO landslide zonation, CIDA / ICTAD cost indices, SLIA architect signoff.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Jamaica:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'JNBC 2018 (BSJ) referencing ASCE 7 wind & seismic, JS 35:1996 natural ventilation, parish-council overlays via wp_mcp_ai_arch_code_packs filter.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'United States:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'IBC 2024 / IRC 2024, IECC 2024, ASCE 7-22, NFPA 101, ADA 2010, ASHRAE 90.1-2022 / 62.1 / 55.', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Cross-cutting:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'buildingSMART IFC 4.3, gbXML 6.01, CSI MasterFormat 2020 / UniFormat II / OmniClass, AIA E202 / E203 BEP, ISO 19650 information management.', 'nvoos-content-graph-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Use Cases', 'nvoos-content-graph-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Architects:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Rapid concept generation, client presentations and AIA-format reports', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Designers:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Space planning, interior layouts and tropical / hurricane-resilient envelope studies', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Contractors & QS:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Material takeoffs, BoQs (POMI / SMM7 / NRM2 / CSI MasterFormat) and value-engineering options', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Developers:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Feasibility studies, regional code dossiers and EDGE / LEED certification scoring', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'BIM Managers:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'IFC / gbXML exchange, BIM Execution Plans and RFI / submittal tracking', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Students:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Learning tool, portfolio projects and precedent research', 'nvoos-content-graph-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Quick Links', 'nvoos-content-graph-pro' ); ?></h3>
				<p class="quick-links">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_proj' ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-multisite"></span>
						<?php esc_html_e( 'Design Projects', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_proj&page=architectural-project-research' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Research & Add Design Project', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_drawing' ) ); ?>" class="button">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Drawings', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_spec' ) ); ?>" class="button">
						<span class="dashicons dashicons-media-document"></span>
						<?php esc_html_e( 'Specifications', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_prec' ) ); ?>" class="button">
						<span class="dashicons dashicons-book-alt"></span>
						<?php esc_html_e( 'Precedent Library', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>

				<h3><?php esc_html_e( 'Related Pro Toolkits', 'nvoos-content-graph-pro' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'These pro toolkits use the same enhanced Research & Add experience (workflow selector, import, review and quality-checking) and pair well with the Architectural Design Toolkit:', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p class="quick-links">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project&page=research-project' ) ); ?>" class="button">
						<span class="dashicons dashicons-portfolio"></span>
						<?php esc_html_e( 'Project Management — Research & Add', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_member&page=health-records-consolidate' ) ); ?>" class="button">
						<span class="dashicons dashicons-heart"></span>
						<?php esc_html_e( 'Health & Wellness — Consolidate & Add', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Architectural Design Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'These defaults are surfaced through wp_mcp_ai_arch_design_settings and consumed by every tool in the toolkit. Settings can be overridden per project (in project meta) or filtered programmatically via wp_mcp_ai_arch_toolkit_settings.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Region', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="default_country" class="regular-text">
							<option value="LK"><?php esc_html_e( 'Sri Lanka (UDA + Gazette 2430/13)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="JM"><?php esc_html_e( 'Jamaica (JNBC 2018 + ASCE 7 hurricane)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="US"><?php esc_html_e( 'United States (IBC / IRC 2024 + ASCE 7-22)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default jurisdiction used for compliance dispatch, wind / seismic tables, BoQ format, currency and cost rates.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Unit System', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="default_unit_system" class="regular-text">
							<option value="metric"><?php esc_html_e( 'Metric (metres, millimetres)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="imperial"><?php esc_html_e( 'Imperial (feet, inches)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default measurement system for floor plans, dimensions, loads and area calculations.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Building Code Pack', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="building_code_standard" class="regular-text">
							<option value="lk_uda_2021"><?php esc_html_e( 'UDA Planning & Building Regulations (Sri Lanka)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="jm_jnbc_2018"><?php esc_html_e( 'Jamaica National Building Code (JNBC) 2018', 'nvoos-content-graph-pro' ); ?></option>
							<option value="us_ibc_2024"><?php esc_html_e( 'International Building Code (IBC) 2024', 'nvoos-content-graph-pro' ); ?></option>
							<option value="us_irc_2024"><?php esc_html_e( 'International Residential Code (IRC) 2024', 'nvoos-content-graph-pro' ); ?></option>
							<option value="local"><?php esc_html_e( 'Local / Custom (registered via wp_mcp_ai_arch_code_packs)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Primary code pack for compliance validation. Custom packs can be registered through the wp_mcp_ai_arch_code_packs filter.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Export Format', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="default_export_format" class="regular-text">
							<option value="pdf"><?php esc_html_e( 'PDF (Universal)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="dwg"><?php esc_html_e( 'DWG (AutoCAD)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="ifc"><?php esc_html_e( 'IFC 4.3 (BIM open exchange)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="gbxml"><?php esc_html_e( 'gbXML 6.01 (energy / OpenStudio)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="svg"><?php esc_html_e( 'SVG (Vector)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format for exporting architectural documents and BIM models.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IFC Export Version', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="ifc_export_version" class="regular-text">
							<option value="4.3"><?php esc_html_e( 'IFC 4.3 (recommended)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="4.0"><?php esc_html_e( 'IFC 4.0', 'nvoos-content-graph-pro' ); ?></option>
							<option value="2x3"><?php esc_html_e( 'IFC 2x3 (legacy)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Schema version used by the IFC STEP-format builder.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rendering Quality', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="rendering_quality" class="regular-text">
							<option value="draft"><?php esc_html_e( 'Draft (Fast)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="standard"><?php esc_html_e( 'Standard (Balanced)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="high"><?php esc_html_e( 'High (Photorealistic)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default quality for 3D renderings and visualizations.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable GPU Rendering', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_gpu_rendering" value="1" />
							<?php esc_html_e( 'Use GPU acceleration for faster rendering (requires remote sites)', 'nvoos-content-graph-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sustainability Framework', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="sustainability_framework" class="regular-text">
							<option value="edge"><?php esc_html_e( 'IFC EDGE (recommended for LK / JM tropical climates)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="leed"><?php esc_html_e( 'LEED v4 BD+C (Leadership in Energy and Environmental Design)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="breeam"><?php esc_html_e( 'BREEAM (Building Research Establishment)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="energy_star"><?php esc_html_e( 'Energy Star', 'nvoos-content-graph-pro' ); ?></option>
							<option value="passive_house"><?php esc_html_e( 'Passive House', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Framework used by score_edge_certification and score_leed_v4_certification. EDGE is the default for LK / JM; LEED v4 BD+C is the default for the US.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'BoQ MasterFormat Year', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="masterformat_year" class="regular-text">
							<option value="2020"><?php esc_html_e( 'CSI MasterFormat 2020 (recommended)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="2018"><?php esc_html_e( 'CSI MasterFormat 2018', 'nvoos-content-graph-pro' ); ?></option>
							<option value="2016"><?php esc_html_e( 'CSI MasterFormat 2016', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'CSI MasterFormat edition used when generating Bills of Quantities for the United States. POMI (LK) and SMM7 / NRM2 (JM) are dispatched automatically per region.', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * Returns the full Phase A + B + C + D + E inventory (39 tools across 10 modules)
	 * matching the Architectural Design Toolkit README.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Floor Planning & Space Design (Phase A — 4 tools).
			'generate_floor_plan'               => __( 'Generate Floor Plan', 'nvoos-content-graph-pro' ),
			'optimize_space_layout'             => __( 'Optimize Space Layout', 'nvoos-content-graph-pro' ),
			'create_floor_plan_variations'      => __( 'Create Floor Plan Variations', 'nvoos-content-graph-pro' ),
			'convert_sketch_to_floor_plan'      => __( 'Convert Sketch to Floor Plan', 'nvoos-content-graph-pro' ),

			// 3D Modeling & Visualization (Phase A — 3 tools).
			'generate_3d_model'                 => __( 'Generate 3D Model', 'nvoos-content-graph-pro' ),
			'render_architectural_view'         => __( 'Render Architectural View', 'nvoos-content-graph-pro' ),
			'create_walkthrough_animation'      => __( 'Create Walkthrough Animation', 'nvoos-content-graph-pro' ),

			// Documentation & Blueprints (Phase A — 3 tools).
			'generate_construction_drawings'    => __( 'Generate Construction Drawings', 'nvoos-content-graph-pro' ),
			'generate_detail_drawings'          => __( 'Generate Detail Drawings', 'nvoos-content-graph-pro' ),
			'export_architectural_documents'    => __( 'Export Architectural Documents', 'nvoos-content-graph-pro' ),

			// Analysis & Compliance (Phase A — 3 tools + Phase B — 2 tools = 5 tools).
			'check_building_code_compliance'    => __( 'Check Building Code Compliance', 'nvoos-content-graph-pro' ),
			'analyze_structural_feasibility'    => __( 'Analyze Structural Feasibility', 'nvoos-content-graph-pro' ),
			'calculate_sustainability_metrics'  => __( 'Calculate Sustainability Metrics', 'nvoos-content-graph-pro' ),
			'analyze_natural_ventilation'       => __( 'Analyze Natural Ventilation', 'nvoos-content-graph-pro' ),
			'analyze_daylight_and_solar_gain'   => __( 'Analyze Daylight & Solar Gain', 'nvoos-content-graph-pro' ),

			// Estimation & Scheduling (Phase A — 3 tools + Phase C — 2 tools = 5 tools).
			'generate_material_schedule'        => __( 'Generate Material Schedule', 'nvoos-content-graph-pro' ),
			'estimate_construction_cost'        => __( 'Estimate Construction Cost', 'nvoos-content-graph-pro' ),
			'generate_construction_timeline'    => __( 'Generate Construction Timeline', 'nvoos-content-graph-pro' ),
			'generate_bill_of_quantities'       => __( 'Generate Bill of Quantities (POMI / SMM7 / NRM2 / CSI MasterFormat)', 'nvoos-content-graph-pro' ),
			'propose_value_engineering_options' => __( 'Propose Value-Engineering Options', 'nvoos-content-graph-pro' ),

			// Regional Compliance (Phase B — 7 tools).
			'calculate_wind_loads'              => __( 'Calculate Wind Loads (BS 6399-2 / IS 875-3 / ASCE 7-22)', 'nvoos-content-graph-pro' ),
			'calculate_seismic_loads'           => __( 'Calculate Seismic Loads (IS 1893 / ASCE 7-22)', 'nvoos-content-graph-pro' ),
			'validate_setbacks_and_far'         => __( 'Validate Setbacks & FAR', 'nvoos-content-graph-pro' ),
			'check_uda_planning_compliance'     => __( 'Check UDA Planning Compliance (Sri Lanka)', 'nvoos-content-graph-pro' ),
			'check_jnbc_hurricane_compliance'   => __( 'Check JNBC Hurricane Compliance (Jamaica)', 'nvoos-content-graph-pro' ),
			'check_us_ibc_irc_compliance'       => __( 'Check US IBC / IRC Compliance', 'nvoos-content-graph-pro' ),
			'generate_compliance_dossier'       => __( 'Generate Compliance Dossier', 'nvoos-content-graph-pro' ),

			// Sustainability (Phase B — 1 tool + Phase C — 2 tools = 3 tools).
			'simulate_thermal_comfort'          => __( 'Simulate Thermal Comfort', 'nvoos-content-graph-pro' ),
			'score_edge_certification'          => __( 'Score IFC EDGE Certification', 'nvoos-content-graph-pro' ),
			'score_leed_v4_certification'       => __( 'Score LEED v4 BD+C Certification', 'nvoos-content-graph-pro' ),

			// Interoperability — IFC / gbXML / DWG (Phase D — 4 tools).
			'import_dwg_floor_plan'             => __( 'Import DWG Floor Plan', 'nvoos-content-graph-pro' ),
			'import_ifc_model'                  => __( 'Import IFC Model', 'nvoos-content-graph-pro' ),
			'export_to_ifc'                     => __( 'Export to IFC 4.3 (STEP)', 'nvoos-content-graph-pro' ),
			'export_to_gbxml'                   => __( 'Export to gbXML 6.01', 'nvoos-content-graph-pro' ),

			// Project Delivery — BEP / RFI / Submittal (Phase D — 3 tools).
			'generate_bim_execution_plan'       => __( 'Generate BIM Execution Plan (AIA E202 / E203 / ISO 19650-2)', 'nvoos-content-graph-pro' ),
			'manage_rfi_log'                    => __( 'Manage RFI Log', 'nvoos-content-graph-pro' ),
			'manage_submittal_log'              => __( 'Manage Submittal Log', 'nvoos-content-graph-pro' ),

			// Precedent Library — Semantic Search (Phase E — 2 tools).
			'manage_architectural_precedents'   => __( 'Manage Architectural Precedents', 'nvoos-content-graph-pro' ),
			'search_architectural_precedents'   => __( 'Search Architectural Precedents (Embedding-Based)', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Architectural_Design_Settings_Page();
}
