<?php
/**
 * Tool — Generate BIM Execution Plan.
 *
 * Produces a BIM Execution Plan (BEP) outline aligned with AIA E202 / E203
 * and ISO 19650-2. The tool returns both a structured section map (for the
 * LLM to fill in) and a markdown rendering ready for review.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Generate BIM Execution Plan.
 */
class WP_MCP_AI_Tool_Generate_Bim_Execution_Plan implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */

	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'generate_bim_execution_plan';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Generate BIM Execution Plan', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Produce a BIM Execution Plan (BEP) outline aligned with AIA E202/E203 and ISO 19650-2 — section catalogue and seeded content from the supplied project metadata, plus a ready-to-edit markdown rendering.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_name' => array( 'type' => 'string' ),
				'country_code' => array( 'type' => 'string' ),
				'standards'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'BIM standards to reference (e.g. ISO 19650-2, AIA E203, BS EN ISO 19650-1).', 'mcp-ai-wpoos-pro' ),
				),
				'bim_uses'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'BIM uses to highlight (e.g. design_authoring, clash_detection, energy_analysis, 4d_scheduling, 5d_costing, asset_management).', 'mcp-ai-wpoos-pro' ),
				),
				'lod'          => array(
					'type'        => 'string',
					'description' => __( 'Level of Development (e.g. LOD 200/300/350/400) per AIA E203.', 'mcp-ai-wpoos-pro' ),
				),
				'cde_platform' => array(
					'type'        => 'string',
					'description' => __( 'Common Data Environment platform name.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'project_name' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate a BIM Execution Plan.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $arguments['project_name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'project_name is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_name = sanitize_text_field( (string) $arguments['project_name'] );
		$country_code = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$standards    = isset( $arguments['standards'] ) && is_array( $arguments['standards'] )
			? array_map( 'sanitize_text_field', $arguments['standards'] )
			: array( 'ISO 19650-2', 'AIA E203' );
		$bim_uses     = isset( $arguments['bim_uses'] ) && is_array( $arguments['bim_uses'] )
			? array_map( 'sanitize_text_field', $arguments['bim_uses'] )
			: array( 'design_authoring', 'clash_detection', 'energy_analysis' );
		$lod          = isset( $arguments['lod'] ) ? sanitize_text_field( $arguments['lod'] ) : 'LOD 350';
		$cde_platform = isset( $arguments['cde_platform'] ) ? sanitize_text_field( $arguments['cde_platform'] ) : 'BIM 360 / ACC';

		$catalog  = WP_MCP_AI_Architectural_Interop::bep_section_catalog();
		$sections = array();
		foreach ( $catalog as $key => $title ) {
			$sections[ $key ] = array(
				'title'    => $title,
				'guidance' => $this->section_guidance( $key, $project_name, $country_code, $standards, $bim_uses, $lod, $cde_platform ),
			);
		}

		// Markdown rendering.
		$md   = array();
		$md[] = '# BIM Execution Plan — ' . $project_name;
		$md[] = '';
		$md[] = '*Aligned with: ' . implode( ', ', $standards ) . '*';
		if ( '' !== $country_code ) {
			$md[] = '*Country / jurisdiction:* `' . $country_code . '`';
		}
		$md[] = '';
		foreach ( $sections as $key => $section ) {
			$md[] = '## ' . $section['title'];
			$md[] = '';
			$md[] = $section['guidance'];
			$md[] = '';
		}

		return array(
			'success'       => true,
			'project_name'  => $project_name,
			'standards'     => $standards,
			'bim_uses'      => $bim_uses,
			'lod'           => $lod,
			'cde_platform'  => $cde_platform,
			'sections'      => $sections,
			'markdown'      => implode( "\n", $md ),
			'section_count' => count( $sections ),
		);
	}

	/**
	 * Build seeded guidance for a single BEP section.
	 *
	 * @param string $key          Section key.
	 * @param string $project_name Project name.
	 * @param string $country_code Country code.
	 * @param array  $standards    Standards.
	 * @param array  $bim_uses     BIM uses.
	 * @param string $lod          Level of Development.
	 * @param string $cde_platform CDE platform.
	 * @return string
	 */
	private function section_guidance( $key, $project_name, $country_code, array $standards, array $bim_uses, $lod, $cde_platform ) {
		switch ( $key ) {
			case 'project_information':
				return sprintf(
					'Project: %s. Jurisdiction: %s. Document the client, lead designer, contractor, key consultants and their contact details. Reference applicable %s.',
					$project_name,
					'' !== $country_code ? $country_code : 'TBC',
					implode( ' + ', $standards )
				);
			case 'project_goals':
				return 'List measurable BIM goals (e.g. clash density < X per discipline, energy modelling within Y% of measured baseline, 4D schedule integration with critical path).';
			case 'project_uses':
				return 'BIM uses adopted for this project: ' . implode( ', ', $bim_uses ) . '. Confirm the responsible discipline lead and the upstream / downstream dependencies for each.';
			case 'roles_responsibilities':
				return 'Define Information Manager, BIM Manager, Discipline BIM Leads, and Modeller roles per ISO 19650-2 § 5.1. Capture decision-rights for model federation and clash resolution.';
			case 'process_design':
				return 'Document the information delivery cycle (Project Information Requirements → Exchange Information Requirements → BIM Execution Plan → Master / Task Information Delivery Plans).';
			case 'information_exchanges':
				return 'Specify Level of Development (' . $lod . '), Level of Information Need (LOIN), and the deliverable matrix per discipline / phase. Document IFC export profile, COBie scope, and asset data drops.';
			case 'collaboration':
				return 'CDE platform: ' . $cde_platform . '. Document file naming convention (e.g. PROJECT-ORIG-VOL-LVL-TYPE-ROLE-NUMBER per ISO 19650-2 Annex A), revision codes (S0 / S1 / etc.) and federation cadence.';
			case 'quality_control':
				return 'Define clash-detection cycles (frequency, tolerance, sign-off matrix), model audit checklists, and validation tools (Solibri, Navisworks, BIMcollab Zoom).';
			case 'tech_infrastructure':
				return 'Capture authoring software versions (Revit, ArchiCAD, Tekla), exchange formats (IFC 4.3, BCF 3.0, COBie), and minimum hardware for federation. Identify export presets.';
			case 'project_deliverables':
				return 'List PIM / AIM, IFC, COBie, native-format deliverables per RIBA / AIA stage. Reference the asset information requirements for handover.';
			case 'risk_management':
				return 'Maintain a BIM risk register: model fidelity, software incompatibility, data-loss on exchange, IP and licensing risks.';
			case 'training_handover':
				return 'Record training plan for project participants and the closeout / handover steps (final IFC, COBie data, AIM transfer to FM platform).';
		}
		return '';
	}
}
